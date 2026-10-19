<?php
session_start();
require_once __DIR__ . '/../config/supabase.php';

// Set timezone to Philippines (Asia/Manila) for accurate real-time logging
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM employee WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$employeeData = $stmt->fetch();

if (!$employeeData) {
    session_destroy();
    header("Location: ../authentication/login.php");
    exit();
}

$email = $employeeData['email'];
$message = '';
$error = '';

$currentTime = date('Y-m-d H:i:s');
$currentHour = (int)date('H');
$currentMinute = (int)date('i');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Check if employee already completed a shift today
    $todayCheckStmt = $pdo->prepare("
        SELECT id FROM attendance 
        WHERE email = :email 
        AND time_out IS NOT NULL 
        AND DATE(time_in) = CURRENT_DATE
    ");
    $todayCheckStmt->execute(['email' => $email]);
    $alreadyWorkedToday = $todayCheckStmt->fetch();

    if ($action === 'time_in') {
        if ($alreadyWorkedToday) {
            $error = "You have already completed your shift for today and cannot time in again.";
        } else {
            $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE email = :email AND time_out IS NULL ORDER BY time_in DESC LIMIT 1");
            $checkStmt->execute(['email' => $email]);
            
            if ($checkStmt->fetch()) {
                $error = "You already have an active shift or break. Please check your status.";
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO attendance (email, time_in) VALUES (:email, :time_in)");
                $insertStmt->execute(['email' => $email, 'time_in' => $currentTime]);
                $message = "Successfully Timed In at " . date('h:i:s A') . "!";
            }
        }
    } elseif ($action === 'break_time') {
        // FIXED: Using exact database column name "Break_Time" with quotes for PostgreSQL
        $findStmt = $pdo->prepare('SELECT id, time_in, "Break_Time" FROM attendance WHERE email = :email AND time_out IS NULL ORDER BY time_in DESC LIMIT 1');
        $findStmt->execute(['email' => $email]);
        $activeShift = $findStmt->fetch();

        if (!$activeShift) {
            $error = "You must Time In first before taking a break.";
        } elseif (!empty($activeShift['Break_Time'])) {
            $error = "You have already taken your break for this shift.";
        } else {
            // Rule: Break allowed only between 12:00 PM (12:00) and 1:00 PM (13:00)
            $currentDecimalTime = $currentHour + ($currentMinute / 60);
            if ($currentDecimalTime < 12.0 || $currentDecimalTime > 13.0) {
                $error = "Break time is only allowed between 12:00 PM and 1:00 PM. Current time is " . date('h:i A') . ".";
            } else {
                $updateBreak = $pdo->prepare('UPDATE attendance SET "Break_Time" = :break_time WHERE id = :id');
                $updateBreak->execute(['break_time' =>$currentTime, 'id' => $activeShift['id']]);$message = "Break Time recorded successfully at " . date('h:i:s A') . ".";
            }
        }
    } elseif ($action === 'time_out') {
        $findStmt =$pdo->prepare('SELECT id, time_in, "Break_Time" FROM attendance WHERE email = :email AND time_out IS NULL ORDER BY time_in DESC LIMIT 1');
        $findStmt->execute(['email' =>$email]);
        $activeShift =$findStmt->fetch();

        if (!$activeShift) {$error = "No active shift found to Time Out.";
        } else {
            $timeInObj = new DateTime($activeShift['time_in']);$timeOutObj = new DateTime($currentTime);$diffSeconds = $timeOutObj->getTimestamp() -$timeInObj->getTimestamp();
            $hours =$diffSeconds / 3600;

            // Subtract 1-hour break if break was recorded
            if (!empty($activeShift['Break_Time'])) {$hours -= 1.0; 
            }

            $workhours = round(max(0,$hours), 2);

            $updateStmt =$pdo->prepare("UPDATE attendance SET time_out = :time_out, workhours = :workhours WHERE id = :id");
            $updateStmt->execute([
                'time_out' => $currentTime,
                'workhours' => $workhours,
                'id' => $activeShift['id']
            ]);
            $message = "Successfully Timed Out! Total Work Hours (excluding break): {$workhours} hrs.";
        }
    }
}

// Fetch history (Using explicit quotes for "Break_Time")
$historyStmt =$pdo->prepare('SELECT id, created_at, email, time_in, "Break_Time", time_out, workhours FROM attendance WHERE email = :email ORDER BY time_in DESC');
$historyStmt->execute(['email' =>$email]);
$attendanceLogs =$historyStmt->fetchAll();

$nameParts = explode(' ', trim($employeeData['name']));$user = [
    'full_name' => htmlspecialchars($employeeData['name']),
    'email' => htmlspecialchars($employeeData['email']),
    'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($employeeData['name']) . '&background=4763ff&color=fff'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Dashboard - HRIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sidebar: '#0e0d13', mainbg: '#180a2b', cardbg: '#0a0514',
                        cardborder: '#281545', accent: '#4763ff', accenthover: '#354beb', textmuted: '#8b8994'
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-mainbg text-white h-screen overflow-hidden flex">

    <!-- Sidebar -->
    <aside class="w-64 bg-sidebar flex flex-col justify-between border-r border-cardborder">
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
                    <span class="font-medium text-sm">Dashboard</span>
                </a>
                <a href="profile.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <span class="font-medium text-sm">My Profile</span>
                </a>
                <a href="attendance.php" class="flex items-center px-4 py-2.5 bg-[#1f1d2b] text-white rounded-lg group transition-colors border-l-2 border-accent">
                    <span class="font-medium text-sm text-accent">Attendance</span>
                </a>
                <a href="file_leave.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <span class="font-medium text-sm">Leave</span>
                </a>
                <a href="payslips.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <span class="font-medium text-sm">Payslips</span>
                </a>
                <a href="settings.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <span class="font-medium text-sm">Settings</span>
                </a>
                <div class="pt-4 mt-4 border-t border-cardborder">
                    <a href="../authentication/login.php" class="flex items-center px-4 py-2.5 text-red-400 hover:text-red-300 hover:bg-[#15131e] rounded-lg transition-colors">
                        <span class="font-medium text-sm">Log Out</span>
                    </a>
                </div>
            </nav>
        </div>

        <div class="p-6 border-t border-cardborder">
            <div class="flex items-center space-x-3 overflow-hidden">
                <img src="<?= $user['avatar'] ?>" alt="Profile" class="w-10 h-10 rounded-full border border-gray-700 flex-shrink-0">
                <div class="truncate">
                    <h4 class="text-sm font-semibold truncate"><?= $user['full_name'] ?></h4>
                    <p class="text-xs text-textmuted truncate"><?= $user['email'] ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-y-auto">
        <header class="h-20 border-b border-cardborder flex items-center justify-between px-8 bg-mainbg sticky top-0 z-10">
            <h2 class="text-xl font-bold">Attendance Management</h2>
            <div class="flex items-center space-x-6">
                <span id="live-datetime" class="text-sm text-textmuted font-medium tracking-wide"></span>
            </div>
        </header>

        <div class="p-8 max-w-[1400px] w-full mx-auto space-y-6">
            
            <?php if (!empty($message)): ?>
                <div class="bg-teal-950 border border-teal-500 text-teal-300 px-4 py-3 rounded-xl text-sm">
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="bg-rose-950 border border-rose-500 text-rose-300 px-4 py-3 rounded-xl text-sm">
                    <?= htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- DTR Control Buttons -->
            <div class="bg-cardbg border border-cardborder rounded-2xl p-6 shadow-lg flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-bold">Daily Time Record (DTR)</h3>
                    <p class="text-xs text-textmuted mt-1">Break time allowed strictly between 12:00 PM and 1:00 PM.</p>
                </div>
                <div class="flex items-center space-x-3 w-full sm:w-auto">
                    <!-- Time In -->
                    <form method="POST">
                        <input type="hidden" name="action" value="time_in">
                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm py-2.5 px-5 rounded-xl transition-colors shadow-lg">
                             Time In
                        </button>
                    </form>
                    <!-- Break Time -->
                    <form method="POST">
                        <input type="hidden" name="action" value="break_time">
                        <button type="submit" class="bg-amber-600 hover:bg-amber-500 text-white font-medium text-sm py-2.5 px-5 rounded-xl transition-colors shadow-lg">
                            ☕ Break Time (12PM-1PM)
                        </button>
                    </form>
                    <!-- Time Out with Confirmation Popup -->
                    <form id="timeoutForm" method="POST">
                        <input type="hidden" name="action" value="time_out">
                        <button type="button" onclick="confirmTimeOut()" class="bg-rose-600 hover:bg-rose-500 text-white font-medium text-sm py-2.5 px-5 rounded-xl transition-colors shadow-lg">
                            ⏱️ Time Out
                        </button>
                    </form>
                </div>
            </div>

            <!-- Attendance History Table -->
            <div class="bg-cardbg border border-cardborder rounded-2xl p-6 shadow-lg">
                <h3 class="text-base font-bold mb-4">Attendance History</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-cardborder text-textmuted text-xs uppercase tracking-wider">
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Time In</th>
                                <th class="py-3 px-4">Break Time</th>
                                <th class="py-3 px-4">Time Out</th>
                                <th class="py-3 px-4">Work Hours</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cardborder text-sm">
                            <?php if (empty($attendanceLogs)): ?>
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-textmuted">No attendance logs found yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendanceLogs as$log): ?>
                                    <tr class="hover:bg-[#150a25] transition-colors">
                                        <td class="py-3 px-4 font-medium">
                                            <?= date('M d, Y', strtotime($log['time_in'])) ?>
                                        </td>
                                        <td class="py-3 px-4 text-emerald-400">
                                            <?= date('h:i:s A', strtotime($log['time_in'])) ?>
                                        </td>
                                        <td class="py-3 px-4 text-amber-400">
                                            <?= !empty($log['Break_Time']) ? date('h:i:s A', strtotime($log['Break_Time'])) : '<span class="text-textmuted italic">None</span>' ?>
                                        </td>
                                        <td class="py-3 px-4 text-rose-400">
                                            <?= !empty($log['time_out']) ? date('h:i:s A', strtotime($log['time_out'])) : '<span class="text-amber-400 italic">Active Shift...</span>' ?>
                                        </td>
                                        <td class="py-3 px-4 font-bold text-[#06b6d4]">
                                            <?= $log['workhours'] !== null ? number_format($log['workhours'], 2) . ' hrs' : '-' ?>
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

    <!-- JavaScript Confirmation Popup for Time Out -->
    <script>
        function confirmTimeOut() {
            if (confirm("Are you sure you want to Time Out? (Ensure you have logged your break time if applicable)")) {
                document.getElementById('timeoutForm').submit();
            }
        }

        function updateDateTime() {
            const now = new Date();
            const formattedDate = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
            const formattedTime = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            document.getElementById('live-datetime').textContent = `${formattedDate} | ${formattedTime}`;
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>
</html>