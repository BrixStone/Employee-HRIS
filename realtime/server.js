'use strict';
/**
 * HRIS real-time server
 * =====================
 * One port (default 8090) serves:
 *   GET  /ws?token=...  WebSocket for browsers. The token is issued by
 *                       api/realtime_token.php and lists the channels the
 *                       user may hear: "employee:<id>" (own requests only)
 *                       or "hr" (all requests, for HR/admin).
 *   POST /publish       Called by the PHP app after a leave transaction.
 *                       Body is HMAC-SHA256 signed with the shared secret.
 *   GET  /metrics       Prometheus metrics (connections, events).
 *   GET  /health        Liveness check.
 *
 * Secret: HRIS_REALTIME_SECRET env var, or config/realtime.json (written by
 * Terraform) when running locally with `node realtime/server.js`.
 */

const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { WebSocketServer, WebSocket } = require('ws');

const PORT = Number(process.env.PORT || 8090);
const HOST = process.env.HOST || '0.0.0.0';
const MAX_BODY_BYTES = 64 * 1024;
const PUBLISH_MAX_SKEW_SECONDS = 300;      // reject replayed/old publish calls
const HEARTBEAT_MS = 30_000;
const CHANNEL_RE = /^(hr|employee:\d{1,18})$/;
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || '')
  .split(',').map((s) => s.trim()).filter(Boolean);

const SECRET = loadSecret();

// ---------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------
const startedAt = Date.now();
const counters = {
  events: new Map(),          // type -> count
  deliveries: 0,
  authFailures: 0,
  publishRejected: 0,
};

// channel -> Set<WebSocket>
const channels = new Map();

function loadSecret() {
  let secret = process.env.HRIS_REALTIME_SECRET || '';
  if (!secret) {
    const file = process.env.HRIS_REALTIME_CONFIG
      || path.join(__dirname, '..', 'config', 'realtime.json');
    try {
      secret = JSON.parse(fs.readFileSync(file, 'utf8')).secret || '';
    } catch {
      // handled below
    }
  }
  if (secret.length < 32) {
    console.error('HRIS_REALTIME_SECRET is missing or shorter than 32 characters. '
      + 'Run `terraform apply` in terraform/ (it writes config/realtime.json) '
      + 'or set the environment variable.');
    process.exit(1);
  }
  return secret;
}

const nowSeconds = () => Math.floor(Date.now() / 1000);
const hmac = (data) => crypto.createHmac('sha256', SECRET).update(data).digest();

function safeEqual(a, b) {
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/** Token format (made by classes/Realtime.php): base64url(json).base64url(hmac) */
function verifyToken(token) {
  if (typeof token !== 'string' || token.length > 4096) return null;
  const parts = token.split('.');
  if (parts.length !== 2) return null;
  const [payload, signature] = parts;
  if (!safeEqual(Buffer.from(signature, 'base64url'), hmac(payload))) return null;

  let claims;
  try {
    claims = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
  } catch {
    return null;
  }
  if (!claims || typeof claims.exp !== 'number' || claims.exp < nowSeconds()) return null;
  if (!Array.isArray(claims.ch) || claims.ch.length === 0
    || !claims.ch.every((c) => typeof c === 'string' && CHANNEL_RE.test(c))) return null;
  return claims;
}

function subscribe(ws) {
  for (const ch of ws.claims.ch) {
    if (!channels.has(ch)) channels.set(ch, new Set());
    channels.get(ch).add(ws);
  }
}

function unsubscribe(ws) {
  for (const ch of ws.claims.ch) {
    const set = channels.get(ch);
    if (!set) continue;
    set.delete(ws);
    if (set.size === 0) channels.delete(ch);
  }
}

/** Send one event to every socket on any of the channels (each socket once). */
function deliver(targetChannels, event) {
  const sockets = new Set();
  for (const ch of targetChannels) {
    for (const ws of channels.get(ch) || []) sockets.add(ws);
  }
  const message = JSON.stringify({ type: 'event', event });
  let sent = 0;
  for (const ws of sockets) {
    if (ws.readyState === WebSocket.OPEN) {
      ws.send(message);
      sent += 1;
    }
  }
  counters.deliveries += sent;
  const type = typeof event.type === 'string' ? event.type.slice(0, 40) : 'unknown';
  counters.events.set(type, (counters.events.get(type) || 0) + 1);
  return sent;
}

// ---------------------------------------------------------------------------
// HTTP: /publish, /metrics, /health
// ---------------------------------------------------------------------------
function sendJson(res, status, body) {
  const data = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(data),
    'Cache-Control': 'no-store',
  });
  res.end(data);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(new Error('body too large'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

async function handlePublish(req, res) {
  let raw;
  try {
    raw = await readBody(req);
  } catch {
    counters.publishRejected += 1;
    return sendJson(res, 413, { ok: false, error: 'body too large' });
  }

  const header = String(req.headers['x-hris-signature'] || '');
  const given = Buffer.from(header.replace(/^sha256=/, ''), 'hex');
  if (!safeEqual(given, hmac(raw))) {
    counters.publishRejected += 1;
    return sendJson(res, 401, { ok: false, error: 'bad signature' });
  }

  let msg;
  try {
    msg = JSON.parse(raw.toString('utf8'));
  } catch {
    counters.publishRejected += 1;
    return sendJson(res, 400, { ok: false, error: 'invalid json' });
  }

  const skew = Math.abs(nowSeconds() - Number(msg.ts));
  const target = Array.isArray(msg.channels) ? msg.channels : [];
  if (!(skew <= PUBLISH_MAX_SKEW_SECONDS)
    || target.length === 0
    || !target.every((c) => typeof c === 'string' && CHANNEL_RE.test(c))
    || !msg.event || typeof msg.event !== 'object') {
    counters.publishRejected += 1;
    return sendJson(res, 400, { ok: false, error: 'invalid message' });
  }

  const delivered = deliver(target, msg.event);
  console.log(`publish ${msg.event.type || 'event'} -> ${target.length} channel(s), ${delivered} socket(s)`);
  return sendJson(res, 202, { ok: true, delivered });
}

function renderMetrics() {
  const byRole = new Map([['employee', 0], ['hr', 0]]);
  for (const ws of wss.clients) {
    const role = ws.claims && ws.claims.ch.includes('hr') ? 'hr' : 'employee';
    byRole.set(role, byRole.get(role) + 1);
  }
  const lines = [
    '# HELP hris_realtime_up Realtime WebSocket server is running (always 1)',
    '# TYPE hris_realtime_up gauge',
    'hris_realtime_up 1',
    '# HELP hris_realtime_start_time_seconds Unix time the server started',
    '# TYPE hris_realtime_start_time_seconds gauge',
    `hris_realtime_start_time_seconds ${Math.floor(startedAt / 1000)}`,
    '# HELP hris_realtime_connections Open WebSocket connections by audience',
    '# TYPE hris_realtime_connections gauge',
    ...[...byRole].map(([role, n]) => `hris_realtime_connections{role="${role}"} ${n}`),
    '# HELP hris_realtime_events_total Events published by the PHP app, by type',
    '# TYPE hris_realtime_events_total counter',
    ...[...counters.events].map(([type, n]) => `hris_realtime_events_total{type="${type.replace(/["\\\n]/g, '_')}"} ${n}`),
    '# HELP hris_realtime_deliveries_total Messages pushed to browsers',
    '# TYPE hris_realtime_deliveries_total counter',
    `hris_realtime_deliveries_total ${counters.deliveries}`,
    '# HELP hris_realtime_auth_failures_total WebSocket connections rejected (bad/expired token)',
    '# TYPE hris_realtime_auth_failures_total counter',
    `hris_realtime_auth_failures_total ${counters.authFailures}`,
    '# HELP hris_realtime_publish_rejected_total Publish calls rejected (bad signature or payload)',
    '# TYPE hris_realtime_publish_rejected_total counter',
    `hris_realtime_publish_rejected_total ${counters.publishRejected}`,
  ];
  return lines.join('\n') + '\n';
}

const server = http.createServer((req, res) => {
  const { pathname } = new URL(req.url, 'http://localhost');

  if (req.method === 'POST' && pathname === '/publish') {
    handlePublish(req, res).catch((err) => {
      console.error('publish error:', err.message);
      sendJson(res, 500, { ok: false, error: 'internal error' });
    });
    return;
  }
  if (req.method === 'GET' && pathname === '/metrics') {
    const body = renderMetrics();
    res.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4; charset=utf-8' });
    res.end(body);
    return;
  }
  if (req.method === 'GET' && pathname === '/health') {
    sendJson(res, 200, { status: 'ok', connections: wss.clients.size });
    return;
  }
  sendJson(res, 404, { ok: false, error: 'not found' });
});

// ---------------------------------------------------------------------------
// WebSocket: /ws?token=...
// ---------------------------------------------------------------------------
const wss = new WebSocketServer({ noServer: true, maxPayload: 4 * 1024 });

function rejectUpgrade(socket, status) {
  socket.write(`HTTP/1.1 ${status} ${http.STATUS_CODES[status]}\r\n`
    + 'Connection: close\r\nContent-Length: 0\r\n\r\n');
  socket.destroy();
}

server.on('upgrade', (req, socket, head) => {
  socket.on('error', () => socket.destroy());
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname !== '/ws') return rejectUpgrade(socket, 404);

  if (ALLOWED_ORIGINS.length && !ALLOWED_ORIGINS.includes(req.headers.origin)) {
    counters.authFailures += 1;
    return rejectUpgrade(socket, 403);
  }

  const claims = verifyToken(url.searchParams.get('token'));
  if (!claims) {
    counters.authFailures += 1;
    return rejectUpgrade(socket, 401);
  }

  wss.handleUpgrade(req, socket, head, (ws) => wss.emit('connection', ws, claims));
});

wss.on('connection', (ws, claims) => {
  ws.claims = claims;
  ws.isAlive = true;
  subscribe(ws);

  ws.send(JSON.stringify({
    type: 'hello',
    role: claims.role,
    channels: claims.ch,
  }));

  ws.on('pong', () => { ws.isAlive = true; });
  // Browsers only listen; anything they send is ignored.
  ws.on('message', () => {});
  ws.on('close', () => unsubscribe(ws));
  ws.on('error', () => ws.terminate());
});

// Drop connections that stop answering pings (closed laptops, dead networks)
const heartbeat = setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.isAlive) {
      ws.terminate();
      continue;
    }
    ws.isAlive = false;
    ws.ping();
  }
}, HEARTBEAT_MS);

server.listen(PORT, HOST, () => {
  console.log(`HRIS realtime server listening on ${HOST}:${PORT}`);
});

function shutdown() {
  clearInterval(heartbeat);
  for (const ws of wss.clients) ws.close(1001, 'server shutting down');
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 3000).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
