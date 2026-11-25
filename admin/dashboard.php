<?php
require_once __DIR__ . '/../authentication/guard.php';
require_access('user_management', 'view', 'all');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$cssPageOverride = 'admin_dashboard';

// ── Calendar month — supports ?m=YYYY-MM ─────────────────────────────────────
$rawM = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawM)) {
    $rawM = date('Y-m');
}
[$calYear, $calMonth] = array_map('intval', explode('-', $rawM));
$calToday     = (date('n') == $calMonth && date('Y') == $calYear) ? (int)date('j') : 0;
$calFirstDay  = (int)date('N', mktime(0, 0, 0, $calMonth, 1, $calYear)) % 7;
$calDays      = (int)date('t', mktime(0, 0, 0, $calMonth, 1, $calYear));
$calMonthName = date('F Y', mktime(0, 0, 0, $calMonth, 1, $calYear));

// Prev / Next month links
$prevTS   = mktime(0, 0, 0, $calMonth - 1, 1, $calYear);
$nextTS   = mktime(0, 0, 0, $calMonth + 1, 1, $calYear);
$prevHref = '?m=' . date('Y-m', $prevTS);
$nextHref = '?m=' . date('Y-m', $nextTS);

// Last 12 months for select dropdown
$monthOptions = [];

for ($i = 1; $i <= 12; $i++) {
    $monthOptions[] = [
        'val' => str_pad($i, 2, '0', STR_PAD_LEFT),
        'lbl' => date('F', mktime(0, 0, 0, $i, 1))
    ];
}

$currentYear = (int)date('Y');

$yearOptions = range($currentYear, $currentYear - 5);

// ── Today's login activity ────────────────────────────────────────────────────
$loggedTodayStmt = $pdo->query("
    SELECT COUNT(DISTINCT actor_user_id)
    FROM audit_logs
    WHERE action = 'login'
      AND DATE(created_at) = CURDATE()
      AND actor_user_id IS NOT NULL
");
$loggedToday = (int)$loggedTodayStmt->fetchColumn();

try {
    $onlineStmt = $pdo->query("
        SELECT COUNT(*) FROM users
        WHERE last_activity >= NOW() - INTERVAL 15 MINUTE
          AND status = 'Active'
    ");
    $currentlyOnline = (int)$onlineStmt->fetchColumn();
} catch (Throwable $e) {
    // last_activity column may not exist yet on this database — it will be
    // created on the next login via guard.php's self-healing ALTER.
    $currentlyOnline = 0;
    error_log('admin/dashboard.php last_activity query failed: ' . $e->getMessage());
}

$totalActiveStmt = $pdo->query("
    SELECT COUNT(*) FROM employees WHERE employment_status = 'Active'
");
$totalActive = (int)$totalActiveStmt->fetchColumn();

$totalOffline = max(0, $loggedToday - $currentlyOnline);

// ── Pending leave ─────────────────────────────────────────────────────────────
$pendingLeaveStmt = $pdo->query("
    SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'
");
$pendingLeave = (int)$pendingLeaveStmt->fetchColumn();

// ── Employee growth ───────────────────────────────────────────────────────────
$growthStmt = $pdo->query("
    SELECT MONTH(date_hired) AS m, COUNT(*) AS cnt
    FROM employees
    WHERE YEAR(date_hired) = YEAR(CURDATE())
    GROUP BY MONTH(date_hired)
");
$growthRaw   = $growthStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$growthData  = [];
for ($i = 1; $i <= 12; $i++) {
    $growthData[] = (int)($growthRaw[$i] ?? 0);
}

// ── Department distribution ───────────────────────────────────────────────────
$deptStmt = $pdo->query("
    SELECT d.name, COUNT(e.id) AS cnt
    FROM departments d
    LEFT JOIN employees e
        ON e.department_id = d.id
       AND e.employment_status = 'Active'
    WHERE d.status = 'Active'
    GROUP BY d.id, d.name
    ORDER BY cnt DESC
    LIMIT 8
");
$deptDist     = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
$maxDeptCount = max(1, (int)($deptDist[0]['cnt'] ?? 1));

// ── Birthdays this month ──────────────────────────────────────────────────────
$birthdayStmt = $pdo->prepare("
    SELECT e.first_name, e.last_name, e.date_of_birth, d.name AS department_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE MONTH(e.date_of_birth) = :m
      AND e.employment_status = 'Active'
    ORDER BY DAY(e.date_of_birth)
");
$birthdayStmt->execute([':m' => date('n')]);
$birthdays = $birthdayStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Upcoming approved leaves ──────────────────────────────────────────────────
$upcomingLeaveStmt = $pdo->prepare("
    SELECT lr.start_date, lr.end_date, lr.number_of_days,
           e.first_name, e.last_name, lt.leave_name
    FROM leave_requests lr
    JOIN employees   e  ON e.id  = lr.employee_id
    JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.status = 'Approved'
      AND lr.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY lr.start_date
");
$upcomingLeaveStmt->execute();
$upcomingLeaves = $upcomingLeaveStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Today's events (for below calendar) ──────────────────────────────────────
// Birthdays today
$todayBirthdayStmt = $pdo->query("
    SELECT e.first_name, e.last_name, d.name AS department_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE MONTH(e.date_of_birth) = MONTH(CURDATE())
      AND DAY(e.date_of_birth)   = DAY(CURDATE())
      AND e.employment_status = 'Active'
");
$todayBirthdays = $todayBirthdayStmt->fetchAll(PDO::FETCH_ASSOC);

// On leave today
$todayLeaveStmt = $pdo->query("
    SELECT e.first_name, e.last_name, lt.leave_name,
           lr.start_date, lr.end_date
    FROM leave_requests lr
    JOIN employees   e  ON e.id  = lr.employee_id
    JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.status = 'Approved'
      AND CURDATE() BETWEEN lr.start_date AND lr.end_date
    ORDER BY e.last_name
    LIMIT 10
");
$todayLeaves = $todayLeaveStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Recent audit trail ────────────────────────────────────────────────────────
$recentAuditStmt = $pdo->query("
    SELECT al.action, al.entity_type, al.created_at, u.username AS actor
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.actor_user_id
    ORDER BY al.id DESC
    LIMIT 6
");
$recentAudit = $recentAuditStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Calendar leave dots + modal data ─────────────────────────────────────────
$calLeaveStmt = $pdo->prepare("
    SELECT
        DAY(d.d)                               AS day_num,
        CONCAT(e.first_name,' ',e.last_name)   AS employee_name,
        lt.leave_name                          AS leave_type,
        lr.start_date, lr.end_date, lr.number_of_days
    FROM (
        SELECT lr2.id AS lr_id,
               lr2.start_date + INTERVAL nums.seq DAY AS d
        FROM leave_requests lr2
        JOIN (
            SELECT 0 seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
            UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
            UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
            UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
            UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
            UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
            UNION SELECT 30
        ) nums
        WHERE lr2.status = 'Approved'
          AND MONTH(lr2.start_date) = :m
          AND YEAR(lr2.start_date)  = :y
          AND lr2.start_date + INTERVAL nums.seq DAY <= lr2.end_date
    ) d
    JOIN leave_requests lr ON lr.id  = d.lr_id
    JOIN employees      e  ON e.id   = lr.employee_id
    JOIN leave_types    lt ON lt.id  = lr.leave_type_id
    WHERE MONTH(d.d) = :m AND YEAR(d.d) = :y
    ORDER BY day_num, employee_name
");
$calLeaveStmt->execute([':m' => $calMonth, ':y' => $calYear]);
$calLeaveRows = $calLeaveStmt->fetchAll(PDO::FETCH_ASSOC);

$leaveDays  = [];
$leaveByDay = [];
foreach ($calLeaveRows as $row) {
    $d = (int)$row['day_num'];
    $leaveDays[$d] = true;
    $leaveByDay[$d][] = [
        'name'       => $row['employee_name'],
        'leave_type' => $row['leave_type'],
        'start'      => $row['start_date'],
        'end'        => $row['end_date'],
        'days'       => $row['number_of_days'],
    ];
}
$leaveByDayJson = json_encode($leaveByDay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);

// ── Totals ────────────────────────────────────────────────────────────────────
$totalEmpStmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'Active'");
$totalEmployees = (int)$totalEmpStmt->fetchColumn();

$totalDeptStmt = $pdo->query("SELECT COUNT(*) FROM departments WHERE status = 'Active'");
$totalDepts = (int)$totalDeptStmt->fetchColumn();

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<div class="adash-grid">

    <!-- ═══ BOX 1 — Today's User Activity ════════════════════════════════════ -->
    <div class="mbox mbox-1">
        <div class="mbox-title">Today's User Activity</div>
        <div class="mbox-1-stats">

            <div class="mstat-card">
                <span class="mstat-label">Logged in today</span>
                <strong class="mstat-num"><?= $loggedToday ?></strong>
            </div>

            <div class="mstat-card">
                <span class="mstat-label">Currently online</span>
                <strong class="mstat-num online"><?= $currentlyOnline ?></strong>
            </div>

            <div class="mstat-card">
                <span class="mstat-label">Currently offline</span>
                <strong class="mstat-num muted"><?= $totalOffline ?></strong>
            </div>

        </div>
    </div>

    <!-- ═══ BOX 2 — Calendar ════════════════════════════════════════════════ -->
    <div class="mbox mbox-2">

        <!-- Calendar header with filter -->
        <div class="cal-header">
            <div class="cal-nav">
                <a href="<?= $prevHref ?>" class="cal-nav-btn" title="Previous month">&#8249;</a>
                <?php
                $selectedMonth = str_pad($calMonth, 2, '0', STR_PAD_LEFT);
                ?>

                <select class="cal-month-select"
                    id="calMonthSelect"
                    onchange="updateCalendar()">

                    <?php foreach ($monthOptions as $opt): ?>
                        <option value="<?= $opt['val'] ?>"
                            <?= $opt['val'] === $selectedMonth ? 'selected' : '' ?>>
                            <?= $opt['lbl'] ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <select class="cal-month-select"
                    id="calYearSelect"
                    onchange="updateCalendar()">

                    <?php foreach ($yearOptions as $year): ?>
                        <option value="<?= $year ?>"
                            <?= $year === $calYear ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                    <?php endforeach; ?>

                </select>
                <a href="<?= $nextHref ?>" class="cal-nav-btn" title="Next month">&#8250;</a>
            </div>
            <?php if ($pendingLeave > 0): ?>
                <a href="/hr/leave_management.php" class="cal-badge">
                    <?= $pendingLeave ?> pending
                </a>
            <?php endif; ?>
        </div>

        <!-- Calendar grid -->
        <div class="cal-grid">
            <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d): ?>
                <div class="cal-dow"><?= $d ?></div>
            <?php endforeach; ?>

            <?php for ($blank = 0; $blank < $calFirstDay; $blank++): ?>
                <div></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $calDays; $day++): ?>
                <div class="cal-day
              <?= $day === $calToday ? 'cal-today' : '' ?>
              <?= isset($leaveDays[$day]) ? 'cal-has-leave' : '' ?>"
                    <?= isset($leaveDays[$day])
                        ? 'data-day="' . $day . '" data-bs-toggle="modal" data-bs-target="#leaveModal"'
                        : '' ?>>
                    <?= $day ?>
                    <?php if (isset($leaveDays[$day])): ?>
                        <span class="cal-dot"></span>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

        <div class="cal-legend">
            <span class="dot-purple"></span> Leave
        </div>

        <!-- Today's Events -->
        <div class="cal-today-events">
            <div class="cte-title">
                <i class="bi bi-calendar-day me-1"></i>
                Today's Events
                <span class="cte-date"><?= date('F j, Y') ?></span>
            </div>

            <?php if (empty($todayBirthdays) && empty($todayLeaves)): ?>
                <div class="cte-empty">No events today.</div>
            <?php endif; ?>

            <?php foreach ($todayBirthdays as $tb): ?>
                <div class="cte-row cte-birthday">
                    <span class="cte-icon">🎂</span>
                    <div class="cte-info">
                        <span class="cte-name"><?= h($tb['first_name'] . ' ' . $tb['last_name']) ?></span>
                        <span class="cte-meta">Birthday · <?= h($tb['department_name'] ?? '—') ?></span>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($todayLeaves as $tl): ?>
                <div class="cte-row cte-leave">
                    <span class="cte-icon">🏖️</span>
                    <div class="cte-info">
                        <span class="cte-name"><?= h($tl['first_name'] . ' ' . $tl['last_name']) ?></span>
                        <span class="cte-meta"><?= h($tl['leave_name']) ?> · until <?= date('M j', strtotime($tl['end_date'])) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>
    </div>

    <!-- ═══ BOX 3 — Employee Growth ═════════════════════════════════════════ -->
    <div class="mbox mbox-3">
        <div class="mbox-header">
            <div>
                <div class="mbox-title">Employee Growth</div>
                <div class="mbox-sub">Hired per month · <?= date('Y') ?></div>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="growthChart"></canvas>
        </div>
    </div>

    <!-- ═══ BOX 4 — People & Schedule ═══════════════════════════════════════ -->
    <div class="mbox mbox-4">
        <div class="mbox-header">
            <span class="mbox-title">People &amp; Schedule</span>
            <span class="mbox-sub"><?= h(date('F Y')) ?></span>
        </div>

        <div class="people-tabs">
            <button class="people-tab active" data-tab="birthdays">Birthdays</button>
            <button class="people-tab" data-tab="leaves">Incoming Leave</button>
            <button class="people-tab" data-tab="events">Events</button>
        </div>

        <!-- Birthdays this month -->

        <!-- Birthdays this month -->
        <div class="people-panel" id="tab-birthdays">
            <div id="birthdays-list"></div>

            <div class="people-pagination" id="birthdays-pagination">
                <button type="button" class="people-prev">&laquo; Previous</button>
                <span class="people-page-info">1 of 1</span>
                <button type="button" class="people-next">Next &raquo;</button>
            </div>
        </div>

        <!-- Incoming Leave -->
        <div class="people-panel hidden" id="tab-leaves">
            <div id="leaves-list"></div>

            <div class="people-pagination" id="leaves-pagination">
                <button type="button" class="people-prev">&laquo; Previous</button>
                <span class="people-page-info">1 of 1</span>
                <button type="button" class="people-next">Next &raquo;</button>
            </div>
        </div>

        <!-- Events -->
        <div class="people-panel hidden" id="tab-events">
            <div id="events-list"></div>

            <div class="people-pagination" id="events-pagination">
                <button type="button" class="people-prev">&laquo; Previous</button>
                <span class="people-page-info">1 of 1</span>
                <button type="button" class="people-next">Next &raquo;</button>
            </div>
        </div>

    </div>

    <!-- ═══ BOX 5 — Department Distribution ═════════════════════════════════ -->
    <div class="mbox mbox-5">
        <div class="mbox-header">
            <div>
                <div class="mbox-title">Department Distribution</div>
                <div class="mbox-sub">Headcount by department</div>
            </div>
        </div>
        <div class="deptdist-summary">
            <div><strong><?= $totalEmployees ?></strong><span>Employees</span></div>
            <div class="deptdist-sep"></div>
            <div><strong><?= $totalDepts ?></strong><span>Departments</span></div>
        </div>
        <div class="deptdist-bars">
            <?php foreach ($deptDist as $dd): ?>
                <?php $pct = $maxDeptCount > 0 ? round(((int)$dd['cnt'] / $maxDeptCount) * 100) : 0; ?>
                <div class="deptdist-row">
                    <div class="deptdist-label"><?= h($dd['name']) ?></div>
                    <div class="deptdist-track">
                        <div class="deptdist-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                    <div class="deptdist-count"><?= (int)$dd['cnt'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══ BOX 6 — Recent Activity ══════════════════════════════════════════ -->
    <div class="mbox mbox-6">
        <div class="mbox-header">
            <span class="mbox-title">Recent Activity</span>
            <a href="/admin/audit_logs.php" class="adash-viewall">View all</a>
        </div>
        <?php if (empty($recentAudit)): ?>
            <p class="adash-empty">No activity yet.</p>
        <?php else: ?>
            <?php foreach ($recentAudit as $entry): ?>
                <div class="recent-row">
                    <div class="recent-icon">
                        <?= str_contains($entry['action'], 'login')     ? '🔐'
                            : (str_contains($entry['action'], 'leave')    ? '📋'
                                : (str_contains($entry['action'], 'employee') ? '👤' : '📝')) ?>
                    </div>
                    <div class="recent-info">
                        <div class="recent-action"><?= h($entry['action']) ?></div>
                        <div class="recent-actor"><?= h($entry['actor'] ?? 'System') ?> · <?= h($entry['entity_type']) ?></div>
                        <div class="recent-time"><?= h(date('M j, g:i a', strtotime($entry['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ═══ BOX 7 — Audit Trail ══════════════════════════════════════════════ -->
    <div class="mbox mbox-7">
        <div class="mbox-header">
            <span class="mbox-title">Audit Trail</span>
            <a href="/admin/audit_logs.php" class="adash-viewall">View all</a>
        </div>
        <?php if (empty($recentAudit)): ?>
            <p class="adash-empty">No audit logs yet.</p>
        <?php else: ?>
            <?php foreach ($recentAudit as $entry): ?>
                <div class="recent-row">
                    <div class="recent-icon">
                        <?= str_contains($entry['action'], 'login')     ? '🔐'
                            : (str_contains($entry['action'], 'leave')    ? '📋'
                                : (str_contains($entry['action'], 'employee') ? '👤' : '📝')) ?>
                    </div>
                    <div class="recent-info">
                        <div class="recent-action"><?= h($entry['action']) ?></div>
                        <div class="recent-actor"><?= h($entry['actor'] ?? 'System') ?></div>
                        <div class="recent-time"><?= h(date('M j, g:i a', strtotime($entry['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- ═══ Leave Modal ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="leaveModal" tabindex="-1" aria-labelledby="leaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content adash-modal">
            <div class="modal-header adash-modal-header">
                <h5 class="modal-title" id="leaveModalLabel">
                    <i class="bi bi-calendar-check me-2"></i>
                    Employees on Leave — <span id="leaveModalDate"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body adash-modal-body">
                <div id="leaveModalBody"></div>

                <div class="leave-pagination" id="leavePagination">
                    <button type="button" id="leavePrev" disabled>
                        &laquo; Previous
                    </button>

                    <span id="leavePageInfo">1 of 1</span>

                    <button type="button" id="leaveNext" disabled>
                        Next &raquo;
                    </button>
                </div>
            </div>
            <div class="modal-footer adash-modal-footer">
                <a href="/hr/leave_management.php" class="btn adash-modal-btn">View All Leave Requests</a>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // ── Growth chart ──────────────────────────────────────────────────────────────
    const growthCtx = document.getElementById('growthChart').getContext('2d');
    new Chart(growthCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($monthLabels) ?>,
            datasets: [{
                data: <?= json_encode($growthData) ?>,
                backgroundColor: '#AD86D2',
                hoverBackgroundColor: '#C4A5ED',
                borderRadius: 4,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.raw} hired`
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: '#332A3D'
                    },
                    ticks: {
                        color: '#B4A5C0',
                        font: {
                            size: 10
                        }
                    }
                },
                y: {
                    grid: {
                        color: '#332A3D'
                    },
                    ticks: {
                        color: '#B4A5C0',
                        font: {
                            size: 10
                        },
                        stepSize: 1
                    },
                    beginAtZero: true
                }
            }
        }
    });

    // ── Tabs ──────────────────────────────────────────────────────────────────────
    
// ── People & Schedule Pagination ─────────────────────────────

const peoplePerPage = 5;

const peoplePages = {
    birthdays: 1,
    leaves: 1,
    events: 1
};

// PHP data
const birthdayData = <?= json_encode($birthdays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const incomingLeaveData = <?= json_encode($upcomingLeaves, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const todayBirthdayData = <?= json_encode($todayBirthdays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const todayLeaveData = <?= json_encode($todayLeaves, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

// Safely escape text
function escapePeopleHTML(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[char]);
}

// Render pagination
function renderPeoplePagination(type, data, renderer) {

    const list = document.getElementById(type + '-list');
    const pagination = document.getElementById(type + '-pagination');

    const prev = pagination.querySelector('.people-prev');
    const next = pagination.querySelector('.people-next');
    const info = pagination.querySelector('.people-page-info');

    const totalPages = Math.max(
        1,
        Math.ceil(data.length / peoplePerPage)
    );

    peoplePages[type] = Math.min(
        peoplePages[type],
        totalPages
    );

    const start = (peoplePages[type] - 1) * peoplePerPage;

    const pageData = data.slice(
        start,
        start + peoplePerPage
    );

    list.innerHTML = pageData.map(renderer).join('');

    info.textContent = `${peoplePages[type]} of ${totalPages}`;

    prev.disabled = peoplePages[type] === 1;
    next.disabled = peoplePages[type] === totalPages;

    pagination.style.display =
        data.length > peoplePerPage ? 'flex' : 'none';
}

// Birthday renderer
function renderBirthday(b) {

    const date = new Date(b.date_of_birth + 'T00:00:00');

    return `
        <div class="people-row">
            <div class="people-date">
                <strong>${date.getDate()}</strong>
                <span>${date.toLocaleString('en', {month: 'short'}).toUpperCase()}</span>
            </div>

            <div class="people-info">
                <div class="people-name">
                    ${escapePeopleHTML(b.first_name + ' ' + b.last_name)}
                </div>

                <div class="people-dept">
                    ${escapePeopleHTML(b.department_name || '—')}
                </div>
            </div>
        </div>
    `;
}

// Incoming Leave renderer
function renderIncomingLeave(lv) {

    const date = new Date(lv.start_date + 'T00:00:00');

    const start = date.toLocaleString('en', {
        month: 'short',
        day: 'numeric'
    });

    const end = new Date(lv.end_date + 'T00:00:00').toLocaleString('en', {
        month: 'short',
        day: 'numeric'
    });

    return `
        <div class="people-row">
            <div class="people-date leave-date">
                <strong>${date.getDate()}</strong>
                <span>${date.toLocaleString('en', {month: 'short'}).toUpperCase()}</span>
            </div>

            <div class="people-info">
                <div class="people-name">
                    ${escapePeopleHTML(lv.first_name + ' ' + lv.last_name)}
                </div>

                <div class="people-dept">
                    ${escapePeopleHTML(lv.leave_name)}
                    · ${escapePeopleHTML(lv.number_of_days)} days
                </div>
            </div>

            <div class="people-day">${start} – ${end}</div>
        </div>
    `;
}

// Events renderer
function renderEvent(event) {

    const date = new Date(event.date + 'T00:00:00');

    const birthday = event.type === 'birthday';

    return `
        <div class="people-row">
            <div class="people-date ${birthday ? '' : 'leave-date'}">
                <strong>${date.getDate()}</strong>
                <span>${date.toLocaleString('en', {month: 'short'}).toUpperCase()}</span>
            </div>

            <div class="people-info">
                <div class="people-name">
                    ${escapePeopleHTML(event.name)}
                </div>

                <div class="people-dept">
                    ${birthday
                        ? '🎂 Birthday · ' + escapePeopleHTML(event.department || '—')
                        : '🏖️ ' + escapePeopleHTML(event.leave_name) +
                          ' · until ' + escapePeopleHTML(event.end_date)
                    }
                </div>
            </div>
        </div>
    `;
}

// Combine today's birthdays and leaves
const eventData = [

    ...todayBirthdayData.map(b => ({
        type: 'birthday',
        name: b.first_name + ' ' + b.last_name,
        department: b.department_name,
        date: '<?= date('Y-m-d') ?>'
    })),

    ...todayLeaveData.map(lv => ({
        type: 'leave',
        name: lv.first_name + ' ' + lv.last_name,
        leave_name: lv.leave_name,
        date: '<?= date('Y-m-d') ?>',
        end_date: lv.end_date
    }))

];

// Render all panels
function renderAllPeople() {

    renderPeoplePagination(
        'birthdays',
        birthdayData,
        renderBirthday
    );

    renderPeoplePagination(
        'leaves',
        incomingLeaveData,
        renderIncomingLeave
    );

    renderPeoplePagination(
        'events',
        eventData,
        renderEvent
    );
}

// Previous and Next buttons
document.querySelectorAll('.people-pagination').forEach(pagination => {

    const type = pagination.id.replace('-pagination', '');

    pagination.querySelector('.people-prev').addEventListener('click', () => {

        if (peoplePages[type] > 1) {
            peoplePages[type]--;
            renderAllPeople();
        }

    });

    pagination.querySelector('.people-next').addEventListener('click', () => {

        peoplePages[type]++;
        renderAllPeople();

    });

});

// Tab switching
document.querySelectorAll('.people-tab').forEach(btn => {

    btn.addEventListener('click', () => {

        document.querySelectorAll('.people-tab')
            .forEach(b => b.classList.remove('active'));

        document.querySelectorAll('.people-panel')
            .forEach(p => p.classList.add('hidden'));

        btn.classList.add('active');

        document.getElementById('tab-' + btn.dataset.tab)
            .classList.remove('hidden');

    });

});

// Initial rendering
renderAllPeople();


    // ── Leave modal ───────────────────────────────────────────────────────────────
    const leaveByDay = <?= $leaveByDayJson ?>;
    const monthName = <?= json_encode($calMonthName) ?>;

    document.getElementById('leaveModal').addEventListener('show.bs.modal', function(e) {
        const day = parseInt(e.relatedTarget.dataset.day);
        const entries = leaveByDay[day] || [];

        document.getElementById('leaveModalDate').textContent =
            monthName.replace(/\s\d{4}$/, '') + ' ' + day;

        const body = document.getElementById('leaveModalBody');

        if (entries.length === 0) {
            body.innerHTML = '<p class="adash-modal-empty">No leave records for this day.</p>';
            return;
        }

        body.innerHTML = entries.map(emp => `
    <div class="adash-modal-row">
      <div class="adash-modal-avatar">${emp.name.charAt(0)}</div>
      <div class="adash-modal-info">
        <strong>${emp.name}</strong>
        <span class="adash-modal-type">${emp.leave_type}</span>
      </div>
      <div class="adash-modal-meta">
        <span class="adash-modal-days">${emp.days} day${emp.days != 1 ? 's' : ''}</span>
        <span class="adash-modal-range">${emp.start} → ${emp.end}</span>
      </div>
    </div>
  `).join('');
    });

    function updateCalendar() {
        const month = document.getElementById('calMonthSelect').value;
        const year = document.getElementById('calYearSelect').value;

        window.location.href = `?m=${year}-${month}`;
    }
</script>

</section>
</main>
</body>

</html>