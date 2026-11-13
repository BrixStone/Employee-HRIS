<?php
// classes/User.php
class User
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(): array
    {
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

    public function findByUsername(string $username): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
        $st->execute(['u' => $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): bool
    {
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

    public function deactivate(int $id): bool
    {
        $st = $this->pdo->prepare("UPDATE users SET status='Inactive', updated_at=NOW() WHERE id=:id");
        return $st->execute(['id' => $id]);
    }

    public function activate(int $id): bool
    {
        $st = $this->pdo->prepare("UPDATE users SET status='Active', updated_at=NOW() WHERE id=:id");
        return $st->execute(['id' => $id]);
    }

    // Set the status ('Active' / 'Inactive') of every login linked to an
    // employee record. Returns the number of accounts matched.
    public function setStatusForEmployee(int $employeeId, string $status): int
    {
        $st = $this->pdo->prepare("UPDATE users SET status=:s, updated_at=NOW() WHERE employee_id=:emp");
        $st->execute(['s' => $status, 'emp' => $employeeId]);
        return $st->rowCount();
    }

    public function resetPassword(int $id, string $newHash): bool
    {
        $st = $this->pdo->prepare("UPDATE users SET password=:p, updated_at=NOW() WHERE id=:id");
        return $st->execute(['p' => $newHash, 'id' => $id]);
    }

    // Employees who don't have accounts yet (for the Create User form)
    public function getEmployeesWithoutUsers(): array
    {
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

    // Create a new account awaiting password setup.
    public function createPendingAccount(array $data): int
    {
        $temporaryHash = password_hash(
            bin2hex(random_bytes(32)),
            PASSWORD_DEFAULT
        );

        $sql = "INSERT INTO users
                (employee_id, username, password, role, status, setup_completed)
                VALUES (:emp, :u, :p, :r, :s, 0)";

        $st = $this->pdo->prepare($sql);

        $st->execute([
            'emp' => $data['employee_id'] ?? null,
            'u'   => $data['username'],
            'p'   => $temporaryHash,
            'r'   => $data['role'],
            's'   => $data['status'] ?? 'Active',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // Generate a secure, one-time password setup token.
    public function createSetupToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $this->pdo->beginTransaction();

        try {
            // Invalidate previous unused invitations.
            $st = $this->pdo->prepare(
                "UPDATE password_setup_tokens
                 SET used_at = NOW()
                 WHERE user_id = :id
                 AND used_at IS NULL"
            );

            $st->execute(['id' => $userId]);

            $st = $this->pdo->prepare(
                "INSERT INTO password_setup_tokens
                 (user_id, token_hash, expires_at)
                 VALUES (:id, :hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
            );

            $st->execute([
                'id' => $userId,
                'hash' => $tokenHash
            ]);

            $this->pdo->commit();

            return $token;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }


    public function deleteExpiredPendingAccounts(): int
    {
        $sql = "
        DELETE u
        FROM users u
        INNER JOIN password_setup_tokens t
            ON t.user_id = u.id
        WHERE u.setup_completed = 0
          AND u.status = 'Active'
          AND t.used_at IS NULL
          AND t.expires_at <= NOW()
    ";

        $st = $this->pdo->prepare($sql);
        $st->execute();

        return $st->rowCount();
    }


    // Find a valid invitation.
    public function findValidSetupToken(string $token): ?array
    {
        $hash = hash('sha256', $token);

        $st = $this->pdo->prepare(
            "SELECT
                t.id AS token_id,
                t.user_id,
                u.username
             FROM password_setup_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash
             AND t.used_at IS NULL
             AND t.expires_at > NOW()
             AND u.setup_completed = 0
             LIMIT 1"
        );

        $st->execute(['hash' => $hash]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Complete account activation and save the password.
    public function completeSetup(
        int $tokenId,
        int $userId,
        string $passwordHash
    ): bool {
        try {
            $this->pdo->beginTransaction();

            $st = $this->pdo->prepare(
                "SELECT id
                 FROM password_setup_tokens
                 WHERE id = :token_id
                 AND user_id = :user_id
                 AND used_at IS NULL
                 AND expires_at > NOW()
                 FOR UPDATE"
            );

            $st->execute([
                'token_id' => $tokenId,
                'user_id' => $userId
            ]);

            if (!$st->fetch()) {
                $this->pdo->rollBack();
                return false;
            }

            $st = $this->pdo->prepare(
                "UPDATE users
                 SET password = :password,
                     setup_completed = 1,
                     updated_at = NOW()
                 WHERE id = :id
                 AND setup_completed = 0"
            );

            $st->execute([
                'password' => $passwordHash,
                'id' => $userId
            ]);

            if ($st->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $st = $this->pdo->prepare(
                "UPDATE password_setup_tokens
                 SET used_at = NOW()
                 WHERE id = :id"
            );

            $st->execute(['id' => $tokenId]);

            $this->pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
