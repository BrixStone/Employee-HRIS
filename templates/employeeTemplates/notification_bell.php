<?php
// templates/employeeTemplates/notification_bell.php
// Drop-in notification bell for all Employee pages (2-day pending leave reminders + payout-due alerts).

?>
<style>
  .hris-emp-bell-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
  }
  .hris-emp-bell-btn {
    position: relative;
    width: 36px;
    height: 36px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    background: rgba(30, 41, 59, 0.72);
    color: #f8fafc;
    font-size: 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
  }
  .hris-emp-bell-count {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 17px;
    height: 17px;
    padding: 0 4px;
    border-radius: 999px;
    background: #ef4444;
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .hris-emp-bell-panel {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 350px;
    max-width: calc(100vw - 24px);
    border-radius: 14px;
    background: #0f172a;
    border: 1px solid rgba(96, 165, 250, 0.35);
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.55);
    z-index: 1200;
    overflow: hidden;
  }
  .hris-emp-bell-panel.is-open {
    display: block;
  }
  .hris-emp-bell-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 14px;
    background: rgba(30, 41, 59, 0.85);
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    color: #f8fafc;
    font-size: 13px;
    font-weight: 700;
  }
  .hris-emp-bell-status {
    padding: 6px 14px;
    font-size: 11.5px;
    color: #93c5fd;
    background: rgba(37, 99, 235, 0.12);
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
  }
  .hris-emp-bell-list {
    max-height: 300px;
    overflow-y: auto;
    padding: 8px 12px;
  }
  .hris-emp-bell-item {
    padding: 9px 4px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    font-size: 12.5px;
  }
  .hris-emp-bell-item:last-child {
    border-bottom: none;
  }
  .hris-emp-bell-top {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 3px;
  }
  .hris-emp-bell-title {
    font-weight: 700;
    color: #f8fafc;
  }
  .hris-emp-bell-tag {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 2px 6px;
    border-radius: 6px;
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
  }
  .hris-emp-bell-tag.payout {
    background: rgba(16, 185, 129, 0.2);
    color: #34d399;
  }
  .hris-emp-bell-msg {
    margin: 0;
    color: #cbd5e1;
    line-height: 1.35;
  }
  .hris-emp-bell-foot {
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
    font-size: 11px;
    color: #94a3b8;
  }
  .hris-emp-bell-foot a {
    color: #60a5fa;
    text-decoration: none;
    font-weight: 600;
  }
</style>

<div id="hris-employee-bell" class="hris-emp-bell-wrap"></div>

<script>
(function () {
  document.addEventListener('DOMContentLoaded', () => {
    const mount = document.getElementById('hris-employee-bell');
    if (!mount || mount.dataset.empBellMounted === '1') return;
    mount.dataset.empBellMounted = '1';

    mount.innerHTML = `
      <button type="button" class="hris-emp-bell-btn" aria-label="Employee Notifications" aria-expanded="false">
        <span>🔔</span>
        <span class="hris-emp-bell-count" data-emp-bell-count>0</span>
      </button>
      <div class="hris-emp-bell-panel" role="dialog">
        <div class="hris-emp-bell-head">
          <span>My Notifications</span>
        </div>
        <div class="hris-emp-bell-status" data-emp-bell-status>Checking alerts...</div>
        <div class="hris-emp-bell-list" data-emp-bell-list></div>
      </div>
    `;

    const btn = mount.querySelector('.hris-emp-bell-btn');
    const panel = mount.querySelector('.hris-emp-bell-panel');
    const countEl = mount.querySelector('[data-emp-bell-count]');
    const statusEl = mount.querySelector('[data-emp-bell-status]');
    const listEl = mount.querySelector('[data-emp-bell-list]');

    const esc = (s) => {
      const d = document.createElement('div');
      d.textContent = s == null ? '' : String(s);
      return d.innerHTML;
    };

    function renderItems(items) {
      if (!Array.isArray(items) || items.length === 0) {
        listEl.innerHTML = '<div class="hris-emp-bell-item"><p class="hris-emp-bell-msg">No notifications right now.</p></div>';
        return;
      }
      listEl.innerHTML = items.map((n) => {
        const isPayout = (n.category || '').toLowerCase() === 'payout';
        return `
          <div class="hris-emp-bell-item">
            <div class="hris-emp-bell-top">
              <span class="hris-emp-bell-title">${esc(n.title || 'Notification')}</span>
              <span class="hris-emp-bell-tag ${isPayout ? 'payout' : ''}">${isPayout ? 'Payout' : 'Leave'}</span>
            </div>
            <p class="hris-emp-bell-msg">${esc(n.message || '')}</p>
            <div class="hris-emp-bell-foot">
              <span>${esc(n.time || '')}</span>
              ${n.url ? `<a href="${esc(n.url)}">Open →</a>` : ''}
            </div>
          </div>
        `;
      }).join('');
    }

    async function loadEmployeeNotifications() {
      try {
        const res = await fetch('/api/hr_cron_notifications.php?action=list&audience=employee&_ts=' + Date.now(), {
          cache: 'no-store'
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          statusEl.textContent = (data && data.error) ? data.error : 'Unable to load alerts';
          return;
        }
        if (typeof data.unread_count === 'number') {
          countEl.textContent = String(data.unread_count);
        }
        renderItems(data.notifications || []);
        statusEl.textContent = 'Updated • ' + (data.last_run_at || 'Live');
      } catch (_) {
        statusEl.textContent = 'Offline / reconnecting...';
      }
    }

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = !panel.classList.contains('is-open');
      panel.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', String(open));
      if (open) loadEmployeeNotifications();
    });

    document.addEventListener('click', (e) => {
      if (!panel.contains(e.target) && !btn.contains(e.target)) {
        panel.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    loadEmployeeNotifications();
    setInterval(loadEmployeeNotifications, 15000);
  });
})();
</script>