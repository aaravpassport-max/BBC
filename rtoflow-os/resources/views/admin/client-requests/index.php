<?php if (!defined('ABSPATH')) exit;
/** @var array $requests @var string $status @var string $type @var int $page @var int $perPage @var int $total @var int $pendingCount */
$pageTitle = 'Client Requests';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$statusColors = ['pending' => 'warning', 'approved' => 'success', 'declined' => 'secondary'];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Client Requests</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <?php if ($pendingCount): ?><span class="rto-badge rto-badge-danger" style="font-size:14px"><?= (int)$pendingCount ?> Pending</span><?php endif; ?>
    </div>
  </div>
  <p style="color:#6b7280;font-size:13px;margin:-8px 0 16px">Cancellation and refund requests submitted by clients from their own order page. Approving a cancellation moves the order to Cancelled (subject to the same workflow guards as any status change); approving a refund creates a pending refund on the Payments screen for you to finish reviewing before any money moves.</p>

  <form method="GET" class="rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="client-requests">
    <div class="rto-filter-row">
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="crStatus">Status</label>
        <select id="crStatus" name="status" class="rto-select">
          <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'all' => 'All'] as $k => $l): ?>
          <option value="<?= esc_attr($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= esc_html($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="crType">Type</label>
        <select id="crType" name="type" class="rto-select">
          <option value="">All</option>
          <option value="cancellation" <?= $type === 'cancellation' ? 'selected' : '' ?>>Cancellation</option>
          <option value="refund" <?= $type === 'refund' ? 'selected' : '' ?>>Refund</option>
        </select>
      </div>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
        <a href="<?= esc_url(home_url('/rto-admin/client-requests/')) ?>" class="rto-btn rto-btn-outline">Reset</a>
      </div>
    </div>
  </form>

  <table class="rto-table" data-rto-responsive="cards">
    <thead><tr><th>Order</th><th>Client</th><th>Type</th><th>Reason</th><th>Status</th><th>Filed</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($requests as $r): ?>
      <tr data-request-id="<?= (int)$r['id'] ?>">
        <td data-label="Order"><code><?= esc_html($r['lead_number'] ?: ('#' . $r['lead_id'])) ?></code> <span class="rto-small rto-muted"><?= esc_html($r['lead_status'] ?? '—') ?></span></td>
        <td data-label="Client"><?= esc_html($r['client_name'] ?: ('User #' . $r['client_id'])) ?></td>
        <td data-label="Type"><?= esc_html(ucfirst($r['request_type'])) ?></td>
        <td data-label="Reason" style="max-width:260px;white-space:normal"><?= esc_html($r['reason']) ?></td>
        <td data-label="Status"><span class="rto-badge rto-badge-<?= esc_attr($statusColors[$r['status']] ?? 'secondary') ?>"><?= esc_html(ucfirst($r['status'])) ?></span>
          <?php if ($r['status'] !== 'pending' && $r['staff_note']): ?><div class="rto-small rto-muted" title="<?= esc_attr($r['staff_note']) ?>">Note: <?= esc_html(mb_strimwidth($r['staff_note'], 0, 40, '…')) ?></div><?php endif; ?>
        </td>
        <td data-label="Filed"><?= esc_html(mysql2date('d M Y, H:i', $r['created_at'])) ?></td>
        <td data-label="Actions">
          <?php if ($r['status'] === 'pending'): ?>
          <button class="rto-btn rto-btn--sm rto-btn--primary cr-approve" aria-label="Approve <?= esc_attr($r['request_type']) ?> request for order <?= esc_attr($r['lead_number'] ?: $r['lead_id']) ?>">Approve</button>
          <button class="rto-btn rto-btn--sm rto-btn--danger cr-decline" aria-label="Decline <?= esc_attr($r['request_type']) ?> request for order <?= esc_attr($r['lead_number'] ?: $r['lead_id']) ?>">Decline</button>
          <?php else: ?>
          <span class="rto-small rto-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($requests)): ?>
      <tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8">No requests match this filter.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($total > $perPage): $pages = (int)ceil($total / $perPage); ?>
  <div class="rto-pagination" style="margin-top:12px">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <a class="rto-btn rto-btn--sm <?= $i === $page ? 'rto-btn--primary' : 'rto-btn-outline' ?>" href="<?= esc_url(add_query_arg(['paged' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';
  function post(action, extra){
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action',action);
    fd.append('rto_nonce', nonce);
    Object.keys(extra||{}).forEach(function(k){ fd.append(k, extra[k]); });
    return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});
  }
  document.querySelectorAll('.cr-approve').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Approve this request? For a cancellation this will move the order to Cancelled; for a refund this creates a pending refund for you to finish on the Payments screen.')) return;
      var id = btn.closest('tr').dataset.requestId;
      btn.disabled = true;
      post('approve_client_request', {request_id:id}).then(function(r){
        alert(r.message || (r.success ? 'Approved.' : 'Failed.'));
        if (r.success) location.reload(); else btn.disabled = false;
      });
    });
  });
  document.querySelectorAll('.cr-decline').forEach(function(btn){
    btn.addEventListener('click', function(){
      var reason = prompt('Reason for declining this request (the client can see this):');
      if (reason === null) return;
      if (!reason.trim()) { alert('A reason is required.'); return; }
      var id = btn.closest('tr').dataset.requestId;
      btn.disabled = true;
      post('decline_client_request', {request_id:id, staff_note:reason}).then(function(r){
        alert(r.message || (r.success ? 'Declined.' : 'Failed.'));
        if (r.success) location.reload(); else btn.disabled = false;
      });
    });
  });
})();
</script>

<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
