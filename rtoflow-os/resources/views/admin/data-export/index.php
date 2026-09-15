<?php if (!defined('ABSPATH')) exit;
/** @var bool $enabled @var string $webhookUrl @var array $runs
 * ENTERPRISE GAP FIX (Phase 12, item — "No data-warehouse / BI export
 * path"): admin screen for BiExportService.
 */
$pageTitle = 'Data Export';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Data Export</h1>
  </div>
  <p class="rto-small rto-muted rto-mb-4">
    Nightly (or on-demand) full-snapshot export of leads, payments, vendors, complaints, and ratings as
    newline-delimited JSON — the format most warehouse loaders (BigQuery, Snowflake, S3+Athena) ingest
    directly. This is separate from the in-admin Reports CSV export, which stays for one-off, human-read
    reporting.
  </p>

  <div id="deMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Schedule Settings</h3></div>
    <div class="rto-card-body">
      <form id="deSettingsForm" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <label style="display:flex;align-items:center;gap:6px">
          <input type="checkbox" id="deEnabled" <?= $enabled ? 'checked' : '' ?>>
          Run automatically every night
        </label>
        <div class="rto-form-group" style="flex:1;min-width:260px">
          <label class="rto-label" for="deWebhookUrl">Destination Webhook URL (optional — receives a small completion manifest, not the full data)</label>
          <input type="url" id="deWebhookUrl" class="rto-input" value="<?= esc_attr($webhookUrl) ?>" placeholder="https://your-warehouse-loader.example.com/rtoflow-export">
        </div>
        <button type="submit" class="rto-btn rto-btn-secondary">Save Settings</button>
        <button type="button" id="deRunNowBtn" class="rto-btn rto-btn-primary">Run Export Now</button>
      </form>
    </div>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>Export History (<?= count($runs) ?>)</h3></div>
    <?php if (empty($runs)): ?>
    <div class="rto-empty-state"><p>No exports have run yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Started</th><th>Type</th><th>Status</th><th>Rows</th><th>Size</th><th>Webhook Sent</th><th><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r):
          $counts = $r['row_counts'] ? json_decode($r['row_counts'], true) : [];
          $totalRows = is_array($counts) ? array_sum($counts) : 0;
        ?>
        <tr>
          <td data-label="Started" class="rto-small rto-muted"><?= esc_html(date('d M Y H:i', strtotime($r['started_at']))) ?></td>
          <td data-label="Type"><?= esc_html(ucfirst($r['run_type'])) ?></td>
          <td data-label="Status">
            <span class="rto-badge rto-badge-<?= $r['status'] === 'success' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= esc_html(ucfirst($r['status'])) ?></span>
          </td>
          <td data-label="Rows"><?= esc_html((string)$totalRows) ?></td>
          <td data-label="Size" class="rto-small rto-muted"><?= $r['file_size_bytes'] ? esc_html(size_format((int)$r['file_size_bytes'])) : '—' ?></td>
          <td data-label="Webhook Sent"><?= $r['destination_sent'] ? '✓' : '—' ?></td>
          <td data-label="Actions">
            <?php if ($r['status'] === 'success'): ?>
            <a class="rto-btn rto-btn-xs" href="<?= esc_url(admin_url('admin-ajax.php') . '?action=rto_admin&rto_area=admin&rto_action=download_bi_export&rto_nonce=' . $nonce . '&log_id=' . (int)$r['id']) ?>">Download</a>
            <?php else: ?>
            <span class="rto-small rto-muted"><?= esc_html($r['error_message'] ?? '') ?></span>
            <?php endif; ?>
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
  var msg = document.getElementById('deMsg');
  function showMsg(success, text){
    msg.style.display = 'block';
    msg.className = 'rto-msg ' + (success ? 'rto-msg-success' : 'rto-msg-error');
    msg.textContent = text;
  }
  document.getElementById('deSettingsForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','save_data_export_settings');
    fd.append('rto_nonce', nonce);
    fd.append('enabled', document.getElementById('deEnabled').checked ? '1' : '0');
    fd.append('webhook_url', document.getElementById('deWebhookUrl').value.trim());
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
      showMsg(r.success, r.message || (r.success ? 'Saved.' : 'Failed.'));
    }).catch(function(){ showMsg(false, 'Network error — please try again.'); });
  });
  document.getElementById('deRunNowBtn').addEventListener('click', function(){
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Running…';
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','run_data_export_now');
    fd.append('rto_nonce', nonce);
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
      showMsg(r.success, r.message || (r.success ? 'Done.' : 'Failed.'));
      if (r.success) location.reload();
    }).catch(function(){ showMsg(false, 'Network error — please try again.'); }).finally(function(){
      btn.disabled = false; btn.textContent = 'Run Export Now';
    });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
