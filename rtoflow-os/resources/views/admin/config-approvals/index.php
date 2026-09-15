<?php if (!defined('ABSPATH')) exit;
/** @var array $pending @var array $history @var int $currentUserId
 * ENTERPRISE GAP FIX (Phase 2, item 2 — config-change approval workflow):
 * see SettingsController::REQUIRES_APPROVAL_TABS and ConfigApprovalService
 * for the full mechanism. Payload values are intentionally NOT rendered
 * here in full — this list is deliberately limited to which fields changed
 * (not the raw values), since the payloads for Payment/SMS/WhatsApp
 * contain live secrets in plaintext form (pre-encryption) while pending. */
$pageTitle = 'Config Approvals';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
$tabLabels = ['payment' => 'Payment Gateway', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Config Approvals</h1>
  </div>
  <p class="rto-small rto-muted rto-mb-4">
    Changes to Payment Gateway, SMS, and WhatsApp settings require a different RTO Admin to approve before they
    take effect. The admin who submitted a change cannot approve their own request.
  </p>

  <div id="caMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Pending (<?= count($pending) ?>)</h3></div>
    <?php if (empty($pending)): ?>
    <div class="rto-empty-state"><p>No config changes awaiting approval.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Tab</th><th>Requested By</th><th>Requested At</th><th>Fields Changed</th><th><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $req):
          $requester = get_userdata((int)$req['requested_by']);
          $payload   = json_decode($req['payload_json'], true) ?: [];
          $fieldKeys = array_slice(array_keys(array_diff_key($payload, ['rtoflow_settings_save' => 1, 'rtoflow_nonce' => 1, '_wp_http_referer' => 1])), 0, 8);
          $isOwnRequest = (int)$req['requested_by'] === $currentUserId;
        ?>
        <tr data-request-id="<?= esc_attr($req['id']) ?>">
          <td data-label="Tab"><?= esc_html($tabLabels[$req['tab']] ?? esc_html($req['tab'])) ?></td>
          <td data-label="Requested By"><?= esc_html($requester->display_name ?? 'Unknown') ?></td>
          <td data-label="Requested At" class="rto-small rto-muted"><?= esc_html(date('d M Y H:i', strtotime($req['requested_at']))) ?></td>
          <td data-label="Fields Changed" class="rto-small rto-muted"><?= esc_html(implode(', ', $fieldKeys)) ?></td>
          <td data-label="Actions">
            <?php if ($isOwnRequest): ?>
            <span class="rto-small rto-muted">Awaiting a different admin</span>
            <?php else: ?>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-primary ca-approve-btn">Approve &amp; Apply</button>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-outline ca-reject-btn">Reject</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>History</h3></div>
    <?php if (empty($history)): ?>
    <div class="rto-empty-state"><p>No config change requests yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Tab</th><th>Status</th><th>Requested By</th><th>Decided By</th><th>Decided At</th></tr></thead>
        <tbody>
        <?php foreach ($history as $req):
          $requester = get_userdata((int)$req['requested_by']);
          $decider   = $req['decided_by'] ? get_userdata((int)$req['decided_by']) : null;
        ?>
        <tr>
          <td data-label="Tab"><?= esc_html($tabLabels[$req['tab']] ?? esc_html($req['tab'])) ?></td>
          <td data-label="Status">
            <span class="rto-badge rto-badge-<?= $req['status'] === 'approved' ? 'success' : ($req['status'] === 'rejected' ? 'danger' : 'secondary') ?>">
              <?= esc_html(ucfirst($req['status'])) ?>
            </span>
          </td>
          <td data-label="Requested By"><?= esc_html($requester->display_name ?? 'Unknown') ?></td>
          <td data-label="Decided By"><?= $decider ? esc_html($decider->display_name) : '—' ?></td>
          <td data-label="Decided At" class="rto-small rto-muted"><?= $req['decided_at'] ? esc_html(date('d M Y H:i', strtotime($req['decided_at']))) : '—' ?></td>
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
  var msg = document.getElementById('caMsg');
  function post(action, extra){
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action',action);
    fd.append('rto_nonce', nonce);
    Object.keys(extra||{}).forEach(function(k){ fd.append(k, extra[k]); });
    return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});
  }
  function showMsg(success, text){
    msg.style.display = 'block';
    msg.className = 'rto-msg ' + (success ? 'rto-msg-success' : 'rto-msg-error');
    msg.textContent = text;
  }
  document.querySelectorAll('.ca-approve-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Approve and apply this change? It will take effect immediately.')) return;
      var id = btn.closest('tr').dataset.requestId;
      btn.disabled = true;
      post('approve_config_change', {request_id:id}).then(function(r){
        showMsg(r.success, r.message || (r.success ? 'Approved.' : 'Failed.'));
        if (r.success) location.reload(); else btn.disabled = false;
      });
    });
  });
  document.querySelectorAll('.ca-reject-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var note = prompt('Reason for rejecting this change (optional):') || '';
      var id = btn.closest('tr').dataset.requestId;
      btn.disabled = true;
      post('reject_config_change', {request_id:id, note:note}).then(function(r){
        showMsg(r.success, r.message || (r.success ? 'Rejected.' : 'Failed.'));
        if (r.success) location.reload(); else btn.disabled = false;
      });
    });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
