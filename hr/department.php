<?php

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Department.php';

$departmentModel = new Department($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'code' => trim($_POST['code'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'status' => $_POST['status'] ?? 'Active'
    ];

    if ($data['code'] === '' || $data['name'] === '') {
        $error = 'Department code and name are required.';
    } else {
        try {

            $departmentModel->create($data);

            header('Location: department.php');
            exit;

        } catch (PDOException $e) {

            $error = $e->getMessage();

        }
    }
}

$departments = $departmentModel->getAll();

require __DIR__ . '/../templates/hrTemplates/header.php';

?>

<h1>Department Management</h1>

<?php if ($error !== ''): ?>

    <p style="color: red;">
        <?= htmlspecialchars($error) ?>
    </p>

<?php endif; ?>


<h2>Add Department</h2>



<form method="POST">

    <div>
        <label for="code">Department Code *</label>

        <input
            type="text"
            id="code"
            name="code"
            required
        >
    </div>

    <br>

    <div>
        <label for="name">Department Name *</label>

        <input
            type="text"
            id="name"
            name="name"
            required
        >
    </div>

    <br>

    <div>
        <label for="description">Description</label>

        <textarea
            id="description"
            name="description"
        ></textarea>
    </div>

    <br>

    <div>
        <label for="status">Status</label>

        <select id="status" name="status">

            <option value="Active">
                Active
            </option>

            <option value="Inactive">
                Inactive
            </option>

        </select>
    </div>

    <br>

    <button type="submit">
        Add Department
    </button>
    <button type="button" onclick="window.location.href='position.php'">
    See Position
    </button>

</form>


<hr>


<h2>Departments</h2>

<table border="1" cellpadding="8" cellspacing="0">

    <thead>

        <tr>
            <th>ID</th>
            <th>Code</th>
            <th>Name</th>
            <th>Description</th>
            <th>Status</th>
        </tr>

    </thead>

    <tbody>

        <?php if (empty($departments)): ?>

            <tr>
                <td colspan="5">
                    No departments found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($departments as $department): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($department['id']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($department['code']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($department['name']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $department['description'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($department['status']) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

    </tbody>

</table>
