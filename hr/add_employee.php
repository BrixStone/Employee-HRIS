<?php

require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/Employee.php';
require __DIR__ . '/../classes/Department.php';
require __DIR__ . '/../classes/Position.php';

$employeeModel = new Employee($pdo);
$departmentModel = new Department($pdo);
$positionModel = new Position($pdo);

$departments = $departmentModel->getAll();
$positions = $positionModel->getAll();



$employeeModel = new Employee($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'suffix' => trim($_POST['suffix'] ?? ''),
        'date_of_birth' => $_POST['date_of_birth'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'department_id' => $_POST['department_id'] ?? '',
        'position_id' => $_POST['position_id'] ?? '',
        'date_hired' => $_POST['date_hired'] ?? '',
        'employment_type' => $_POST['employment_type'] ?? 'Full-time',
        'employment_status' => $_POST['employment_status'] ?? 'Active'
    ];

    if (
        $data['first_name'] === '' ||
        $data['last_name'] === '' ||
        $data['date_hired'] === ''
    ) {
        $error = 'Please fill in all required fields.';
    } else {
        try {
            $employeeModel->create($data);

            header('Location: employees.php');
            exit;

        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
}

require __DIR__ . '/../templates/hrTemplates/header.php';

?>

<h1>Add Employee</h1>

<?php if ($error !== ''): ?>

    <p style="color: red;">
        <?= htmlspecialchars($error) ?>
    </p>

<?php endif; ?>

<form method="POST">

    <h2>Personal Information</h2>

    <div>
        <label for="first_name">First Name *</label>
        <input
            type="text"
            id="first_name"
            name="first_name"
            required
        >
    </div>

    <div>
        <label for="middle_name">Middle Name</label>
        <input
            type="text"
            id="middle_name"
            name="middle_name"
        >
    </div>

    <div>
        <label for="last_name">Last Name *</label>
        <input
            type="text"
            id="last_name"
            name="last_name"
            required
        >
    </div>

    <div>
        <label for="suffix">Suffix</label>
        <input
            type="text"
            id="suffix"
            name="suffix"
            placeholder="Jr., Sr., III"
        >
    </div>

    <div>
        <label for="date_of_birth">Date of Birth</label>
        <input
            type="date"
            id="date_of_birth"
            name="date_of_birth"
        >
    </div>

    <div>
        <label for="gender">Gender</label>
        <select id="gender" name="gender">
            <option value="">Select Gender</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
        </select>
    </div>

    <div>
        <label for="phone">Phone</label>
        <input
            type="text"
            id="phone"
            name="phone"
        >
    </div>

    <div>
        <label for="email">Email</label>
        <input
            type="email"
            id="email"
            name="email"
        >
    </div>

    <div>
        <label for="address">Address</label>
        <textarea
            id="address"
            name="address"
        ></textarea>
    </div>


    <h2>Employment Information</h2>

<div>
    <label for="department_id">Department</label>

    <select id="department_id" name="department_id">

        <option value="">
            Select Department
        </option>

        <?php foreach ($departments as $department): ?>

            <option value="<?= htmlspecialchars($department['id']) ?>">
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
            <option value="<?= htmlspecialchars($position['id']) ?>">
                <?= htmlspecialchars($position['title']) ?>
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
            required
        >
    </div>

    <div>
        <label for="employment_type">Employment Type</label>
        <select id="employment_type" name="employment_type">

            <option value="Full-time">
                Full-time
            </option>

            <option value="Part-time">
                Part-time
            </option>

            <option value="Contract">
                Contract
            </option>

            <option value="Intern">
                Intern
            </option>

        </select>
    </div>

    <div>
        <label for="employment_status">Employment Status</label>

        <select
            id="employment_status"
            name="employment_status"
        >
            <option value="Active">
                Active
            </option>

            <option value="Inactive">
                Inactive
            </option>

            <option value="On Leave">
                On Leave
            </option>

            <option value="Suspended">
                Suspended
            </option>
        </select>
    </div>

    <br>

    <button type="submit">
        Save Employee
    </button>

    <a href="employees.php">
        Cancel
    </a>

</form>
