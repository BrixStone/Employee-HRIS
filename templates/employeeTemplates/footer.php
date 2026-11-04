  </section>
</main>

<?php if (!empty($embedded)): ?>
<?php /* Embedded (inside another page's iframe): no sidebar shell, so skip the
         Pocket Profile modal and page-transition script entirely. */ ?>
<?= $footerExtra ?? '' ?>
</body>
</html>
<?php return; ?>
<?php endif; ?>

<?php
/**
 * Pocket Profile modal — available on every employee page so the sidebar
 * profile card opens it in a single click from anywhere (no redirect).
 *
 * Uses the sidebar identity ($navName/$navEmail/$navAvatar) plus any richer
 * "About Me" fields a page chooses to expose via $pocketProfile:
 *   ['id','email','phone','status']
 */
$pp = $pocketProfile ?? [];
$ppName    = $navName ?? ($pp['name'] ?? 'Employee');
$ppEmail   = $pp['email'] ?? ($navEmail ?? '');
// Prefer an avatar the page resolved (uploaded picture); fall back to sidebar.
$ppAvatar  = $pp['avatar'] ?? ($navAvatar ?? ('https://ui-avatars.com/api/?name=' . urlencode($ppName) . '&background=4763ff&color=fff'));
$ppId      = $pp['id'] ?? null;
$ppPhone   = $pp['phone'] ?? null;
$ppStatus  = $pp['status'] ?? null;
$ppActive  = $ppStatus !== null ? (strcasecmp((string)$ppStatus, 'Active') === 0) : null;
$ppAllowUpload = !empty($pp['allow_upload']);
?>
<div class="pocket-overlay" id="pocketOverlay" aria-hidden="true">
  <div class="pocket-dialog" role="dialog" aria-modal="true" aria-labelledby="pocketName">
    <div class="pocket-card">
      <button type="button" class="pocket-close" id="closePocketProfile" aria-label="Close">&times;</button>

      <div class="pocket-head">
        <?php if ($ppAllowUpload): ?>
          <!-- Clickable avatar that opens the file browser to change the photo -->
          <form id="pocketAvatarForm" action="/employee/upload_avatar.php" method="post" enctype="multipart/form-data">
            <button type="button" class="pocket-avatar-btn" id="pocketAvatarBtn" title="Change profile photo" aria-label="Change profile photo">
              <img src="<?= htmlspecialchars($ppAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="Profile avatar" class="pocket-avatar">
              <span class="pocket-avatar-edit" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" stroke="currentColor" stroke-width="2"/>
                  <path d="M4 8.5 5.4 6A2 2 0 0 1 7.1 5h9.8a2 2 0 0 1 1.7 1l1.4 2.5A2 2 0 0 1 21 9.6V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9.6c0-.38.1-.76.3-1.1Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                </svg>
              </span>
            </button>
            <input type="file" name="avatar" id="pocketAvatarInput" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
          </form>
        <?php else: ?>
          <img src="<?= htmlspecialchars($ppAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="Profile avatar" class="pocket-avatar">
        <?php endif; ?>
        <div class="pocket-id">
          <h3 id="pocketName"><?= htmlspecialchars($ppName, ENT_QUOTES, 'UTF-8') ?></h3>
          <?php if ($ppStatus !== null): ?>
            <span class="pill <?= $ppActive ? 'pill-active' : 'pill-inactive' ?>"><?= htmlspecialchars((string)$ppStatus, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="pocket-about">
        <p class="eyebrow">ABOUT ME</p>
        <ul class="pocket-about-list">
          <?php if ($ppId !== null): ?>
            <li><span class="pa-label">Employee ID</span><strong class="pa-value">#<?= htmlspecialchars((string)$ppId, ENT_QUOTES, 'UTF-8') ?></strong></li>
          <?php endif; ?>
          <li><span class="pa-label">Full Name</span><strong class="pa-value"><?= htmlspecialchars($ppName, ENT_QUOTES, 'UTF-8') ?></strong></li>
          <li><span class="pa-label">Email Address</span><strong class="pa-value teal"><?= htmlspecialchars($ppEmail, ENT_QUOTES, 'UTF-8') ?></strong></li>
          <?php if ($ppPhone !== null): ?>
            <li><span class="pa-label">Phone Number</span><strong class="pa-value"><?= htmlspecialchars((string)$ppPhone, ENT_QUOTES, 'UTF-8') ?></strong></li>
          <?php endif; ?>
          <?php if ($ppStatus !== null): ?>
            <li><span class="pa-label">Employment Status</span><strong class="pa-value <?= $ppActive ? 'green' : '' ?>"><?= htmlspecialchars((string)$ppStatus, ENT_QUOTES, 'UTF-8') ?></strong></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    var el = document.getElementById('live-datetime');
    if (!el) return;
    function tick() {
      var now = new Date();
      var d = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
      var t = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      el.textContent = d + ' · ' + t;
    }
    tick();
    setInterval(tick, 1000);
  })();
</script>

<script>
  (function () {
    var overlay = document.getElementById('pocketOverlay');
    if (!overlay) return;

    function openModal() {
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }
    function closeModal() {
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    // Delegated so the sidebar card opens the modal from any page in one click.
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-pocket-trigger]')) {
        e.preventDefault();
        e.stopPropagation();
        openModal();
        return;
      }
      if (e.target.closest('#closePocketProfile')) {
        e.preventDefault();
        closeModal();
        return;
      }
      if (e.target === overlay) closeModal();
    });

    document.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('[data-pocket-trigger]')) {
        e.preventDefault();
        openModal();
      }
      if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

    // --- Profile photo upload (only present on the profile page) ---
    var avatarInput = document.getElementById('pocketAvatarInput');
    var avatarForm  = document.getElementById('pocketAvatarForm');
    if (avatarInput && avatarForm) {
      function pickFile(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        avatarInput.click();                 // opens the OS file browser
      }
      var avatarBtn = document.getElementById('pocketAvatarBtn');
      if (avatarBtn) avatarBtn.addEventListener('click', pickFile);

      // Submit as soon as the user chooses an image.
      avatarInput.addEventListener('change', function () {
        if (avatarInput.files && avatarInput.files.length > 0) {
          avatarForm.submit();
        }
      });
    }
  })();
</script>

<script src="../asset/js/page-transition.js" defer></script>
<?php if (!empty($realtime)): ?>
<!-- Real-time updates: only this employee's own leave events arrive here -->
<script src="../asset/js/realtime.js" data-refresh="<?= htmlspecialchars($realtimeRefresh ?? 'prompt', ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?= $footerExtra ?? '' ?>
</body>
</html>
