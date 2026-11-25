<?php
// admin/backup_management.php

require_once __DIR__ . '/../authentication/guard.php';

$account = require_access();

if (!Rbac::canManageBackups($account['role'] ?? '')) {
    guard_forbidden(
        $account['role'] ?? null,
        'Only the Super Admin can access Backup Management.'
    );
}

$cssPage = 'backup_management';

require __DIR__ . '/../templates/adminTemplates/header.php';

// Flash message
$backupFlash = $_SESSION['backup_flash'] ?? null;
unset($_SESSION['backup_flash']);

// ==========================================
// NORMAL BACKUPS
// ==========================================

$backupFiles = glob('/backups/hris_backup_*.sql.gz') ?: [];

$backupFiles = array_filter($backupFiles, function ($path) {
    return preg_match(
        '/^hris_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/',
        basename($path)
    );
});

usort($backupFiles, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
});

$backups = [];

foreach ($backupFiles as $backupPath) {
    $filename = basename($backupPath);
    $createdAt = filemtime($backupPath);
    $fileSize = filesize($backupPath);

    $backups[] = [
        'filename' => $filename,
        'created_at' => $createdAt,
        'formatted_date' => date('M d, Y h:i A', $createdAt),
        'size' => $fileSize !== false
            ? number_format($fileSize / 1024, 2) . ' KB'
            : 'Unknown',
    ];
}

// ==========================================
// SAFETY BACKUPS
// ==========================================

$safetyBackupFiles = glob('/backups/hris_pre_restore_*.sql.gz') ?: [];

$safetyBackupFiles = array_filter($safetyBackupFiles, function ($path) {
    return preg_match(
        '/^hris_pre_restore_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/',
        basename($path)
    );
});

usort($safetyBackupFiles, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
});

$safetyBackups = [];

foreach ($safetyBackupFiles as $backupPath) {
    $filename = basename($backupPath);
    $createdAt = filemtime($backupPath);
    $fileSize = filesize($backupPath);

    $safetyBackups[] = [
        'filename' => $filename,
        'created_at' => $createdAt,
        'formatted_date' => date('M d, Y h:i A', $createdAt),
        'size' => $fileSize !== false
            ? number_format($fileSize / 1024, 2) . ' KB'
            : 'Unknown',
    ];
}
// ==========================================
// BACKUP OVERVIEW STATISTICS
// ==========================================

$totalNormalBackups = count($backups);
$totalSafetyBackups = count($safetyBackups);

$totalBackups = $totalNormalBackups + $totalSafetyBackups;

$totalStorageBytes = 0;

foreach (array_merge($backupFiles, $safetyBackupFiles) as $file) {
    if (is_file($file)) {
        $size = filesize($file);

        if ($size !== false) {
            $totalStorageBytes += $size;
        }
    }
}

function formatBackupStorage($bytes)
{
    if ($bytes >= 1024 ** 3) {
        return number_format($bytes / (1024 ** 3), 2) . ' GB';
    }

    if ($bytes >= 1024 ** 2) {
        return number_format($bytes / (1024 ** 2), 2) . ' MB';
    }

    return number_format($bytes / 1024, 2) . ' KB';
}

$formattedStorage = formatBackupStorage($totalStorageBytes);
// ==========================================
// BACKUP ACTIVITY - LAST 14 DAYS
// ==========================================

$activityLabels = [];
$normalActivity = [];
$safetyActivity = [];
$restoreActivity = [];

$activityDates = [];

for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));

    $activityDates[$date] = [
        'normal' => 0,
        'safety' => 0,
        'restores' => 0,
    ];

    $activityLabels[] = date('M d', strtotime($date));
}

// Count normal backups.
foreach ($backups as $backup) {
    $date = date('Y-m-d', $backup['created_at']);

    if (isset($activityDates[$date])) {
        $activityDates[$date]['normal']++;
    }
}

// Count safety backups.
foreach ($safetyBackups as $backup) {
    $date = date('Y-m-d', $backup['created_at']);

    if (isset($activityDates[$date])) {
        $activityDates[$date]['safety']++;
    }
}

// Count successful live restores.
$resultFiles = glob('/requests/*.result') ?: [];

foreach ($resultFiles as $resultFile) {
    $resultData = [];

    $lines = file($resultFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        continue;
    }

    foreach ($lines as $line) {
        $parts = explode('=', $line, 2);

        if (count($parts) === 2) {
            $resultData[$parts[0]] = $parts[1];
        }
    }

    $status = $resultData['status'] ?? '';
    $message = $resultData['message'] ?? '';
    $completedAt = $resultData['completed_at'] ?? '';

    if (
        $status !== 'success' ||
        strpos($message, 'live database validation passed') === false ||
        empty($completedAt)
    ) {
        continue;
    }

    $timestamp = strtotime($completedAt);

    if ($timestamp === false) {
        continue;
    }

    $date = date('Y-m-d', $timestamp);

    if (isset($activityDates[$date])) {
        $activityDates[$date]['restores']++;
    }
}

// Prepare Chart.js datasets.
foreach ($activityDates as $activity) {
    $normalActivity[] = $activity['normal'];
    $safetyActivity[] = $activity['safety'];
    $restoreActivity[] = $activity['restores'];
}
?>

<div class="backup-management">
    <section class="backup-overview">

        <div class="backup-overview-header">
            <div>
                <h2>Backup Overview</h2>
                <p>Monitor your HRIS database backups and restore activity.</p>
            </div>
        </div>

        <div class="backup-overview-grid">

            <article class="backup-stat-card">
                <span>Total Backups</span>
                <strong><?= number_format($totalBackups) ?></strong>
                <small>All backups</small>
            </article>

            <article class="backup-stat-card">
                <span>Storage Used</span>
                <strong><?= htmlspecialchars($formattedStorage) ?></strong>
                <small>Backup storage</small>
            </article>

            <article class="backup-stat-card">
                <span>Safety Backups</span>
                <strong><?= number_format($totalSafetyBackups) ?></strong>
                <small>Pre-restore copies</small>
            </article>

            <article class="backup-stat-card">
                <span>Normal Backups</span>
                <strong><?= number_format($totalNormalBackups) ?></strong>
                <small>Regular database backups</small>
            </article>

        </div>
        <div class="backup-activity">
            <div class="backup-activity-header">
                <div>
                    <h3>Backup Activity</h3>
                    <p>Backup and restore activity over the last 14 days.</p>
                </div>
                <span>Last 14 days</span>
            </div>

            <div class="backup-activity-chart">
                <canvas id="backupActivityChart"></canvas>
            </div>
        </div>
    </section>

    <div class="backup-page-header">
        <div>
            <h1>Backup Management</h1>
            <p>Manage and monitor your HRIS database backups.</p>
        </div>

        <div class="backup-actions">
            <form method="POST" action="/admin/backup/create">
                <?= csrf_field() ?>

                <button type="submit">
                    Create Backup Now
                </button>
            </form>
        </div>
    </div>

    <?php if ($backupFlash): ?>
        <div class="backup-message <?= htmlspecialchars(
                                        $backupFlash['type'] ?? 'info',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>">
            <?= htmlspecialchars(
                $backupFlash['message'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>

            <?php if (!empty($backupFlash['filename'])): ?>
                <p>
                    File:
                    <?= htmlspecialchars(
                        $backupFlash['filename'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="backup-history">

        <!-- Shared restore form.
             This must remain outside the normal backup condition. -->
        <form
            id="restoreForm"
            method="POST"
            action="/admin/backup/restore">

            <?= csrf_field() ?>

            <input
                type="hidden"
                name="filename"
                id="selectedBackupFilename">
        </form>

        <div class="backup-section-header">
            <div>
                <h2>Backup History</h2>
                <p>Select a backup to restore, verify, or download.</p>
            </div>

            <span id="backupTotal">
                <?= count($backups) ?> backup(s)
            </span>
        </div>

        <?php if (empty($backups)): ?>

            <div class="backup-empty">
                <h3>No backups found</h3>
                <p>Create your first database backup to see it here.</p>
            </div>

        <?php else: ?>

            <!-- Search and sorting -->
            <div class="backup-table-controls">

                <div class="backup-search">
                    <label for="backupSearch">Search backups</label>
                    <input
                        type="search"
                        id="backupSearch"
                        placeholder="Search filename...">
                </div>

                <div class="backup-sort">
                    <label for="backupSort">Sort by date</label>
                    <select id="backupSort">
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                    </select>
                </div>

            </div>

            <div class="backup-table-wrapper">

                <table class="backup-table" id="backupTable">

                    <thead>
                        <tr>
                            <th>Select</th>
                            <th>Backup Filename</th>
                            <th>Date Created</th>
                            <th>File Size</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody id="backupTableBody">

                        <?php foreach ($backups as $backup): ?>

                            <?php $filename = $backup['filename']; ?>

                            <tr
                                class="backup-row"
                                data-filename="<?= htmlspecialchars(
                                                    strtolower($filename),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                data-date="<?= (int) $backup['created_at'] ?>">

                                <td>
                                    <input
                                        type="radio"
                                        name="backup_selection"
                                        class="backup-radio"
                                        value="<?= htmlspecialchars(
                                                    $filename,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                        form="restoreForm"
                                        required
                                        aria-label="Select <?= htmlspecialchars(
                                                                $filename,
                                                                ENT_QUOTES,
                                                                'UTF-8'
                                                            ) ?>">
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $filename,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $backup['formatted_date'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $backup['size'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="backup-status success">
                                        Available
                                    </span>
                                </td>

                                <td>
                                    <div class="backup-row-actions">

                                        <form
                                            method="POST"
                                            action="/admin/backup/verify">

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="filename"
                                                value="<?= htmlspecialchars(
                                                            $filename,
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>">

                                            <button
                                                type="submit"
                                                class="backup-verify">
                                                Verify
                                            </button>
                                        </form>

                                        <a
                                            href="/admin/backup/download?file=<?= rawurlencode($filename) ?>"
                                            class="backup-download">
                                            Download
                                        </a>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

                <div id="backupNoResults" class="backup-empty" hidden>
                    <h3>No matching backups</h3>
                    <p>Try another filename or change your search.</p>
                </div>

            </div>

            <!-- Pagination -->
            <div class="backup-table-footer">

                <div class="backup-pagination-info">
                    <span id="backupPaginationInfo">
                        Showing 0–0 of 0 backups
                    </span>
                </div>

                <div class="backup-pagination">

                    <button
                        type="button"
                        id="backupPrevious"
                        class="backup-page-button">
                        Previous
                    </button>

                    <span id="backupPageNumbers"></span>

                    <button
                        type="button"
                        id="backupNext"
                        class="backup-page-button">
                        Next
                    </button>

                </div>

            </div>

            <!-- Normal backup restore action -->
            <div class="backup-restore-footer">

                <div>
                    <strong id="selectedBackupLabel">
                        No backup selected
                    </strong>

                    <p>Select one backup from the table to restore.</p>
                </div>

                <button
                    type="button"
                    id="openRestoreConfirmation"
                    class="backup-restore-button"
                    disabled>
                    Restore Selected Backup
                </button>

            </div>

        <?php endif; ?>

    </section>

    <!-- ======================================
         RESTORE SAFETY BACKUPS
    ======================================= -->

    <section class="backup-history safety-backup-history">

        <div class="backup-section-header">
            <div>
                <h2>Restore Safety Backups</h2>
                <p>
                    Recovery copies automatically created before database restoration.
                </p>
            </div>

            <span>
                <?= count($safetyBackups) ?> safety backup(s)
            </span>
        </div>

        <?php if (empty($safetyBackups)): ?>

            <div class="backup-empty">
                <h3>No safety backups found</h3>
                <p>
                    Safety backups will appear here whenever a database restore
                    creates a pre-restore recovery copy.
                </p>
            </div>

        <?php else: ?>

            <div class="backup-table-wrapper">

                <table class="backup-table">

                    <thead>
                        <tr>
                            <th>Backup Filename</th>
                            <th>Date Created</th>
                            <th>File Size</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($safetyBackups as $backup): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $backup['filename'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $backup['formatted_date'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $backup['size'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="backup-status success">
                                        Available
                                    </span>
                                </td>

                                <td>
                                    <div class="backup-row-actions">

                                        <a
                                            href="/admin/backup/download?file=<?= rawurlencode($backup['filename']) ?>"
                                            class="backup-download">
                                            Download
                                        </a>

                                        <button
                                            type="button"
                                            class="backup-restore-button safety-restore-button"
                                            data-filename="<?= htmlspecialchars(
                                                                $backup['filename'],
                                                                ENT_QUOTES,
                                                                'UTF-8'
                                                            ) ?>"
                                            data-date="<?= htmlspecialchars(
                                                            $backup['formatted_date'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>">
                                            Restore
                                        </button>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</div>


<!-- ======================================
     RESTORE CONFIRMATION MODAL
======================================= -->

<div
    class="backup-modal"
    id="restoreConfirmationModal"
    aria-hidden="true">

    <div class="backup-modal-overlay" data-close-modal></div>

    <div
        class="backup-modal-content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="restoreConfirmationTitle">

        <div class="backup-modal-icon warning">
            <span>!</span>
        </div>

        <h2 id="restoreConfirmationTitle">
            Confirm Database Restore
        </h2>

        <p>
            You are about to restore your HRIS database using this backup:
        </p>

        <div class="backup-selected-file">
            <strong id="confirmationFilename"></strong>
            <span id="confirmationDate"></span>
        </div>

        <div class="backup-warning">
            <strong>Warning:</strong>
            This operation will replace the current HRIS database.
            Changes made after this backup was created may be lost.
        </div>

        <p class="backup-safety-note">
            A safety backup of the current database must be created
            before restoration begins.
        </p>

        <div class="backup-modal-actions">

            <button
                type="button"
                class="backup-modal-cancel"
                data-close-modal>
                Cancel
            </button>

            <button
                type="button"
                class="backup-modal-confirm"
                id="confirmRestoreButton">
                Confirm Restore
            </button>

        </div>

    </div>
</div>


<!-- ======================================
     RESTORE STATUS MODAL
======================================= -->

<div
    class="backup-modal"
    id="restoreStatusModal"
    aria-hidden="true">

    <div class="backup-modal-overlay"></div>

    <div
        class="backup-modal-content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="restoreStatusTitle">

        <div class="backup-modal-icon progress" id="restoreStatusIcon">
            <span id="restoreStatusIconText">...</span>
        </div>

        <h2 id="restoreStatusTitle">Database Restore</h2>

        <p id="restoreStatusDescription">
            Preparing database restoration...
        </p>

        <div class="backup-selected-file">
            <strong id="statusFilename"></strong>
        </div>

        <div class="backup-restore-progress-list">

            <div class="restore-progress-item" data-stage="validate">
                <span class="restore-stage-icon"></span>
                <span>Validate selected backup</span>
                <span class="restore-stage-status">Waiting</span>
            </div>

            <div class="restore-progress-item" data-stage="safety">
                <span class="restore-stage-icon"></span>
                <span>Create safety backup</span>
                <span class="restore-stage-status">Waiting</span>
            </div>

            <div class="restore-progress-item" data-stage="replace">
                <span class="restore-stage-icon"></span>
                <span>Replace HRIS database</span>
                <span class="restore-stage-status">Waiting</span>
            </div>

            <div class="restore-progress-item" data-stage="validate-live">
                <span class="restore-stage-icon"></span>
                <span>Validate restored database</span>
                <span class="restore-stage-status">Waiting</span>
            </div>

            <div class="restore-progress-item" data-stage="rollback">
                <span class="restore-stage-icon"></span>
                <span class="restore-stage-label">Restore safety backup</span>
                <span class="restore-stage-status">Waiting</span>
            </div>

        </div>

        <div
            class="backup-restore-result"
            id="restoreFinalResult"
            hidden></div>

        <div class="backup-modal-actions">

            <button
                type="button"
                class="backup-modal-cancel"
                id="closeRestoreStatus"
                disabled>
                Close
            </button>

        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {

        // ==========================================
        // ELEMENTS
        // ==========================================

        const rows = Array.from(
            document.querySelectorAll('.backup-row')
        );

        const restoreForm = document.getElementById('restoreForm');
        // ==========================================
        // BACKUP ACTIVITY CHART
        // ==========================================

        const activityCanvas = document.getElementById('backupActivityChart');

        if (activityCanvas && typeof Chart !== 'undefined') {
            new Chart(activityCanvas, {
                type: 'bar',

                data: {
                    labels: <?= json_encode($activityLabels) ?>,

                    datasets: [{
                            label: 'Normal Backups',
                            data: <?= json_encode($normalActivity) ?>,
                            backgroundColor: '#9B7BD3',
                            borderRadius: 5,
                            borderSkipped: false
                        },
                        {
                            label: 'Safety Backups',
                            data: <?= json_encode($safetyActivity) ?>,
                            backgroundColor: '#4F9DA6',
                            borderRadius: 5,
                            borderSkipped: false
                        },
                        {
                            label: 'Successful Restores',
                            data: <?= json_encode($restoreActivity) ?>,
                            backgroundColor: '#D6A45D',
                            borderRadius: 5,
                            borderSkipped: false
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },

                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#B4A5C0',
                                usePointStyle: true,
                                pointStyle: 'circle',
                                padding: 20
                            }
                        },

                        tooltip: {
                            backgroundColor: '#171d32',
                            titleColor: '#FFFFFF',
                            bodyColor: '#D8D2E2',
                            borderColor: '#303b5c',
                            borderWidth: 1,
                            padding: 12
                        }
                    },

                    scales: {
                        x: {
                            stacked: false,
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#B4A5C0'
                            }
                        },

                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                color: '#B4A5C0'
                            },
                            grid: {
                                color: 'rgba(180, 165, 192, 0.12)'
                            }
                        }
                    }
                }
            });
        }
        if (!restoreForm) {
            return;
        }

        const hasNormalBackups = rows.length > 0;

        const searchInput = document.getElementById('backupSearch');
        const sortSelect = document.getElementById('backupSort');

        const previousButton = document.getElementById('backupPrevious');
        const nextButton = document.getElementById('backupNext');

        const pageNumbers = document.getElementById('backupPageNumbers');
        const paginationInfo = document.getElementById('backupPaginationInfo');
        const noResults = document.getElementById('backupNoResults');

        const radios = document.querySelectorAll('.backup-radio');

        const restoreButton = document.getElementById(
            'openRestoreConfirmation'
        );

        const selectedBackupLabel = document.getElementById(
            'selectedBackupLabel'
        );

        const confirmationModal = document.getElementById(
            'restoreConfirmationModal'
        );

        const statusModal = document.getElementById(
            'restoreStatusModal'
        );

        const confirmRestoreButton = document.getElementById(
            'confirmRestoreButton'
        );

        const closeStatusButton = document.getElementById(
            'closeRestoreStatus'
        );

        const statusDescription = document.getElementById(
            'restoreStatusDescription'
        );

        const statusTitle = document.getElementById(
            'restoreStatusTitle'
        );

        const statusIcon = document.getElementById(
            'restoreStatusIcon'
        );

        const statusIconText = document.getElementById(
            'restoreStatusIconText'
        );

        const finalResult = document.getElementById(
            'restoreFinalResult'
        );

        const stageItems = Array.from(
            document.querySelectorAll('.restore-progress-item')
        );

        // ==========================================
        // STATE
        // ==========================================

        let currentPage = 1;
        const pageSize = 5;

        let filteredRows = [...rows];

        let restoreRequestId = null;
        let restoreFinished = false;
        let polling = false;

        let selectedRestoreFilename = null;
        let selectedRestoreDate = '';

        const stageOrder = [
            'validate',
            'safety',
            'replace',
            'validate-live'
        ];

        // ==========================================
        // MODAL HELPERS
        // ==========================================

        function openModal(modal) {
            if (!modal) return;

            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeModal(modal) {
            if (!modal) return;

            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        }

        // ==========================================
        // SELECT BACKUP FOR RESTORATION
        // ==========================================

        function selectBackup(filename, date) {

            selectedRestoreFilename = filename;
            selectedRestoreDate = date;

            document.getElementById(
                'selectedBackupFilename'
            ).value = filename;

            if (selectedBackupLabel) {
                selectedBackupLabel.textContent = filename;
            }

            document.getElementById(
                'confirmationFilename'
            ).textContent = filename;

            document.getElementById(
                'confirmationDate'
            ).textContent = date;

            document.getElementById(
                'statusFilename'
            ).textContent = filename;
        }

        // ==========================================
        // NORMAL BACKUP SEARCH, SORTING, PAGINATION
        // ==========================================

        function getVisibleBackups() {

            if (!hasNormalBackups) {
                return;
            }

            const searchTerm = searchInput.value.trim().toLowerCase();

            filteredRows = rows.filter(function(row) {
                return row.dataset.filename.includes(searchTerm);
            });

            filteredRows.sort(function(a, b) {

                const dateA = Number(a.dataset.date);
                const dateB = Number(b.dataset.date);

                return sortSelect.value === 'oldest' ?
                    dateA - dateB :
                    dateB - dateA;
            });

            const totalPages = Math.max(
                1,
                Math.ceil(filteredRows.length / pageSize)
            );

            currentPage = Math.min(currentPage, totalPages);

            rows.forEach(function(row) {
                row.style.display = 'none';
            });

            const startIndex = (currentPage - 1) * pageSize;

            const pageRows = filteredRows.slice(
                startIndex,
                startIndex + pageSize
            );

            pageRows.forEach(function(row) {
                row.style.display = '';

                document.getElementById(
                    'backupTableBody'
                ).appendChild(row);
            });

            noResults.hidden = filteredRows.length !== 0;

            const start = filteredRows.length === 0 ?
                0 :
                startIndex + 1;

            const end = Math.min(
                startIndex + pageSize,
                filteredRows.length
            );

            paginationInfo.textContent =
                `Showing ${start}–${end} of ${filteredRows.length} backups`;

            previousButton.disabled = currentPage <= 1;
            nextButton.disabled = currentPage >= totalPages;

            renderPageNumbers(totalPages);

            document.getElementById(
                'backupTotal'
            ).textContent = `${filteredRows.length} backup(s)`;
        }

        function renderPageNumbers(totalPages) {

            pageNumbers.innerHTML = '';

            for (let page = 1; page <= totalPages; page++) {

                const button = document.createElement('button');

                button.type = 'button';
                button.textContent = page;
                button.className = 'backup-page-button';

                if (page === currentPage) {
                    button.classList.add('active');
                }

                button.addEventListener('click', function() {
                    currentPage = page;
                    getVisibleBackups();
                });

                pageNumbers.appendChild(button);
            }
        }

        // Bind normal backup controls only when normal backups exist.
        if (hasNormalBackups) {

            searchInput.addEventListener('input', function() {
                currentPage = 1;
                getVisibleBackups();
            });

            sortSelect.addEventListener('change', function() {
                currentPage = 1;
                getVisibleBackups();
            });

            previousButton.addEventListener('click', function() {

                if (currentPage > 1) {
                    currentPage--;
                    getVisibleBackups();
                }
            });

            nextButton.addEventListener('click', function() {

                const totalPages = Math.ceil(
                    filteredRows.length / pageSize
                );

                if (currentPage < totalPages) {
                    currentPage++;
                    getVisibleBackups();
                }
            });

            // Normal backup selection
            radios.forEach(function(radio) {

                radio.addEventListener('change', function() {

                    const selectedRow = this.closest('tr');

                    const filename = this.value;
                    const date = selectedRow.children[2].textContent.trim();

                    selectBackup(filename, date);

                    if (restoreButton) {
                        restoreButton.disabled = false;
                    }
                });
            });

            // Normal backup restore button
            if (restoreButton) {

                restoreButton.addEventListener('click', function() {

                    if (!selectedRestoreFilename) {
                        alert('Please select a backup first.');
                        return;
                    }

                    openModal(confirmationModal);
                });
            }

            // Initialize normal backup table.
            getVisibleBackups();
        }

        // ==========================================
        // SAFETY BACKUP SELECTION
        // ==========================================

        document.querySelectorAll('.safety-restore-button')
            .forEach(function(button) {

                button.addEventListener('click', function() {

                    const filename = this.dataset.filename;
                    const date = this.dataset.date;

                    selectBackup(filename, date);

                    openModal(confirmationModal);
                });
            });

        // ==========================================
        // CONFIRMATION MODAL CLOSE
        // ==========================================

        confirmationModal.querySelectorAll('[data-close-modal]')
            .forEach(function(button) {

                button.addEventListener('click', function() {
                    closeModal(confirmationModal);
                });
            });

        // ==========================================
        // RESTORE STATUS RESET
        // ==========================================

        function resetRestoreStatus() {

            restoreFinished = false;

            statusTitle.textContent = 'Database Restore';

            statusDescription.textContent =
                'Submitting database restoration request...';

            statusIcon.className = 'backup-modal-icon progress';
            statusIconText.textContent = '...';

            finalResult.hidden = true;
            finalResult.textContent = '';

            closeStatusButton.disabled = true;

            stageItems.forEach(function(item) {

                item.classList.remove(
                    'active',
                    'completed',
                    'failed'
                );

                item.dataset.completed = 'false';

                item.querySelector(
                    '.restore-stage-icon'
                ).textContent = '';

                item.querySelector(
                    '.restore-stage-status'
                ).textContent = 'Waiting';

                const label = item.querySelector(
                    '.restore-stage-label'
                );

                if (label) {
                    label.textContent = 'Restore safety backup';
                }
            });
        }

        // ==========================================
        // STAGE DISPLAY
        // ==========================================

        function markStage(item, state, label) {

            const icon = item.querySelector('.restore-stage-icon');
            const status = item.querySelector('.restore-stage-status');

            item.classList.remove(
                'active',
                'completed',
                'failed'
            );

            item.classList.add(state);

            icon.textContent =
                state === 'completed' ? '✓' :
                state === 'failed' ? '!' : '...';

            status.textContent = label;

            item.dataset.completed =
                state === 'completed' ? 'true' : 'false';
        }

        function updateRestoreStage(stage, status, message) {

            const currentIndex = stageOrder.indexOf(stage);

            // Handle rollback separately.
            if (stage === 'rollback') {

                stageItems.forEach(function(item) {

                    if (item.dataset.stage === 'rollback') {

                        markStage(
                            item,
                            status === 'failed' ? 'failed' : 'active',
                            status === 'failed' ? 'Failed' : 'In progress'
                        );

                    } else if (item.dataset.completed === 'true') {

                        markStage(item, 'completed', 'Completed');
                    }
                });

                statusDescription.textContent = message;
                return;
            }

            // Mark normal stages up to the current stage.
            if (currentIndex >= 0) {

                stageItems.forEach(function(item) {

                    const index = stageOrder.indexOf(
                        item.dataset.stage
                    );

                    if (index === -1) {
                        return;
                    }

                    if (index < currentIndex) {

                        markStage(item, 'completed', 'Completed');

                    } else if (index === currentIndex) {

                        if (status === 'failed') {

                            markStage(item, 'failed', 'Failed');

                        } else if (status === 'success') {

                            markStage(item, 'completed', 'Completed');

                        } else {

                            markStage(item, 'active', 'In progress');
                        }
                    }
                });
            }

            // Handle the worker's final failed stage.
            if (stage === 'failed') {

                const unfinishedStage = stageItems.find(function(item) {
                    return item.dataset.stage !== 'rollback' &&
                        item.dataset.completed !== 'true' &&
                        !item.classList.contains('failed');
                });

                if (unfinishedStage) {
                    markStage(unfinishedStage, 'failed', 'Failed');
                }
            }

            statusDescription.textContent = message ||
                'Database restoration is processing.';
        }

        // ==========================================
        // FINISH RESTORE
        // ==========================================

        function finishRestore(status, message) {

            restoreFinished = true;

            closeStatusButton.disabled = false;

            statusDescription.textContent = message;
            finalResult.textContent = message;
            finalResult.hidden = false;

            if (status === 'success') {

                statusTitle.textContent = 'Restore Completed';

                statusIcon.className = 'backup-modal-icon success';
                statusIconText.textContent = '✓';

                stageItems.forEach(function(item) {

                    if (item.dataset.stage !== 'rollback') {
                        markStage(item, 'completed', 'Completed');
                    }
                });

                const rollbackItem = document.querySelector(
                    '.restore-progress-item[data-stage="rollback"]'
                );

                if (rollbackItem) {

                    const label = rollbackItem.querySelector(
                        '.restore-stage-label'
                    );

                    if (label) {
                        label.textContent = 'Safety backup secured';
                    }

                    markStage(
                        rollbackItem,
                        'completed',
                        'Completed'
                    );
                }

            } else {

                statusTitle.textContent = 'Restore Failed';

                statusIcon.className = 'backup-modal-icon warning';
                statusIconText.textContent = '!';

                const activeStage = stageItems.find(function(item) {
                    return item.classList.contains('active');
                });

                if (activeStage) {
                    markStage(activeStage, 'failed', 'Failed');
                }
            }
        }

        // ==========================================
        // SUBMIT RESTORE REQUEST
        // ==========================================

        confirmRestoreButton.addEventListener('click', async function() {

            if (!selectedRestoreFilename) {
                alert('Please select a backup first.');
                closeModal(confirmationModal);
                return;
            }

            if (polling) {
                return;
            }

            document.getElementById(
                'selectedBackupFilename'
            ).value = selectedRestoreFilename;

            confirmRestoreButton.disabled = true;
            confirmRestoreButton.textContent = 'Submitting...';

            resetRestoreStatus();

            closeModal(confirmationModal);
            openModal(statusModal);

            try {

                const response = await fetch(restoreForm.action, {
                    method: 'POST',
                    body: new FormData(restoreForm),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (!response.ok || !data.success || !data.request_id) {
                    throw new Error(
                        data.message || 'Could not submit restore request.'
                    );
                }

                restoreRequestId = data.request_id;

                statusDescription.textContent =
                    data.message || 'Restore request submitted.';

                await pollRestoreStatus();

            } catch (error) {

                finishRestore(
                    'failed',
                    error.message || 'An unexpected error occurred.'
                );

            } finally {

                confirmRestoreButton.disabled = false;
                confirmRestoreButton.textContent = 'Confirm Restore';
            }
        });

        // ==========================================
        // POLL WORKER PROGRESS
        // ==========================================

        function wait(milliseconds) {
            return new Promise(function(resolve) {
                setTimeout(resolve, milliseconds);
            });
        }

        async function pollRestoreStatus() {

            if (!restoreRequestId || polling) {
                return;
            }

            polling = true;

            try {

                while (!restoreFinished) {

                    try {

                        const url =
                            '/admin/backup/restore_status?request_id=' +
                            encodeURIComponent(restoreRequestId);

                        const response = await fetch(url, {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json'
                            },
                            cache: 'no-store'
                        });

                        const data = await response.json();

                        if (!response.ok || !data.success) {
                            throw new Error(
                                data.message ||
                                'Unable to retrieve restore status.'
                            );
                        }

                        updateRestoreStage(
                            data.stage,
                            data.status,
                            data.message
                        );

                        if (
                            data.status === 'success' ||
                            data.status === 'failed'
                        ) {

                            finishRestore(
                                data.status,
                                data.message
                            );

                            break;
                        }

                    } catch (error) {

                        console.error(
                            'Restore status error:',
                            error
                        );

                        statusDescription.textContent =
                            'Unable to retrieve the latest status. Retrying...';
                    }

                    await wait(1500);
                }

            } finally {

                polling = false;
            }
        }

        // ==========================================
        // CLOSE STATUS MODAL
        // ==========================================

        closeStatusButton.addEventListener('click', function() {

            if (!restoreFinished) {
                return;
            }

            closeModal(statusModal);

            if (statusTitle.textContent === 'Restore Completed') {
                window.location.reload();
            }
        });

    });
</script>