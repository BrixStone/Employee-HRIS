<?php

require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'edit', 'all');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Employee.php';
require __DIR__ . '/../classes/Department.php';
require __DIR__ . '/../classes/Position.php';

$employeeModel   = new Employee($pdo);
$departmentModel = new Department($pdo);
$positionModel   = new Position($pdo);

$departments = $departmentModel->getAll();
$positions   = $positionModel->getAll();

$error = '';

// Prefill values when redirected from Recruitment -> "Onboard in Employees"
$appId        = (int)($_REQUEST['app_id'] ?? 0);
$prefillName  = trim((string)($_GET['full_name'] ?? ''));
$prefillEmail = trim((string)($_GET['email'] ?? ''));
$prefillPhone = trim((string)($_GET['phone'] ?? ''));
$prefillDept  = trim((string)($_GET['department'] ?? ''));
$prefillTitle = trim((string)($_GET['job_title'] ?? ''));

$nameParts = $prefillName !== '' ? (preg_split('/\s+/', $prefillName) ?: []) : [];
$prefillFirst = $nameParts[0] ?? '';
$prefillLast  = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

// Match department_id by name or common abbreviation (e.g., "IT" -> "Information Technology", "HR" -> "Human Resources")
$matchedDeptId = '';
if ($prefillDept !== '') {
    $deptAliases = [
        'it'      => 'information technology',
        'hr'      => 'human resources',
        'fin'     => 'finance',
        'acct'    => 'accounting',
        'ops'     => 'operations',
        'mktg'    => 'marketing',
    ];
    $needle = strtolower($prefillDept);
    $mapped = $deptAliases[$needle] ?? $needle;

    foreach ($departments as $d) {
        $dName = strtolower(trim((string)($d['name'] ?? '')));
        if ($dName === $needle || $dName === $mapped || str_contains($dName, $mapped)) {
            $matchedDeptId = (string)$d['id'];
            break;
        }
    }
}

// Match position_id by title
$matchedPosId = '';
if ($prefillTitle !== '') {
    $pNeedle = strtolower($prefillTitle);
    foreach ($positions as $p) {
        $pTitle = strtolower(trim((string)($p['title'] ?? '')));
        if ($pTitle === $pNeedle || str_contains($pTitle, $pNeedle)) {
            $matchedPosId = (string)$p['id'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'first_name'        => trim($_POST['first_name'] ?? ''),
        'middle_name'       => trim($_POST['middle_name'] ?? ''),
        'last_name'         => trim($_POST['last_name'] ?? ''),
        'suffix'            => trim($_POST['suffix'] ?? ''),
        'date_of_birth'     => $_POST['date_of_birth'] ?? '',
        'gender'            => $_POST['gender'] ?? '',
        'phone'             => trim($_POST['phone'] ?? ''),
        'email'             => trim($_POST['email'] ?? ''),
        'address'           => trim($_POST['address'] ?? ''),
        'department_id'     => $_POST['department_id'] ?? '',
        'position_id'       => $_POST['position_id'] ?? '',
        'rank'              => in_array(
            $_POST['rank'] ?? 'employee',
            ['employee', 'team_leader', 'manager'],
            true
        )
            ? $_POST['rank']
            : 'employee',
        'manager_id'        => ($_POST['manager_id'] ?? '') !== '' ? (int)$_POST['manager_id'] : null,
        'date_hired'        => $_POST['date_hired'] ?? '',
        'employment_type'   => $_POST['employment_type'] ?? 'Full-time',
        'employment_status' => $_POST['employment_status'] ?? 'Active',
    ];

    if (
        $data['first_name'] === '' ||
        $data['last_name'] === '' ||
        $data['date_hired'] === ''
    ) {
        $error = 'Please fill in all required fields.';
    } elseif ($data['manager_id'] !== null && !$employeeModel->isManagerCandidate($data['manager_id'], null)) {
        $error = 'Please choose a valid manager (an active employee with manager rank).';
    } else {
        try {
            $employeeModel->create($data);

            // Sync with Recruitment & Employee Onboarding if this came from Hiring
            $fullName = trim($data['first_name'] . ' ' . $data['last_name']);
            $deptLabel = $prefillDept;
            foreach ($departments as $d) {
                if ((string)$d['id'] === (string)$data['department_id']) {
                    $deptLabel = (string)$d['name'];
                    break;
                }
            }
            $posLabel = $prefillTitle;
            foreach ($positions as $p) {
                if ((string)$p['id'] === (string)$data['position_id']) {
                    $posLabel = (string)$p['title'];
                    break;
                }
            }

            if ($appId > 0) {
                $pdo->prepare("UPDATE `recruitment_applications` SET `onboarded_to_employee` = 1 WHERE `id` = ?")
                    ->execute([$appId]);

                $chkOnb = $pdo->prepare("SELECT `id` FROM `employee_onboarding` WHERE `application_id` = ? LIMIT 1");
                $chkOnb->execute([$appId]);
                $onbId = $chkOnb->fetchColumn();

                if ($onbId) {
                    $pdo->prepare("
                        UPDATE `employee_onboarding`
                        SET `full_name` = ?, `email` = ?, `job_title` = ?, `department` = ?, `step_info` = 1, `status` = 'In Progress'
                        WHERE `id` = ?
                    ")->execute([
                        $fullName,
                        $data['email'],
                        $posLabel !== '' ? $posLabel : 'Employee',
                        $deptLabel !== '' ? $deptLabel : 'General',
                        (int)$onbId,
                    ]);
                } else {
                    $empCode = 'EMP-OB-' . str_pad((string)$appId, 3, '0', STR_PAD_LEFT);
                    $pdo->prepare("
                        INSERT INTO `employee_onboarding`
                            (`application_id`, `employee_code`, `full_name`, `email`, `job_title`, `department`,
                             `step_info`, `step_documents`, `step_orientation`, `step_policy`, `status`)
                        VALUES (?, ?, ?, ?, ?, ?, 1, 0, 0, 0, 'In Progress')
                    ")->execute([
                        $appId,
                        $empCode,
                        $fullName,
                        $data['email'],
                        $posLabel !== '' ? $posLabel : 'Employee',
                        $deptLabel !== '' ? $deptLabel : 'General',
                    ]);
                }
            } elseif ($data['email'] !== '') {
                $chkOnb = $pdo->prepare("SELECT `id` FROM `employee_onboarding` WHERE LOWER(`email`) = LOWER(?) OR LOWER(`full_name`) = LOWER(?) LIMIT 1");
                $chkOnb->execute([$data['email'], $fullName]);
                $onbId = $chkOnb->fetchColumn();
                if ($onbId) {
                    $pdo->prepare("
                        UPDATE `employee_onboarding`
                        SET `full_name` = ?, `email` = ?, `job_title` = ?, `department` = ?, `step_info` = 1
                        WHERE `id` = ?
                    ")->execute([
                        $fullName,
                        $data['email'],
                        $posLabel !== '' ? $posLabel : 'Employee',
                        $deptLabel !== '' ? $deptLabel : 'General',
                        (int)$onbId,
                    ]);
                }
            }

            header('Location: employees.php');
            exit;
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
}

$valFirst      = $_POST['first_name']        ?? $prefillFirst;
$valMiddle     = $_POST['middle_name']       ?? '';
$valLast       = $_POST['last_name']         ?? $prefillLast;
$valSuffix     = $_POST['suffix']            ?? '';
$valDob        = $_POST['date_of_birth']     ?? '';
$valGender     = $_POST['gender']            ?? '';
$valPhone      = $_POST['phone']             ?? $prefillPhone;
$valEmail      = $_POST['email']             ?? $prefillEmail;
$valAddress    = $_POST['address']           ?? '';
$valDeptId     = (string)($_POST['department_id'] ?? $matchedDeptId);
$valPosId      = (string)($_POST['position_id']   ?? $matchedPosId);
$valRank       = $_POST['rank']              ?? 'employee';
$valManagerId  = (string)($_POST['manager_id']    ?? '');
$valDateHired  = $_POST['date_hired']        ?? ($appId > 0 ? date('Y-m-d') : '');
$valEmpType    = $_POST['employment_type']   ?? 'Full-time';
$valEmpStatus  = $_POST['employment_status'] ?? 'Active';

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<h1>Add Employee<?= $appId > 0 ? ' (Onboarding Candidate)' : '' ?></h1>

<?php if ($appId > 0): ?>
    <p style="color: #60a5fa;">
        Prefilled from Recruitment Hiring. Save this employee record first, then create their login account in Admin → Create User and complete Onboarding.
    </p>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <p style="color: red;">
        <?= htmlspecialchars($error) ?>
    </p>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="app_id" value="<?= (int)$appId ?>">

    <h2>Personal Information</h2>

    <div>
        <label for="first_name">First Name *</label>
        <input
            type="text"
            id="first_name"
            name="first_name"
            value="<?= htmlspecialchars($valFirst) ?>"
            required>
    </div>

    <div>
        <label for="middle_name">Middle Name</label>
        <input
            type="text"
            id="middle_name"
            name="middle_name"
            value="<?= htmlspecialchars($valMiddle) ?>">
    </div>

    <div>
        <label for="last_name">Last Name *</label>
        <input
            type="text"
            id="last_name"
            name="last_name"
            value="<?= htmlspecialchars($valLast) ?>"
            required>
    </div>

    <div>
        <label for="suffix">Suffix</label>
        <input
            type="text"
            id="suffix"
            name="suffix"
            value="<?= htmlspecialchars($valSuffix) ?>"
            placeholder="Jr., Sr., III">
    </div>

    <div>
        <label for="date_of_birth">Date of Birth</label>
        <input
            type="date"
            id="date_of_birth"
            name="date_of_birth"
            value="<?= htmlspecialchars($valDob) ?>">
    </div>

    <div>
        <label for="gender">Gender</label>
        <select id="gender" name="gender">
            <option value="">Select Gender</option>
            <option value="Male" <?= $valGender === 'Male' ? 'selected' : '' ?>>Male</option>
            <option value="Female" <?= $valGender === 'Female' ? 'selected' : '' ?>>Female</option>
            <option value="Other" <?= $valGender === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
    </div>

    <div>
        <label for="phone">Phone</label>
        <input
            type="text"
            id="phone"
            name="phone"
            value="<?= htmlspecialchars($valPhone) ?>">
    </div>

    <div>
        <label for="email">Email</label>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars($valEmail) ?>">
    </div>

    <div>
        <label for="address">Address</label>
        <textarea
            id="address"
            name="address"><?= htmlspecialchars($valAddress) ?></textarea>
    </div>


    <h2>Employment Information</h2>

    <div>
        <label for="department_id">Department</label>

        <select id="department_id" name="department_id">
            <option value="">Select Department</option>

            <?php foreach ($departments as $department): ?>
                <option value="<?= htmlspecialchars((string)$department['id']) ?>" <?= $valDeptId === (string)$department['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($department['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>


    <div>
        <label for="position_id">Position</label>

        <select id="position_id" name="position_id">
            <option value="">Select Position</option>

            <?php foreach ($positions as $position): ?>
                <option value="<?= htmlspecialchars((string)$position['id']) ?>" <?= $valPosId === (string)$position['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($position['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="rank">Position Rank</label>

        <select id="rank" name="rank">
            <option value="employee" <?= $valRank === 'employee' ? 'selected' : '' ?>>Employee</option>
            <option value="team_leader" <?= $valRank === 'team_leader' ? 'selected' : '' ?>>Team Leader</option>
            <option value="manager" <?= $valRank === 'manager' ? 'selected' : '' ?>>Department Manager</option>
        </select>
    </div>

    <div>
        <label for="manager_id">Reports to (Manager)</label>
        <select id="manager_id" name="manager_id">
            <option value="">No manager</option>
            <?php foreach ($employeeModel->getManagerOptions() as $m): ?>
                <option value="<?= htmlspecialchars((string)$m['id']) ?>" <?= $valManagerId === (string)$m['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['employee_id'] . ' - ' . $m['first_name'] . ' ' . $m['last_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>


    <div>
        <label for="date_hired">Date Hired *</label>
        <input
            type="date"
            id="date_hired"
            name="date_hired"
            value="<?= htmlspecialchars($valDateHired) ?>"
            required>
    </div>

    <div>
        <label for="employment_type">Employment Type</label>
        <select id="employment_type" name="employment_type">
            <option value="Full-time" <?= $valEmpType === 'Full-time' ? 'selected' : '' ?>>Full-time</option>
            <option value="Part-time" <?= $valEmpType === 'Part-time' ? 'selected' : '' ?>>Part-time</option>
            <option value="Contract" <?= $valEmpType === 'Contract' ? 'selected' : '' ?>>Contract</option>
            <option value="Intern" <?= $valEmpType === 'Intern' ? 'selected' : '' ?>>Intern</option>
        </select>
    </div>

    <div>
        <label for="employment_status">Employment Status</label>

        <select id="employment_status" name="employment_status">
            <option value="Active" <?= $valEmpStatus === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Inactive" <?= $valEmpStatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="On Leave" <?= $valEmpStatus === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
            <option value="Suspended" <?= $valEmpStatus === 'Suspended' ? 'selected' : '' ?>>Suspended</option>
        </select>
    </div>

    <br>

    <button type="submit">
        Add Employee
    </button>

    <a href="employees.php">
        Cancel
    </a>

</form>