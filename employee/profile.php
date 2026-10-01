<?php
session_start();
require_once __DIR__ . '/../config/supabase.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit();
}

// Fetch the real employee data from Supabase including the 'status' column
$stmt =$pdo->prepare("SELECT * FROM employee WHERE id = :id");
$stmt->execute(['id' =>$_SESSION['user_id']]);
$employee =$stmt->fetch();

if (!$employee) {
    session_destroy();
    header("Location: ../authentication/login.php");
    exit();
}

$user = [
    'id' => $employee['id'],
    'name' => htmlspecialchars($employee['name']),
    'email' => htmlspecialchars($employee['email']),
    'phone_number' => htmlspecialchars($employee['phone_number'] ?? 'Not Provided'),
    'status' => htmlspecialchars($employee['status'] ?? 'Active'), // Fetched directly from the database column
    'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($employee['name']) . '&background=4763ff&color=fff'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - HRIS</title>
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
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span class="font-medium text-sm">Dashboard</span>
                </a>
                <a href="profile.php" class="flex items-center px-4 py-2.5 bg-[#1f1d2b] text-white rounded-lg group transition-colors border-l-2 border-accent">
                    <div class="w-5 h-5 mr-3 flex items-center justify-center"><div class="w-1.5 h-1.5 rounded-full bg-accent"></div></div>
                    <span class="font-medium text-sm text-accent">My Profile</span>
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
                    <h4 class="text-sm font-semibold truncate"><?= $user['name'] ?></h4>
                    <p class="text-xs text-textmuted truncate"><?= $user['email'] ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-y-auto">
        <header class="h-20 border-b border-cardborder flex items-center justify-between px-8 bg-mainbg sticky top-0 z-10">
            <h2 class="text-xl font-bold">My Profile</h2>
            <div class="flex items-center space-x-6">
                <span id="live-datetime" class="text-sm text-textmuted font-medium tracking-wide"></span>
            </div>
        </header>

        <div class="p-8 max-w-[1000px] w-full mx-auto space-y-6">
            
            <!-- Profile Card Header with Dynamic Status from Database -->
            <div class="bg-cardbg border border-cardborder rounded-2xl p-8 shadow-lg flex items-center space-x-6">
                <img src="<?= $user['avatar'] ?>" alt="Profile Avatar" class="w-24 h-24 rounded-full border-2 border-accent shadow-lg">
                <div>
                    <h3 class="text-2xl font-bold"><?= $user['name'] ?></h3>
                    <p class="text-textmuted text-sm mt-1"><?= $user['email'] ?></p>
                    <span class="inline-block mt-3 bg-[#042f2e] text-[#2dd4bf] text-xs font-semibold px-3 py-1 rounded-md">
                        <?= $user['status'] ?>
                    </span>
                </div>
            </div>

            <!-- Profile Details Grid -->
            <div class="bg-cardbg border border-cardborder rounded-2xl p-8 shadow-lg space-y-6">
                <h3 class="text-lg font-bold border-b border-cardborder pb-4">Personal Information</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                        <p class="text-xs text-textmuted mb-1">Employee ID</p>
                        <p class="text-base font-semibold">#<?= $user['id'] ?></p>
                    </div>

                    <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                        <p class="text-xs text-textmuted mb-1">Full Name</p>
                        <p class="text-base font-semibold"><?= $user['name'] ?></p>
                    </div>

                    <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                        <p class="text-xs text-textmuted mb-1">Email Address</p>
                        <p class="text-base font-semibold text-[#06b6d4]"><?= $user['email'] ?></p>
                    </div>

                    <div class="bg-[#150a25] border border-cardborder rounded-xl p-4">
                        <p class="text-xs text-textmuted mb-1">Phone Number</p>
                        <p class="text-base font-semibold"><?= $user['phone_number'] ?></p>
                    </div>

                    <div class="bg-[#150a25] border border-cardborder rounded-xl p-4 md:col-span-2">
                        <p class="text-xs text-textmuted mb-1">Employment Status</p>
                        <p class="text-base font-semibold text-emerald-400"><?= $user['status'] ?></p>
                    </div>
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