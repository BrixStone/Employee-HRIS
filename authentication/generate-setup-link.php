<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Only administrators can generate setup links.
if (empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// CSRF protection.
$csrfToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    http_response_code(403);
    exit('Invalid request. Please refresh and try again.');
}

$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    exit('Invalid user account.');
}

try {
    $pdo->beginTransaction();

    // Lock the account while generating its setup token.
    $stmt = $pdo->prepare("
        SELECT id, username, status, setup_completed
        FROM users
        WHERE id = :user_id
        FOR UPDATE
    ");

    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User account not found.');
    }

    if (strcasecmp($user['status'], 'Active') !== 0) {
        throw new RuntimeException('Cannot generate a link for an inactive account.');
    }

    if ((int)$user['setup_completed'] === 1) {
        throw new RuntimeException('This account has already completed password setup.');
    }

    // Invalidate previous unused setup links.
    $stmt = $pdo->prepare("
        UPDATE password_setup_tokens
        SET used_at = NOW()
        WHERE user_id = :user_id
          AND used_at IS NULL
    ");

    $stmt->execute([':user_id' => $userId]);

    // Generate a cryptographically secure random token.
    $token = bin2hex(random_bytes(32));

    // Store only the SHA-256 hash.
    $tokenHash = hash('sha256', $token);

    // Link expires after 30 minutes.
    $stmt = $pdo->prepare("
        INSERT INTO password_setup_tokens
            (user_id, token_hash, expires_at)
        VALUES
            (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':token_hash' => $tokenHash
    ]);

    $pdo->commit();

    // Use your actual HTTPS domain in production.
    $baseUrl = 'https://localhost:8000';

    $setupLink = $baseUrl . '/setup-password.php?token=' . urlencode($token);

    // Show the link to the administrator.
    // Do not log or store the raw token.
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>HRIS | Password Setup Link</title>
        <link rel="stylesheet" href="/asset/css/style.css">
    </head>
    <body>
        <main style="max-width:650px;margin:70px auto;padding:25px;">
            <div class="panel">
                <div class="panel-head">
                    <h2>Password Setup Link Generated</h2>
                </div>

                <div class="panel-body">
                    <p>
                        Setup link for
                        <strong><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </p>

                    <p class="muted">
                        This link expires in 30 minutes and can only be used once.
                    </p>

                    <label for="setup-link">Temporary Setup Link</label>

                    <input
                        id="setup-link"
                        type="text"
                        readonly
                        value="<?= htmlspecialchars($setupLink, ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <button type="button" class="primary-button"
                            onclick="copySetupLink()">
                        Copy Setup Link
                    </button>

                    <p id="copy-message" class="muted"></p>

                    <p>
                        <a href="/admin/users.php">Back to User Management</a>
                    </p>
                </div>
            </div>
        </main>

        <script>
        function copySetupLink() {
            const input = document.getElementById('setup-link');

            navigator.clipboard.writeText(input.value)
                .then(function() {
                    document.getElementById('copy-message').textContent =
                        'Setup link copied successfully.';
                })
                .catch(function() {
                    input.select();
                    document.execCommand('copy');
                    document.getElementById('copy-message').textContent =
                        'Please check that the link was copied.';
                });
        }
        </script>
    </body>
    </html>
    <?php

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Setup link generation error: ' . $e->getMessage());

    http_response_code(400);
    exit('Unable to generate the setup link. Please check the account and try again.');
}
