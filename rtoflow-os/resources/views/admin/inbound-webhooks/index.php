<?php if (!defined('ABSPATH')) exit;
/** @var array $providers @var array $log
 * ENTERPRISE GAP FIX (Phase 12, item — "No inbound webhook framework
 * beyond per-integration handlers"): admin screen for InboundWebhookService.
 */
$pageTitle = 'Inbound Webhooks';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Inbound Webhooks</h1>
  </div>
  <p class="rto-small rto-muted rto-mb-4">
    A generic, reusable framework for a NEW integration to send us signed inbound calls without any code
    change — register a provider below and it gets a URL under
    <code><?= esc_html(home_url('/rto-webhook/custom/')) ?>&lt;slug&gt;/</code>, signature-verified against
    the secret you set and logged the same way as every other inbound call. Razorpay and WhatsApp keep their
    own existing dedicated handlers (payment/messaging integrations already in production), but they now log
    into the same table you see below.
  </p>

  <div id="iwMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Register New Provider</h3></div>
    <div class="rto-card-body">
      <form id="registerProviderForm" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;align-items:end">
        <div class="rto-form-group">
          <label class="rto-label" for="ivSlug">Slug</label>
          <input type="text" id="ivSlug" class="rto-input" placeholder="e.g. franchise-portal" required>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="ivLabel">Label</label>
          <input type="text" id="ivLabel" class="rto-input" placeholder="Franchise Portal" required>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="ivHeader">Signature Header</label>
          <input type="text" id="ivHeader" class="rto-input" value="X-Signature">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="ivScheme">Signature Scheme</label>
          <select id="ivScheme" class="rto-input">
            <option value="hmac_sha256_hex">HMAC-SHA256 (raw hex — Razorpay-style)</option>
            <option value="hmac_sha256_prefix">HMAC-SHA256 ("sha256=" prefix — Meta-style)</option>
          </select>
        </div>
        <div style="grid-column:1/-1">
          <button type="submit" class="rto-btn rto-btn-primary">Register Provider</button>
        </div>
      </form>
      <div id="newProviderBox" style="display:none;margin-top:14px" class="rto-msg rto-msg-warning">
        <strong>Copy this secret now — it will not be shown again:</strong>
        <div style="font-family:monospace;word-break:break-all;margin-top:6px;padding:8px;background:#fff;border-radius:6px" id="newProviderSecret"></div>
        <div class="rto-small rto-mt-2">Endpoint URL: <code id="newProviderUrl" style="word-break:break-all"></code></div>
      </div>
    </div>
  </div>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Registered Providers (<?= count($providers) ?>)</h3></div>
    <?php if (empty($providers)): ?>
    <div class="rto-empty-state"><p>No custom providers registered yet — Razorpay and WhatsApp use their own dedicated handlers and don't appear here.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Label</th><th>Slug</th><th>Scheme</th><th>Status</th><th>Created</th><th><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($providers as $p): ?>
        <tr data-provider-id="<?= esc_attr($p['id']) ?>">
          <td data-label="Label"><?= esc_html($p['label']) ?></td>
          <td data-label="Slug"><code><?= esc_html($p['slug']) ?></code></td>
          <td data-label="Scheme" class="rto-small rto-muted"><?= esc_html($p['signature_scheme']) ?></td>
          <td data-label="Status"><span class="rto-badge rto-badge-<?= $p['is_active'] ? 'success' : 'secondary' ?>"><?= $p['is_active'] ? 'Active' : 'Disabled' ?></span></td>
          <td data-label="Created" class="rto-small rto-muted"><?= esc_html(date('d M Y', strtotime($p['created_at']))) ?></td>
          <td data-label="Actions">
            <button type="button" class="rto-btn rto-btn-xs iw-toggle-btn" data-active="<?= $p['is_active'] ? '1' : '0' ?>"><?= $p['is_active'] ? 'Disable' : 'Enable' ?></button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>Recent Inbound Activity (last <?= count($log) ?>)</h3></div>
    <?php if (empty($log)): ?>
    <div class="rto-empty-state"><p>No inbound webhook calls logged yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Provider</th><th>Event</th><th>Signature</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($log as $l): ?>
        <tr>
          <td data-label="Provider"><?= esc_html($l['provider']) ?></td>
          <td data-label="Event" class="rto-small rto-muted"><?= esc_html($l['event_type'] ?? '—') ?></td>
          <td data-label="Signature"><span class="rto-badge rto-badge-<?= $l['signature_valid'] ? 'success' : 'danger' ?>"><?= $l['signature_valid'] ? 'Valid' : 'Rejected' ?></span></td>
          <td data-label="IP" class="rto-small rto-muted"><?= esc_html($l['remote_ip'] ?? '—') ?></td>
          <td data-label="When" class="rto-small rto-muted"><?= esc_html(date('d M Y H:i', strtotime($l['created_at']))) ?></td>
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
  var msg = document.getElementById('iwMsg');
  function showMsg(success, text){
    msg.style.display = 'block';
    msg.className = 'rto-msg ' + (success ? 'rto-msg-success' : 'rto-msg-error');
    msg.textContent = text;
  }
  document.getElementById('registerProviderForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','register_inbound_webhook');
    fd.append('rto_nonce', nonce);
    fd.append('slug', document.getElementById('ivSlug').value.trim());
    fd.append('label', document.getElementById('ivLabel').value.trim());
    fd.append('signature_header', document.getElementById('ivHeader').value.trim());
    fd.append('signature_scheme', document.getElementById('ivScheme').value);
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
      if (r.success) {
        document.getElementById('newProviderBox').style.display = 'block';
        document.getElementById('newProviderSecret').textContent = r.data.secret;
        document.getElementById('newProviderUrl').textContent = r.data.url;
        showMsg(true, r.message);
      } else {
        showMsg(false, r.message || 'Failed.');
      }
    }).catch(function(){ showMsg(false, 'Network error — please try again.'); });
  });
  document.querySelectorAll('.iw-toggle-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var row = btn.closest('tr');
      var id = row.dataset.providerId;
      var next = btn.dataset.active === '1' ? '0' : '1';
      btn.disabled = true;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','toggle_inbound_webhook');
      fd.append('rto_nonce', nonce); fd.append('provider_id', id); fd.append('active', next);
      fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
        showMsg(r.success, r.message || (r.success ? 'Updated.' : 'Failed.'));
        if (r.success) location.reload(); else btn.disabled = false;
      }).catch(function(){ btn.disabled = false; showMsg(false, 'Network error — please try again.'); });
    });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
