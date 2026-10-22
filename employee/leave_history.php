<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LeaveAuditLog.php';

date_default_timezone_set('Asia/Manila');

// 1. Check login session (same rules as file_leave.php)
if (empty($_SESSION['user_id']) || empty($_SESSION['employee_id'])) {
    header("Location: ../authentication/login.php");
    exit();
}

if (strtolower(trim($_SESSION['role'] ?? '')) !== 'employee') {
    http_response_code(403);
    exit('Access denied: Employee account required.');
}

$message = '';
$error = '';

// 2. Fetch the employee linked to the logged-in user account
$stmt = $pdo->prepare("
    SELECT e.id, e.first_name, e.last_name, e.email, e.phone, e.employment_status AS status
    FROM users AS u
    INNER JOIN employees AS e ON e.id = u.employee_id
    WHERE u.id = :user_id
      AND LOWER(TRIM(u.role)) = 'employee'
      AND u.status = 'Active'
    LIMIT 1
");
$stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
$employeeData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employeeData) {
    session_unset();
    session_destroy();
    header("Location: ../authentication/login.php");
    exit();
}

// leave_requests.employee_id references employees.id
$employeeId = (int)$employeeData['id'];
$employeeData['name'] = trim(($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''));
$email = $employeeData['email'] ?? '';

// 3. LeaveHistory Service Class
class LeaveHistoryService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getHistory(int $employeeId, string $status = '', string $typeId = '', string $year = '', string $search = ''): array
    {
        $sql = "
            SELECT
                lr.id,
                lr.employee_id,
                lr.leave_type_id,
                COALESCE(lt.leave_name, 'Custom / Other') AS leave_name,
                lr.start_date,
                lr.end_date,
                lr.number_of_days,
                lr.reason,
                lr.status,
                lr.approved_by,
                lr.approved_at,
                lr.created_at
            FROM leave_requests AS lr
            LEFT JOIN leave_types AS lt
                ON lt.id = lr.leave_type_id
            WHERE lr.employee_id = :employee_id
        ";

        $params = ['employee_id' => $employeeId];

        if ($status !== '' && $status !== 'all') {
            $sql .= " AND lr.status = :status";
            $params['status'] = $status;
        }

        if ($typeId !== '' && $typeId !== 'all') {
            $sql .= " AND lr.leave_type_id = :type_id";
            $params['type_id'] = (int)$typeId;
        }

        if ($year !== '' && $year !== 'all') {
            $sql .= " AND (EXTRACT(YEAR FROM lr.start_date) = :year OR EXTRACT(YEAR FROM lr.created_at) = :year)";
            $params['year'] = (int)$year;
        }

        if ($search !== '') {
            $sql .= " AND (
                LOWER(lr.reason) LIKE :search 
                OR LOWER(lt.leave_name) LIKE :search
            )";
            $params['search'] = '%' . strtolower(trim($search)) . '%';
        }

        $sql .= " ORDER BY lr.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats(int $employeeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total_filed,
                COALESCE(SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END), 0) AS total_approved,
                COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END), 0) AS total_pending,
                COALESCE(SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END), 0) AS total_rejected,
                COALESCE(SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END), 0) AS total_cancelled,
                COALESCE(SUM(CASE WHEN status = 'Approved' THEN number_of_days ELSE 0 END), 0) AS approved_days
            FROM leave_requests
            WHERE employee_id = :employee_id
        ");
        $stmt->execute(['employee_id' => $employeeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_filed' => 0,
            'total_approved' => 0,
            'total_pending' => 0,
            'total_rejected' => 0,
            'total_cancelled' => 0,
            'approved_days' => 0
        ];
    }

    public function getLeaveTypes(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, leave_name 
            FROM leave_types 
            ORDER BY leave_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAvailableYears(int $employeeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT YEAR(created_at) AS req_year
            FROM leave_requests
            WHERE employee_id = :employee_id
            ORDER BY req_year DESC
        ");
        $stmt->execute(['employee_id' => $employeeId]);
        $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $currentYear = (int)date('Y');
        if (!in_array($currentYear, $years)) {
            array_unshift($years, $currentYear);
        }
        return $years;
    }

    public function cancelRequest(int $requestId, int $employeeId, array $actor = []): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE leave_requests
            SET status = 'Cancelled'
            WHERE id = :id AND employee_id = :employee_id AND status = 'Pending'
        ");
        $stmt->execute(['id' => $requestId, 'employee_id' => $employeeId]);
        $cancelled = $stmt->rowCount() > 0;

        if ($cancelled) {
            (new LeaveAuditLog($this->pdo))->log(
                $requestId,
                $employeeId,
                LeaveAuditLog::ACTION_CANCELLED,
                'Pending',
                'Cancelled',
                $actor
            );
        }
        return $cancelled;
    }
}

$historyService = new LeaveHistoryService($pdo);

// 4. Handle Cancel Request Action
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['action'] ?? '') === 'cancel_request'
) {
    $cancelId = (int)($_POST['request_id'] ?? 0);
    if ($cancelId > 0) {
        $cancelActor = [
            'id'    => $employeeId,
            'name'  => $employeeData['name'] ?? null,
            'email' => $employeeData['email'] ?? null,
            'role'  => $_SESSION['role'] ?? 'employee',
        ];
        if ($historyService->cancelRequest($cancelId, $employeeId, $cancelActor)) {
            $message = "Leave application #{$cancelId} has been successfully cancelled.";
        } else {
            $error = "Unable to cancel request #{$cancelId}. Only requests with 'Pending' status can be cancelled.";
        }
    }
}

// 5. Query Filters
$filterStatus = trim($_GET['status'] ?? 'all');
$filterType = trim($_GET['type'] ?? 'all');
$filterYear = trim($_GET['year'] ?? 'all');
$searchKeyword = trim($_GET['q'] ?? '');

$leaveLogs = $historyService->getHistory($employeeId, $filterStatus, $filterType, $filterYear, $searchKeyword);
$stats = $historyService->getStats($employeeId);
$availableTypes = $historyService->getLeaveTypes();
$availableYears = $historyService->getAvailableYears($employeeId);

// 5b. Full transaction history (Filed / Cancelled / Approved / Rejected) with the
//     account that performed each action.
try {
    $transactions = (new LeaveAuditLog($pdo))->getTransactionsForEmployee($employeeId, $employeeData);
} catch (PDOException $e) {
    error_log('Leave transaction history error: ' . $e->getMessage());
    $transactions = [];
    if ($error === '') {
        $error = 'Unable to load the leave transaction history.';
    }
}
$latestReview = LeaveAuditLog::latestReviewByRequest($transactions);

$txCounts = ['all' => count($transactions), 'Filed' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
foreach ($transactions as $tx) {
    if (isset($txCounts[$tx['action']])) {
        $txCounts[$tx['action']]++;
    }
}

/** Display name for the account behind a transaction. */
function actorLabel(array $tx, int $selfId): string
{
    $name = trim((string)($tx['actor_name'] ?? ''));
    if ($name === '') {
        $name = !empty($tx['actor_id']) ? 'Account #' . $tx['actor_id'] : 'System';
    }
    $isSelf = ($tx['actor_role'] ?? '') === 'employee' && (int)($tx['actor_id'] ?? 0) === $selfId;
    return $isSelf ? $name . ' (You)' : $name;
}

function roleLabel(?string $role): string
{
    return match (strtolower((string)$role)) {
        'hr'       => 'HR',
        'admin'    => 'Admin',
        'employee' => 'Employee',
        ''         => 'Unknown',
        default    => ucfirst((string)$role),
    };
}

// 6. Fetch leave balances for overview cards
try {
    $stmtLeaveBal = $pdo->prepare("
        SELECT annual_leave, sick_leave, personal_leave
        FROM `leave`
        WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email))
        LIMIT 1
    ");
    $stmtLeaveBal->execute(['email' => $email]);
    $leaveBalData = $stmtLeaveBal->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
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
    <title>Leave History & Applications - HRIS</title>
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
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #180a2b;
        }
        ::-webkit-scrollbar-thumb {
            background: #281545;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #4763ff;
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
        <!-- Header with Live Running Clock & Exit Button -->
        <header class="min-h-20 border-b border-cardborder flex items-center justify-between px-8 py-4 bg-mainbg sticky top-0 z-10">
            <div>
                <h2 class="text-xl font-bold">Leave History & Review Records</h2>
                <p class="text-xs text-textmuted mt-0.5">Comprehensive audit trail of all filed leave requests and review outcomes</p>
            </div>
            <div class="flex items-center space-x-3">
                <span class="text-xs bg-[#1f1d2b] px-3.5 py-2 rounded-xl border border-cardborder text-gray-200 font-mono tracking-tight flex items-center shadow-inner">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 mr-2.5 inline-block animate-pulse shadow-[0_0_8px_rgba(52,211,153,0.8)]"></span>
                    <span id="liveClockText"><?= date('M d, Y h:i:s A') ?></span>
                </span>
                <!-- Real-time connection status (asset/js/realtime.js) -->
                <span class="text-xs bg-[#1f1d2b] px-3 py-2 rounded-xl border border-cardborder text-textmuted" data-realtime-status>Offline</span>

                <!-- Exit Button: Returns to Leave Management (file_leave.php) -->
                <a
                    href="file_leave.php"
                    target="_top"
                    title="Exit and return to Leave Management"
                    class="flex items-center space-x-2 text-xs font-semibold bg-rose-950/80 hover:bg-rose-900 border border-rose-600/70 hover:border-rose-500 text-rose-200 hover:text-white px-4 py-2 rounded-xl transition-all shadow-md group"
                >
                    <svg class="w-4 h-4 text-rose-400 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    <span>Exit to Leave Management</span>
                </a>
            </div>
        </header>

        <div class="p-8 max-w-[1400px] w-full mx-auto space-y-6">

            <!-- Sub Navigation Tabs with Exit Shortcut -->
            <div class="flex items-center justify-between border-b border-cardborder pb-4">
                <div class="flex space-x-2">
                    <a href="file_leave.php" class="px-5 py-2.5 text-xs font-semibold rounded-xl text-textmuted hover:text-white hover:bg-[#1f1d2b] transition-all flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        File a Leave Request
                    </a>
                    <a href="leave_history.php" class="px-5 py-2.5 text-xs font-semibold rounded-xl bg-accent text-white shadow-[0_0_15px_rgba(71,99,255,0.4)] transition-all flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Leave History & Logs
                    </a>
                </div>

                <!-- Secondary Exit Link to Leave Management -->
                <a
                    href="file_leave.php"
                    target="_top"
                    class="inline-flex items-center text-xs font-semibold bg-[#1f1d2b] hover:bg-rose-950/50 hover:border-rose-600/50 border border-cardborder px-4 py-2.5 rounded-xl text-gray-300 hover:text-rose-300 transition-colors"
                >
                    <svg class="w-4 h-4 mr-1.5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to Leave Management
                </a>
            </div>

            <!-- Flash Notifications -->
            <?php if ($message !== ''): ?>
                <div class="bg-teal-950/80 border border-teal-500 text-teal-300 px-5 py-4 rounded-xl flex items-center shadow-lg">
                    <svg class="w-5 h-5 mr-3 shrink-0 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <p class="text-sm font-medium"><?= e($message) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="bg-rose-950/80 border border-rose-500 text-rose-300 px-5 py-4 rounded-xl flex items-center shadow-lg">
                    <svg class="w-5 h-5 mr-3 shrink-0 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <p class="text-sm font-medium"><?= e($error) ?></p>
                </div>
            <?php endif; ?>

            <!-- Metric & Statistics Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-cardbg border border-cardborder rounded-2xl p-5 hover:border-accent/40 transition-colors">
                    <div class="flex items-center justify-between text-textmuted mb-2">
                        <span class="text-xs font-medium uppercase tracking-wider">Total Filed</span>
                        <div class="w-7 h-7 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-white"><?= (int)$stats['total_filed'] ?></p>
                    <p class="text-[11px] text-textmuted mt-1">Lifetime applications</p>
                </div>

                <div class="bg-cardbg border border-cardborder rounded-2xl p-5 hover:border-emerald-500/40 transition-colors">
                    <div class="flex items-center justify-between text-textmuted mb-2">
                        <span class="text-xs font-medium uppercase tracking-wider">Approved</span>
                        <div class="w-7 h-7 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-emerald-400"><?= (int)$stats['total_approved'] ?></p>
                    <p class="text-[11px] text-textmuted mt-1"><?= (float)$stats['approved_days'] ?> approved day(s)</p>
                </div>

                <div class="bg-cardbg border border-cardborder rounded-2xl p-5 hover:border-amber-500/40 transition-colors">
                    <div class="flex items-center justify-between text-textmuted mb-2">
                        <span class="text-xs font-medium uppercase tracking-wider">Pending Review</span>
                        <div class="w-7 h-7 rounded-lg bg-amber-500/10 flex items-center justify-center text-amber-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-amber-400"><?= (int)$stats['total_pending'] ?></p>
                    <p class="text-[11px] text-textmuted mt-1">Awaiting HR decision</p>
                </div>

                <div class="bg-cardbg border border-cardborder rounded-2xl p-5 hover:border-rose-500/40 transition-colors">
                    <div class="flex items-center justify-between text-textmuted mb-2">
                        <span class="text-xs font-medium uppercase tracking-wider">Rejected / Cancelled</span>
                        <div class="w-7 h-7 rounded-lg bg-rose-500/10 flex items-center justify-center text-rose-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-rose-400"><?= (int)$stats['total_rejected'] + (int)$stats['total_cancelled'] ?></p>
                    <p class="text-[11px] text-textmuted mt-1"><?= (int)$stats['total_rejected'] ?> rejected, <?= (int)$stats['total_cancelled'] ?> cancelled</p>
                </div>
            </div>

            <!-- Filters, Search & Action Bar -->
            <div class="bg-cardbg border border-cardborder rounded-2xl p-5 shadow-lg">
                <form method="GET" action="leave_history.php" class="grid grid-cols-1 md:grid-cols-12 gap-3.5 items-end">
                    <!-- Search Input -->
                    <div class="md:col-span-4">
                        <label for="searchInput" class="block text-xs text-textmuted mb-1 font-medium">Search Applications</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-textmuted">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            </span>
                            <input
                                type="text"
                                id="searchInput"
                                name="q"
                                value="<?= e($searchKeyword) ?>"
                                placeholder="Search by reason or leave type..."
                                class="w-full bg-[#1b0d35] text-xs text-white border border-cardborder rounded-xl pl-9 pr-3.5 py-2.5 focus:outline-none focus:border-accent transition-colors"
                            >
                        </div>
                    </div>

                    <!-- Status Filter -->
                    <div class="md:col-span-2">
                        <label for="statusSelect" class="block text-xs text-textmuted mb-1 font-medium">Status</label>
                        <select
                            id="statusSelect"
                            name="status"
                            class="w-full bg-[#1b0d35] text-xs text-white border border-cardborder rounded-xl px-3 py-2.5 focus:outline-none focus:border-accent transition-colors"
                        >
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="Rejected" <?= $filterStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>

                    <!-- Leave Type Filter -->
                    <div class="md:col-span-3">
                        <label for="typeSelect" class="block text-xs text-textmuted mb-1 font-medium">Leave Type</label>
                        <select
                            id="typeSelect"
                            name="type"
                            class="w-full bg-[#1b0d35] text-xs text-white border border-cardborder rounded-xl px-3 py-2.5 focus:outline-none focus:border-accent transition-colors"
                        >
                            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Leave Types</option>
                            <?php foreach ($availableTypes as $t): ?>
                                <option
                                    value="<?= (int)$t['id'] ?>"
                                    <?= (string)$filterType === (string)$t['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($t['leave_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Year Filter -->
                    <div class="md:col-span-2">
                        <label for="yearSelect" class="block text-xs text-textmuted mb-1 font-medium">Year</label>
                        <select
                            id="yearSelect"
                            name="year"
                            class="w-full bg-[#1b0d35] text-xs text-white border border-cardborder rounded-xl px-3 py-2.5 focus:outline-none focus:border-accent transition-colors"
                        >
                            <option value="all" <?= $filterYear === 'all' ? 'selected' : '' ?>>All Years</option>
                            <?php foreach ($availableYears as $yr): ?>
                                <option
                                    value="<?= (int)$yr ?>"
                                    <?= (string)$filterYear === (string)$yr ? 'selected' : '' ?>
                                >
                                    <?= (int)$yr ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Action Buttons -->
                    <div class="md:col-span-1 flex space-x-1.5">
                        <button
                            type="submit"
                            title="Filter records"
                            class="w-full bg-accent hover:bg-accenthover text-white text-xs py-2.5 px-3 rounded-xl font-medium transition-colors flex items-center justify-center shadow-md"
                        >
                            Filter
                        </button>
                        <?php if ($filterStatus !== 'all' || $filterType !== 'all' || $filterYear !== 'all' || $searchKeyword !== ''): ?>
                            <a
                                href="leave_history.php"
                                title="Reset all filters"
                                class="bg-[#1b0d35] hover:bg-[#281545] border border-cardborder text-textmuted hover:text-white text-xs py-2.5 px-2.5 rounded-xl transition-colors flex items-center justify-center"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Leave History Table -->
            <div class="bg-cardbg border border-cardborder rounded-2xl shadow-xl overflow-hidden">
                <div class="p-6 border-b border-cardborder flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-accent mr-2.5"></span>
                            Leave Records & Status History
                        </h3>
                        <p class="text-xs text-textmuted mt-0.5">
                            Showing <?= count($leaveLogs) ?> request(s) matching your criteria
                        </p>
                    </div>

                    <div class="flex items-center space-x-2 text-xs">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-emerald-950/70 border border-emerald-500/40 text-emerald-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                            Approved: <?= (int)$stats['total_approved'] ?>
                        </span>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-amber-950/70 border border-amber-500/40 text-amber-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 mr-1.5 animate-pulse"></span>
                            Pending: <?= (int)$stats['total_pending'] ?>
                        </span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-cardborder text-textmuted text-xs uppercase tracking-wider bg-[#10071f]/60">
                                <th class="py-3.5 px-4 font-semibold">Ref #</th>
                                <th class="py-3.5 px-4 font-semibold">Leave Type</th>
                                <th class="py-3.5 px-4 font-semibold">Date Filed</th>
                                <th class="py-3.5 px-4 font-semibold">Duration Period</th>
                                <th class="py-3.5 px-4 font-semibold text-center">Days</th>
                                <th class="py-3.5 px-4 font-semibold">Reason</th>
                                <th class="py-3.5 px-4 font-semibold">Review Remarks</th>
                                <th class="py-3.5 px-4 font-semibold">Status</th>
                                <th class="py-3.5 px-4 font-semibold text-right">Action</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-cardborder text-sm" id="historyTableBody">
                            <?php if (empty($leaveLogs)): ?>
                                <tr>
                                    <td colspan="9" class="py-12 text-center text-textmuted">
                                        <div class="flex flex-col items-center justify-center space-y-2">
                                            <div class="w-12 h-12 rounded-full bg-[#1b0d35] border border-cardborder flex items-center justify-center text-textmuted">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                            </div>
                                            <p class="font-medium text-white">No leave records found</p>
                                            <p class="text-xs text-textmuted max-w-sm">
                                                <?= ($filterStatus !== 'all' || $searchKeyword !== '') ? 'Try clearing or modifying your filter criteria.' : 'You have not submitted any leave applications yet.' ?>
                                            </p>
                                            <a href="file_leave.php" class="mt-2 inline-flex items-center text-xs text-accent hover:underline font-semibold">
                                                + File your first leave application &rarr;
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($leaveLogs as $log): ?>
                                    <tr class="hover:bg-[#150a25] transition-colors group">
                                        <!-- Ref ID -->
                                        <td class="py-4 px-4 text-xs font-mono text-cyan-400 font-semibold">
                                            #<?= str_pad((string)$log['id'], 5, '0', STR_PAD_LEFT) ?>
                                        </td>

                                        <!-- Leave Type -->
                                        <td class="py-4 px-4">
                                            <div class="flex items-center">
                                                <div class="w-2 h-2 rounded-full bg-accent mr-2.5"></div>
                                                <span class="font-semibold text-white"><?= e($log['leave_name']) ?></span>
                                            </div>
                                        </td>

                                        <!-- Date Filed -->
                                        <td class="py-4 px-4 text-xs text-textmuted whitespace-nowrap">
                                            <?= !empty($log['created_at']) ? e(date('M d, Y h:i A', strtotime($log['created_at']))) : '—' ?>
                                        </td>

                                        <!-- Duration -->
                                        <td class="py-4 px-4 text-xs whitespace-nowrap">
                                            <span class="text-white font-medium"><?= e(date('M d, Y', strtotime($log['start_date']))) ?></span>
                                            <span class="text-textmuted mx-1">to</span>
                                            <span class="text-white font-medium"><?= e(date('M d, Y', strtotime($log['end_date']))) ?></span>
                                        </td>

                                        <!-- Days -->
                                        <td class="py-4 px-4 text-center">
                                            <span class="font-bold text-cyan-400 bg-cyan-950/60 border border-cyan-800/50 px-2.5 py-0.5 rounded-md text-xs">
                                                <?= (float)$log['number_of_days'] ?> day(s)
                                            </span>
                                        </td>

                                        <!-- Reason -->
                                        <td class="py-4 px-4 text-xs text-gray-300 max-w-xs break-words">
                                            <?= e($log['reason'] ?? '—') ?>
                                        </td>

                                        <!-- Review Details -->
                                        <?php $rev = $latestReview[(int)$log['id']] ?? null; ?>
                                        <td class="py-4 px-4 text-xs text-textmuted">
                                            <?php if ($log['status'] === 'Approved'): ?>
                                                <span class="text-emerald-300 font-medium flex items-center">
                                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                    Approved by <?= e($rev ? actorLabel($rev, $employeeId) : 'HR') ?>
                                                </span>
                                                <span class="block text-[11px] text-textmuted mt-0.5">
                                                    <?= !empty($log['approved_at']) ? e(date('M d, Y h:i A', strtotime($log['approved_at']))) : '' ?>
                                                </span>
                                            <?php elseif ($log['status'] === 'Rejected'): ?>
                                                <span class="text-rose-300 font-medium flex items-center">
                                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                    Declined by <?= e($rev ? actorLabel($rev, $employeeId) : 'HR') ?>
                                                </span>
                                                <span class="block text-[11px] text-textmuted mt-0.5">
                                                    <?= !empty($log['approved_at']) ? e(date('M d, Y h:i A', strtotime($log['approved_at']))) : '' ?>
                                                </span>
                                            <?php elseif ($log['status'] === 'Cancelled'): ?>
                                                <span class="text-gray-400 italic">Withdrawn by <?= e($rev ? actorLabel($rev, $employeeId) : 'Employee') ?></span>
                                            <?php else: ?>
                                                <span class="italic text-amber-400/90 flex items-center">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 mr-1.5 animate-pulse"></span>
                                                    Awaiting HR Review
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status Badge -->
                                        <td class="py-4 px-4 whitespace-nowrap">
                                            <?php if ($log['status'] === 'Approved'): ?>
                                                <span class="inline-flex items-center bg-emerald-950/80 border border-emerald-500/50 text-emerald-300 text-xs px-2.5 py-1 rounded-md font-medium">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                                                    Approved
                                                </span>
                                            <?php elseif ($log['status'] === 'Rejected'): ?>
                                                <span class="inline-flex items-center bg-rose-950/80 border border-rose-500/50 text-rose-300 text-xs px-2.5 py-1 rounded-md font-medium">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400 mr-1.5"></span>
                                                    Rejected
                                                </span>
                                            <?php elseif ($log['status'] === 'Cancelled'): ?>
                                                <span class="inline-flex items-center bg-gray-900 border border-gray-700 text-gray-400 text-xs px-2.5 py-1 rounded-md font-medium">
                                                    Cancelled
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center bg-amber-950/80 border border-amber-500/50 text-amber-300 text-xs px-2.5 py-1 rounded-md font-medium">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 mr-1.5 animate-pulse"></span>
                                                    Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Action / Cancel Button -->
                                        <td class="py-4 px-4 text-right whitespace-nowrap">
                                            <?php if ($log['status'] === 'Pending'): ?>
                                                <form method="POST" action="leave_history.php" class="inline" onsubmit="return confirm('Are you sure you want to cancel this leave application (#<?= (int)$log['id'] ?>)?');">
                                                    <input type="hidden" name="action" value="cancel_request">
                                                    <input type="hidden" name="request_id" value="<?= (int)$log['id'] ?>">
                                                    <button
                                                        type="submit"
                                                        class="text-xs bg-rose-950/50 hover:bg-rose-900 border border-rose-700/50 text-rose-300 hover:text-white px-3 py-1.5 rounded-lg transition-colors flex items-center ml-auto"
                                                    >
                                                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                        Cancel
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-xs text-textmuted/40">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ================= Transaction History (Audit Trail) ================= -->
            <?php
            $txStyles = [
                'Filed'     => ['dot' => 'bg-blue-400',    'badge' => 'bg-blue-950/80 border-blue-500/50 text-blue-300',          'verb' => 'Filed by'],
                'Approved'  => ['dot' => 'bg-emerald-400', 'badge' => 'bg-emerald-950/80 border-emerald-500/50 text-emerald-300', 'verb' => 'Approved by'],
                'Rejected'  => ['dot' => 'bg-rose-400',    'badge' => 'bg-rose-950/80 border-rose-500/50 text-rose-300',          'verb' => 'Rejected by'],
                'Cancelled' => ['dot' => 'bg-gray-400',    'badge' => 'bg-gray-900 border-gray-700 text-gray-300',                'verb' => 'Cancelled by'],
            ];
            $statusText = static function (?string $s): string {
                return match ($s) {
                    'Pending'   => 'text-amber-300',
                    'Approved'  => 'text-emerald-300',
                    'Rejected'  => 'text-rose-300',
                    'Cancelled' => 'text-gray-400',
                    default     => 'text-textmuted',
                };
            };
            ?>
            <div class="bg-cardbg border border-cardborder rounded-2xl shadow-xl overflow-hidden" id="transactionHistory">
                <div class="p-6 border-b border-cardborder flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-cyan-400 mr-2.5"></span>
                            Transaction History
                        </h3>
                        <p class="text-xs text-textmuted mt-0.5">
                            Every action on your leave requests (filed, approved, rejected, cancelled) and the account that performed it
                        </p>
                    </div>

                    <!-- Action filter (client-side) -->
                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filter transactions by action">
                        <?php foreach (['all' => 'All', 'Filed' => 'Filed / Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected', 'Cancelled' => 'Cancelled'] as $key => $label): ?>
                            <button
                                type="button"
                                data-tx-filter="<?= e($key) ?>"
                                aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"
                                class="tx-filter-btn text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors <?= $key === 'all' ? 'bg-accent border-accent text-white' : 'bg-[#1b0d35] border-cardborder text-textmuted hover:text-white' ?>"
                            >
                                <?= e($label) ?> <span class="opacity-70">(<?= (int)$txCounts[$key] ?>)</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-cardborder text-textmuted text-xs uppercase tracking-wider bg-[#10071f]/60">
                                <th scope="col" class="py-3.5 px-4 font-semibold">Date &amp; Time</th>
                                <th scope="col" class="py-3.5 px-4 font-semibold">Ref #</th>
                                <th scope="col" class="py-3.5 px-4 font-semibold">Leave</th>
                                <th scope="col" class="py-3.5 px-4 font-semibold">Action</th>
                                <th scope="col" class="py-3.5 px-4 font-semibold">Status Change</th>
                                <th scope="col" class="py-3.5 px-4 font-semibold">Performed By</th>
                                <th scope="col" class="py-3.5 px-4 font-semibold">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cardborder text-sm" id="txTableBody">
                            <?php if (empty($transactions)): ?>
                                <tr data-tx-empty>
                                    <td colspan="7" class="py-10 text-center text-textmuted text-sm">No transactions recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $tx):
                                    $style = $txStyles[$tx['action']] ?? $txStyles['Filed'];
                                ?>
                                    <tr class="hover:bg-[#150a25] transition-colors" data-tx-action="<?= e($tx['action']) ?>">
                                        <td class="py-3.5 px-4 text-xs whitespace-nowrap">
                                            <?php if (!empty($tx['occurred_at'])): ?>
                                                <span class="text-white"><?= e(date('M d, Y', strtotime($tx['occurred_at']))) ?></span>
                                                <span class="block text-[11px] text-textmuted"><?= e(date('h:i A', strtotime($tx['occurred_at']))) ?></span>
                                            <?php else: ?>
                                                <span class="text-textmuted italic" title="This action happened before transaction logging was enabled, so its exact time was not stored.">Time not recorded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-xs font-mono text-cyan-400 font-semibold">
                                            #<?= str_pad((string)$tx['request_id'], 5, '0', STR_PAD_LEFT) ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-xs">
                                            <span class="font-semibold text-white"><?= e($tx['leave_name']) ?></span>
                                            <?php if (!empty($tx['start_date'])): ?>
                                                <span class="block text-[11px] text-textmuted">
                                                    <?= e(date('M d', strtotime($tx['start_date']))) ?> – <?= e(date('M d, Y', strtotime($tx['end_date']))) ?>
                                                    · <?= (float)$tx['number_of_days'] ?> day(s)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 whitespace-nowrap">
                                            <span class="inline-flex items-center border text-xs px-2.5 py-1 rounded-md font-medium <?= $style['badge'] ?>">
                                                <span class="w-1.5 h-1.5 rounded-full mr-1.5 <?= $style['dot'] ?>"></span>
                                                <?= e($tx['action']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 text-xs whitespace-nowrap">
                                            <?php if (!empty($tx['old_status'])): ?>
                                                <span class="<?= $statusText($tx['old_status']) ?>"><?= e($tx['old_status']) ?></span>
                                                <span class="text-textmuted mx-1" aria-hidden="true">&rarr;</span>
                                                <span class="sr-only">to</span>
                                            <?php else: ?>
                                                <span class="text-textmuted">New</span>
                                                <span class="text-textmuted mx-1" aria-hidden="true">&rarr;</span>
                                                <span class="sr-only">to</span>
                                            <?php endif; ?>
                                            <span class="font-semibold <?= $statusText($tx['new_status']) ?>"><?= e($tx['new_status'] ?? '—') ?></span>
                                        </td>
                                        <td class="py-3.5 px-4 text-xs">
                                            <div class="flex items-center space-x-2.5">
                                                <img
                                                    src="https://ui-avatars.com/api/?name=<?= urlencode(trim((string)($tx['actor_name'] ?? '')) ?: 'System') ?>&background=281545&color=fff&size=64"
                                                    alt=""
                                                    class="w-7 h-7 rounded-full border border-cardborder shrink-0"
                                                >
                                                <div class="min-w-0">
                                                    <p class="font-semibold text-white truncate">
                                                        <span class="sr-only"><?= e($style['verb']) ?> </span><?= e(actorLabel($tx, $employeeId)) ?>
                                                    </p>
                                                    <p class="text-[11px] text-textmuted truncate">
                                                        <?= e(roleLabel($tx['actor_role'])) ?>
                                                        <?php if (!empty($tx['actor_email'])): ?>
                                                            · <?= e($tx['actor_email']) ?>
                                                        <?php elseif (!empty($tx['actor_id'])): ?>
                                                            · ID #<?= (int)$tx['actor_id'] ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4 text-xs text-gray-300 max-w-xs break-words">
                                            <?= !empty($tx['remarks']) ? e($tx['remarks']) : '<span class="text-textmuted/50">—</span>' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr data-tx-no-match class="hidden">
                                    <td colspan="7" class="py-10 text-center text-textmuted text-sm">No transactions for this filter.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script>
        // Real-Time Running Clock
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

        // Transaction History action filter
        (function () {
            const buttons = document.querySelectorAll('.tx-filter-btn');
            const rows = document.querySelectorAll('#txTableBody tr[data-tx-action]');
            const noMatch = document.querySelector('#txTableBody tr[data-tx-no-match]');
            const active = ['bg-accent', 'border-accent', 'text-white'];
            const idle = ['bg-[#1b0d35]', 'border-cardborder', 'text-textmuted'];

            buttons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const filter = btn.dataset.txFilter;
                    let shown = 0;
                    rows.forEach(row => {
                        const match = filter === 'all' || row.dataset.txAction === filter;
                        row.classList.toggle('hidden', !match);
                        if (match) shown++;
                    });
                    if (noMatch) noMatch.classList.toggle('hidden', shown > 0);

                    buttons.forEach(b => {
                        const on = b === btn;
                        b.setAttribute('aria-pressed', on ? 'true' : 'false');
                        active.forEach(c => b.classList.toggle(c, on));
                        idle.forEach(c => b.classList.toggle(c, !on));
                    });
                });
            });
        })();

        // Optional Live Client Search
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const term = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('#historyTableBody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(term) || row.querySelector('td[colspan]')) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
    </script>
    <!-- Real-time updates: only this employee's own leave events arrive here -->
    <script src="../asset/js/realtime.js" data-refresh="auto" defer></script>
</body>
</html>