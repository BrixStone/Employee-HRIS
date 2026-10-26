<?php

require_once __DIR__ . '/LeaveAuditLog.php';

class LeaveRequest
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

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

    private function audit(): LeaveAuditLog {
        return new LeaveAuditLog($this->pdo);
    }
}