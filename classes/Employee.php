<?php

class Employee
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(): array
    {
        $sql = "
            SELECT
                e.id,
                e.employee_id,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix,
                e.date_of_birth,
                e.gender,
                e.phone,
                e.email,
                e.address,
                e.department_id,
                e.position_id,
                e.date_hired,
                e.employment_type,
                e.employment_status,
                d.name AS department_name,
                p.title AS position_title
            FROM employees e
            LEFT JOIN departments d
                ON e.department_id = d.id
            LEFT JOIN positions p
                ON e.position_id = p.id
            ORDER BY e.last_name ASC, e.first_name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $sql = "
            SELECT
                e.*,
                d.name AS department_name,
                p.title AS position_title
            FROM employees e
            LEFT JOIN departments d
                ON e.department_id = d.id
            LEFT JOIN positions p
                ON e.position_id = p.id
            WHERE e.id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'id' => $id
        ]);

        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        return $employee ?: null;
    }

    public function create(array $data): bool
    {
        $employeeId = $this->generateEmployeeId();

        // manager_id ("reports to") is only written when the caller sends it.
        $hasMgr = array_key_exists('manager_id', $data);
        $mgrCol = $hasMgr ? "manager_id,\n                " : '';
        $mgrVal = $hasMgr ? ":manager_id,\n                " : '';

        $sql = "
            INSERT INTO employees (
                employee_id,
                first_name,
                middle_name,
                last_name,
                suffix,
                date_of_birth,
                gender,
                phone,
                email,
                address,
                department_id,
                position_id,
                {$mgrCol}date_hired,
                employment_type,
                employment_status
            )
            VALUES (
                :employee_id,
                :first_name,
                :middle_name,
                :last_name,
                :suffix,
                :date_of_birth,
                :gender,
                :phone,
                :email,
                :address,
                :department_id,
                :position_id,
                {$mgrVal}:date_hired,
                :employment_type,
                :employment_status
            )
        ";

        $stmt = $this->pdo->prepare($sql);

        $params = [
            'employee_id' => $employeeId,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?: null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?: null,
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'department_id' => $data['department_id'] ?: null,
            'position_id' => $data['position_id'] ?: null,
            'date_hired' => $data['date_hired'],
            'employment_type' => $data['employment_type'],
            'employment_status' => $data['employment_status']
        ];
        if ($hasMgr) {
            $params['manager_id'] = $data['manager_id'] ?: null;
        }

        return $stmt->execute($params);
    }

    /**
     * Employees who can be chosen as someone's manager: those with an active
     * login that has the manager role. $keepId (the current manager) is always
     * included so an edit form never drops it.
     */
    public function getManagerOptions(?int $keepId = null): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.employee_id, e.first_name, e.last_name
            FROM employees e
            WHERE e.id = :keep
               OR e.id IN (
                    SELECT employee_id FROM users
                    WHERE LOWER(role) = 'manager' AND status = 'Active' AND employee_id IS NOT NULL
               )
            ORDER BY e.last_name, e.first_name
        ");
        $stmt->execute(['keep' => $keepId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * May $managerId become the manager of $selfId? (null $selfId = new employee)
     * Nobody can manage themselves; the manager must be a listed manager option
     * or the person's current manager (so an unchanged value always saves).
     */
    public function isManagerCandidate(int $managerId, ?int $selfId, ?int $currentManagerId = null): bool
    {
        if ($selfId !== null && $managerId === $selfId) {
            return false;
        }
        foreach ($this->getManagerOptions($currentManagerId) as $option) {
            if ((int)$option['id'] === $managerId) {
                return true;
            }
        }
        return false;
    }

    public function update(int $id, array $data): bool {

        // manager_id ("reports to") is only written when the caller sends it,
        // so forms that don't know about it never wipe it.
        $hasMgr = array_key_exists('manager_id', $data);
        $mgrSet = $hasMgr ? "manager_id =       :manager_id,\n            " : '';

        $sql = "UPDATE employees
        SET
            employee_id =       :employee_id,
            first_name =        :first_name,
            middle_name =       :middle_name,
            last_name =         :last_name,
            suffix =            :suffix,
            date_of_birth =     :date_of_birth,
            gender =            :gender,
            phone =             :phone,
            email =             :email,
            address =           :address,
            department_id =     :department_id,
            position_id =       :position_id,
            date_hired =        :date_hired,
            employment_type =   :employment_type,
            employment_status = :employment_status,
            {$mgrSet}updated_at =        NOW()
        WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);

        $params = [
            'employee_id'       => $data['employee_id'],
            'first_name'        => $data['first_name'],
            'middle_name'       => $data['middle_name'] ?: null,
            'last_name'         => $data['last_name'],
            'suffix'            => $data['suffix'],
            // An empty date must be NULL: MySQL strict mode rejects '' for a DATE column.
            'date_of_birth'     => $data['date_of_birth'] ?: null,
            'gender'            => $data['gender'],
            'phone'             => $data['phone'],
            'email'             => $data['email'],
            'address'           => $data['address'],
            'department_id'     => $data['department_id'],
            'position_id'        => $data['position_id'],
            'date_hired'        => $data['date_hired'],
            'employment_type'   => $data['employment_type'],
            'employment_status' => $data['employment_status'],
            'id'                => $id
        ];
        if ($hasMgr) {
            $params['manager_id'] = $data['manager_id'] ?: null;
        }

        return $stmt->execute($params);
    }

    public function deactivate(int $id): bool {
        $sql = "
                UPDATE employees
                SET employment_status = 'Inactive', updated_at = NOW()
                WHERE id = :id
                ";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function activate(int $id): bool {
        $sql = "
                UPDATE employees
                SET employment_status = 'Active', updated_at = NOW()
                WHERE id = :id
                ";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function generateEmployeeId(): string {
        $sql = "
            SELECT employee_id
            FROM employees
            WHERE employee_id REGEXP '^EMP-[0-9]+$'
            ORDER BY CAST(SUBSTRING(employee_id, 5) AS UNSIGNED) DESC
            LIMIT 1       
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $lastId = $stmt->fetchColumn();

        $nextNumber = $lastId
            ? (int) substr($lastId, 4) + 1
            : 1;

        return 'EMP-' . str_pad(
            (string) $nextNumber,
            3,
            '0',
            STR_PAD_LEFT
        );
    }
}
