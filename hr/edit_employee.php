<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Employee.php';
require __DIR__ . '/../classes/Department.php';
require __DIR__ . '/../classes/Position.php';

$employeeModel = new Employee($pdo);
$departmentModel = new Department($pdo);
$positionModel = new Position($pdo);

$id = isset($_GET['id']) && ctype_digit($_GET['id']) 
    ? (int) $_GET['id']
    : 0;

if ($id <= 0) {
    header('Location: /hr/employees.php');
    exit;
}

$emp = $employeeModel->findById($id);

if (!$emp) {
    header('Location: /hr/employees.php');
    exit;
}

$departments = $departmentModel->getActive();
$positions = $positionModel->getActive();

function includeCurrentIfInactive
    (PDO $pdo, array &$list, string $table, string $labelCol, ?int $currentId)
    : void {
        if(!$currentId) return;
        $ids = array_column($list, 'id');
        if (!in_array($currentId, $ids, true)) {
            $stmt = $pdo->prepare("
            SELECT id, {$labelCol} AS label, status FROM {$table}
            WHERE id = :id
            LIMIT 1
            ");
            $stmt->execute(['id' => $currentId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $list[] = ['id' => $row['id'], $labelCol => $row['label'] . ' (Inactive)', 'status' =>
                $row['status']];
            }
        }
}

includeCurrentIfInactive($pdo, $departments, 'departments', 'name', $emp['department_id'] ??
null);
includeCurrentIfInactive($pdo, $positions, 'positions', 'title', $emp['position_id']??
null);

function h($s) {return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sel($a,$b) { return ((string)$a === (string)$b)? 'selected': ''; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [

            'employee_id'   => trim($_POST['employee_id'] ?? ''),
            'first_name'    => trim($_POST['first_name'] ?? ''),
            'middle_name'   => trim($_POST['middle_name'] ?? ''),
            'last_name'     => trim($_POST['last_name'] ?? ''),
            'suffix'        => trim($_POST['suffix'] ?? ''),
            'date_of_birth' => $_POST['date_of_birth'] ?? '',
            'gender'        => $_POST['gender'] ?? '',
            'phone'         => trim($_POST['phone'] ?? ''),
            'email'         => trim($_POST['email'] ?? ''),
            'address'       => trim($_POST['address'] ?? ''),
            'department_id' => ($_POST['department_id'] ?? '') !== '' 
            ? (int)$_POST['department_id']
            : null,
            'position_id'        => ($_POST['position_id'] ?? '') !== ''
            ? (int)$_POST['position_id']
            : null,
            'date_hired'        => $_POST['date_hired'] ?? '',
            'employment_type'   => $_POST['employment_type'] ?? 'Full-time',
            'employment_status' => $_POST['employment_status'] ?? 'Active',
            
            ];

            if ($data['employee_id'] === '' || 
                $data['first_name'] === '' ||
                $data['last_name'] === ''  ||
                $data['date_hired'] === '') {
                $error = 'Please fill in all required fields (Employee ID, First Name, Last Name, Date Hired).';

                } else {
                    try {
                        $employeeModel->update($id,$data);
                        header('Location: /hr/view_employee.php?id=' . $id);
                        exit;
                    } catch (PDOException $e) {
                        $msg = $e->getMessage();
                        // MySQL duplicate key: SQLSTATE 23000 / driver error 1062 ("Duplicate entry ...")
                        if (stripos($msg, 'duplicate') !== false || (int)($e->errorInfo[1] ?? 0) === 1062) {
                            $error = 'Employee ID already exists. Please use a different one.';
                        } else {
                            $error = 'Unable to update employee. ' . $msg;
                       }
                    }
                }

}

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<h1>Edit Employee</h1>

<?php if($error): ?>
    <p style="color: red;"><?= h($error) ?></p>
<?php endif; ?>

<form action="" method="post">

    <h2>Personal Information</h2>
    <div>
        <label>Employee ID : </label>
        <input 
        type="text"
        name="employee_id"
        value="<?= h($emp['employee_id']) ?>" 
        required
        >
    </div>
    
    <div>
        <label>First Name : </label>
        <input 
        type="text"
        name="first_name"
        value="<?= h($emp['first_name']) ?>"
        required >
    </div>

    <div>
        <label>Middle Name : </label>
        <input 
        type="text"
        name="middle_name"
        value="<?= h($emp['middle_name']) ?>"
        >
    </div>

    <div>
        <label>Last Name : </label>
        <input 
        type="text"
        name="last_name"
        value="<?= h($emp['last_name']) ?>"
        required>
    </div>

    <div>
        <label>Suffix :</label>
        <input 
        type="text"
        name="suffix"
        value="<?= h($emp['suffix']) ?>"
        >
    </div>

    <div>
    <label>Date of Birth : </label>
    <input 
    type="date" 
    name="date_of_birth" 
    value="<?= h($emp['date_of_birth'] ?? '') ?>"
    >
    </div>

    <div>
        <label>Gender : </label>
        <select name="gender" id="gender">
            <option value="">Select Gender</option>
            <option value="Male" <?= sel($emp['gender']?? '', 'Male') ?>>Male</option>
            <option value="Female" <?= sel($emp['gender']?? '', 'Female') ?>>Female</option>
        </select>
    </div>

    <div>
        <label>Phone Number : </label>
        <input 
        type="text"
        name="phone"
        value="<?= h($emp['phone'] ?? '') ?>"
        >
    </div>

    <div>
        <label>Email : </label>
        <input 
        type="text"
        name="email"
        value="<?= h($emp['email'] ?? '') ?>"
        >
    </div>

    <div>
        <label>Address : </label>
        <textarea 
        name="address"><?= h($emp['address'] ?? '') ?>
        </textarea>
    </div>

    <h2>Employment Information</h2>

    <div>
        <label>Department : </label>
        <select name="department_id">
            <option value="">Select Department</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= h($d['id']) ?>"<?= sel($emp['department_id'] ?? '', $d['id']) ?>>
                    <?= h($d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label>Position : </label>
        <select name="position_id">
      <option value="">Select Position</option>
      <?php foreach ($positions as $p): ?>
        <option value="<?= h($p['id']) ?>" <?= sel($emp['position_id'] ?? '', $p['id']) ?>>
          <?= h($p['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label>Date Hired *</label>
    <input type="date" name="date_hired" value="<?= h($emp['date_hired'] ?? '') ?>" required>
  </div>

  <div>
    <label>Employment Type</label>
    <select name="employment_type">
      <option value="Full-time" <?= sel($emp['employment_type'] ?? '', 'Full-time') ?>>Full-time</option>
      <option value="Part-time" <?= sel($emp['employment_type'] ?? '', 'Part-time') ?>>Part-time</option>
      <option value="Contract"  <?= sel($emp['employment_type'] ?? '', 'Contract') ?>>Contract</option>
      <option value="Intern"    <?= sel($emp['employment_type'] ?? '', 'Intern') ?>>Intern</option>
    </select>
  </div>

  <div>
    <label>Employment Status</label>
    <select name="employment_status">
      <option value="Active"    <?= sel($emp['employment_status'] ?? '', 'Active') ?>>Active</option>
      <option value="Inactive"  <?= sel($emp['employment_status'] ?? '', 'Inactive') ?>>Inactive</option>
      <option value="On Leave"  <?= sel($emp['employment_status'] ?? '', 'On Leave') ?>>On Leave</option>
      <option value="Suspended" <?= sel($emp['employment_status'] ?? '', 'Suspended') ?>>Suspended</option>
    </select>
  </div>

  <br>
  <button type="submit">Save Changes</button>
  <a href="/hr/view_employee.php?id=<?= (int)$id ?>">Cancel</a>
</form>