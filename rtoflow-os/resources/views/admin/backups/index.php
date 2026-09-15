<?php if (!defined('ABSPATH')) exit;
/** @var array $backups
 * ENTERPRISE GAP FIX (Phase 2, item 5 — backup/DR automation): see
 * BackupService's class docblock. Runs automatically weekly; this screen
 * lists what exists and lets an admin trigger one on demand or download
 * any retained backup for offsite storage. */
$pageTitle = 'Backups';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Backups</h1>
  </div>
  <p class="rto-small rto-muted rto-mb-4">
    A full database backup runs automatically every week. The last 8 are kept here; download any of them
    for offsite/cold storage. Restoring: <code>gunzip -c &lt;file&gt; | mysql -u USER -p DATABASE</code>.
  </p>

  <div id="backupMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div class="rto-card">
    <div class="rto-card-header" style="display:flex;justify-content:space-between;align-items:center">
      <h3>Available Backups (<?= count($backups) ?>)</h3>
      <button type="button" id="backupNowBtn" class="rto-btn rto-btn-primary rto-btn-xs">Back Up Now</button>
    </div>
    <?php if (empty($backups)): ?>
    <div class="rto-empty-state"><p>No backups yet. Click "Back Up Now" to create the first one, or wait for the weekly automatic run.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Filename</th><th>Size</th><th>Created</th><th><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
        <tr>
          <td data-label="Filename"><?= esc_html($b['filename']) ?></td>
          <td data-label="Size"><?= number_format($b['size_kb']) ?> KB</td>
          <td data-label="Created" class="rto-small rto-muted"><?= esc_html(date('d M Y H:i', $b['created_at'])) ?></td>
          <td data-label="Actions">
            <a class="rto-btn rto-btn-xs rto-btn-outline" href="<?= esc_url(add_query_arg(['download' => $b['filename']])) ?>">Download</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = <?= wp_json_encode($nonce) ?>;
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';
  var btn = document.getElementById('backupNowBtn');
  var msg = document.getElementById('backupMsg');
  btn.addEventListener('click', function(){
    btn.disabled = true; btn.textContent = 'Backing up…';
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','run_backup_now');
    fd.append('rto_nonce', nonce);
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
      msg.style.display = 'block';
      msg.className = 'rto-msg ' + (r.success ? 'rto-msg-success' : 'rto-msg-error');
      msg.textContent = r.message || (r.success ? 'Backup created.' : 'Backup failed.');
      btn.disabled = false; btn.textContent = 'Back Up Now';
      if (r.success) location.reload();
    }).catch(function(){
      btn.disabled = false; btn.textContent = 'Back Up Now';
      msg.style.display = 'block'; msg.className = 'rto-msg rto-msg-error'; msg.textContent = 'Network error — please try again.';
    });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
