<?php

require_once __DIR__ . '/LeaveAuditLog.php';

class LeaveRequest
{
    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled'];

    // LEFT JOINs keep requests whose leave type or employee row is gone.
    private const SELECT_WITH_NAMES = "
        SELECT lr.id, lr.employee_id, e.employee_id AS employee_no,
               e.first_name, e.middle_name, e.last_name,
               lr.leave_type_id, COALESCE(lt.leave_name, 'Custom / Other') AS leave_name,
               lr.start_date, lr.end_date, lr.number_of_days, lr.reason, lr.status,
               lr.approved_by, lr.approved_at, lr.created_at
        FROM leave_requests AS lr
        LEFT JOIN employees AS e ON e.id = lr.employee_id
        LEFT JOIN leave_types AS lt ON lt.id = lr.leave_type_id
    ";

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function findById(int $id): ?array {
        $st = $this->pdo->prepare(self::SELECT_WITH_NAMES . " WHERE lr.id = :id LIMIT 1");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Newest first, optionally filtered by status and/or employee (employees.id). */
    public function search(?string $status = null, ?int $employeeId = null): array {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'lr.status = :st';
            $params['st'] = $status;
        }
        if ($employeeId !== null) {
            $where[] = 'lr.employee_id = :emp';
            $params['emp'] = $employeeId;
        }
        $sql = self::SELECT_WITH_NAMES
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY lr.created_at DESC, lr.id DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Requests belonging to any of the given employees (employees.id list),
     * newest first. Used for a manager's team. Empty list => no requests.
     *
     * @param int[] $employeeIds
     */
    public function searchForEmployees(array $employeeIds, ?string $status = null): array {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if (!$employeeIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($employeeIds), '?'));
        $params = $employeeIds;
        $sql = self::SELECT_WITH_NAMES . " WHERE lr.employee_id IN ({$in})";
        if ($status !== null) {
            $sql .= ' AND lr.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY lr.created_at DESC, lr.id DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The owner withdraws a Pending request. Returns false when the request
     * is not theirs or is no longer Pending.
     */
    public function cancel(int $requestId, int $employeeId, array $actor = []): bool {
        $st = $this->pdo->prepare("
            UPDATE leave_requests
            SET status = 'Cancelled'
            WHERE id = :id AND employee_id = :emp AND status = 'Pending'
        ");
        $st->execute(['id' => $requestId, 'emp' => $employeeId]);
        if ($st->rowCount() === 0) {
            return false;
        }

        $this->audit()->log(
            $requestId,
            $employeeId,
            LeaveAuditLog::ACTION_CANCELLED,
            'Pending',
            'Cancelled',
            $actor + ['id' => $employeeId, 'role' => 'employee']
        );
        return true;
    }

    public function create(array $data): bool {
        $sql = "INSERT INTO leave_requests
                (employee_id, leave_type_id, start_date, end_date, number_of_days, reason, status)
                VALUES
                (:emp, :type, :start, :end, :days, :reason, 'Pending')";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            'emp'   => $data['employee_id'],
            'type'  => $data['leave_type_id'],
            'start' => $data['start_date'],
            'end'   => $data['end_date'],
            'days'  => $data['number_of_days'],
            'reason'=> $data['reason'] ?? null,
        ]);
    }

    public function getForHR(?string $status = null): array {
        $sql = "SELECT lr.*, e.employee_id AS employee_no,
                       e.first_name, e.middle_name, e.last_name,
                       lt.leave_name
                FROM leave_requests lr
                JOIN employees e ON e.id = lr.employee_id
                JOIN leave_types lt ON lt.id = lr.leave_type_id";
        $params = [];
        if ($status) {
            $sql .= " WHERE lr.status = :st";
            $params['st'] = $status;
        }
        $sql .= " ORDER BY lr.created_at DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getForEmployee(int $employeeId): array {
        $sql = "SELECT lr.*, lt.leave_name
                FROM leave_requests lr
                JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.employee_id = :emp
                ORDER BY lr.created_at DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute(['emp'=>$employeeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    
    /**
     * @param array $actor Reviewer account for the audit trail:
     *                     ['id' => ?int, 'name' => ?string, 'email' => ?string, 'role' => ?string]
     */
    public function approve(int $requestId, ?int $hrUserId = null, array $actor = []): bool {
        try {
            $this->pdo->beginTransaction();

            
            $q = "SELECT * FROM leave_requests WHERE id = :id FOR UPDATE";
            $st = $this->pdo->prepare($q);
            $st->execute(['id'=>$requestId]);
            $lr = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lr) throw new RuntimeException('Request not found');
            if ($lr['status'] !== 'Pending') throw new RuntimeException('Request is not Pending');

            
            $yrStart = (int)date('Y', strtotime($lr['start_date']));
            $yrEnd   = (int)date('Y', strtotime($lr['end_date']));
            if ($yrStart !== $yrEnd) throw new RuntimeException('Leave spanning multiple years not supported in this version.');

            
            $sqlLT = "SELECT default_days FROM leave_types WHERE id = :t";
            $stLT = $this->pdo->prepare($sqlLT);
            $stLT->execute(['t'=>$lr['leave_type_id']]);
            $lt = $stLT->fetch(PDO::FETCH_ASSOC);
            $defaultAlloc = $lt ? (float)$lt['default_days'] : 0.0;

            $lb = new LeaveBalance($this->pdo);
            $lb->ensureRow((int)$lr['employee_id'], (int)$lr['leave_type_id'], $yrStart, $defaultAlloc);

            
            if (!$lb->hasSufficient((int)$lr['employee_id'], (int)$lr['leave_type_id'], $yrStart, (float)$lr['number_of_days'])) {
                throw new RuntimeException('Insufficient leave balance.');
            }

            
            $qa = "UPDATE leave_requests
                   SET status='Approved', approved_by=:by, approved_at=NOW()
                   WHERE id=:id";
            $sta = $this->pdo->prepare($qa);
            $sta->execute(['by'=>$hrUserId, 'id'=>$requestId]);

            
            $lb->consume((int)$lr['employee_id'], (int)$lr['leave_type_id'], $yrStart, (float)$lr['number_of_days']);

            $this->pdo->commit();

            $this->audit()->log(
                $requestId,
                (int)$lr['employee_id'],
                LeaveAuditLog::ACTION_APPROVED,
                'Pending',
                'Approved',
                $actor + ['id' => $hrUserId, 'role' => 'hr']
            );
            return true;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function reject(int $requestId, ?int $hrUserId = null, array $actor = []): bool {
        $sql = "UPDATE leave_requests
                SET status='Rejected', approved_by=:by, approved_at=NOW()
                WHERE id=:id AND status='Pending'";
        $st = $this->pdo->prepare($sql);
        $st->execute(['by'=>$hrUserId, 'id'=>$requestId]);

        if ($st->rowCount() === 0) {
            return false; // not found or no longer Pending
        }

        // MySQL has no UPDATE ... RETURNING, so read the owner separately
        $own = $this->pdo->prepare("SELECT employee_id FROM leave_requests WHERE id = :id");
        $own->execute(['id' => $requestId]);
        $employeeId = $own->fetchColumn();

        $this->audit()->log(
            $requestId,
            (int)$employeeId,
            LeaveAuditLog::ACTION_REJECTED,
            'Pending',
            'Rejected',
            $actor + ['id' => $hrUserId, 'role' => 'hr']
        );
        return true;
    }

    /**
     * Record a reminder that this request is still waiting for HR, and push a
     * realtime "leave.reminder" event to the employee and HR. The request's
     * status is unchanged (it stays Pending). Returns false if the request is
     * not found or no longer Pending.
     *
     * @param array $actor Who/what sent the reminder (usually the scheduler).
     */
    public function remind(int $requestId, array $actor = [], ?string $remarks = null): bool {
        $st = $this->pdo->prepare("SELECT employee_id, status FROM leave_requests WHERE id = :id LIMIT 1");
        $st->execute(['id' => $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['status'] !== 'Pending') {
            return false; // nothing to remind about
        }

        // old == new (Pending) because a reminder does not change the status.
        return $this->audit()->log(
            $requestId,
            (int)$row['employee_id'],
            LeaveAuditLog::ACTION_REMINDER,
            'Pending',
            'Pending',
            $actor + ['role' => 'system'],
            $remarks
        );
    }

    /** How many reminders have already been logged for this request. */
    public function countReminders(int $requestId): int {
        try {
            $st = $this->pdo->prepare("
                SELECT COUNT(*) FROM leave_request_logs
                WHERE leave_request_id = :id AND action = :action
            ");
            $st->execute(['id' => $requestId, 'action' => LeaveAuditLog::ACTION_REMINDER]);
            return (int)$st->fetchColumn();
        } catch (PDOException $e) {
            // Audit table may not exist yet on a fresh install; treat as none.
            return 0;
        }
    }

    private function audit(): LeaveAuditLog {
        return new LeaveAuditLog($this->pdo);
    }
}