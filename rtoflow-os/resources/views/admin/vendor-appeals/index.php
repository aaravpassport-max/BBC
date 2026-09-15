<?php if (!defined('ABSPATH')) exit;
/** @var array $pending @var array $resolved
 * ENTERPRISE GAP FIX (Phase 4, item 5 — "no rating or suspension appeal
 * workflow for vendors"): admin-facing half of VendorAppealService — see
 * Vendor\DashboardController::submitAppeal() for how these get created. */
$pageTitle = 'Vendor Appeals';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Vendor Appeals</h1>
  </div>

  <div id="appealMsg2" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Pending (<?= (int)$pending['total'] ?>)</h3></div>
    <?php if (empty($pending['rows'])): ?>
    <div class="rto-empty-state"><p>No pending appeals.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Vendor</th><th>Type</th><th>Reason</th><th>Filed</th><th>Decision</th></tr></thead>
        <tbody>
        <?php foreach ($pending['rows'] as $a): ?>
        <tr>
          <td data-label="Vendor"><?= esc_html($a['full_name']) ?> <span class="rto-small rto-muted"><?= esc_html($a['vendor_number']) ?></span></td>
          <td data-label="Type"><span class="rto-badge rto-badge-secondary"><?= esc_html(ucfirst($a['subject_type'])) ?></span></td>
          <td data-label="Reason" style="max-width:320px;white-space:normal"><?= esc_html($a['reason']) ?></td>
          <td data-label="Filed" class="rto-small rto-muted"><?= esc_html(rto_date($a['created_at'])) ?></td>
          <td data-label="Decision">
            <div style="display:flex;gap:6px">
              <button type="button" class="rto-btn rto-btn-xs rto-btn-success resolve-btn" data-id="<?= (int)$a['id'] ?>" data-decision="upheld">Uphold</button>
              <button type="button" class="rto-btn rto-btn-xs rto-btn-outline resolve-btn" data-id="<?= (int)$a['id'] ?>" data-decision="rejected">Reject</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>Resolved</h3></div>
    <?php if (empty($resolved['rows'])): ?>
    <div class="rto-empty-state"><p>No resolved appeals yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Vendor</th><th>Type</th><th>Decision</th><th>Response</th><th>Resolved</th></tr></thead>
        <tbody>
        <?php foreach ($resolved['rows'] as $a): ?>
        <tr>
          <td data-label="Vendor"><?= esc_html($a['full_name']) ?></td>
          <td data-label="Type"><?= esc_html(ucfirst($a['subject_type'])) ?></td>
          <td data-label="Decision"><span class="rto-badge rto-badge-<?= $a['status']==='upheld'?'success':'danger' ?>"><?= esc_html(ucfirst($a['status'])) ?></span></td>
          <td data-label="Response" style="max-width:280px;white-space:normal"><?= esc_html($a['admin_response'] ?: '—') ?></td>
          <td data-label="Resolved" class="rto-small rto-muted"><?= esc_html(rto_date($a['resolved_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Decision modal -->
<div id="decideModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;padding:24px;max-width:420px;width:90%">
    <h3 style="margin:0 0 12px" id="decideTitle">Resolve Appeal</h3>
    <textarea id="decideResponse" class="rto-input" rows="4" placeholder="Response to the vendor (optional)…"></textarea>
    <div style="display:flex;gap:10px;margin-top:14px">
      <button type="button" id="decideConfirmBtn" class="rto-btn rto-btn-primary">Confirm</button>
      <button type="button" id="decideCancelBtn" class="rto-btn rto-btn-outline">Cancel</button>
    </div>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function() {
  var modal = document.getElementById('decideModal');
  var titleEl = document.getElementById('decideTitle');
  var responseEl = document.getElementById('decideResponse');
  var confirmBtn = document.getElementById('decideConfirmBtn');
  var activeId = null, activeDecision = null;

  document.querySelectorAll('.resolve-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      activeId = btn.dataset.id; activeDecision = btn.dataset.decision;
      titleEl.textContent = (activeDecision === 'upheld' ? 'Uphold' : 'Reject') + ' Appeal';
      responseEl.value = '';
      modal.style.display = 'flex';
    });
  });
  document.getElementById('decideCancelBtn').addEventListener('click', function() { modal.style.display = 'none'; });

  confirmBtn.addEventListener('click', function() {
    var fd = new FormData();
    fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin');
    fd.append('rto_action', 'resolve_vendor_appeal');
    fd.append('appeal_id', activeId); fd.append('decision', activeDecision);
    fd.append('response', responseEl.value.trim());
    fd.append('rto_nonce', rtoflowAdmin.nonce);
    confirmBtn.disabled = true;
    fetch(rtoflowAdmin.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(r) {
        confirmBtn.disabled = false;
        if (r.success) { location.reload(); }
        else { var m = document.getElementById('appealMsg2'); m.className='rto-msg rto-msg-error'; m.textContent = r.message || 'Failed.'; m.style.display='block'; }
      });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
