/**
 * HRIS realtime client
 * --------------------
 * Include on a page (after login) with:
 *   <script src="../asset/js/realtime.js" data-refresh="auto|prompt|none" defer></script>
 *
 *  - Gets a short-lived token from api/realtime_token.php (session cookie),
 *    opens the WebSocket and reconnects with backoff when it drops.
 *  - Shows an accessible toast for each leave event the server sends.
 *    Employees only ever receive events for their own requests.
 *  - data-refresh="auto"   reloads the page after the toast (lists stay current)
 *    data-refresh="prompt" shows a "Refresh" button instead (keeps form input)
 *  - Dispatches a "hris:realtime" DOM event so pages can react themselves.
 *  - Updates any element with [data-realtime-status] to Live / Offline.
 *  - Does nothing inside iframes (the parent page owns the connection).
 */
(function () {
  'use strict';
  if (window.HRISRealtime) return;
  if (window.top !== window.self) {
    // Embedded (e.g. the history panel in file_leave.php): the parent page
    // holds the connection and reloads this frame, so hide the badge here.
    document.querySelectorAll('[data-realtime-status]').forEach((el) => { el.hidden = true; });
    return;
  }

  const script = document.currentScript;
  const refreshMode = (script && script.dataset.refresh) || 'prompt';
  const tokenUrl = new URL('../../api/realtime_token.php', script ? script.src : location.href).href;

  let socket = null;
  let attempts = 0;
  let stopped = false;
  let reloadTimer = null;

  // GET the same URL again. location.reload() would re-submit a POST
  // (e.g. right after cancelling a request) and trigger a resend prompt.
  function reloadPage() {
    window.location.replace(window.location.href.split('#')[0]);
  }

  // ------------------------------------------------------------------ UI
  function region() {
    let el = document.getElementById('hris-realtime-toasts');
    if (!el) {
      el = document.createElement('div');
      el.id = 'hris-realtime-toasts';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:9999;display:flex;'
        + 'flex-direction:column;gap:10px;max-width:380px;font-family:Inter,Arial,sans-serif;';
      document.body.appendChild(el);
    }
    return el;
  }

  const COLORS = {
    filed: '#3b82f6', approved: '#10b981', rejected: '#f43f5e', cancelled: '#9ca3af',
  };

  function toast(title, body, action, withRefresh) {
    const box = document.createElement('div');
    box.style.cssText = 'background:#0a0514;color:#fff;border:1px solid #281545;border-left:4px solid '
      + (COLORS[action] || '#4763ff') + ';border-radius:12px;padding:12px 14px;'
      + 'box-shadow:0 10px 30px rgba(0,0,0,.45);font-size:13px;line-height:1.4;';

    const strong = document.createElement('div');
    strong.style.cssText = 'font-weight:600;margin-bottom:2px;';
    strong.textContent = title;
    const text = document.createElement('div');
    text.style.color = '#c9c7d1';
    text.textContent = body;
    box.append(strong, text);

    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;margin-top:8px;';
    if (withRefresh) {
      const refresh = document.createElement('button');
      refresh.type = 'button';
      refresh.textContent = 'Refresh';
      refresh.style.cssText = 'background:#4763ff;color:#fff;border:0;border-radius:8px;padding:5px 12px;'
        + 'font-size:12px;font-weight:600;cursor:pointer;';
      refresh.addEventListener('click', reloadPage);
      row.appendChild(refresh);
    }
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = 'Dismiss';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.style.cssText = 'background:transparent;color:#8b8994;border:1px solid #281545;border-radius:8px;'
      + 'padding:5px 10px;font-size:12px;cursor:pointer;';
    close.addEventListener('click', () => box.remove());
    row.appendChild(close);
    box.appendChild(row);

    region().appendChild(box);
    if (!withRefresh) setTimeout(() => box.remove(), 8000);
  }

  function setStatus(state) {
    document.querySelectorAll('[data-realtime-status]').forEach((el) => {
      el.textContent = state === 'live' ? 'Live' : state === 'connecting' ? 'Connecting…' : 'Offline';
      el.dataset.state = state;
      el.title = state === 'live'
        ? 'Real-time updates are on'
        : 'Real-time updates are unavailable; refresh to see changes';
    });
  }

  // ------------------------------------------------------------ messages
  function describe(ev) {
    const ref = '#' + String(ev.request_id || '').padStart(5, '0');
    const leave = ev.leave_name || 'Leave';
    const dates = ev.start_date
      ? ' (' + ev.start_date + (ev.end_date && ev.end_date !== ev.start_date ? ' to ' + ev.end_date : '') + ')'
      : '';
    const by = ev.by_name || (ev.by_role === 'hr' ? 'HR' : 'someone');
    const who = ev.employee_name || 'An employee';
    const forHr = !!(window.HRISRealtime && window.HRISRealtime.role !== 'employee');

    switch (ev.action) {
      case 'Filed':
        return forHr
          ? ['New leave request ' + ref, who + ' filed ' + leave + dates + '.']
          : ['Leave request filed ' + ref, 'Your ' + leave + dates + ' was submitted for review.'];
      case 'Approved':
        return forHr
          ? ['Request ' + ref + ' approved', by + ' approved ' + who + "'s " + leave + dates + '.']
          : ['Your leave was approved', by + ' approved your ' + leave + dates + ' ' + ref + '.'];
      case 'Rejected':
        return forHr
          ? ['Request ' + ref + ' rejected', by + ' rejected ' + who + "'s " + leave + dates + '.']
          : ['Your leave was declined', by + ' declined your ' + leave + dates + ' ' + ref + '.'];
      case 'Cancelled':
        return forHr
          ? ['Request ' + ref + ' cancelled', who + ' cancelled ' + leave + dates + '.']
          : ['Leave request cancelled', 'Your ' + leave + dates + ' ' + ref + ' was cancelled.'];
      default:
        return ['Leave update ' + ref, leave + ' is now ' + (ev.new_status || 'updated') + '.'];
    }
  }

  function onEvent(ev) {
    const [title, body] = describe(ev);
    const action = String(ev.action || '').toLowerCase();
    const domEvent = new CustomEvent('hris:realtime', { detail: ev, cancelable: true });
    const handled = !window.dispatchEvent(domEvent); // page called preventDefault()

    toast(title, body, action, !handled && refreshMode === 'prompt');

    if (!handled && refreshMode === 'auto') {
      clearTimeout(reloadTimer);
      reloadTimer = setTimeout(reloadPage, 2500);
    }
  }

  // ---------------------------------------------------------- connection
  async function connect() {
    if (stopped) return;
    setStatus('connecting');

    let info;
    try {
      const res = await fetch(tokenUrl, { credentials: 'same-origin', cache: 'no-store' });
      if (res.status === 401 || res.status === 403) {
        stopped = true;                      // not logged in / no channel: stay quiet
        setStatus('offline');
        return;
      }
      info = await res.json();
      if (!info.enabled) throw new Error(info.error || 'realtime disabled');
    } catch (err) {
      return retry();
    }

    window.HRISRealtime = { role: info.role, connected: false };
    socket = new WebSocket(info.url + '?token=' + encodeURIComponent(info.token));

    socket.addEventListener('open', () => {
      attempts = 0;
      window.HRISRealtime.connected = true;
      setStatus('live');
    });
    socket.addEventListener('message', (msg) => {
      let data;
      try { data = JSON.parse(msg.data); } catch { return; }
      if (data.type === 'event' && data.event) onEvent(data.event);
    });
    socket.addEventListener('close', () => {
      if (window.HRISRealtime) window.HRISRealtime.connected = false;
      retry();
    });
    socket.addEventListener('error', () => { /* close follows */ });
  }

  function retry() {
    if (stopped) return;
    setStatus('offline');
    attempts += 1;
    const delay = Math.min(30000, 1000 * 2 ** Math.min(attempts, 5)) + Math.random() * 1000;
    setTimeout(connect, delay);
  }

  window.addEventListener('pagehide', () => {
    stopped = true;
    if (socket) socket.close(1000, 'page closed');
  });
  // Back/forward cache restored the page: reconnect
  window.addEventListener('pageshow', (ev) => {
    if (ev.persisted) {
      stopped = false;
      attempts = 0;
      connect();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', connect);
  } else {
    connect();
  }
})();
