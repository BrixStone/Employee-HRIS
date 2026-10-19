<?php
// classes/User.php
class User
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function getAll(): array {
        $sql = "
            SELECT u.id, u.username, u.role, u.status, u.employee_id,
                   e.employee_id AS employee_no,
                   e.first_name, e.middle_name, e.last_name
            FROM users u
            LEFT JOIN employees e ON e.id = u.employee_id
            ORDER BY u.created_at DESC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByUsername(string $username): ?array {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
        $st->execute(['u' => $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findById(int $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): bool {
        $sql = "INSERT INTO users (employee_id, username, password, role, status)
                VALUES (:emp, :u, :p, :r, :s)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            'emp' => $data['employee_id'] ?? null,      // null OK for pure admin
            'u'   => $data['username'],
            'p'   => $data['password_hash'],            // hash from password_hash()
            'r'   => $data['role'],
            's'   => $data['status'] ?? 'Active',
        ]);
    }

    public function deactivate(int $id): bool {
        $st = $this->pdo->prepare("UPDATE users SET status='Inactive', updated_at=NOW() WHERE id=:id");
        return $st->execute(['id'=>$id]);
    }

    public function activate(int $id): bool {
        $st = $this->pdo->prepare("UPDATE users SET status='Active', updated_at=NOW() WHERE id=:id");
        return $st->execute(['id'=>$id]);
    }

    public function resetPassword(int $id, string $newHash): bool {
        $st = $this->pdo->prepare("UPDATE users SET password=:p, updated_at=NOW() WHERE id=:id");
        return $st->execute(['p'=>$newHash, 'id'=>$id]);
    }

    // Employees who don't have accounts yet (for the Create User form)
    public function getEmployeesWithoutUsers(): array {
    $sql = "
        SELECT
            e.id,
            e.employee_id,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.email
        FROM employees e
        LEFT JOIN users u ON u.employee_id = e.id
        WHERE u.id IS NULL
        ORDER BY e.last_name ASC, e.first_name ASC
    ";

    $st = $this->pdo->prepare($sql);
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC);
}
}