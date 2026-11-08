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
require_once __DIR__ . '/Rbac.php';

final class Realtime
{
    private const TOKEN_TTL_SECONDS = 300;
    /** A manager's token lists one channel per team member; keep it small. */
    private const MAX_TEAM_CHANNELS = 100;
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
        return self::tokenForClaims(
            (int)($session['user_id'] ?? 0),
            strtolower((string)($session['role'] ?? '')),
            self::channelsForSession($session)
        );
    }

    /**
     * The channels a role may hear, taken from the permission matrix
     * (leave_requests scope) instead of from the role's name:
     *   all  (admin, hr)  -> "hr"                      every leave event
     *   team (manager)    -> "employee:<id>" per report their team's events only
     *   own  (employee)   -> "employee:<own id>"       their own events only
     *   none              -> no channel
     * A manager's list is capped so the token stays small.
     *
     * @return string[]
     */
    public static function channelsForAccount(PDO $pdo, string $role, ?int $employeeId): array
    {
        if (!Rbac::can($pdo, $role, 'leave_requests', 'view')) {
            return [];
        }
        switch (Rbac::scope($pdo, $role, 'leave_requests')) {
            case 'all':
                return ['hr'];
            case 'team':
                $channels = [];
                foreach (array_slice(Rbac::teamEmployeeIds($pdo, $employeeId), 0, self::MAX_TEAM_CHANNELS) as $id) {
                    $channels[] = 'employee:' . $id;
                }
                return $channels;
            case 'own':
                return $employeeId ? ['employee:' . $employeeId] : [];
            default:
                return [];
        }
    }

    /**
     * Issue a token from a DB-verified account (preferred over
     * tokenForSession, which trusts the PHP session as-is).
     *
     * $account must come from a fresh database read and contain:
     *   'user_id' => int, 'role' => string, 'employee_id' => ?int
     * Returns null if the account's role has no realtime channel.
     */
    public static function tokenForAccount(array $account): ?string
    {
        $role = strtolower(trim((string)($account['role'] ?? '')));
        $employeeId = $account['employee_id'] ?? null;

        // Preferred: the caller passes the channels it worked out from the
        // permission matrix (see channelsForAccount()). Only well-formed
        // channel names are accepted; the server rejects anything else anyway.
        if (isset($account['channels']) && is_array($account['channels'])) {
            $channels = array_values(array_filter(
                $account['channels'],
                static fn ($c): bool => is_string($c) && preg_match('/^(hr|employee:\d{1,18})$/', $c) === 1
            ));
        } else {
            // Fallback (no channel list given): same rule as channelsForSession().
            $channels = [];
            if ($role === 'employee' && !empty($employeeId)) {
                $channels = ['employee:' . (int)$employeeId];
            } elseif ($role === 'hr' || $role === 'admin') {
                $channels = ['hr'];
            }
        }

        return self::tokenForClaims((int)($account['user_id'] ?? 0), $role, $channels);
    }

    /** Sign a token for the given user id, role and channels. */
    private static function tokenForClaims(int $userId, string $role, array $channels): ?string
    {
        if (!$channels || !self::enabled()) {
            return null;
        }
        $claims = [
            'uid'  => $userId,
            'role' => $role,
            'ch'   => array_values($channels),
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
