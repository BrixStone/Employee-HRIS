<?php

require_once __DIR__ . '/../../classes/BackupAuth.php';
require_once __DIR__ . '/../../config/database.php';

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Validate the CSRF token.
csrf_check($account['role']);

// Read submitted values.
$enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? 1 : 0;

$frequency = $_POST['frequency'] ?? 'daily';
$backupMinute = filter_var($_POST['backup_minute'] ?? 0, FILTER_VALIDATE_INT);
$backupTime = $_POST['backup_time'] ?? '02:00';
$backupDay = filter_var($_POST['backup_day'] ?? 1, FILTER_VALIDATE_INT);
$retentionDays = filter_var($_POST['retention_days'] ?? 30, FILTER_VALIDATE_INT);

// Allowed values.
$allowedFrequencies = ['hourly', 'daily', 'weekly'];
$allowedRetention = [7, 14, 30, 60, 90];

// Validate frequency.
if (!in_array($frequency, $allowedFrequencies, true)) {
    http_response_code(422);
    exit('Invalid backup frequency.');
}

// Validate hourly minute.
if (
    $backupMinute === false ||
    !in_array($backupMinute, [0, 15, 30, 45], true)
) {
    http_response_code(422);
    exit('Invalid backup minute.');
}

// Validate time.
$time = DateTime::createFromFormat('!H:i', $backupTime);

if (!$time || $time->format('H:i') !== $backupTime) {
    http_response_code(422);
    exit('Invalid backup time.');
}

// Validate weekday: 1 = Monday, 7 = Sunday.
if ($backupDay === false || $backupDay < 1 || $backupDay > 7) {
    http_response_code(422);
    exit('Invalid backup day.');
}

// Validate retention.
if (
    $retentionDays === false ||
    !in_array($retentionDays, $allowedRetention, true)
) {
    http_response_code(422);
    exit('Invalid retention period.');
}

try {
    $sql = "
        INSERT INTO backup_settings (
            id,
            enabled,
            frequency,
            backup_minute,
            backup_time,
            backup_day,
            retention_days,
            timezone
        )
        VALUES (
            1,
            :enabled,
            :frequency,
            :backup_minute,
            :backup_time,
            :backup_day,
            :retention_days,
            'Asia/Manila'
        )
        ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            frequency = VALUES(frequency),
            backup_minute = VALUES(backup_minute),
            backup_time = VALUES(backup_time),
            backup_day = VALUES(backup_day),
            retention_days = VALUES(retention_days),
            timezone = VALUES(timezone)
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':enabled' => $enabled,
        ':frequency' => $frequency,
        ':backup_minute' => $backupMinute,
        ':backup_time' => $backupTime . ':00',
        ':backup_day' => $backupDay,
        ':retention_days' => $retentionDays
    ]);

    $_SESSION['backup_flash'] = [
        'type' => 'success',
        'message' => 'Automatic backup settings saved successfully.',
        'filename' => null
    ];

    header('Location: /admin/backup_management.php');
    exit;

} catch (PDOException $e) {
    error_log('Backup settings error: ' . $e->getMessage());

    $_SESSION['backup_flash'] = [
        'type' => 'error',
        'message' => 'Unable to save backup settings. Please try again.',
        'filename' => null
    ];

    header('Location: /admin/backup_management.php');
    exit;
}
