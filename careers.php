<?php
// careers.php — Public Job Application Portal (Real-Time Job Vacancies)

date_default_timezone_set('Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/config/database.php';

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {}

function car_ensure_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `job_vacancies` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(160) NOT NULL,
            `department` VARCHAR(120) NOT NULL DEFAULT 'General',
            `employment_type` VARCHAR(64) NOT NULL DEFAULT 'Full-time',
            `openings` INT NOT NULL DEFAULT 1,
            `status` VARCHAR(32) NOT NULL DEFAULT 'Open',
            `description` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `job_applicants` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `vacancy_id` INT UNSIGNED NULL,
            `full_name` VARCHAR(160) NOT NULL,
            `email` VARCHAR(190) NOT NULL,
            `phone` VARCHAR(64) NULL,
            `job_title` VARCHAR(160) NOT NULL DEFAULT 'Applicant',
            `department` VARCHAR(120) NOT NULL DEFAULT 'General',
            `stage` VARCHAR(32) NOT NULL DEFAULT 'Applied',
            `interview_at` DATETIME NULL,
            `interview_mode` VARCHAR(64) NULL DEFAULT 'Online / Video Call',
            `interview_location` VARCHAR(255) NULL,
            `interviewer_name` VARCHAR(160) NULL,
            `interview_notes` TEXT NULL,
            `email_sent_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function car_fetch_open_vacancies(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT `id`, `title`, `department`, `employment_type`, `openings`, `status`
        FROM `job_vacancies`
        WHERE LOWER(COALESCE(`status`, 'open')) = 'open'
        ORDER BY `id` DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function car_broadcast_event(string $type, array $payload = []): void {
    $wsNotifyUrl = trim((string)(getenv('WS_NOTIFY_URL') ?: 'http://127.0.0.1:8090/notify'));
    if ($wsNotifyUrl === '' || !function_exists('curl_init')) return;

    $body = json_encode([
        'event' => [
            'type'      => $type,
            'timestamp' => date('c'),
            'data'      => $payload,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($wsNotifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => $body,
        CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_TIMEOUT_MS        => 350,
        CURLOPT_CONNECTTIMEOUT_MS => 200,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

$successMsg = '';
$errorMsg   = '';
$vacancies  = [];

try {
    car_ensure_tables($pdo);
    $vacancies = car_fetch_open_vacancies($pdo);
} catch (Throwable $e) {
    $errorMsg = 'Unable to load open job vacancies right now.';
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'vacancies') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        car_ensure_tables($pdo);
        $list = car_fetch_open_vacancies($pdo);
        echo json_encode([
            'ok'          => true,
            'server_time' => date('h:i:s A'),
            'vacancies'   => $list,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName  = trim((string)($_POST['full_name'] ?? ''));
    $email     = trim((string)($_POST['email'] ?? ''));
    $phone     = trim((string)($_POST['phone'] ?? ''));
    $vacancyId = (int)($_POST['vacancy_id'] ?? 0);
    $notes     = trim((string)($_POST['cover_notes'] ?? ''));

    try {
        if ($fullName === '' || $email === '' || $vacancyId <= 0) {
            throw new RuntimeException('Please complete your name, email address, and select an open position.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $vStmt = $pdo->prepare("SELECT * FROM `job_vacancies` WHERE `id` = ? LIMIT 1");
        $vStmt->execute([$vacancyId]);
        $vacancy = $vStmt->fetch(PDO::FETCH_ASSOC);
        if (!$vacancy) {
            throw new RuntimeException('The selected job vacancy is no longer available.');
        }

        $ins = $pdo->prepare("
            INSERT INTO `job_applicants`
                (`vacancy_id`, `full_name`, `email`, `phone`, `job_title`, `department`, `stage`, `interview_notes`)
            VALUES
                (?, ?, ?, ?, ?, ?, 'Applied', ?)
        ");
        $ins->execute([
            $vacancyId,
            $fullName,
            $email,
            $phone !== '' ? $phone : null,
            (string)$vacancy['title'],
            (string)$vacancy['department'],
            $notes !== '' ? $notes : null,
        ]);

        $newId = (int)$pdo->lastInsertId();
        car_broadcast_event('recruitment.applicant_submitted', [
            'applicant_id' => $newId,
            'full_name'    => $fullName,
            'job_title'    => (string)$vacancy['title'],
        ]);

        $successMsg = "Application submitted for {$vacancy['title']}! HR will review your application and send interview details to {$email}.";
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Careers &amp; Job Application</title>
  <style>
    body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #0b1120; color: #f8fafc; padding: 32px 16px; }
    .car-box { max-width: 640px; margin: 0 auto; background: #111827; border: 1px solid rgba(148,163,184,0.2); border-radius: 16px; padding: 28px; }
    .car-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 18px; }
    h1 { margin: 0 0 6px; font-size: 24px; color: #ffffff; }
    p.sub { margin: 0; color: #94a3b8; font-size: 13.5px; }
    .live-pill {
      display: inline-flex; align-items: center; gap: 7px; padding: 5px 11px; border-radius: 999px;
      font-size: 11.5px; font-weight: 600; background: rgba(16,185,129,0.14); color: #34d399;
      border: 1px solid rgba(52,211,153,0.35); white-space: nowrap;
    }
    .live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; }
    .field { margin-bottom: 14px; }
    label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 6px; }
    input, select, textarea {
      width: 100%; padding: 10px 12px; border-radius: 9px; border: 1px solid #334155;
      background: #0f172a; color: #f8fafc; font-size: 14px; box-sizing: border-box;
    }
    button {
      width: 100%; padding: 11px 16px; border-radius: 9px; border: none; background: #2563eb;
      color: #ffffff; font-size: 14px; font-weight: 700; cursor: pointer; margin-top: 6px;
    }
    button:hover { background: #1d4ed8; }
    .alert-ok { padding: 12px 14px; border-radius: 10px; background: rgba(16,185,129,0.16); border: 1px solid rgba(52,211,153,0.4); color: #6ee7b7; margin-bottom: 16px; font-size: 13.5px; }
    .alert-err { padding: 12px 14px; border-radius: 10px; background: rgba(239,68,68,0.16); border: 1px solid rgba(248,113,113,0.4); color: #fca5a5; margin-bottom: 16px; font-size: 13.5px; }
    .job-hint { font-size: 12px; color: #94a3b8; margin-top: 6px; }
  </style>
</head>
<body>
  <div class="car-box">
    <div class="car-top">
      <div>
        <h1>Join Our Team</h1>
        <p class="sub">Open positions sync automatically as HR posts new jobs.</p>
      </div>
      <div class="live-pill">
        <span class="live-dot"></span>
        <span id="carLiveStatus">Live Jobs • <?= date('h:i:s A') ?></span>
      </div>
    </div>

    <?php if ($successMsg !== ''): ?>
      <div class="alert-ok"><?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?></div>
      <script>
        try {
          if ('BroadcastChannel' in window) {
            new BroadcastChannel('hris_realtime_sync').postMessage({ type: 'recruitment.applicant_submitted' });
          }
        } catch (_) {}
      </script>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
      <div class="alert-err"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="field">
        <label>Open Position (<span id="carOpenCount"><?= count($vacancies) ?></span> available)</label>
        <select name="vacancy_id" id="carVacancySelect" required>
          <option value="">-- Choose a Job Vacancy --</option>
          <?php foreach ($vacancies as $v): ?>
            <option value="<?= (int)$v['id'] ?>">
              <?= htmlspecialchars((string)$v['title'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string)$v['department'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$v['employment_type'], ENT_QUOTES, 'UTF-8') ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="job-hint" id="carJobHint">
          <?= empty($vacancies) ? 'No open job vacancies right now — waiting for new postings...' : 'Select an open role above to apply.' ?>
        </div>
      </div>

      <div class="field">
        <label>Full Name</label>
        <input type="text" name="full_name" required placeholder="e.g., Juan Dela Cruz">
      </div>

      <div class="field">
        <label>Email Address (for interview invitations)</label>
        <input type="email" name="email" required placeholder="juan.delacruz@example.com">
      </div>

      <div class="field">
        <label>Phone / Mobile Number</label>
        <input type="text" name="phone" placeholder="e.g., +63 917 000 0000">
      </div>

      <div class="field">
        <label>Summary / Portfolio Link / Notes (Optional)</label>
        <textarea name="cover_notes" rows="3" placeholder="Share a brief intro, GitHub/portfolio link, or availability..."></textarea>
      </div>

      <button type="submit">Submit Application</button>
    </form>
  </div>

  <script>
    (function () {
      let lastSignature = <?= json_encode(json_encode($vacancies, JSON_UNESCAPED_UNICODE), JSON_UNESCAPED_UNICODE) ?>;

      function esc(str) {
        return String(str ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      function renderVacancies(list) {
        const rows = Array.isArray(list) ? list : [];
        const sig = JSON.stringify(rows);
        if (sig === lastSignature) return;
        lastSignature = sig;

        const select = document.getElementById('carVacancySelect');
        const countEl = document.getElementById('carOpenCount');
        const hintEl = document.getElementById('carJobHint');
        if (!select) return;

        const prevSelected = String(select.value || '');
        const options = ['<option value="">-- Choose a Job Vacancy --</option>'];

        for (const v of rows) {
          const idStr = String(v.id);
          const label = `${v.title} — ${v.department} (${v.employment_type})`;
          const sel = idStr === prevSelected ? ' selected' : '';
          options.push(`<option value="${esc(idStr)}"${sel}>${esc(label)}</option>`);
        }

        select.innerHTML = options.join('');
        if (countEl) countEl.textContent = String(rows.length);
        if (hintEl) {
          hintEl.textContent = rows.length === 0
            ? 'No open job vacancies right now — waiting for new postings...'
            : 'Open positions updated automatically.';
        }
      }

      async function syncVacancies() {
        try {
          const res = await fetch('?ajax=vacancies&_t=' + Date.now(), { cache: 'no-store' });
          if (!res.ok) return;
          const data = await res.json();
          if (data && data.ok) {
            renderVacancies(data.vacancies || []);
            const st = document.getElementById('carLiveStatus');
            if (st && data.server_time) {
              st.textContent = 'Live Jobs • ' + data.server_time;
            }
          }
        } catch (_) {}
      }

      setInterval(syncVacancies, 2000);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) syncVacancies();
      });

      try {
        if ('BroadcastChannel' in window) {
          const bc = new BroadcastChannel('hris_realtime_sync');
          bc.onmessage = (ev) => {
            const t = ev && ev.data && String(ev.data.type || '');
            if (t.startsWith('recruitment.')) syncVacancies();
          };
        }
      } catch (_) {}

      (async function connectWs() {
        try {
          for (const url of ['/api/realtime_token.php']) {
            const r = await fetch(url, { cache: 'no-store' });
            if (!r.ok) continue;
            const j = await r.json();
            const token = j.token || (j.data && j.data.token) || '';
            if (!token) continue;
            const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
            ws.onmessage = (ev) => {
              try {
                const pkt = JSON.parse(ev.data);
                const t = (pkt && pkt.event && pkt.event.type) || '';
                if (t.startsWith('recruitment.')) syncVacancies();
              } catch (_) {}
            };
            break;
          }
        } catch (_) {}
      })();
    })();
  </script>
</body>
</html>