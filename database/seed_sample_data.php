<?php

/**
 * HRIS SAMPLE DATA SEEDER
 * Database: hris
 * Attendance: September - October 2026
 * Payroll: September - October 2026
 *
 * Run:
 * C:\xampp\php\php.exe database\seed_sample_data.php
 */

// Command line only. This script resets the sample accounts' passwords (hr,
// admin, ...), so it must never be runnable from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Manila');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'hris';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

$pdo->exec("SET time_zone = '+08:00'");

try {

    $pdo->beginTransaction();

    // =========================================
    // PART 1: EMPLOYEE INFORMATION
    // =========================================

    $employees = [
        [
            'employee_id' => 'EMP-001',
            'first_name' => 'Brandon',
            'middle_name' => 'Romero',
            'last_name' => 'Babatuan',
            'dob' => '1998-04-15',
            'gender' => 'Male',
            'phone' => '09171234501',
            'email' => 'brandon@example.com',
            'address' => 'Pulpogan 1, Consolacion, Cebu',
            'department' => 2,
            'position' => 4,
            'hired' => '2023-06-01',
            'salary' => 28000,
            'allowance' => 2000,
            'username' => 'brandon',
            'password' => 'brandon123'
        ],
        [
            'employee_id' => 'EMP-002',
            'first_name' => 'Kaiser',
            'middle_name' => 'James',
            'last_name' => 'Mendoza',
            'dob' => '1997-08-20',
            'gender' => 'Male',
            'phone' => '09171234502',
            'email' => 'kaiser@example.com',
            'address' => 'Lapu-Lapu City, Cebu',
            'department' => 2,
            'position' => 2,
            'hired' => '2024-03-15',
            'salary' => 30000,
            'allowance' => 2000,
            'username' => 'kaiser',
            'password' => 'kaiser123'
        ],
        [
            'employee_id' => 'EMP-003',
            'first_name' => 'Donald',
            'middle_name' => 'Ramos',
            'last_name' => 'Reyes',
            'dob' => '1995-11-10',
            'gender' => 'Male',
            'phone' => '09171234503',
            'email' => 'donald@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2022-09-01',
            'salary' => 32000,
            'allowance' => 2500,
            'username' => 'donald',
            'password' => 'donald123'
        ],

        // =========================================
        // 10 HR EMPLOYEES - DEPARTMENT ID 1
        // =========================================
        [
            'employee_id' => 'EMP-004',
            'first_name' => 'Joshua',
            'middle_name' => 'Miguel',
            'last_name' => 'Santos',
            'dob' => '1999-02-12',
            'gender' => 'Male',
            'phone' => '09171234504',
            'email' => 'joshua@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 25000,
            'allowance' => 1500,
            'username' => 'joshua',
            'password' => 'joshua123'
        ],
        [
            'employee_id' => 'EMP-005',
            'first_name' => 'Angela',
            'middle_name' => 'Marie',
            'last_name' => 'Garcia',
            'dob' => '2000-05-18',
            'gender' => 'Female',
            'phone' => '09171234505',
            'email' => 'angela@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 28000,
            'allowance' => 2000,
            'username' => 'angela',
            'password' => 'angela123'
        ],
        [
            'employee_id' => 'EMP-006',
            'first_name' => 'Kevin',
            'middle_name' => 'Paul',
            'last_name' => 'Rodriguez',
            'dob' => '1998-09-22',
            'gender' => 'Male',
            'phone' => '09171234506',
            'email' => 'kevin@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 26000,
            'allowance' => 1500,
            'username' => 'kevin',
            'password' => 'kevin123'
        ],
        [
            'employee_id' => 'EMP-007',
            'first_name' => 'Sophia',
            'middle_name' => 'Anne',
            'last_name' => 'Reyes',
            'dob' => '2001-01-30',
            'gender' => 'Female',
            'phone' => '09171234507',
            'email' => 'sophia@example.com',
            'address' => 'Consolacion, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 29000,
            'allowance' => 2000,
            'username' => 'sophia',
            'password' => 'sophia123'
        ],
        [
            'employee_id' => 'EMP-008',
            'first_name' => 'Daniel',
            'middle_name' => 'Joseph',
            'last_name' => 'Cruz',
            'dob' => '1997-07-14',
            'gender' => 'Male',
            'phone' => '09171234508',
            'email' => 'daniel@example.com',
            'address' => 'Talisay City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 35000,
            'allowance' => 2500,
            'username' => 'daniel',
            'password' => 'daniel123'
        ],
        [
            'employee_id' => 'EMP-009',
            'first_name' => 'Michelle',
            'middle_name' => 'Grace',
            'last_name' => 'Flores',
            'dob' => '1999-12-05',
            'gender' => 'Female',
            'phone' => '09171234509',
            'email' => 'michelle@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 27000,
            'allowance' => 1800,
            'username' => 'michelle',
            'password' => 'michelle123'
        ],
        [
            'employee_id' => 'EMP-010',
            'first_name' => 'Patrick',
            'middle_name' => 'Lee',
            'last_name' => 'Torres',
            'dob' => '1996-03-25',
            'gender' => 'Male',
            'phone' => '09171234510',
            'email' => 'patrick@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 32000,
            'allowance' => 2500,
            'username' => 'patrick',
            'password' => 'patrick123'
        ],
        [
            'employee_id' => 'EMP-011',
            'first_name' => 'Isabella',
            'middle_name' => 'Rose',
            'last_name' => 'Mendoza',
            'dob' => '2000-10-11',
            'gender' => 'Female',
            'phone' => '09171234511',
            'email' => 'isabella@example.com',
            'address' => 'Lapu-Lapu City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 28500,
            'allowance' => 2000,
            'username' => 'isabella',
            'password' => 'isabella123'
        ],
        [
            'employee_id' => 'EMP-012',
            'first_name' => 'Nathan',
            'middle_name' => 'James',
            'last_name' => 'Villanueva',
            'dob' => '1998-06-19',
            'gender' => 'Male',
            'phone' => '09171234512',
            'email' => 'nathan@example.com',
            'address' => 'Consolacion, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 25500,
            'allowance' => 1500,
            'username' => 'nathan',
            'password' => 'nathan123'
        ],
        [
            'employee_id' => 'EMP-013',
            'first_name' => 'Camille',
            'middle_name' => 'Joy',
            'last_name' => 'Aquino',
            'dob' => '2001-04-08',
            'gender' => 'Female',
            'phone' => '09171234513',
            'email' => 'camille@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 1,
            'position' => 1,
            'hired' => '2025-01-06',
            'salary' => 26500,
            'allowance' => 1800,
            'username' => 'camille',
            'password' => 'camille123'
        ],

        // =========================================
        // 10 IT EMPLOYEES - DEPARTMENT ID 2
        // =========================================
        [
            'employee_id' => 'EMP-014',
            'first_name' => 'Mark',
            'middle_name' => 'Anthony',
            'last_name' => 'Bautista',
            'dob' => '1997-02-14',
            'gender' => 'Male',
            'phone' => '09171234514',
            'email' => 'mark@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 2,
            'position' => 2,
            'hired' => '2025-01-06',
            'salary' => 35000,
            'allowance' => 2500,
            'username' => 'mark',
            'password' => 'mark123'
        ],
        [
            'employee_id' => 'EMP-015',
            'first_name' => 'Christine',
            'middle_name' => 'Mae',
            'last_name' => 'Dizon',
            'dob' => '1999-08-21',
            'gender' => 'Female',
            'phone' => '09171234515',
            'email' => 'christine@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 2,
            'position' => 2,
            'hired' => '2025-01-06',
            'salary' => 32000,
            'allowance' => 2000,
            'username' => 'christine',
            'password' => 'christine123'
        ],
        [
            'employee_id' => 'EMP-016',
            'first_name' => 'Ryan',
            'middle_name' => 'Patrick',
            'last_name' => 'Lopez',
            'dob' => '1996-11-03',
            'gender' => 'Male',
            'phone' => '09171234516',
            'email' => 'ryan@example.com',
            'address' => 'Lapu-Lapu City, Cebu',
            'department' => 2,
            'position' => 4,
            'hired' => '2025-01-06',
            'salary' => 28000,
            'allowance' => 1800,
            'username' => 'ryan',
            'password' => 'ryan123'
        ],
        [
            'employee_id' => 'EMP-017',
            'first_name' => 'Andrea',
            'middle_name' => 'Nicole',
            'last_name' => 'Castro',
            'dob' => '2000-03-17',
            'gender' => 'Female',
            'phone' => '09171234517',
            'email' => 'andrea@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 2,
            'position' => 2,
            'hired' => '2025-01-06',
            'salary' => 31000,
            'allowance' => 2000,
            'username' => 'andrea',
            'password' => 'andrea123'
        ],
        [
            'employee_id' => 'EMP-018',
            'first_name' => 'Michael',
            'middle_name' => 'John',
            'last_name' => 'Ramos',
            'dob' => '1995-06-25',
            'gender' => 'Male',
            'phone' => '09171234518',
            'email' => 'michael@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 2,
            'position' => 5,
            'hired' => '2025-01-06',
            'salary' => 40000,
            'allowance' => 3000,
            'username' => 'michael',
            'password' => 'michael123'
        ],
        [
            'employee_id' => 'EMP-019',
            'first_name' => 'Patricia',
            'middle_name' => 'Louise',
            'last_name' => 'Gonzales',
            'dob' => '1998-12-09',
            'gender' => 'Female',
            'phone' => '09171234519',
            'email' => 'patricia@example.com',
            'address' => 'Talisay City, Cebu',
            'department' => 2,
            'position' => 4,
            'hired' => '2025-01-06',
            'salary' => 29000,
            'allowance' => 1800,
            'username' => 'patricia',
            'password' => 'patricia123'
        ],
        [
            'employee_id' => 'EMP-020',
            'first_name' => 'Jerome',
            'middle_name' => 'David',
            'last_name' => 'Fernandez',
            'dob' => '1997-04-13',
            'gender' => 'Male',
            'phone' => '09171234520',
            'email' => 'jerome@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 2,
            'position' => 2,
            'hired' => '2025-01-06',
            'salary' => 33000,
            'allowance' => 2200,
            'username' => 'jerome',
            'password' => 'jerome123'
        ],
        [
            'employee_id' => 'EMP-021',
            'first_name' => 'Nicole',
            'middle_name' => 'Faith',
            'last_name' => 'Martinez',
            'dob' => '2001-07-19',
            'gender' => 'Female',
            'phone' => '09171234521',
            'email' => 'nicole@example.com',
            'address' => 'Consolacion, Cebu',
            'department' => 2,
            'position' => 4,
            'hired' => '2025-01-06',
            'salary' => 27500,
            'allowance' => 1800,
            'username' => 'nicole',
            'password' => 'nicole123'
        ],
        [
            'employee_id' => 'EMP-022',
            'first_name' => 'Francis',
            'middle_name' => 'Carlo',
            'last_name' => 'Perez',
            'dob' => '1996-01-28',
            'gender' => 'Male',
            'phone' => '09171234522',
            'email' => 'francis@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 2,
            'position' => 5,
            'hired' => '2025-01-06',
            'salary' => 38000,
            'allowance' => 2800,
            'username' => 'francis',
            'password' => 'francis123'
        ],
        [
            'employee_id' => 'EMP-023',
            'first_name' => 'Jasmine',
            'middle_name' => 'Claire',
            'last_name' => 'Rivera',
            'dob' => '2000-09-15',
            'gender' => 'Female',
            'phone' => '09171234523',
            'email' => 'jasmine@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 2,
            'position' => 2,
            'hired' => '2025-01-06',
            'salary' => 30500,
            'allowance' => 2000,
            'username' => 'jasmine',
            'password' => 'jasmine123'
        ],

        // =========================================
        // 10 FINANCE EMPLOYEES - DEPARTMENT ID 3
        // =========================================
        [
            'employee_id' => 'EMP-024',
            'first_name' => 'Robert',
            'middle_name' => 'James',
            'last_name' => 'Navarro',
            'dob' => '1995-05-12',
            'gender' => 'Male',
            'phone' => '09171234524',
            'email' => 'robert@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 35000,
            'allowance' => 2500,
            'username' => 'robert',
            'password' => 'robert123'
        ],
        [
            'employee_id' => 'EMP-025',
            'first_name' => 'Maria',
            'middle_name' => 'Elena',
            'last_name' => 'Delos Santos',
            'dob' => '1998-10-20',
            'gender' => 'Female',
            'phone' => '09171234525',
            'email' => 'maria@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 30000,
            'allowance' => 2000,
            'username' => 'maria',
            'password' => 'maria123'
        ],
        [
            'employee_id' => 'EMP-026',
            'first_name' => 'Anthony',
            'middle_name' => 'Mark',
            'last_name' => 'Salazar',
            'dob' => '1997-03-08',
            'gender' => 'Male',
            'phone' => '09171234526',
            'email' => 'anthony@example.com',
            'address' => 'Lapu-Lapu City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 32000,
            'allowance' => 2200,
            'username' => 'anthony',
            'password' => 'anthony123'
        ],
        [
            'employee_id' => 'EMP-027',
            'first_name' => 'Beatrice',
            'middle_name' => 'Ann',
            'last_name' => 'Lim',
            'dob' => '1999-06-16',
            'gender' => 'Female',
            'phone' => '09171234527',
            'email' => 'beatrice@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 29000,
            'allowance' => 1800,
            'username' => 'beatrice',
            'password' => 'beatrice123'
        ],
        [
            'employee_id' => 'EMP-028',
            'first_name' => 'Christopher',
            'middle_name' => 'Paul',
            'last_name' => 'Tan',
            'dob' => '1994-12-02',
            'gender' => 'Male',
            'phone' => '09171234528',
            'email' => 'christopher@example.com',
            'address' => 'Talisay City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 38000,
            'allowance' => 2800,
            'username' => 'christopher',
            'password' => 'christopher123'
        ],
        [
            'employee_id' => 'EMP-029',
            'first_name' => 'Denise',
            'middle_name' => 'Marie',
            'last_name' => 'Sy',
            'dob' => '2000-02-24',
            'gender' => 'Female',
            'phone' => '09171234529',
            'email' => 'denise@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 28500,
            'allowance' => 1800,
            'username' => 'denise',
            'password' => 'denise123'
        ],
        [
            'employee_id' => 'EMP-030',
            'first_name' => 'Gabriel',
            'middle_name' => 'Luis',
            'last_name' => 'Ocampo',
            'dob' => '1996-08-11',
            'gender' => 'Male',
            'phone' => '09171234530',
            'email' => 'gabriel@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 33000,
            'allowance' => 2300,
            'username' => 'gabriel',
            'password' => 'gabriel123'
        ],
        [
            'employee_id' => 'EMP-031',
            'first_name' => 'Hannah',
            'middle_name' => 'Joy',
            'last_name' => 'Chua',
            'dob' => '2001-05-07',
            'gender' => 'Female',
            'phone' => '09171234531',
            'email' => 'hannah@example.com',
            'address' => 'Consolacion, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 27500,
            'allowance' => 1700,
            'username' => 'hannah',
            'password' => 'hannah123'
        ],
        [
            'employee_id' => 'EMP-032',
            'first_name' => 'Vincent',
            'middle_name' => 'Eric',
            'last_name' => 'Alvarez',
            'dob' => '1995-09-29',
            'gender' => 'Male',
            'phone' => '09171234532',
            'email' => 'vincent@example.com',
            'address' => 'Cebu City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 36000,
            'allowance' => 2500,
            'username' => 'vincent',
            'password' => 'vincent123'
        ],
        [
            'employee_id' => 'EMP-033',
            'first_name' => 'Rachel',
            'middle_name' => 'Anne',
            'last_name' => 'Domingo',
            'dob' => '1999-11-18',
            'gender' => 'Female',
            'phone' => '09171234533',
            'email' => 'rachel@example.com',
            'address' => 'Lapu-Lapu City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2025-01-06',
            'salary' => 29500,
            'allowance' => 1900,
            'username' => 'rachel',
            'password' => 'rachel123'
        ]

    ];

    $employeeIds = [];

    // =========================================
    // INSERT OR UPDATE EMPLOYEES
    // =========================================

    foreach ($employees as $e) {

        // Match Brandon and existing Kai by username first.
        $stmt = $pdo->prepare("
            SELECT employee_id
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([
            $e['username'] === 'kaiser' ? 'kai' : $e['username']
        ]);

        $existingUser = $stmt->fetch();

        $id = null;

        if ($existingUser && $existingUser['employee_id']) {
            $id = (int)$existingUser['employee_id'];
        }

        // Otherwise match employee number.
        if (!$id) {
            $stmt = $pdo->prepare("
                SELECT id FROM employees
                WHERE employee_id = ?
                LIMIT 1
            ");
            $stmt->execute([$e['employee_id']]);
            $id = $stmt->fetchColumn();
        }

        if ($id) {

            $stmt = $pdo->prepare("
                UPDATE employees SET
                    employee_id = ?,
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    date_of_birth = ?,
                    gender = ?,
                    phone = ?,
                    email = ?,
                    address = ?,
                    department_id = ?,
                    position_id = ?,
                    date_hired = ?,
                    employment_type = 'Full-time',
                    employment_status = 'Active'
                WHERE id = ?
            ");

            $stmt->execute([
                $e['employee_id'],
                $e['first_name'],
                $e['middle_name'],
                $e['last_name'],
                $e['dob'],
                $e['gender'],
                $e['phone'],
                $e['email'],
                $e['address'],
                $e['department'],
                $e['position'],
                $e['hired'],
                $id
            ]);
        } else {

            $stmt = $pdo->prepare("
                INSERT INTO employees (
                    employee_id, first_name, middle_name,
                    last_name, date_of_birth, gender,
                    phone, email, address,
                    department_id, position_id,
                    date_hired, employment_type,
                    employment_status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'Full-time', 'Active'
                )
            ");

            $stmt->execute([
                $e['employee_id'],
                $e['first_name'],
                $e['middle_name'],
                $e['last_name'],
                $e['dob'],
                $e['gender'],
                $e['phone'],
                $e['email'],
                $e['address'],
                $e['department'],
                $e['position'],
                $e['hired']
            ]);

            $id = (int)$pdo->lastInsertId();
        }

        $employeeIds[$e['username']] = (int)$id;
    }


    // =========================================
    // PART 2: EMPLOYEE LOGIN ACCOUNTS
    // =========================================


    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        $hash = password_hash(
            $e['password'],
            PASSWORD_DEFAULT
        );

        // Find existing employee account.
        $oldUsername = $e['username'] === 'kaiser'
            ? 'kai'
            : $e['username'];

        $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE username IN (?, ?)
           OR employee_id = ?
        ORDER BY
            CASE
                WHEN username = ? THEN 0
                WHEN username = ? THEN 1
                ELSE 2
            END
        LIMIT 1
    ");

        $stmt->execute([
            $e['username'],
            $oldUsername,
            $id,
            $e['username'],
            $oldUsername
        ]);

        $existing = $stmt->fetch();

        if ($existing) {

            $stmt = $pdo->prepare("
            UPDATE users SET
                employee_id = ?,
                username = ?,
                password = ?,
                role = 'employee',
                status = 'Active',
                setup_completed = 1
            WHERE id = ?
        ");

            $stmt->execute([
                $id,
                $e['username'],
                $hash,
                $existing['id']
            ]);
        } else {

            $stmt = $pdo->prepare("
            INSERT INTO users (
                employee_id,
                username,
                password,
                role,
                status,
                setup_completed
            ) VALUES (?, ?, ?, 'employee', 'Active', 1)
        ");

            $stmt->execute([
                $id,
                $e['username'],
                $hash
            ]);
        }
    }


    // =========================================
    // HR AND ADMIN LOGIN ACCOUNTS
    // =========================================

    $accounts = [
        [
            'username' => 'hr',
            'password' => 'hr123',
            'role' => 'hr'
        ],
        [
            'username' => 'admin',
            'password' => 'admin123',
            'role' => 'admin'
        ]
    ];

    foreach ($accounts as $account) {

        $hash = password_hash(
            $account['password'],
            PASSWORD_DEFAULT
        );

        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([$account['username']]);

        $existing = $stmt->fetch();

        if ($existing) {

            $stmt = $pdo->prepare("
    UPDATE users SET
        password = ?,
        role = ?,
        status = 'Active',
        setup_completed = 1
    WHERE id = ?
");

            $stmt->execute([
                $hash,
                $account['role'],
                $existing['id']
            ]);
        } else {

            $stmt = $pdo->prepare("
    INSERT INTO users (
        employee_id,
        username,
        password,
        role,
        status,
        setup_completed
    ) VALUES (NULL, ?, ?, ?, 'Active', 1)
");

            $stmt->execute([
                $account['username'],
                $hash,
                $account['role']
            ]);
        }
    }

    // =========================================
    // LEAVE BALANCES
    // =========================================

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        // Legacy leave balance.
        $stmt = $pdo->prepare("
            SELECT id FROM `leave`
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$e['email']]);

        if ($stmt->fetch()) {

            $stmt = $pdo->prepare("
                UPDATE `leave`
                SET annual_leave = 12,
                    sick_leave = 6,
                    personal_leave = 3
                WHERE email = ?
            ");

            $stmt->execute([$e['email']]);
        } else {

            $stmt = $pdo->prepare("
                INSERT INTO `leave` (
                    email,
                    annual_leave,
                    sick_leave,
                    personal_leave
                ) VALUES (?, 12, 6, 3)
            ");

            $stmt->execute([$e['email']]);
        }

        // Leave balances per type.
        $leaveTypes = [
            1 => 12,
            2 => 6,
            3 => 3
        ];

        foreach ($leaveTypes as $typeId => $days) {

            $stmt = $pdo->prepare("
                INSERT INTO leave_balances (
                    employee_id,
                    leave_type_id,
                    year,
                    allocated_days,
                    used_days,
                    remaining_days
                ) VALUES (?, ?, 2026, ?, 0, ?)

                ON DUPLICATE KEY UPDATE
                    allocated_days = VALUES(allocated_days),
                    remaining_days =
                        VALUES(allocated_days) - used_days
            ");

            $stmt->execute([
                $id,
                $typeId,
                $days,
                $days
            ]);
        }
    }


    // =========================================
    // PART 3: GENERATE ATTENDANCE
    // SEPTEMBER - OCTOBER 2026
    // =========================================

    $attendanceCount = 0;

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        $startDate = new DateTime('2026-09-01');
        $endDate = new DateTime('2026-11-01');

        for (
            $date = clone $startDate;
            $date < $endDate;
            $date->modify('+1 day')
        ) {

            // Skip Saturday and Sunday.
            if ((int)$date->format('N') > 5) {
                continue;
            }

            $day = (int)$date->format('j');

            // Different time-in variations.
            $minutes = [0, 5, 10, 15];
            $lateMinutes = $minutes[($day + $id) % 4];

            // TIME IN
            $timeIn = clone $date;
            $timeIn->setTime(8, $lateMinutes, 0);

            // BREAK
            $breakTime = clone $date;
            $breakTime->setTime(12, 0, 0);

            // TIME OUT
            $timeOut = clone $date;
            $timeOut->setTime(17, $lateMinutes, 0);

            // Check existing attendance.
            $stmt = $pdo->prepare("
                SELECT id FROM attendance
                WHERE employee_id = ?
                AND DATE(time_in) = ?
                LIMIT 1
            ");

            $stmt->execute([
                $id,
                $date->format('Y-m-d')
            ]);

            if ($stmt->fetch()) {
                continue;
            }

            // Insert attendance.
            $stmt = $pdo->prepare("
                INSERT INTO attendance (
                    employee_id,
                    created_at,
                    email,
                    time_in,
                    time_out,
                    workhours,
                    Break_Time
                ) VALUES (?, ?, ?, ?, ?, 8.00, ?)
            ");

            $stmt->execute([
                $id,
                $timeIn->format('Y-m-d H:i:s'),
                $e['email'],
                $timeIn->format('Y-m-d H:i:s'),
                $timeOut->format('Y-m-d H:i:s'),
                $breakTime->format('Y-m-d H:i:s')
            ]);

            $attendanceCount++;
        }
    }

    echo "Part 3 completed: Attendance generated.\n";
    echo "New attendance records: $attendanceCount\n";

    // =========================================
    // VERIFY ATTENDANCE
    // =========================================

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance
            WHERE employee_id = ?
            AND time_in >= '2026-09-01'
            AND time_in < '2026-11-01'
        ");

        $stmt->execute([$id]);

        echo $e['username'] . ': '
            . $stmt->fetchColumn()
            . " attendance records\n";
    }

    // =========================================
    // PART 4: EMPLOYEE SALARIES
    // =========================================

    $salaries = [];

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        // Find existing active salary.
        $stmt = $pdo->prepare("
            SELECT id
            FROM employee_salaries
            WHERE employee_id = ?
            AND status = 'Active'
            ORDER BY effective_date DESC, id DESC
            LIMIT 1
        ");

        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if ($existing) {

            $stmt = $pdo->prepare("
                UPDATE employee_salaries SET
                    pay_type = 'Monthly',
                    basic_salary = ?,
                    allowance = ?,
                    effective_date = '2026-09-01'
                WHERE id = ?
            ");

            $stmt->execute([
                $e['salary'],
                $e['allowance'],
                $existing['id']
            ]);
        } else {

            $stmt = $pdo->prepare("
                INSERT INTO employee_salaries (
                    employee_id,
                    pay_type,
                    basic_salary,
                    allowance,
                    effective_date,
                    status
                ) VALUES (
                    ?, 'Monthly', ?, ?,
                    '2026-09-01', 'Active'
                )
            ");

            $stmt->execute([
                $id,
                $e['salary'],
                $e['allowance']
            ]);
        }

        $salaries[$e['username']] = [
            'basic' => $e['salary'],
            'allowance' => $e['allowance']
        ];
    }

    echo "Part 4A completed: Employee salaries prepared.\n";

    // =========================================
    // PAYROLL PERIODS
    // =========================================

    // Get admin user ID.
    $stmt = $pdo->prepare("
        SELECT id FROM users
        WHERE username = 'admin'
        LIMIT 1
    ");

    $stmt->execute();
    $adminId = $stmt->fetchColumn();

    if (!$adminId) {
        throw new Exception("Admin account not found.");
    }

    $payrollPeriods = [
        [
            'name' => 'September 2026 Payroll',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'pay_date' => '2026-10-05'
        ],
        [
            'name' => 'October 2026 Payroll',
            'start' => '2026-10-01',
            'end' => '2026-10-31',
            'pay_date' => '2026-11-05'
        ]
    ];

    $periodIds = [];

    foreach ($payrollPeriods as $period) {

        $stmt = $pdo->prepare("
            INSERT INTO payroll_periods (
                period_name,
                start_date,
                end_date,
                pay_date,
                status,
                created_by
            ) VALUES (
                ?, ?, ?, ?, 'Processed', ?
            )

            ON DUPLICATE KEY UPDATE
                period_name = VALUES(period_name),
                pay_date = VALUES(pay_date),
                status = 'Processed',
                created_by = VALUES(created_by)
        ");

        $stmt->execute([
            $period['name'],
            $period['start'],
            $period['end'],
            $period['pay_date'],
            $adminId
        ]);

        // Get payroll period ID.
        $stmt = $pdo->prepare("
            SELECT id
            FROM payroll_periods
            WHERE start_date = ?
            AND end_date = ?
            LIMIT 1
        ");

        $stmt->execute([
            $period['start'],
            $period['end']
        ]);

        $periodIds[] = (int)$stmt->fetchColumn();
    }

    // =========================================
    // PART 5: GENERATE PAYSLIPS
    // =========================================

    foreach ($employees as $e) {

        $employeeId = $employeeIds[$e['username']];

        $basic = (float)$e['salary'];
        $allowance = (float)$e['allowance'];

        foreach ($periodIds as $periodIndex => $periodId) {

            // Two months of sample payroll.
            $workingDays = ($periodIndex === 0) ? 22 : 22;
            $daysWorked = $workingDays;

            $absentDays = 0;
            $lateMinutes = 0;
            $overtimeHours = 0;

            $basicPay = $basic;

            $grossPay = $basicPay + $allowance;

            // Sample statutory deductions.
            $sss = round($basicPay * 0.045, 2);
            $philhealth = round($basicPay * 0.025, 2);
            $pagibig = 100;

            $withholdingTax = 0;
            $absenceDeduction = 0;
            $lateDeduction = 0;
            $otherDeductions = 0;

            $totalDeductions =
                $sss +
                $philhealth +
                $pagibig +
                $withholdingTax +
                $absenceDeduction +
                $lateDeduction +
                $otherDeductions;

            $netPay = $grossPay - $totalDeductions;

            // Check for an existing payslip.
            $stmt = $pdo->prepare("
                SELECT id
                FROM payslips
                WHERE payroll_period_id = ?
                AND employee_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $periodId,
                $employeeId
            ]);

            $existing = $stmt->fetch();

            if ($existing) {

                $stmt = $pdo->prepare("
                    UPDATE payslips SET
                        pay_type = 'Monthly',
                        basic_salary = ?,
                        working_days = ?,
                        days_worked = ?,
                        absent_days = ?,
                        late_minutes = ?,
                        ot_hours = ?,
                        basic_pay = ?,
                        allowance = ?,
                        overtime_pay = 0,
                        other_earnings = 0,
                        gross_pay = ?,
                        sss = ?,
                        philhealth = ?,
                        pagibig = ?,
                        withholding_tax = ?,
                        absences_deduction = ?,
                        late_deduction = ?,
                        other_deductions = ?,
                        total_deductions = ?,
                        net_pay = ?,
                        remarks = 'Sample payroll for testing',
                        status = 'Finalized'
                    WHERE id = ?
                ");

                $stmt->execute([
                    $basic,
                    $workingDays,
                    $daysWorked,
                    $absentDays,
                    $lateMinutes,
                    $overtimeHours,
                    $basicPay,
                    $allowance,
                    $grossPay,
                    $sss,
                    $philhealth,
                    $pagibig,
                    $withholdingTax,
                    $absenceDeduction,
                    $lateDeduction,
                    $otherDeductions,
                    $totalDeductions,
                    $netPay,
                    $existing['id']
                ]);
            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO payslips (
                        payroll_period_id,
                        employee_id,
                        pay_type,
                        basic_salary,
                        working_days,
                        days_worked,
                        absent_days,
                        late_minutes,
                        ot_hours,
                        basic_pay,
                        allowance,
                        overtime_pay,
                        other_earnings,
                        gross_pay,
                        sss,
                        philhealth,
                        pagibig,
                        withholding_tax,
                        absences_deduction,
                        late_deduction,
                        other_deductions,
                        total_deductions,
                        net_pay,
                        remarks,
                        status
                    ) VALUES (
                        ?, ?, 'Monthly', ?, ?, ?, ?, ?, ?,
                        ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, 'Sample payroll for testing', 'Finalized'
                    )
                ");

                $stmt->execute([
                    $periodId,
                    $employeeId,
                    $basic,
                    $workingDays,
                    $daysWorked,
                    $absentDays,
                    $lateMinutes,
                    $overtimeHours,
                    $basicPay,
                    $allowance,
                    $grossPay,
                    $sss,
                    $philhealth,
                    $pagibig,
                    $withholdingTax,
                    $absenceDeduction,
                    $lateDeduction,
                    $otherDeductions,
                    $totalDeductions,
                    $netPay
                ]);
            }
        }
    }

    echo "Part 5 completed: Six payslips generated.\n";

    // =========================================
    // FINALIZE TRANSACTION
    // =========================================

    $pdo->commit();

    echo "\n====================================\n";
    echo "HRIS SAMPLE DATA SEEDED SUCCESSFULLY!\n";
    echo "====================================\n";

    echo "\nLOGIN ACCOUNTS:\n";
    echo "HR      : hr / hr123\n";
    echo "ADMIN   : admin / admin123\n";
    echo "BRANDON : brandon / brandon123\n";
    echo "DONALD  : donald / donald123\n";
    echo "KAISER  : kaiser / kaiser123\n";

    echo "\nGENERATED DATA:\n";
    echo "3 employee records\n";
    echo "2 months attendance\n";
    echo "2 payroll periods\n";
    echo "6 payslips\n";
} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "\nSEEDING FAILED!\n";
    echo $e->getMessage() . "\n";

    exit(1);
}

// RBAC tables (role_permissions and employees.manager_id).
// Safe to run on every start: existing permission rows are kept, so changes
// made in phpMyAdmin survive a restart. See database/migrate_rbac.php.
require_once __DIR__ . '/migrate_rbac.php';
