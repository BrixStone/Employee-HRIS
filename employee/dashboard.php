<?php
session_start();
require_once __DIR__ . '/../config/supabase.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit();
}

// Fetch the real user data
$stmt =$pdo->prepare("SELECT * FROM employee WHERE id = :id");
$stmt->execute(['id' =>$_SESSION['user_id']]);
$employeeData =$stmt->fetch();

if (!$employeeData) {
    session_destroy();
    header("Location: ../authentication/login.php");
    exit();
}

$userEmail = $employeeData['email'];$nameParts = explode(' ', trim($employeeData['name']));$firstName = $nameParts[0];$lastName = isset($nameParts[1]) ?$nameParts[1] : '';

$user = [
    'first_name' => htmlspecialchars($firstName),
    'last_name' => htmlspecialchars($lastName),
    'full_name' => htmlspecialchars($employeeData['name']),
    'email' => htmlspecialchars($userEmail),
    'role' => 'Employee', 
    'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($employeeData['name']) . '&background=4763ff&color=fff' 
];

// Fetch Real-Time Attendance & Calculate Total Weekly Hours
$stmtAtt =$pdo->prepare("SELECT time_in, time_out FROM attendance WHERE email = :email ORDER BY time_in DESC LIMIT 1");
$stmtAtt->execute(['email' =>$userEmail]);
$attendanceData =$stmtAtt->fetch();

$stmtWeekly =$pdo->prepare("SELECT SUM(workhours) as total_hours FROM attendance WHERE email = :email");
$stmtWeekly->execute(['email' => $userEmail]);$weeklyTotalResult = $stmtWeekly->fetch();$totalWeeklyHours = $weeklyTotalResult['total_hours'] ? (float)$weeklyTotalResult['total_hours'] : 0;

$todayLogText = 'No logs yet';
$progressPercent = min(100, round(($totalWeeklyHours / 40) * 100));

if ($attendanceData) {$timeIn = new DateTime($attendanceData['time_in']);$timeOutText = 'Current';
    if (!empty($attendanceData['time_out'])) {
        $timeOut = new DateTime($attendanceData['time_out']);
        $timeOutText =$timeOut->format('h:i A');
    }
    $todayLogText = $timeIn->format('h:i A') . ' - ' .$timeOutText;
}

// Fetch Real-Time Leave Balances from Supabase 'leave' table
$stmtLeave =$pdo->prepare("SELECT annual_leave, sick_leave, personal_leave FROM public.leave WHERE email = :email LIMIT 1");
$stmtLeave->execute(['email' =>$userEmail]);
$leaveData =$stmtLeave->fetch();

$timeOffBalances = [
    'annual' => $leaveData ? $leaveData['annual_leave'] : 0,
    'sick' => $leaveData ? $leaveData['sick_leave'] : 0,
    'personal' => $leaveData ? $leaveData['personal_leave'] : 0
];

// Dynamic Philippine Holidays for the current year (2026)
$currentYear = date('Y');$phHolidays = [
    ['name' => 'New Year\'s Day', 'date' => "January 1, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'Araw ng Kagitingan', 'date' => "April 9, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'Labor Day', 'date' => "May 1, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'Independence Day', 'date' => "June 12, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'National Heroes Day', 'date' => "August 31, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'All Saints\' Day', 'date' => "November 1, {$currentYear}", 'type' => 'Special Non-Working'],
    ['name' => 'Bonifacio Day', 'date' => "November 30, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'Christmas Day', 'date' => "December 25, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'Rizal Day', 'date' => "December 30, {$currentYear}", 'type' => 'Regular Holiday']
];

$todayObj = new DateTime();$upcomingHolidays = [];
foreach ($phHolidays as$hol) {
    $holDate = DateTime::createFromFormat('F j, Y',$hol['date']);
    if ($holDate &&$holDate >= $todayObj) {$upcomingHolidays[] = [
            'name' => $hol['name'],
            'date' => $holDate->format('l, M j, Y'),
            'type' => $hol['type']
        ];
    }
    if (count($upcomingHolidays) >= 3) break; 
}

if (empty($upcomingHolidays)) {
    $upcomingHolidays[] = ['name' => 'New Year\'s Day', 'date' => 'January 1, ' . ($currentYear + 1), 'type' => 'Regular Holiday'];
}

// Properly Defined Pay Period String for the Current Month
$payPeriodString = date('M 1 - M t, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - HRIS</title>
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
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 bg-[#1f1d2b] text-white rounded-lg group transition-colors border-l-2 border-accent">
                    <svg class="w-5 h-5 mr-3 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span class="font-medium text-sm text-accent">Dashboard</span>
                </a>
                <a href="profile.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <div class="w-5 h-5 mr-3 flex items-center justify-center"><div class="w-1.5 h-1.5 rounded-full bg-textmuted"></div></div>
                    <span class="font-medium text-sm">My Profile</span>
                </a>
                <a href="attendance.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <div class="w-5 h-5 mr-3 flex items-center justify-center"><div class="w-3 h-3 border-2 border-textmuted rounded-sm"></div></div>
                    <span class="font-medium text-sm">Attendance</span>
                </a>
                <a href="leave.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="font-medium text-sm">Leave</span>
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
                    <a href="../authentication/login.php" class="flex items-center px-4 py-2.5 text-red-400 hover:text-red-300 hover:bg-[#15131e] rounded-lg transition-colors">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
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
            <div class="relative w-96">
                <input type="text" placeholder="Search services, payslips..." class="w-full bg-[#1b0d35] text-sm text-white placeholder-textmuted rounded-lg pl-10 pr-4 py-2.5 focus:outline-none">
            </div>
            <div class="flex items-center space-x-6">
                <span id="live-datetime" class="text-sm text-textmuted font-medium tracking-wide"></span>
            </div>
        </header>

        <div class="p-8 max-w-[1400px] w-full mx-auto space-y-6">
            
            <!-- Row 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-cardbg border border-cardborder rounded-2xl p-8 relative overflow-hidden shadow-lg">
                    <div class="relative z-10">
                        <p class="text-xs font-bold tracking-wider text-[#06b6d4] mb-3 uppercase">Employee Dashboard</p>
                        <h2 class="text-3xl font-bold mb-4">Hello, <?= $user['first_name'] ?>! <span class="text-[#06b6d4]">Have a good and productive day.</span></h2>
                        <p class="text-sm text-textmuted max-w-xl leading-relaxed">
                            Your latest recorded shift started at <?= $attendanceData ? (new DateTime($attendanceData['time_in']))->format('h:i A') : 'N/A' ?>. Remember to complete your weekly self-assessment by Saturday.
                        </p>
                    </div>
                </div>

                <div class="lg:col-span-1 bg-cardbg border border-cardborder rounded-2xl p-6 shadow-lg">
                    <h3 class="text-base font-bold mb-5">Quick Actions</h3>
                    <div class="space-y-3">
                        <button class="w-full bg-accent hover:bg-accenthover text-white font-medium text-sm py-3 px-4 rounded-xl flex items-center transition-colors">
                            Request Time Off
                        </button>
                        <button class="w-full bg-[#1b0c35] hover:bg-[#251249] text-white font-medium text-sm py-3 px-4 rounded-xl flex items-center transition-colors border border-cardborder">
                            Download Latest Payslip
                        </button>
                    </div>
                </div>
            </div>

            <!-- Row 2 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-cardbg border border-cardborder rounded-2xl p-6 shadow-lg">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-base font-bold">Attendance This Week</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                            <p class="text-xs text-textmuted mb-1">Today's Log</p>
                            <p class="text-lg font-bold"><?= $todayLogText ?></p>
                        </div>
                        <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                            <p class="text-xs text-textmuted mb-1">Weekly Total</p>
                            <p class="text-lg font-bold text-[#06b6d4]"><?= number_format($totalWeeklyHours, 1) ?> Hours</p>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs mb-2">
                            <span class="text-textmuted">Progress to 40h target</span>
                            <span class="font-bold"><?= $progressPercent ?>%</span>
                        </div>
                        <div class="w-full bg-[#2a1a45] rounded-full h-1.5">
                            <div class="bg-accent h-1.5 rounded-full" style="width: <?= $progressPercent ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Time Off Balances -->
                <div class="bg-cardbg border border-cardborder rounded-2xl p-6 shadow-lg">
                    <h3 class="text-base font-bold mb-6">Time Off Balances</h3>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                            <p class="text-2xl font-bold text-[#06b6d4] mb-2"><?= $timeOffBalances['annual'] ?></p>
                            <p class="text-sm font-semibold mb-1">Annual Leave</p>
                            <p class="text-xs text-textmuted">Days available</p>
                        </div>
                        <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                            <p class="text-2xl font-bold text-[#c084fc] mb-2"><?= $timeOffBalances['sick'] ?></p>
                            <p class="text-sm font-semibold mb-1">Sick Leave</p>
                            <p class="text-xs text-textmuted">Days available</p>
                        </div>
                        <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                            <p class="text-2xl font-bold text-[#4ade80] mb-2"><?= $timeOffBalances['personal'] ?></p>
                            <p class="text-sm font-semibold mb-1">Personal Leave</p>
                            <p class="text-xs text-textmuted">Days available</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 3 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Upcoming PH Holidays -->
                <div class="bg-cardbg border border-cardborder rounded-2xl p-6 shadow-lg">
                    <h3 class="text-base font-bold mb-6">Upcoming Philippine Holidays</h3>
                    <div class="space-y-3">
                        <?php foreach($upcomingHolidays as$holiday): ?>
                        <div class="bg-[#150a25] border border-cardborder rounded-xl p-4 flex justify-between items-center">
                            <div>
                                <h4 class="font-semibold text-sm mb-1"><?= $holiday['name'] ?></h4>
                                <p class="text-xs text-textmuted"><?= $holiday['date'] ?></p>
                            </div>
                            <span class="text-xs font-semibold text-[#a78bfa]"><?= $holiday['type'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Payslip -->
                <div class="bg-cardbg border border-cardborder rounded-2xl p-6 shadow-lg flex flex-col justify-between">
                    <div>
                        <h3 class="text-base font-bold mb-6">Recent Payslip</h3>
                        <div class="bg-[#150a25] border border-cardborder rounded-xl overflow-hidden">
                            <div class="flex justify-between items-center p-4 border-b border-cardborder">
                                <span class="text-sm text-textmuted">Pay Period</span>
                                <span class="text-sm font-semibold"><?= $payPeriodString ?></span>
                            </div>
                            <div class="flex justify-between items-center p-4">
                                <span class="text-sm text-textmuted">Net Pay</span>
                                <span class="text-xl font-bold text-[#06b6d4]">$0.00</span>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-textmuted mt-4">
                        No active payroll disbursement for this period yet.
                    </p>
                </div>
            </div>

        </div>
    </main>

    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
            const formattedDate = now.toLocaleDateString('en-US', dateOptions);
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const formattedTime = now.toLocaleTimeString('en-US', timeOptions);
            document.getElementById('live-datetime').textContent = `${formattedDate} | ${formattedTime}`;
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>
</html>