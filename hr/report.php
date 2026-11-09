<?php
// hr/report.php
require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'view', 'all');
require_once __DIR__ . '/../config/database.php';

// Compute all report figures (and handle the ?export=headcount_csv download).
require __DIR__ . '/_report_data.php';

// Render inside the shared HR dashboard shell (sidebar + topbar),
// exactly like the other HR nav pages.
require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<div class="welcome-row">
  <div>
    <p class="eyebrow">OVERVIEW</p>
    <h1>Reports &amp; Audits</h1>
    <p class="muted">Headcount, department breakdown, and exportable HR reports.</p>
  </div>
  <a class="btn btn-green"
     href="/hr/report.php?export=headcount_csv&sort_by=<?= urlencode($sortBy) ?>&dir=<?= urlencode($dir) ?>">
    ⭳ Export CSV
  </a>
</div>

<?php
// Standalone Reports page: render the shared section (not embedded).
$reportEmbedded = false;
require __DIR__ . '/_report_section.php';
