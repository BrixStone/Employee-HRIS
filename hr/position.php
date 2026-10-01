<?php

require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/Position.php';

$positionModel = new Position($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'code' => trim($_POST['code'] ?? ''),
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'status' => $_POST['status'] ?? 'Active'
    ];

    if ($data['code'] === '' || $data['title'] === '') {

        $error = 'Position code and title are required.';

    } else {

        try {

            $positionModel->create($data);

            header('Location: position.php');
            exit;

        } catch (PDOException $e) {

            $error = $e->getMessage();

        }
    }
}

$positions = $positionModel->getAll();

require __DIR__ . '/../templates/hrTemplates/header.php';

?>

<h1>Organization - Positions</h1>

<?php if ($error !== ''): ?>

    <p style="color: red;">
        <?= htmlspecialchars($error) ?>
    </p>

<?php endif; ?>


<h2>Add Position</h2>

<form method="POST">

    <div>

        <label for="code">
            Position Code *
        </label>

        <input
            type="text"
            id="code"
            name="code"
            required
        >

    </div>

    <br>

    <div>

        <label for="title">
            Position Title *
        </label>

        <input
            type="text"
            id="title"
            name="title"
            required
        >

    </div>

    <br>

    <div>

        <label for="description">
            Description
        </label>

        <textarea
            id="description"
            name="description"
        ></textarea>

    </div>

    <br>

    <div>

        <label for="status">
            Status
        </label>

        <select
            id="status"
            name="status"
        >

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
        Add Position
    </button>

</form>


<hr>


<h2>Positions</h2>

<table border="1" cellpadding="8" cellspacing="0">

    <thead>

        <tr>
            <th>ID</th>
            <th>Code</th>
            <th>Title</th>
            <th>Description</th>
            <th>Status</th>
        </tr>

    </thead>

    <tbody>

        <?php if (empty($positions)): ?>

            <tr>
                <td colspan="5">
                    No positions found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($positions as $position): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($position['id']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($position['code']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($position['title']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $position['description'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($position['status']) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

    </tbody>

</table>
