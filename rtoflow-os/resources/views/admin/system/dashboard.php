<?php
/**
 * System Status & Cron Monitor
 * Variables available: $rtoJobs, $nByStatus, $migrations, $totalMigs, $ranMigs, $failedNotifs
 */
if (!defined('ABSPATH')) exit;
?>

<div class="rto-page-header">
  <h1>System Status &amp; Cron Monitor</h1>
</div>

<!-- ── Scheduled Jobs ───────────────────────────────────────────────────── -->
<div class="rto-card rto-mb-4">
  <div class="rto-card-header" style="display:flex;justify-content:space-between;align-items:center">
    <h3>Scheduled Jobs (WP Cron)</h3>
    <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
    <span class="rto-badge rto-badge-success" style="font-size:11px">Server Cron Active ✓</span>
    <?php else: ?>
    <span class="rto-badge rto-badge-warning" style="font-size:11px">⚠ Using WP Pseudo-Cron</span>
    <?php endif; ?>
  </div>
  <div class="rto-card-body">
    <?php if (empty($rtoJobs)): ?>
    <div class="rto-empty-state">
      <p>No RTOFLOW cron jobs scheduled.
        <a href="<?= esc_url(admin_url('plugins.php')) ?>">Deactivate and reactivate the plugin</a> to reschedule.
      </p>
    </div>
    <?php else: ?>
    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr><th>Hook</th><th>Next Run</th><th>Interval</th></tr></thead>
      <tbody>
        <?php foreach ($rtoJobs as $j): ?>
        <tr>
          <td data-label="Hook"><code><?= esc_html($j['hook']) ?></code></td>
          <td data-label="Next Run"><?= esc_html($j['next']) ?></td>
          <td data-label="Interval"><?= esc_html($j['interval']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ── Notification Queue ───────────────────────────────────────────────── -->
<div class="rto-card rto-mb-4">
  <div class="rto-card-header"><h3>Notification Queue</h3></div>
  <div class="rto-card-body">
    <div class="rto-kpi-row" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px">
      <?php foreach (['pending' => 'warning', 'sent' => 'success', 'failed' => 'danger'] as $st => $color): ?>
      <div class="rto-kpi-card" style="background:#f8fafc;padding:16px 24px;border-radius:8px;text-align:center;min-width:100px">
        <div style="font-size:28px;font-weight:800;color:var(--rto-text,#1e293b)"><?= number_format((int)($nByStatus[$st] ?? 0)) ?></div>
        <div style="font-size:12px;color:#64748b;text-transform:uppercase;margin-top:4px"><?= esc_html(ucfirst($st)) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($failedNotifs)): ?>
    <h4 style="margin:0 0 10px">Failed Notifications (Retriable)</h4>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead>
          <tr>
            <th>Template</th><th>Recipient</th><th>Channel</th>
            <th>Attempts</th><th>Error</th><th>Date</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($failedNotifs as $n): ?>
          <tr>
            <td data-label="Template"><?= esc_html($n['template_id']) ?></td>
            <td data-label="Recipient"><?= esc_html($n['recipient']) ?></td>
            <td data-label="Channel"><?= esc_html($n['channel']) ?></td>
            <td data-label="Attempts"><?= (int)$n['attempts'] ?></td>
            <td data-label="Error" style="color:#dc2626;font-size:12px"><?= esc_html(substr($n['error_msg'] ?? '', 0, 80)) ?></td>
            <td data-label="Date"><?= esc_html(rto_date($n['created_at'])) ?></td>
            <td data-label="Actions">
              <button class="rto-btn rto-btn-sm rto-btn-outline retry-notif-btn"
                      data-notif-id="<?= (int)$n['id'] ?>"
                      aria-label="Retry notification <?= (int)$n['id'] ?>">
                Retry
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="rto-empty-small" style="color:#16a34a">✓ No failed notifications.</div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Force Logout ─────────────────────────────────────────────────────── -->
<div class="rto-card rto-mb-4">
  <div class="rto-card-header"><h3>Force User Logout</h3></div>
  <div class="rto-card-body">
    <p class="rto-muted" style="margin:0 0 10px">Immediately terminate all active sessions for any user (e.g. compromised account).</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="number" id="forceLogoutUid" class="rto-input" style="max-width:160px"
             placeholder="WP User ID" min="1" aria-label="WP user ID to force-logout">
      <button class="rto-btn rto-btn-danger" id="forceLogoutBtn">
        Force Logout
      </button>
    </div>
  </div>
</div>

<!-- ── Migrations ───────────────────────────────────────────────────────── -->
<div class="rto-card rto-mb-4">
  <div class="rto-card-header" style="display:flex;align-items:center;justify-content:space-between">
    <h3>Database Migrations</h3>
    <?php if ($ranMigs < $totalMigs): ?>
    <!-- Known Limitations audit fix: this panel used to be status-only —
         MigrationRunner::run() (the exact code plugin activation already
         calls) is now reachable from here too, admin-gated. -->
    <button type="button" id="runMigrationsBtn" class="rto-btn rto-btn--sm rto-btn--primary">Run Pending Migrations</button>
    <?php endif; ?>
  </div>
  <div class="rto-card-body">
    <p><?= (int)$ranMigs ?> / <?= (int)$totalMigs ?> migrations applied.</p>
    <div id="migrationsMsg" class="rto-msg" style="display:none;margin-bottom:10px"></div>
    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr><th>Migration</th><th>Status</th><th>Batch</th></tr></thead>
      <tbody>
        <?php foreach ($migrations as $m): ?>
        <tr>
          <td data-label="Migration"><?= esc_html($m['migration']) ?></td>
          <td data-label="Status">
            <span class="rto-badge rto-badge-<?= $m['ran'] ? 'success' : 'warning' ?>">
              <?= $m['ran'] ? 'Applied' : 'Pending' ?>
            </span>
          </td>
          <td data-label="Batch"><?= esc_html($m['batch'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function() {
  // Retry a failed notification
  function retryNotif(id) {
    var fd = new FormData();
    fd.append('action', 'rto_admin');
    fd.append('rto_area', 'admin');
    fd.append('rto_action', 'retry_notification');
    fd.append('notif_id', id);
    fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
    fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', {
      method: 'POST', credentials: 'same-origin', body: fd
    })
      .then(function(r) { return r.json(); })
      .then(function(r) {
        if (r.success) {
          window.rtoToast && rtoToast('Notification queued for retry.', 'success');
          setTimeout(function() { location.reload(); }, 1200);
        } else {
          window.rtoToast && rtoToast('Retry failed: ' + ((r.data && r.data.message) || 'Error'), 'error');
        }
      })
      .catch(function() {
        window.rtoToast && rtoToast('Request failed. Please try again.', 'error');
      });
  }
  window.retryNotif = retryNotif;

  // CSP fix: 'nonce-...' only covers <script> elements, not inline
  // onclick= attributes — bind Retry buttons via delegation (rows are
  // server-rendered at load, but delegation keeps this robust either way).
  document.querySelectorAll('.retry-notif-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      retryNotif(parseInt(btn.dataset.notifId, 10));
    });
  });

  // Force logout
  function doForceLogout() {
    var uid = parseInt(document.getElementById('forceLogoutUid').value, 10);
    if (!uid || uid < 1) {
      window.rtoToast && rtoToast('Please enter a valid WP User ID.', 'error');
      return;
    }
    if (!confirm('Terminate ALL active sessions for user ID ' + uid + '? They will be logged out immediately.')) return;
    var btn = document.getElementById('forceLogoutBtn');
    btn.disabled = true;
    btn.textContent = 'Logging out…';
    var fd = new FormData();
    fd.append('action', 'rto_admin');
    fd.append('rto_area', 'admin');
    fd.append('rto_action', 'force_logout'); /* FIX P0-1: dispatch key = rto_area + '.' + rto_action; sending 'admin.force_logout' here produced 'admin.admin.force_logout', which never matched the registered 'admin.force_logout' key */
    fd.append('target_user_id', uid);
    fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
    fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', {
      method: 'POST', credentials: 'same-origin', body: fd
    })
      .then(function(r) { return r.json(); })
      .then(function(r) {
        var msg = (r.data && r.data.message) || (r.success ? 'Done.' : 'Failed.');
        window.rtoToast && rtoToast(msg, r.success ? 'success' : 'error');
        btn.disabled = false;
        btn.textContent = 'Force Logout';
      })
      .catch(function() {
        window.rtoToast && rtoToast('Request failed.', 'error');
        btn.disabled = false;
        btn.textContent = 'Force Logout';
      });
  }
  window.doForceLogout = doForceLogout;

  // CSP fix: bind Force Logout button via addEventListener.
  var forceLogoutBtn = document.getElementById('forceLogoutBtn');
  if (forceLogoutBtn) forceLogoutBtn.addEventListener('click', doForceLogout);

  // Known Limitations audit fix: Database Migrations panel — see the
  // matching TRACE comment on Router::runMigrations().
  var runBtn = document.getElementById('runMigrationsBtn');
  if (runBtn) {
    runBtn.addEventListener('click', function() {
      if (!confirm('Apply every pending database migration now? This directly changes your database schema — make sure you have a backup before proceeding.')) return;
      runBtn.disabled = true;
      runBtn.textContent = 'Running…';
      var fd = new FormData();
      fd.append('action', 'rto_admin');
      fd.append('rto_area', 'admin');
      fd.append('rto_action', 'run_migrations');
      fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
      fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', {
        method: 'POST', credentials: 'same-origin', body: fd
      })
        .then(function(r) { return r.json(); })
        .then(function(r) {
          var box = document.getElementById('migrationsMsg');
          box.className = 'rto-msg rto-msg-' + (r.success ? 'success' : 'error');
          box.textContent = r.message || (r.success ? 'Done.' : 'Failed.');
          box.style.display = '';
          if (r.success) { setTimeout(function() { location.reload(); }, 1200); }
          else { runBtn.disabled = false; runBtn.textContent = 'Run Pending Migrations'; }
        })
        .catch(function() {
          var box = document.getElementById('migrationsMsg');
          box.className = 'rto-msg rto-msg-error';
          box.textContent = 'Request failed.';
          box.style.display = '';
          runBtn.disabled = false;
          runBtn.textContent = 'Run Pending Migrations';
        });
    });
  }
})();
</script>
<?php rto_help_box('system'); ?>
