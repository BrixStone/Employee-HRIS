<?php

/**
 * Realtime
 * --------
 * Connects the PHP app to the WebSocket server in realtime/server.js.
 *
 *  - publish(): after a leave transaction, push an event to the server
 *    (HMAC-signed HTTP POST, 1 second timeout, never throws).
 *  - tokenForSession(): short-lived signed token the browser uses to open
 *    the WebSocket. It lists the only channels the user may hear:
 *        employee  -> "employee:<employees.id>"  (their own requests only)
 *        hr/admin  -> "hr"                        (all requests)
 *
 * Configuration (first match wins):
 *   1. env HRIS_REALTIME_SECRET [+ HRIS_REALTIME_PUBLISH_URL, HRIS_REALTIME_PORT]
 *   2. config/realtime.json, written by `terraform apply` in terraform/
 * Without a secret, realtime is disabled and the app works as before.
 */
final class Realtime
{
    private const TOKEN_TTL_SECONDS = 300;
    private const PUBLISH_TIMEOUT_SECONDS = 1.0;

    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $file = [];
        $path = __DIR__ . '/../config/realtime.json';
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            $file = is_array($decoded) ? $decoded : [];
        }

        $secret = getenv('HRIS_REALTIME_SECRET') ?: ($file['secret'] ?? '');
        $port   = (int)(getenv('HRIS_REALTIME_PORT') ?: ($file['ws_port'] ?? 8090));

        return self::$config = [
            'secret'      => is_string($secret) ? $secret : '',
            'publish_url' => getenv('HRIS_REALTIME_PUBLISH_URL') ?: ($file['publish_url'] ?? "http://127.0.0.1:{$port}/publish"),
            'ws_port'     => $port,
            'ws_path'     => '/ws',
        ];
    }

    public static function enabled(): bool
    {
        return strlen(self::config()['secret']) >= 32;
    }

    /**
     * Channels a logged-in user may subscribe to, from the PHP session.
     * Returns [] when the session does not qualify for realtime updates.
     */
    public static function channelsForSession(array $session): array
    {
        $role = strtolower(trim((string)($session['role'] ?? '')));
        if ($role === 'employee' && !empty($session['employee_id'])) {
            return ['employee:' . (int)$session['employee_id']];
        }
        if ($role === 'hr' || $role === 'admin') {
            return ['hr'];
        }
        return [];
    }

    public static function tokenForSession(array $session): ?string
    {
        $channels = self::channelsForSession($session);
        if (!$channels || !self::enabled()) {
            return null;
        }
        $claims = [
            'uid'  => (int)($session['user_id'] ?? 0),
            'role' => strtolower((string)$session['role']),
            'ch'   => $channels,
            'iat'  => time(),
            'exp'  => time() + self::TOKEN_TTL_SECONDS,
        ];
        $payload = self::b64url(json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $payload . '.' . self::b64url(hash_hmac('sha256', $payload, self::config()['secret'], true));
    }

    /** ws://host:port/ws for the browser, using the host the page was loaded from. */
    public static function publicUrl(array $server): string
    {
        $cfg    = self::config();
        $host   = (string)($server['HTTP_HOST'] ?? 'localhost');
        // Strip any port; keep IPv6 brackets ("[::1]:8000" -> "[::1]")
        $host   = preg_replace('/:\d+$/', '', $host);
        if (!preg_match('/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.-]+)$/', $host)) {
            $host = 'localhost';
        }
        $secure = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
        return ($secure ? 'wss' : 'ws') . "://{$host}:{$cfg['ws_port']}{$cfg['ws_path']}";
    }

    /**
     * Push an event to the given channels. Returns false (and logs) when the
     * realtime server is not configured or not reachable; never throws.
     */
    public static function publish(array $channels, string $type, array $data = []): bool
    {
        if (!self::enabled() || !$channels) {
            return false;
        }

        $cfg  = self::config();
        $body = json_encode([
            'channels' => array_values(array_unique($channels)),
            'event'    => ['type' => $type, 'at' => date('c')] + $data,
            'ts'       => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n"
                                 . 'X-HRIS-Signature: sha256=' . hash_hmac('sha256', $body, $cfg['secret']) . "\r\n",
                'content'       => $body,
                'timeout'       => self::PUBLISH_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($cfg['publish_url'], false, $context);
        $ok = is_string($response) && (json_decode($response, true)['ok'] ?? false) === true;
        if (!$ok) {
            error_log('Realtime::publish failed for ' . $type . ' (is the realtime server running?)');
        }
        return $ok;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
