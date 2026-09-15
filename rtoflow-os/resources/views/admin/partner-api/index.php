<?php if (!defined('ABSPATH')) exit;
/** @var array $keys
 * ENTERPRISE GAP FIX (Phase 2, item 4 — partner REST API): see
 * PartnerApiController and ApiKeyService for the full mechanism. */
$pageTitle = 'Partner API';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Partner API</h1>
  </div>
  <p class="rto-small rto-muted rto-mb-4">
    Issue API keys for external partners to submit leads and check their status via
    <code><?= esc_html(rest_url('rtoflow/v1/')) ?></code>. Send the key in an
    <code>X-API-Key</code> header, rate-limited per key (independent of the admin UI's per-IP limits).
  </p>

  <!-- ENTERPRISE GAP FIX (Phase 12, item — "no published API docs"):
       previously the only "documentation" was the one sentence above. -->
  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>API Reference</h3></div>
    <div class="rto-card-body rto-small">
      <table class="rto-table" style="margin-bottom:12px">
        <thead><tr><th>Method</th><th>Path</th><th>Purpose</th></tr></thead>
        <tbody>
          <tr><td><code>POST</code></td><td><code>/leads</code></td><td>Submit a new lead. Body: <code>client_name</code>, <code>email</code>, <code>mobile</code>, <code>service_id</code>, <code>city_id</code> (JSON).</td></tr>
          <tr><td><code>GET</code></td><td><code>/leads</code></td><td>List leads created by this key. Query: <code>page</code>, <code>per_page</code> (max 100). Total counts returned in <code>X-Total-Count</code> / <code>X-Total-Pages</code> headers.</td></tr>
          <tr><td><code>GET</code></td><td><code>/leads/{id}</code></td><td>Fetch status of one lead created by this key.</td></tr>
          <tr><td><code>GET</code></td><td><code>/services</code></td><td>Active services list — build a partner-side submission form against real service/city ids.</td></tr>
          <tr><td><code>GET</code></td><td><code>/cities</code></td><td>Active cities list, with RTO code and state.</td></tr>
        </tbody>
      </table>
      <p><strong>Example — submit a lead:</strong></p>
      <pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;overflow-x:auto;white-space:pre-wrap"><?= esc_html(
"curl -X POST '" . rest_url('rtoflow/v1/leads') . "' \\\n" .
"  -H 'X-API-Key: YOUR_KEY' \\\n" .
"  -H 'Content-Type: application/json' \\\n" .
"  -d '{\"client_name\":\"Ravi Kumar\",\"email\":\"ravi@example.com\",\"mobile\":\"9876543210\",\"service_id\":1,\"city_id\":1}'"
      ) ?></pre>
      <p class="rto-muted">Every response is standard JSON; errors follow the WordPress REST convention (<code>{"code","message","data":{"status"}}</code>). A 401 means a missing/invalid/revoked key; a 429 means the per-key rate limit was hit; a 422 means validation or eligibility failed (see <code>eligibility_failures</code> in the error body for the reason).</p>
    </div>
  </div>

  <div id="apiMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Issue New Key</h3></div>
    <div class="rto-card-body">
      <form id="createKeyForm" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="rto-form-group" style="flex:1;min-width:220px">
          <label class="rto-label" for="partnerName">Partner Name</label>
          <input type="text" id="partnerName" class="rto-input" placeholder="e.g. Acme Referral Network" required>
        </div>
        <button type="submit" class="rto-btn rto-btn-primary">Create Key</button>
      </form>
      <div id="newKeyBox" style="display:none;margin-top:14px" class="rto-msg rto-msg-warning">
        <strong>Copy this key now — it will not be shown again:</strong>
        <div style="font-family:monospace;word-break:break-all;margin-top:6px;padding:8px;background:#fff;border-radius:6px" id="newKeyValue"></div>
      </div>
    </div>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>Existing Keys (<?= count($keys) ?>)</h3></div>
    <?php if (empty($keys)): ?>
    <div class="rto-empty-state"><p>No API keys issued yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Partner</th><th>Key Prefix</th><th>Status</th><th>Created</th><th>Last Used</th><th><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
        <tr data-key-id="<?= esc_attr($k['id']) ?>">
          <td data-label="Partner"><?= esc_html($k['partner_name']) ?></td>
          <td data-label="Key Prefix"><code><?= esc_html($k['key_prefix']) ?>…</code></td>
          <td data-label="Status">
            <span class="rto-badge rto-badge-<?= $k['is_active'] ? 'success' : 'secondary' ?>"><?= $k['is_active'] ? 'Active' : 'Revoked' ?></span>
          </td>
          <td data-label="Created" class="rto-small rto-muted"><?= esc_html(date('d M Y', strtotime($k['created_at']))) ?></td>
          <td data-label="Last Used" class="rto-small rto-muted"><?= $k['last_used_at'] ? esc_html(date('d M Y H:i', strtotime($k['last_used_at']))) : 'Never' ?></td>
          <td data-label="Actions">
            <?php if ($k['is_active']): ?>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-danger api-revoke-btn">Revoke</button>
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
  var msg = document.getElementById('apiMsg');
  function showMsg(success, text){
    msg.style.display = 'block';
    msg.className = 'rto-msg ' + (success ? 'rto-msg-success' : 'rto-msg-error');
    msg.textContent = text;
  }
  document.getElementById('createKeyForm').addEventListener('submit', function(e){
    e.preventDefault();
    var name = document.getElementById('partnerName').value.trim();
    if (!name) return;
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','create_api_key');
    fd.append('rto_nonce', nonce); fd.append('partner_name', name);
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
      if (r.success) {
        document.getElementById('newKeyBox').style.display = 'block';
        document.getElementById('newKeyValue').textContent = r.data.raw_key;
        showMsg(true, r.message);
      } else {
        showMsg(false, r.message || 'Failed.');
      }
    }).catch(function(){ showMsg(false, 'Network error — please try again.'); });
  });
  document.querySelectorAll('.api-revoke-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Revoke this API key? Any integration using it will immediately stop working.')) return;
      var id = btn.closest('tr').dataset.keyId;
      btn.disabled = true;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','revoke_api_key');
      fd.append('rto_nonce', nonce); fd.append('api_key_id', id);
      fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
        showMsg(r.success, r.message || (r.success ? 'Revoked.' : 'Failed.'));
        if (r.success) location.reload(); else btn.disabled = false;
      }).catch(function(){ btn.disabled = false; showMsg(false, 'Network error — please try again.'); });
    });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
