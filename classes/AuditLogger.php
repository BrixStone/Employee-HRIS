<?php

class AuditLogger
{
    private static ?PDO $pdo = null;

    private static function connect(): PDO
    {
        if (self::$pdo === null) {
            global $pdo;
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /**
     * Log a system event.
     *
     * @param string   $action       e.g. 'login', 'employee.create', 'leave.approve'
     * @param string   $entity_type  e.g. 'user', 'employee', 'leave_request', 'payslip'
     * @param int|null $entity_id    The ID of the affected record (null if not applicable)
     * @param array    $details      Any extra data: old values, new values, notes, IP, etc.
     */
    public static function log(
        string $action,
        string $entity_type,
        ?int $entity_id = null,
        array $details = []
    ): void {
        try {
            $actor_user_id = $_SESSION['user_id'] ?? null;

            // Always capture IP and user agent automatically
            $details['ip']         = $_SERVER['REMOTE_ADDR']     ?? 'unknown';
            $details['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

            $db = self::connect();
            $stmt = $db->prepare("
                INSERT INTO audit_logs
                    (actor_user_id, action, entity_type, entity_id, details)
                VALUES
                    (:actor_user_id, :action, :entity_type, :entity_id, :details)
            ");

            $stmt->execute([
                ':actor_user_id' => $actor_user_id,
                ':action'        => $action,
                ':entity_type'   => $entity_type,
                ':entity_id'     => $entity_id,
                ':details'       => json_encode($details, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            // Never crash the app because of a logging failure
            error_log('[AuditLogger] Failed to write log: ' . $e->getMessage());
        }
    }

    /**
     * Shortcut for failed login attempts (no session user_id available).
     */
    public static function logFailedLogin(string $username): void
    {
        try {
            $db = self::connect();
            $stmt = $db->prepare("
                INSERT INTO audit_logs
                    (actor_user_id, action, entity_type, entity_id, details)
                VALUES
                    (NULL, 'login.failed', 'user', NULL, :details)
            ");

            $stmt->execute([
                ':details' => json_encode([
                    'attempted_username' => $username,
                    'ip'                 => $_SERVER['REMOTE_ADDR']     ?? 'unknown',
                    'user_agent'         => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('[AuditLogger] Failed to write failed login log: ' . $e->getMessage());
        }
    }
}
