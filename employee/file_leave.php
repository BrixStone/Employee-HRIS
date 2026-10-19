<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/supabase.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit();
}

$employeeId = (int)$_SESSION['user_id'];
$message = '';
$error = '';

$stmt = $pdo->prepare("
    SELECT id, name, email, phone_number, status
    FROM public.employee
    WHERE id = :id
");
$stmt->execute(['id' => $employeeId]);
$employeeData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employeeData) {
    session_destroy();
    header("Location: ../authentication/login.php");
    exit();
}

$email = $employeeData['email'] ?? '';

$defaultLeaveTypes = [
    [
        'name' => 'Annual Leave',
        'description' => 'Paid time off for vacation, rest, and personal recreation',
        'default_days' => 12
    ],
    [
        'name' => 'Sick Leave',
        'description' => 'Paid leave for illness, medical appointments, and health recovery',
        'default_days' => 6
    ],
    [
        'name' => 'Personal Leave',
        'description' => 'Leave for urgent personal matters or emergencies',
        'default_days' => 3
    ],
    [
        'name' => 'Maternity Leave',
        'description' => 'Paid leave for female employees during childbirth and postpartum recovery',
        'default_days' => 105
    ],
    [
        'name' => 'Paternity Leave',
        'description' => 'Paid leave for married male employees upon child delivery',
        'default_days' => 7
    ],
    [
        'name' => 'Bereavement Leave',
        'description' => 'Leave taken upon the death of an immediate family member',
        'default_days' => 3
    ],
    [
        'name' => 'Emergency Leave',
        'description' => 'Leave for unforeseen emergencies, calamities, or disasters',
        'default_days' => 3
    ]
];

try {
    foreach ($defaultLeaveTypes as $defType) {
        $checkType = $pdo->prepare("
            SELECT id FROM public.leave_types 
            WHERE LOWER(BTRIM(leave_name)) = LOWER(BTRIM(:name)) 
            LIMIT 1
        ");
        $checkType->execute(['name' => $defType['name']]);
        if (!$checkType->fetchColumn()) {
            $insertDef = $pdo->prepare("
                INSERT INTO public.leave_types (leave_name, description, default_days, status)
                VALUES (:name, :desc, :days, 'Active')
            ");
            $insertDef->execute([
                'name' => $defType['name'],
                'desc' => $defType['description'],
                'days' => $defType['default_days']
            ]);
        }
    }

    $leaveTypesStmt = $pdo->query("
        SELECT id, leave_name, default_days, description
        FROM public.leave_types
        WHERE status = 'Active' OR status IS NULL
        ORDER BY id ASC
    ");
    $leaveTypes = $leaveTypesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading leave types: " . $e->getMessage());
    $leaveTypes = [];
    $error = "Unable to load leave types: " . $e->getMessage();
}

$selectedLeaveType = $_POST['leave_type_id'] ?? '';
$otherLeaveType = trim($_POST['other_leave_type'] ?? '');
$startDate = trim($_POST['start_date'] ?? '');
$endDate = trim($_POST['end_date'] ?? '');
$reason = trim($_POST['reason'] ?? '');

$showOtherLeaveType = ($selectedLeaveType === 'other');

$cooldownDuration = 24 * 3600;
$canSubmit = true;
$cooldownSecondsRemaining = 0;
$nextAvailableTimeFormatted = '';
$hoursLeft = 0;
$minutesLeft = 0;

try {
    $lastReqStmt = $pdo->prepare("
        SELECT created_at 
        FROM public.leave_requests 
        WHERE employee_id = :employee_id 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $lastReqStmt->execute(['employee_id' => $employeeId]);
    $lastRequest = $lastReqStmt->fetch(PDO::FETCH_ASSOC);

    if ($lastRequest && !empty($lastRequest['created_at'])) {
        $lastRequestTimestamp = strtotime($lastRequest['created_at']);
        $elapsed = time() - $lastRequestTimestamp;

        if ($elapsed < $cooldownDuration) {
            $canSubmit = false;
            $cooldownSecondsRemaining = $cooldownDuration - $elapsed;
            $hoursLeft = floor($cooldownSecondsRemaining / 3600);
            $minutesLeft = floor(($cooldownSecondsRemaining % 3600) / 60);
            $nextAvailableTimeFormatted = date('M d, Y h:i A', $lastRequestTimestamp + $cooldownDuration);
        }
    }
} catch (PDOException $e) {
    error_log("Error checking 24h limit: " . $e->getMessage());
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['action'] ?? '') === 'request_leave'
) {
    $formAction = $_POST['form_action'] ?? 'submit';


    if ($formAction === 'continue') {
    } elseif (!$canSubmit) {
        $error = "Daily limit reached: You can only file 1 leave request every 24 hours. Next request available on {$nextAvailableTimeFormatted} (in {$hoursLeft}h {$minutesLeft}m).";
    } else {
        if ($selectedLeaveType === '') {
            $error = "Please select a leave type.";
        } elseif ($selectedLeaveType === 'other' && $otherLeaveType === '') {
            $error = "Please enter your custom leave type name.";
        } elseif ($selectedLeaveType === 'other' && mb_strlen($otherLeaveType) > 150) {
            $error = "Leave type cannot exceed 150 characters.";
        } elseif (
            $selectedLeaveType !== 'other'
            && (
                filter_var($selectedLeaveType, FILTER_VALIDATE_INT) === false
                || (int)$selectedLeaveType <= 0
            )
        ) {
            $error = "Please select a valid leave type.";
        } elseif ($startDate === '' || $endDate === '' || $reason === '') {
            $error = "Please fill in all required fields.";
        } elseif ($startDate > $endDate) {
            $error = "Start date cannot be later than end date.";
        } else {
            $startObj = DateTime::createFromFormat('!Y-m-d', $startDate);
            $endObj = DateTime::createFromFormat('!Y-m-d', $endDate);

            if (
                !$startObj
                || !$endObj
                || $startObj->format('Y-m-d') !== $startDate
                || $endObj->format('Y-m-d') !== $endDate
            ) {
                $error = "Please enter valid dates.";
            } elseif ($startObj < new DateTime('today')) {
                $error = "Leave start date cannot be in the past.";
            } else {
                try {
                    $pdo->beginTransaction();

                    if ($selectedLeaveType === 'other') {
                        $findType = $pdo->prepare("
                            SELECT id
                            FROM public.leave_types
                            WHERE LOWER(BTRIM(leave_name)) = LOWER(BTRIM(:leave_name))
                            ORDER BY id ASC
                            LIMIT 1
                        ");
                        $findType->execute(['leave_name' => $otherLeaveType]);
                        $leaveTypeId = $findType->fetchColumn();

                        if ($leaveTypeId === false) {
                            $addType = $pdo->prepare("
                                INSERT INTO public.leave_types (
                                    leave_name,
                                    description,
                                    default_days,
                                    status
                                )
                                VALUES (
                                    :leave_name,
                                    :description,
                                    0,
                                    'Active'
                                )
                                RETURNING id
                            ");
                            $addType->execute([
                                'leave_name'  => $otherLeaveType,
                                'description' => 'Custom leave type filed by employee'
                            ]);
                            $leaveTypeId = $addType->fetchColumn();
                        }
                    } else {
                        $leaveTypeId = (int)$selectedLeaveType;

                        $checkType = $pdo->prepare("
                            SELECT id
                            FROM public.leave_types
                            WHERE id = :id
                        ");
                        $checkType->execute(['id' => $leaveTypeId]);

                        if ($checkType->fetchColumn() === false) {
                            throw new RuntimeException("The selected leave type does not exist in the database.");
                        }
                    }

                    $numberOfDays = $startObj->diff($endObj)->days + 1;

                    $insertStmt = $pdo->prepare("
                        INSERT INTO public.leave_requests (
                            employee_id,
                            leave_type_id,
                            start_date,
                            end_date,
                            number_of_days,
                            reason,
                            status
                        )
                        VALUES (
                            :employee_id,
                            :leave_type_id,
                            :start_date,
                            :end_date,
                            :number_of_days,
                            :reason,
                            'Pending'
                        )
                    ");

                    $insertStmt->execute([
                        'employee_id'    => $employeeId,
                        'leave_type_id'  => $leaveTypeId,
                        'start_date'     => $startDate,
                        'end_date'       => $endDate,
                        'number_of_days' => $numberOfDays,
                        'reason'         => $reason
                    ]);

                    $pdo->commit();

                    $message = "Leave request successfully filed and submitted to database!";

                    $canSubmit = false;
                    $cooldownSecondsRemaining = $cooldownDuration;
                    $hoursLeft = 24;
                    $minutesLeft = 0;
                    $nextAvailableTimeFormatted = date('M d, Y h:i A', time() + $cooldownDuration);

                    $selectedLeaveType = '';
                    $otherLeaveType = '';
                    $startDate = '';
                    $endDate = '';
                    $reason = '';
                    $showOtherLeaveType = false;

                    $leaveTypesStmt = $pdo->query("
                        SELECT id, leave_name, default_days, description
                        FROM public.leave_types
                        WHERE status = 'Active' OR status IS NULL
                        ORDER BY id ASC
                    ");
                    $leaveTypes = $leaveTypesStmt->fetchAll(PDO::FETCH_ASSOC);

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log("Leave request error: " . $e->getMessage());

                    $error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : "Unable to submit your leave request. Please check your database connection.";
                }
            }
        }
    }
}

try {
    $historyStmt = $pdo->prepare("
        SELECT
            lr.id,
            lr.employee_id,
            lr.leave_type_id,
            COALESCE(lt.leave_name, 'Unknown Type') AS leave_name,
            lr.start_date,
            lr.end_date,
            lr.number_of_days,
            lr.reason,
            lr.status,
            lr.approved_by,
            lr.approved_at,
            lr.created_at
        FROM public.leave_requests AS lr
        LEFT JOIN public.leave_types AS lt
            ON lt.id = lr.leave_type_id
        WHERE lr.employee_id = :employee_id
        ORDER BY lr.created_at DESC
    ");
    $historyStmt->execute(['employee_id' => $employeeId]);
    $leaveLogs = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching leave history: " . $e->getMessage());
    $leaveLogs = [];
    if (empty($error)) {
        $error = "Unable to load leave history.";
    }
}

try {
    $stmtLeaveBal = $pdo->prepare("
        SELECT annual_leave, sick_leave, personal_leave
        FROM public.leave
        WHERE LOWER(BTRIM(email)) = LOWER(BTRIM(:email))
        LIMIT 1
    ");
    $stmtLeaveBal->execute(['email' => $email]);
    $leaveBalData = $stmtLeaveBal->fetch(PDO::FETCH_ASSOC);

    if (!$leaveBalData && !empty($email)) {
        try {
            $initBal = $pdo->prepare("
                INSERT INTO public.leave (email, annual_leave, sick_leave, personal_leave)
                VALUES (:email, 12, 6, 3)
                RETURNING annual_leave, sick_leave, personal_leave
            ");
            $initBal->execute(['email' => $email]);
            $leaveBalData = $initBal->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {
            $leaveBalData = ['annual_leave' => 12, 'sick_leave' => 6, 'personal_leave' => 3];
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching leave balances: " . $e->getMessage());
    $leaveBalData = false;
}

$balances = [
    'annual'   => $leaveBalData['annual_leave'] ?? 0,
    'sick'     => $leaveBalData['sick_leave'] ?? 0,
    'personal' => $leaveBalData['personal_leave'] ?? 0
];

$user = [
    'full_name' => htmlspecialchars($employeeData['name'] ?? '', ENT_QUOTES, 'UTF-8'),
    'email'     => htmlspecialchars($employeeData['email'] ?? '', ENT_QUOTES, 'UTF-8'),
    'status'    => htmlspecialchars($employeeData['status'] ?? 'Active Employee', ENT_QUOTES, 'UTF-8'),
    'avatar'    => 'https://ui-avatars.com/api/?name=' . urlencode($employeeData['name'] ?? 'User') . '&background=4763ff&color=fff'
];

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - HRIS</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sidebar: '#0e0d13',
                        mainbg: '#180a2b',
                        cardbg: '#0a0514',
                        cardborder: '#281545',
                        accent: '#4763ff',
                        accenthover: '#354beb',
                        textmuted: '#8b8994'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-mainbg text-white min-h-screen flex">

    <!-- Sidebar -->
    <aside class="w-64 shrink-0 bg-sidebar flex flex-col justify-between border-r border-cardborder">
        <div>
            <div class="h-20 flex items-center px-6 border-b border-cardborder mb-6">
                <div class="w-8 h-8 rounded bg-accent flex items-center justify-center mr-3 shadow-[0_0_15px_rgba(71,99,255,0.5)]">
                    <div class="w-3 h-3 bg-white rounded-full"></div>
                </div>

                <div>
                    <h1 class="font-bold text-lg leading-tight">HRIS</h1>
                    <p class="text-[10px] text-textmuted">Employee Portal</p>
                </div>
            </div>

            <nav class="px-4 space-y-1">
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span class="font-medium text-sm">Dashboard</span>
                </a>
                <a href="profile.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <span class="font-medium text-sm">My Profile</span>
                </a>
                <a href="attendance.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span class="font-medium text-sm">Attendance</span>
                </a>
                <a href="file_leave.php" class="flex items-center px-4 py-2.5 bg-[#1f1d2b] text-white rounded-lg transition-colors border-l-2 border-accent">
                    <svg class="w-5 h-5 mr-3 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="font-medium text-sm text-accent">Leave Management</span>
                </a>
                <a href="payslips.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                    <span class="font-medium text-sm">Payslips</span>
                </a>
                <a href="settings.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="font-medium text-sm">Settings</span>
                </a>

                <div class="pt-4 mt-4 border-t border-cardborder">
                    <a href="../authentication/logout.php" class="flex items-center px-4 py-2.5 text-red-400 hover:text-red-300 hover:bg-[#15131e] rounded-lg transition-colors">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                        <span class="font-medium text-sm">Log Out</span>
                    </a>
                </div>
            </nav>
        </div>

        <div class="p-6 border-t border-cardborder">
            <div class="flex items-center space-x-3 overflow-hidden">
                <img src="<?= e($user['avatar']) ?>" alt="Profile" class="w-10 h-10 rounded-full border border-cardborder">

                <div class="truncate">
                    <h4 class="text-sm font-semibold truncate"><?= $user['full_name'] ?></h4>
                    <p class="text-xs text-textmuted truncate"><?= $user['email'] ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 min-w-0 flex flex-col overflow-y-auto">
        <header class="min-h-20 border-b border-cardborder flex items-center justify-between px-8 py-4 bg-mainbg sticky top-0 z-10">
            <div>
                <h2 class="text-xl font-bold">Leave Management & Filing</h2>
                <p class="text-xs text-textmuted mt-0.5">Submit and monitor leave requests with instant database synchronization</p>
            </div>
            <div class="flex items-center space-x-3">
                <span class="text-xs bg-[#1f1d2b] px-3.5 py-1.5 rounded-lg border border-cardborder text-gray-200 font-mono tracking-tight flex items-center shadow-inner">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 mr-2.5 inline-block animate-pulse shadow-[0_0_8px_rgba(52,211,153,0.8)]"></span>
                    <span id="liveClockText"><?= date('M d, Y h:i:s A') ?></span>
                </span>
            </div>
        </header>

        <div class="p-8 max-w-[1400px] w-full mx-auto space-y-6">

            <!-- Flash Notifications -->
            <?php if ($message !== ''): ?>
                <div class="bg-teal-950/80 border border-teal-500 text-teal-300 px-5 py-4 rounded-xl flex items-center shadow-lg">
                    <svg class="w-5 h-5 mr-3 shrink-0 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div>
                        <p class="text-sm font-medium"><?= e($message) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="bg-rose-950/80 border border-rose-500 text-rose-300 px-5 py-4 rounded-xl flex items-center shadow-lg">
                    <svg class="w-5 h-5 mr-3 shrink-0 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div>
                        <p class="text-sm font-medium"><?= e($error) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Leave Balances -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-cardbg border border-cardborder rounded-2xl p-6 relative overflow-hidden group hover:border-cyan-500/50 transition-colors">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-cyan-500/5 rounded-bl-full pointer-events-none"></div>
                    <p class="text-3xl font-extrabold text-cyan-400 mb-1">
                        <?= e($balances['annual']) ?> <span class="text-sm font-normal text-cyan-300/70">Days</span>
                    </p>
                    <p class="text-sm font-semibold">Annual Leave Balance</p>
                    <p class="text-xs text-textmuted mt-1">Available for personal vacation & recreation</p>
                </div>

                <div class="bg-cardbg border border-cardborder rounded-2xl p-6 relative overflow-hidden group hover:border-purple-500/50 transition-colors">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-purple-500/5 rounded-bl-full pointer-events-none"></div>
                    <p class="text-3xl font-extrabold text-purple-400 mb-1">
                        <?= e($balances['sick']) ?> <span class="text-sm font-normal text-purple-300/70">Days</span>
                    </p>
                    <p class="text-sm font-semibold">Sick Leave Balance</p>
                    <p class="text-xs text-textmuted mt-1">Available for medical care & recovery</p>
                </div>

                <div class="bg-cardbg border border-cardborder rounded-2xl p-6 relative overflow-hidden group hover:border-emerald-500/50 transition-colors">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-emerald-500/5 rounded-bl-full pointer-events-none"></div>
                    <p class="text-3xl font-extrabold text-emerald-400 mb-1">
                        <?= e($balances['personal']) ?> <span class="text-sm font-normal text-emerald-300/70">Days</span>
                    </p>
                    <p class="text-sm font-semibold">Personal Leave Balance</p>
                    <p class="text-xs text-textmuted mt-1">Available for emergency & urgent needs</p>
                </div>
            </div>

            <!-- Leave Request Form -->
            <div class="bg-cardbg border border-cardborder rounded-2xl p-6 sm:p-8 shadow-xl">
                <div class="flex items-center justify-between mb-6 pb-4 border-b border-cardborder">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-accent mr-2.5"></span>
                            File New Leave Request
                        </h3>
                        <p class="text-xs text-textmuted mt-1">Leave applications are recorded in <code class="text-cyan-300">public.leave_requests</code> and queued for HR review.</p>
                    </div>
                </div>

                <form method="POST" action="file_leave.php" id="leaveForm" class="space-y-5">
                    <input type="hidden" name="action" value="request_leave">

                    <!-- 24-Hour Cooldown Banner if active -->
                    <?php if (!$canSubmit): ?>
                        <div id="cooldownAlertBox" class="p-4 rounded-xl bg-amber-950/40 border border-amber-500/40 text-amber-200 flex items-start space-x-3.5 shadow-md">
                            <div class="p-2 rounded-lg bg-amber-500/10 text-amber-400 shrink-0 mt-0.5">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-sm font-semibold text-amber-300">24-Hour Request Limit Active</h4>
                                <p class="text-xs text-amber-200/80 mt-0.5 leading-relaxed">
                                    Employees are limited to <strong>1 leave request every 24 hours</strong>. Your next submission unlocks in:
                                </p>
                                <div class="mt-2.5 flex items-center space-x-2">
                                    <span id="cooldownCountdownBadge" class="font-mono font-bold text-xs bg-amber-900/60 border border-amber-600/50 px-3 py-1 rounded-md text-amber-300">
                                        <?= $hoursLeft ?>h <?= $minutesLeft ?>m remaining
                                    </span>
                                    <span class="text-[11px] text-amber-400/70">
                                        (Available on <?= e($nextAvailableTimeFormatted) ?>)
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label for="leave_type_id" class="block text-xs text-textmuted mb-1.5 font-medium">
                            Leave Type <span class="text-rose-400">*</span>
                        </label>

                        <select
                            id="leave_type_id"
                            name="leave_type_id"
                            required
                            onchange="handleLeaveTypeChange(this.value)"
                            class="w-full bg-[#1b0d35] text-sm text-white border border-cardborder rounded-xl px-4 py-3 focus:outline-none focus:border-accent transition-colors"
                        >
                            <option value="">-- Select Leave Type --</option>

                            <?php foreach ($leaveTypes as $type): ?>
                                <option
                                    value="<?= (int)$type['id'] ?>"
                                    <?= (string)$selectedLeaveType === (string)$type['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($type['leave_name']) ?><?= !empty($type['default_days']) ? ' (' . e($type['default_days']) . ' days default)' : '' ?>
                                </option>
                            <?php endforeach; ?>

                            <option value="other" <?= $showOtherLeaveType ? 'selected' : '' ?>>
                                + Other (Specify Custom Leave Type)
                            </option>
                        </select>
                    </div>

                    <!-- Custom Leave Type Input -->
                    <div id="other_leave_wrapper" class="<?= $showOtherLeaveType ? '' : 'hidden' ?> transition-all">
                        <label for="other_leave_type" class="block text-xs text-textmuted mb-1.5 font-medium">
                            Custom Leave Type Name <span class="text-rose-400">*</span>
                        </label>

                        <input
                            type="text"
                            id="other_leave_type"
                            name="other_leave_type"
                            maxlength="150"
                            value="<?= e($otherLeaveType) ?>"
                            placeholder="e.g. Sabbatical Leave, Bereavement Extension, etc."
                            class="w-full bg-[#1b0d35] text-sm text-white border border-cardborder rounded-xl px-4 py-3 focus:outline-none focus:border-accent transition-colors"
                        >
                        <p class="text-[11px] text-textmuted mt-1">This will be registered under <code class="text-cyan-300">public.leave_types</code> upon submission.</p>
                    </div>

                    <!-- Date Range Selection -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label for="start_date" class="block text-xs text-textmuted mb-1.5 font-medium">
                                Start Date <span class="text-rose-400">*</span>
                            </label>

                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                min="<?= date('Y-m-d') ?>"
                                required
                                value="<?= e($startDate) ?>"
                                onchange="calculateDays()"
                                class="w-full bg-[#1b0d35] text-sm text-white border border-cardborder rounded-xl px-4 py-3 focus:outline-none focus:border-accent transition-colors"
                            >
                        </div>

                        <div>
                            <label for="end_date" class="block text-xs text-textmuted mb-1.5 font-medium">
                                End Date <span class="text-rose-400">*</span>
                            </label>

                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                min="<?= date('Y-m-d') ?>"
                                required
                                value="<?= e($endDate) ?>"
                                onchange="calculateDays()"
                                class="w-full bg-[#1b0d35] text-sm text-white border border-cardborder rounded-xl px-4 py-3 focus:outline-none focus:border-accent transition-colors"
                            >
                        </div>
                    </div>

                    <!-- Calculated Duration Badge -->
                    <div id="days_preview" class="hidden p-3 bg-[#150e26] border border-cardborder rounded-xl flex items-center justify-between text-xs">
                        <span class="text-textmuted">Requested Duration:</span>
                        <span id="days_count" class="font-bold text-cyan-400 text-sm">0 calendar day(s)</span>
                    </div>

                    <div>
                        <label for="reason" class="block text-xs text-textmuted mb-1.5 font-medium">
                            Reason for Leave <span class="text-rose-400">*</span>
                        </label>

                        <textarea
                            id="reason"
                            name="reason"
                            rows="3"
                            required
                            placeholder="Please provide details about your leave application..."
                            class="w-full bg-[#1b0d35] text-sm text-white border border-cardborder rounded-xl p-3 focus:outline-none focus:border-accent transition-colors"
                        ><?= e($reason) ?></textarea>
                    </div>

                    <div class="pt-2 flex items-center space-x-4">
                        <button
                            type="submit"
                            id="submitLeaveBtn"
                            name="form_action"
                            value="submit"
                            <?= !$canSubmit ? 'disabled' : '' ?>
                            class="<?= !$canSubmit ? 'bg-gray-700/60 border border-gray-600/40 text-gray-400 cursor-not-allowed' : 'bg-accent hover:bg-accenthover text-white shadow-[0_0_20px_rgba(71,99,255,0.4)]' ?> font-semibold text-sm py-3 px-8 rounded-xl transition-all flex items-center"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                            <span id="submitBtnText"><?= !$canSubmit ? 'Locked (1 Request / 24h)' : 'Submit Leave Application' ?></span>
                        </button>

                        <button
                            type="reset"
                            onclick="resetFormUI()"
                            class="text-xs text-textmuted hover:text-white px-4 py-3 transition-colors"
                        >
                            Clear Form
                        </button>
                    </div>
                </form>
            </div>

            <!-- Leave History Table -->
            <div class="bg-cardbg border border-cardborder rounded-2xl p-6 sm:p-8 shadow-xl">
                <div class="flex items-center justify-between mb-4 pb-4 border-b border-cardborder">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-cyan-400 mr-2.5"></span>
                            My Leave Applications & Review Status
                        </h3>
                        <p class="text-xs text-textmuted mt-0.5">Real-time status updates from the database</p>
                    </div>
                    <span class="text-xs text-textmuted bg-[#150e26] px-3 py-1 rounded-lg border border-cardborder">
                        Total Requests: <?= count($leaveLogs) ?>
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-cardborder text-textmuted text-xs uppercase tracking-wider">
                                <th class="py-3 px-4">Leave Type</th>
                                <th class="py-3 px-4">Date Filed</th>
                                <th class="py-3 px-4">Inclusive Dates</th>
                                <th class="py-3 px-4 text-center">Days</th>
                                <th class="py-3 px-4">Reason</th>
                                <th class="py-3 px-4">Review Info</th>
                                <th class="py-3 px-4">Status</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-cardborder text-sm">
                            <?php if (empty($leaveLogs)): ?>
                                <tr>
                                    <td colspan="7" class="py-8 text-center text-textmuted">
                                        <div class="flex flex-col items-center justify-center">
                                            <svg class="w-10 h-10 text-textmuted/40 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                            <p>No leave requests found.</p>
                                            <p class="text-xs text-textmuted/60 mt-1">Submit your first application using the form above.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($leaveLogs as $log): ?>
                                    <tr class="hover:bg-[#150a25] transition-colors">
                                        <td class="py-3.5 px-4 font-semibold text-white">
                                            <?= e($log['leave_name']) ?>
                                        </td>

                                        <td class="py-3.5 px-4 text-xs text-textmuted">
                                            <?= !empty($log['created_at']) ? e(date('M d, Y h:i A', strtotime($log['created_at']))) : '—' ?>
                                        </td>

                                        <td class="py-3.5 px-4 text-xs">
                                            <span class="text-white"><?= e(date('M d, Y', strtotime($log['start_date']))) ?></span>
                                            <span class="text-textmuted mx-1">to</span>
                                            <span class="text-white"><?= e(date('M d, Y', strtotime($log['end_date']))) ?></span>
                                        </td>

                                        <td class="py-3.5 px-4 text-center">
                                            <span class="font-bold text-cyan-400 bg-cyan-950/60 border border-cyan-800/50 px-2.5 py-0.5 rounded-md text-xs">
                                                <?= (float)$log['number_of_days'] ?> day(s)
                                            </span>
                                        </td>

                                        <td class="py-3.5 px-4 text-xs text-textmuted max-w-xs break-words">
                                            <?= e($log['reason'] ?? '—') ?>
                                        </td>

                                        <td class="py-3.5 px-4 text-xs text-textmuted">
                                            <?php if (!empty($log['approved_by'])): ?>
                                                <span class="text-emerald-300 font-medium">Reviewed by HR Admin #<?= e($log['approved_by']) ?></span>
                                                <span class="block text-[11px] text-textmuted mt-0.5">
                                                    <?= !empty($log['approved_at']) ? e(date('M d, Y h:i A', strtotime($log['approved_at']))) : '' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="italic text-amber-400/80 flex items-center">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 mr-1.5 animate-pulse"></span>
                                                    Awaiting HR Review
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="py-3.5 px-4">
                                            <?php if ($log['status'] === 'Approved'): ?>
                                                <span class="inline-flex items-center bg-emerald-950 border border-emerald-500/50 text-emerald-300 text-xs px-2.5 py-1 rounded-md font-medium">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                                                    Approved
                                                </span>
                                            <?php elseif ($log['status'] === 'Rejected'): ?>
                                                <span class="inline-flex items-center bg-rose-950 border border-rose-500/50 text-rose-300 text-xs px-2.5 py-1 rounded-md font-medium">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400 mr-1.5"></span>
                                                    Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center bg-amber-950 border border-amber-500/50 text-amber-300 text-xs px-2.5 py-1 rounded-md font-medium">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 mr-1.5"></span>
                                                    <?= e($log['status'] ?? 'Pending') ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script>
        function handleLeaveTypeChange(val) {
            const wrapper = document.getElementById('other_leave_wrapper');
            const input = document.getElementById('other_leave_type');
            if (val === 'other') {
                wrapper.classList.remove('hidden');
                input.setAttribute('required', 'required');
                input.focus();
            } else {
                wrapper.classList.add('hidden');
                input.removeAttribute('required');
                input.value = '';
            }
        }

        function calculateDays() {
            const startVal = document.getElementById('start_date').value;
            const endVal = document.getElementById('end_date').value;
            const preview = document.getElementById('days_preview');
            const countSpan = document.getElementById('days_count');

            if (!startVal || !endVal) {
                preview.classList.add('hidden');
                return;
            }

            const startDate = new Date(startVal);
            const endDate = new Date(endVal);

            if (endDate >= startDate) {
                const diffTime = Math.abs(endDate - startDate);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                countSpan.textContent = `${diffDays} calendar day(s)`;
                preview.classList.remove('hidden');
            } else {
                countSpan.textContent = "Invalid range (Start date is after End date)";
                preview.classList.remove('hidden');
            }
        }

        function resetFormUI() {
            document.getElementById('other_leave_wrapper').classList.add('hidden');
            document.getElementById('days_preview').classList.add('hidden');
        }

        // Real-Time Running Clock (updates live every second without refreshing)
        function updateLiveClock() {
            const el = document.getElementById('liveClockText');
            if (!el) return;
            const now = new Date();
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const month = months[now.getMonth()];
            const day = String(now.getDate()).padStart(2, '0');
            const year = now.getFullYear();

            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';

            hours = hours % 12;
            hours = hours ? String(hours).padStart(2, '0') : '12';

            el.textContent = `${month} ${day}, ${year} ${hours}:${minutes}:${seconds} ${ampm}`;
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock();

        // 24-Hour Cooldown Countdown Timer
        let cooldownRemaining = <?= (int)$cooldownSecondsRemaining ?>;
        if (cooldownRemaining > 0) {
            const countdownBadge = document.getElementById('cooldownCountdownBadge');
            const alertBox = document.getElementById('cooldownAlertBox');
            const submitBtn = document.getElementById('submitLeaveBtn');
            const submitBtnText = document.getElementById('submitBtnText');

            const cooldownInterval = setInterval(function() {
                if (cooldownRemaining <= 0) {
                    clearInterval(cooldownInterval);
                    if (alertBox) {
                        alertBox.classList.add('hidden');
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.className = "bg-accent hover:bg-accenthover text-white font-semibold text-sm py-3 px-8 rounded-xl shadow-[0_0_20px_rgba(71,99,255,0.4)] transition-all flex items-center";
                    }
                    if (submitBtnText) {
                        submitBtnText.textContent = "Submit Leave Application";
                    }
                    return;
                }

                const hrs = Math.floor(cooldownRemaining / 3600);
                const mins = Math.floor((cooldownRemaining % 3600) / 60);
                const secs = cooldownRemaining % 60;

                if (countdownBadge) {
                    countdownBadge.textContent = `${hrs}h ${mins}m ${secs}s remaining`;
                }

                cooldownRemaining--;
            }, 1000);
        }

        // Initialize on page load in case values are preserved
        document.addEventListener('DOMContentLoaded', function() {
            const leaveTypeSelect = document.getElementById('leave_type_id');
            if (leaveTypeSelect && leaveTypeSelect.value === 'other') {
                handleLeaveTypeChange('other');
            }
            calculateDays();
        });
    </script>
</body>
</html>
