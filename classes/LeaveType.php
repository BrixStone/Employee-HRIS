<?php

class LeaveType
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function getAll(): array {
        $sql = "SELECT id, leave_name, description, default_days, status, created_at
                FROM leave_types
                ORDER BY leave_name ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActive(): array {
        $sql = "SELECT id, leave_name, default_days
                FROM leave_types
                WHERE status = 'Active'
                ORDER BY leave_name ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): bool {
        $sql = "INSERT INTO leave_types (leave_name, description, default_days, status)
                VALUES (:leave_name, :description, :default_days, :status)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            'leave_name'   => trim($data['leave_name']),
            'description'  => ($data['description'] ?? '') !== '' ? $data['description'] : null,
            'default_days' => (int)($data['default_days'] ?? 0),
            'status'       => $data['status'] ?? 'Active',
        ]);
    }
}