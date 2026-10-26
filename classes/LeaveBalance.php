<?php

class LeaveBalance
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function getForEmployee(int $employeeId, ?int $year = null): array {
        $year = $year ?? (int)date('Y');
        $sql = "SELECT lb.id, lb.employee_id, lb.leave_type_id, lb.year,
                       lb.allocated_days, lb.used_days, lb.remaining_days,
                       lt.leave_name
                FROM leave_balances lb
                JOIN leave_types lt ON lt.id = lb.leave_type_id
                WHERE lb.employee_id = :emp AND lb.year = :yr
                ORDER BY lt.leave_name ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute(['emp' => $employeeId, 'yr' => $year]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    
    public function upsertAllocated(int $employeeId, int $leaveTypeId, int $year, float $allocated): bool {
        $sql = "
        INSERT INTO leave_balances (employee_id, leave_type_id, year, allocated_days, used_days, remaining_days)
        VALUES (:emp, :type, :yr, :alloc, 0, :alloc)
        ON DUPLICATE KEY UPDATE
            remaining_days = GREATEST(VALUES(allocated_days) - used_days, 0),
            allocated_days = VALUES(allocated_days),
            updated_at = NOW()
        ";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            'emp' => $employeeId,
            'type'=> $leaveTypeId,
            'yr'  => $year,
            'alloc' => $allocated
        ]);
    }

    
    public function consume(int $employeeId, int $leaveTypeId, int $year, float $days): bool {
        $sql = "
        UPDATE leave_balances
        -- MySQL evaluates SET left to right, so remaining_days must be
        -- computed before used_days changes (Postgres used the old values).
        SET remaining_days = GREATEST(allocated_days - (used_days + :d), 0),
            used_days = used_days + :d,
            updated_at = NOW()
        WHERE employee_id = :emp AND leave_type_id = :type AND year = :yr
        ";
        $st = $this->pdo->prepare($sql);
        return $st->execute(['d'=>$days, 'emp'=>$employeeId, 'type'=>$leaveTypeId, 'yr'=>$year]);
    }

    public function ensureRow(int $employeeId, int $leaveTypeId, int $year, float $defaultAllocated = 0): bool {
        
        $sql = "
        INSERT INTO leave_balances (employee_id, leave_type_id, year, allocated_days, used_days, remaining_days)
        VALUES (:emp, :type, :yr, :alloc, 0, :alloc)
        ON DUPLICATE KEY UPDATE id = id
        ";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            'emp'=>$employeeId, 'type'=>$leaveTypeId, 'yr'=>$year, 'alloc'=>$defaultAllocated
        ]);
    }

    public function hasSufficient(int $employeeId, int $leaveTypeId, int $year, float $needed): bool {
        $sql = "SELECT remaining_days FROM leave_balances
                WHERE employee_id = :emp AND leave_type_id = :type AND year = :yr
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute(['emp'=>$employeeId, 'type'=>$leaveTypeId, 'yr'=>$year]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return (float)$row['remaining_days'] >= $needed;
    }
}