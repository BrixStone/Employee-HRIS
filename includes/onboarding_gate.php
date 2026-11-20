<?php
// includes/onboarding_gate.php

function hris_get_linked_onboarding_record(PDO $pdo, int $empId = 0, string $email = '', string $name = ''): ?array
{
    if ($empId > 0 && ($email === '' || $name === '')) {
        try {
            $eStmt = $pdo->prepare("
                SELECT `email`, TRIM(CONCAT(`first_name`, ' ', `last_name`)) AS full_name
                FROM `employees`
                WHERE `id` = ?
                LIMIT 1
            ");
            $eStmt->execute([$empId]);
            if ($eRow = $eStmt->fetch(PDO::FETCH_ASSOC)) {
                if ($email === '') $email = trim((string)($eRow['email'] ?? ''));
                if ($name === '')  $name  = trim((string)($eRow['full_name'] ?? ''));
            }
        } catch (Throwable $e) {
            error_log('Onboarding gate employee lookup error: ' . $e->getMessage());
        }
    }

    try {
        if ($email !== '') {
            $stmt = $pdo->prepare("
                SELECT *
                FROM `employee_onboarding`
                WHERE LOWER(TRIM(`email`)) = LOWER(TRIM(?))
                ORDER BY `id` DESC
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($name !== '') {
            $stmt = $pdo->prepare("
                SELECT *
                FROM `employee_onboarding`
                WHERE LOWER(TRIM(`full_name`)) = LOWER(TRIM(?))
                ORDER BY `id` DESC
                LIMIT 1
            ");
            $stmt->execute([$name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        error_log('Onboarding gate record lookup error: ' . $e->getMessage());
    }

    return null;
}

function hris_is_orientation_locked_for_employee(PDO $pdo, int $empId = 0, string $email = '', string $name = ''): array
{
    $onb = hris_get_linked_onboarding_record($pdo, $empId, $email, $name);

    if (!$onb) {
        return [
            'locked'     => false,
            'hasRecord'  => false,
            'attended'   => true,
            'onboarding' => null,
            'message'    => '',
        ];
    }

    $attended = (int)($onb['step_orientation'] ?? 0) === 1;
    $facName  = trim((string)($onb['facilitator_name'] ?? 'your assigned HR / Facilitator'));
    $schedAt  = trim((string)($onb['orientation_at'] ?? 'scheduled time'));

    return [
        'locked'     => !$attended,
        'hasRecord'  => true,
        'attended'   => $attended,
        'onboarding' => $onb,
        'message'    => $attended
            ? ''
            : "Your Company Orientation attendance ({$schedAt}) has not been marked as Present yet by {$facName}. Check-In / Time In / Time Out and Leave actions remain locked until HR or your facilitator marks you present.",
    ];
}