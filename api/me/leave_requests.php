<?php
/**
 * GET  /api/me/leave_requests.php?status=Pending   Own leave requests   employee
 * POST /api/me/leave_requests.php                  File a leave request employee
 *
 * POST body:
 *   {"leave_type_id": 1, "start_date": "2026-12-01", "end_date": "2026-12-03", "reason": "..."}
 *   or {"other_leave_type": "Sabbatical Leave", ...} for a custom type.
 *
 * Same rules as employee/file_leave.php: one request per 24 hours, the start
 * date cannot be in the past, and new requests are Pending. Filing is
 * written to the leave audit log, which pushes a realtime "leave.filed"
 * event to the employee and to HR.
 */
require_once __DIR__ . '/../_api.php';

$method  = api_methods(['GET', 'POST']);
$session = api_require_role(['employee']);

require_once __DIR__ . '/../../classes/LeaveRequest.php';

const LEAVE_COOLDOWN_SECONDS = 24 * 3600;

try {
    require_once __DIR__ . '/../../config/database.php';

    $account = api_require_employee($pdo, $session);
    api_require_permission($pdo, $account, 'leave_requests', $method === 'POST' ? 'submit' : 'view', ['own']);
    $employeeId = $account['employee_id'];
    $model = new LeaveRequest($pdo);

    if ($method === 'GET') {
        $requests = $model->search(api_query_enum('status', LeaveRequest::STATUSES), $employeeId);
        respond(200, [
            'success' => true,
            'count' => count($requests),
            'data' => $requests
        ]);
    }

    // ---------------------------------------------------------------- POST
    $input = api_json_body();
    $errors = [];
    api_reject_unknown($input, ['leave_type_id', 'other_leave_type', 'start_date', 'end_date', 'reason'], $errors);

    $typeId    = api_clean_id($input, 'leave_type_id', $errors);
    $otherType = api_clean_string($input, 'other_leave_type', 150, $errors);
    $start     = api_clean_date($input, 'start_date', $errors, true);
    $end       = api_clean_date($input, 'end_date', $errors, true);
    $reason    = api_clean_string($input, 'reason', 2000, $errors, true);

    if ($typeId !== null && $otherType !== null) {
        $errors['other_leave_type'] = 'Send either leave_type_id or other_leave_type, not both';
    } elseif ($typeId === null && $otherType === null
        && !isset($errors['leave_type_id']) && !isset($errors['other_leave_type'])) {
        $errors['leave_type_id'] = 'Choose a leave type, or name a custom one in other_leave_type';
    }
    if ($start !== null && $start < date('Y-m-d')) {
        $errors['start_date'] = 'Cannot be in the past';
    }
    if ($start !== null && $end !== null && $start > $end) {
        $errors['end_date'] = 'Cannot be before start_date';
    }

    if ($typeId !== null && !$errors) {
        $chk = $pdo->prepare("
            SELECT 1 FROM leave_types
            WHERE id = :id AND (status = 'Active' OR status IS NULL)
            LIMIT 1
        ");
        $chk->execute(['id' => $typeId]);
        if ($chk->fetchColumn() === false) {
            $errors['leave_type_id'] = 'No active leave type with this id (see GET /api/leave_types.php)';
        }
    }
    if ($errors) {
        api_error(422, 'Validation failed', ['errors' => $errors]);
    }

    $pdo->beginTransaction();

    // Lock the employee row so two quick requests cannot both pass the 24-hour limit.
    $pdo->prepare('SELECT id FROM employees WHERE id = :id FOR UPDATE')->execute(['id' => $employeeId]);

    $last = $pdo->prepare("
        SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed,
               DATE_ADD(created_at, INTERVAL 24 HOUR) AS next_at
        FROM leave_requests
        WHERE employee_id = :emp
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $last->execute(['emp' => $employeeId]);
    $last = $last->fetch(PDO::FETCH_ASSOC);

    if ($last && (int)$last['elapsed'] < LEAVE_COOLDOWN_SECONDS) {
        $pdo->rollBack();
        $retry = LEAVE_COOLDOWN_SECONDS - max(0, (int)$last['elapsed']);
        header('Retry-After: ' . $retry);
        api_error(429, "You can file 1 leave request every 24 hours. Next request available at {$last['next_at']}.", [
            'retry_after_seconds' => $retry,
            'next_request_available_at' => $last['next_at']
        ]);
    }

    if ($typeId === null) {
        // Custom type: reuse a type with the same name, otherwise register it
        // (as employee/file_leave.php does).
        $find = $pdo->prepare("
            SELECT id FROM leave_types
            WHERE LOWER(TRIM(leave_name)) = LOWER(TRIM(:name))
            ORDER BY id ASC
            LIMIT 1
        ");
        $find->execute(['name' => $otherType]);
        $typeId = $find->fetchColumn();

        if ($typeId === false) {
            $pdo->prepare("
                INSERT INTO leave_types (leave_name, description, default_days, status)
                VALUES (:name, 'Custom leave type filed by employee', 0, 'Active')
            ")->execute(['name' => $otherType]);
            $typeId = $pdo->lastInsertId();
        }
        $typeId = (int)$typeId;
    }

    $days = DateTime::createFromFormat('!Y-m-d', $start)->diff(DateTime::createFromFormat('!Y-m-d', $end))->days + 1;

    $model->create([
        'employee_id' => $employeeId,
        'leave_type_id' => $typeId,
        'start_date' => $start,
        'end_date' => $end,
        'number_of_days' => $days,
        'reason' => $reason,
    ]);
    $newId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // After commit, so a logging problem can never undo the request.
    (new LeaveAuditLog($pdo))->log(
        $newId,
        $employeeId,
        LeaveAuditLog::ACTION_FILED,
        null,
        'Pending',
        [
            'id' => $employeeId,
            'name' => $account['name'],
            'email' => $account['email'],
            'role' => 'employee',
        ],
        $reason
    );

    $request = $model->findById($newId);

    respond(201, [
        'success' => true,
        'message' => 'Leave request filed and sent to HR for review',
        'realtime_event' => 'leave.filed',
        'next_request_available_at' => date('Y-m-d H:i:s', strtotime((string)$request['created_at']) + LEAVE_COOLDOWN_SECONDS),
        'data' => $request
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('My leave requests API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
