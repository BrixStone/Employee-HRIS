<?php

require_once __DIR__ . '/Realtime.php';

/**
 * LeaveAuditLog
 * -------------
 * Records every leave transaction (Filed, Cancelled, Approved, Rejected)
 * together with the account that performed it, and reads the full
 * transaction history back for an employee.
 *
 * Table: leave_request_logs (part of database/hris_mysql.sql; also created
 * automatically if missing).
 *
 * Logging never throws: a failure to write an audit row is logged with
 * error_log() and must not break the leave action itself. Call log()
 * AFTER the main transaction has committed: if the table is missing,
 * MySQL's CREATE TABLE would implicitly commit an open transaction.
 */
class LeaveAuditLog
{
    public const ACTION_FILED     = 'Filed';
    public const ACTION_CANCELLED = 'Cancelled';
    public const ACTION_APPROVED  = 'Approved';
    public const ACTION_REJECTED  = 'Rejected';

    /** SQLSTATE for "table does not exist" (MySQL 42S02, Postgres 42P01). */
    private const MISSING_TABLE_CODES = ['42S02', '42P01'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS leave_request_logs (
                id                 BIGINT       NOT NULL AUTO_INCREMENT,
                leave_request_id   BIGINT       NOT NULL,
                employee_id        BIGINT       NOT NULL,
                action             VARCHAR(30)  NOT NULL,
                old_status         VARCHAR(30)  NULL,
                new_status         VARCHAR(30)  NULL,
                performed_by_id    BIGINT       NULL,
                performed_by_name  VARCHAR(255) NULL,
                performed_by_email VARCHAR(255) NULL,
                performed_by_role  VARCHAR(30)  NULL,
                remarks            TEXT         NULL,
                created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_leave_request_logs_employee (employee_id, created_at),
                KEY idx_leave_request_logs_request (leave_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private static function isMissingTable(PDOException $e): bool
    {
        return in_array((string)$e->getCode(), self::MISSING_TABLE_CODES, true);
    }

    /**
     * Write one audit row.
     *
     * @param array $actor ['id' => ?int, 'name' => ?string, 'email' => ?string, 'role' => ?string]
     */
    public function log(
        int $leaveRequestId,
        int $employeeId,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        array $actor,
        ?string $remarks = null
    ): bool {
        // HR/admin actions often only know the account id: store the
        // reviewer's real name and email so the history and the realtime
        // notification can say who did it.
        if (trim((string)($actor['name'] ?? '')) === ''
            && !empty($actor['id'])
            && strtolower((string)($actor['role'] ?? '')) !== 'employee') {
            $known = $this->lookupReviewers([(int)$actor['id']])[(int)$actor['id']] ?? null;
            if ($known) {
                $actor['name']  = $known['name'];
                $actor['email'] = $actor['email'] ?? $known['email'];
            }
        }

        $params = [
            'req'     => $leaveRequestId,
            'emp'     => $employeeId,
            'action'  => $action,
            'old'     => $oldStatus,
            'new'     => $newStatus,
            'by_id'   => isset($actor['id']) && $actor['id'] !== '' ? (int)$actor['id'] : null,
            'by_name' => $this->clip($actor['name'] ?? null, 255),
            'by_mail' => $this->clip($actor['email'] ?? null, 255),
            'by_role' => $this->clip($actor['role'] ?? null, 30),
            'remarks' => ($remarks !== null && trim($remarks) !== '') ? trim($remarks) : null,
        ];

        $sql = "
            INSERT INTO leave_request_logs (
                leave_request_id, employee_id, action, old_status, new_status,
                performed_by_id, performed_by_name, performed_by_email, performed_by_role, remarks
            ) VALUES (
                :req, :emp, :action, :old, :new,
                :by_id, :by_name, :by_mail, :by_role, :remarks
            )
        ";

        try {
            try {
                $ok = $this->pdo->prepare($sql)->execute($params);
            } catch (PDOException $e) {
                // Table missing: create it once, then retry.
                if (!self::isMissingTable($e)) {
                    throw $e;
                }
                $this->ensureTable();
                $ok = $this->pdo->prepare($sql)->execute($params);
            }
        } catch (Throwable $e) {
            error_log('LeaveAuditLog::log failed: ' . $e->getMessage());
            return false;
        }

        if ($ok) {
            $this->broadcast($leaveRequestId, $employeeId, $action, $oldStatus, $newStatus, $params);
        }
        return $ok;
    }

    /**
     * Push the transaction to the realtime server: to the owner's own
     * channel and to HR. Other employees never receive it.
     */
    private function broadcast(int $requestId, int $employeeId, string $action, ?string $old, ?string $new, array $params): void
    {
        if (!Realtime::enabled()) {
            return;
        }
        try {
            $st = $this->pdo->prepare("
                SELECT COALESCE(lt.leave_name, 'Leave') AS leave_name,
                       lr.start_date, lr.end_date, lr.number_of_days,
                       TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))) AS employee_name
                FROM leave_requests AS lr
                LEFT JOIN leave_types AS lt ON lt.id = lr.leave_type_id
                LEFT JOIN employees AS e ON e.id = lr.employee_id
                WHERE lr.id = :id
            ");
            $st->execute(['id' => $requestId]);
            $req = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            Realtime::publish(
                ['employee:' . $employeeId, 'hr'],
                'leave.' . strtolower($action),
                [
                    'request_id'    => $requestId,
                    'employee_id'   => $employeeId,
                    'employee_name' => $req['employee_name'] ?? null,
                    'leave_name'    => $req['leave_name'] ?? null,
                    'start_date'    => $req['start_date'] ?? null,
                    'end_date'      => $req['end_date'] ?? null,
                    'days'          => isset($req['number_of_days']) ? (float)$req['number_of_days'] : null,
                    'action'        => $action,
                    'old_status'    => $old,
                    'new_status'    => $new,
                    'by_name'       => $params['by_name'],
                    'by_role'       => $params['by_role'],
                ]
            );
        } catch (Throwable $e) {
            error_log('LeaveAuditLog::broadcast failed: ' . $e->getMessage());
        }
    }

    /**
     * Full transaction history for one employee, newest first.
     *
     * Combines recorded audit rows with events derived from leave_requests
     * for requests filed before audit logging existed (so older requests
     * still show who filed / reviewed them).
     *
     * @param array $employee ['name' => string, 'email' => string] of the owner
     */
    public function getTransactionsForEmployee(int $employeeId, array $employee): array
    {
        // 1. All requests for this employee
        $st = $this->pdo->prepare("
            SELECT
                lr.id, lr.status, lr.start_date, lr.end_date, lr.number_of_days,
                lr.approved_by, lr.approved_at, lr.created_at,
                COALESCE(lt.leave_name, 'Custom / Other') AS leave_name
            FROM leave_requests AS lr
            LEFT JOIN leave_types AS lt ON lt.id = lr.leave_type_id
            WHERE lr.employee_id = :emp
        ");
        $st->execute(['emp' => $employeeId]);
        $requests = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $requests[(int)$r['id']] = $r;
        }

        // 2. Recorded audit rows (table may not exist yet)
        $logs = [];
        try {
            $st = $this->pdo->prepare("
                SELECT l.id, l.leave_request_id, l.action, l.old_status, l.new_status,
                       l.performed_by_id, l.performed_by_name, l.performed_by_email,
                       l.performed_by_role, l.remarks, l.created_at
                FROM leave_request_logs AS l
                -- Only rows whose request really belongs to this employee
                INNER JOIN leave_requests AS lr
                    ON lr.id = l.leave_request_id AND lr.employee_id = l.employee_id
                WHERE l.employee_id = :emp
            ");
            $st->execute(['emp' => $employeeId]);
            $logs = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
        }

        // Reviewer rows logged without a name (e.g. approved from a page that
        // only knew the user id): resolve the account name from users.
        $unnamed = [];
        foreach ($logs as $log) {
            if (trim((string)$log['performed_by_name']) === ''
                && !empty($log['performed_by_id'])
                && strtolower((string)$log['performed_by_role']) !== 'employee') {
                $unnamed[] = (int)$log['performed_by_id'];
            }
        }
        $logReviewers = $this->lookupReviewers($unnamed);

        $events = [];
        $loggedActions = []; // [requestId][action] = true

        foreach ($logs as $log) {
            $reqId = (int)$log['leave_request_id'];
            $req   = $requests[$reqId] ?? null;
            $loggedActions[$reqId][$log['action']] = true;

            $known = $logReviewers[(int)$log['performed_by_id']] ?? null;
            $events[] = $this->event(
                $reqId,
                $req,
                $log['action'],
                $log['old_status'],
                $log['new_status'],
                [
                    'id'    => $log['performed_by_id'],
                    'name'  => $log['performed_by_name'] ?: ($known['name'] ?? null),
                    'email' => $log['performed_by_email'] ?: ($known['email'] ?? null),
                    'role'  => $log['performed_by_role'],
                ],
                $log['remarks'],
                $log['created_at'],
                true,
                (int)$log['id']
            );
        }

        // 3. Derive missing events for requests that predate audit logging
        $employeeActor = [
            'id'    => $employeeId,
            'name'  => $employee['name'] ?? null,
            'email' => $employee['email'] ?? null,
            'role'  => 'employee',
        ];
        $reviewerIds = [];
        $pendingReviews = [];

        foreach ($requests as $reqId => $req) {
            if (empty($loggedActions[$reqId][self::ACTION_FILED])) {
                $events[] = $this->event(
                    $reqId, $req, self::ACTION_FILED, null, 'Pending',
                    $employeeActor, null, $req['created_at'], false
                );
            }

            $status = (string)$req['status'];

            if ($status === 'Cancelled' && empty($loggedActions[$reqId][self::ACTION_CANCELLED])) {
                // Exact cancel time was never stored for legacy rows.
                $events[] = $this->event(
                    $reqId, $req, self::ACTION_CANCELLED, 'Pending', 'Cancelled',
                    $employeeActor, null, null, false, 0,
                    $this->ts($req['created_at']) + 1
                );
            }

            if (in_array($status, ['Approved', 'Rejected'], true) && empty($loggedActions[$reqId][$status])) {
                if (!empty($req['approved_by'])) {
                    $reviewerIds[] = (int)$req['approved_by'];
                }
                $pendingReviews[] = $req;
            }
        }

        if ($pendingReviews) {
            $reviewers = $this->lookupReviewers($reviewerIds);
            foreach ($pendingReviews as $req) {
                $byId  = !empty($req['approved_by']) ? (int)$req['approved_by'] : null;
                $actor = $byId !== null && isset($reviewers[$byId])
                    ? $reviewers[$byId]
                    : ['id' => $byId, 'name' => $byId !== null ? "HR Reviewer #{$byId}" : 'HR Reviewer', 'email' => null, 'role' => 'hr'];

                $events[] = $this->event(
                    (int)$req['id'], $req, (string)$req['status'], 'Pending', (string)$req['status'],
                    $actor, null, $req['approved_at'], false
                );
            }
        }

        usort($events, static function (array $a, array $b): int {
            return [$b['sort_ts'], $b['log_id'], $b['request_id']] <=> [$a['sort_ts'], $a['log_id'], $a['request_id']];
        });

        return $events;
    }

    /**
     * Latest review (Approved / Rejected / Cancelled) per request, keyed by request id.
     * Useful for showing "who reviewed this" next to each request.
     */
    public static function latestReviewByRequest(array $events): array
    {
        $map = [];
        foreach ($events as $ev) { // events are newest-first
            if ($ev['action'] === self::ACTION_FILED) {
                continue;
            }
            if (!isset($map[$ev['request_id']])) {
                $map[$ev['request_id']] = $ev;
            }
        }
        return $map;
    }

    // ------------------------------------------------------------------

    private function lookupReviewers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }

        $found = [];
        $in = implode(',', array_fill(0, count($ids), '?'));

        // Reviewers are login accounts (leave_requests.approved_by = users.id).
        // Prefer the linked employee's full name, fall back to the username.
        try {
            $st = $this->pdo->prepare("
                SELECT u.id, u.username, u.role, e.first_name, e.last_name, e.email
                FROM users AS u
                LEFT JOIN employees AS e ON e.id = u.employee_id
                WHERE u.id IN ($in)
            ");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $full = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                $found[(int)$u['id']] = [
                    'id'    => (int)$u['id'],
                    'name'  => $full !== '' ? $full : $u['username'],
                    'email' => $u['email'] ?? null,
                    'role'  => $u['role'] ?: 'hr',
                ];
            }
        } catch (Throwable $e) {
            error_log('LeaveAuditLog::lookupReviewers: ' . $e->getMessage());
        }

        return $found;
    }

    private function event(
        int $requestId,
        ?array $req,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        array $actor,
        ?string $remarks,
        ?string $occurredAt,
        bool $recorded,
        int $logId = 0,
        ?int $sortTs = null
    ): array {
        return [
            'log_id'         => $logId,
            'request_id'     => $requestId,
            'leave_name'     => $req['leave_name'] ?? 'Deleted request',
            'start_date'     => $req['start_date'] ?? null,
            'end_date'       => $req['end_date'] ?? null,
            'number_of_days' => $req['number_of_days'] ?? null,
            'current_status' => $req['status'] ?? null,
            'action'         => $action,
            'old_status'     => $oldStatus,
            'new_status'     => $newStatus,
            'actor_id'       => $actor['id'] ?? null,
            'actor_name'     => $actor['name'] ?? null,
            'actor_email'    => $actor['email'] ?? null,
            'actor_role'     => $actor['role'] ?? null,
            'remarks'        => $remarks,
            'occurred_at'    => $occurredAt,
            'sort_ts'        => $sortTs ?? $this->ts($occurredAt),
            'recorded'       => $recorded,
        ];
    }

    private function ts(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $t = strtotime($value);
        return $t === false ? 0 : $t;
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
