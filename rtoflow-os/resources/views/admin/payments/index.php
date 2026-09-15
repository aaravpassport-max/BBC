<?php if (!defined('ABSPATH')) exit;
/** @var array $payments @var int $total @var int $page @var int $pages @var string $method @var string $from @var string $to @var array $summary @var array $pendingRefunds */
$pageTitle = 'Payments';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$exportUrl = add_query_arg(array_merge($_GET,['export'=>'csv']));
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Payments</h1>
    <a href="<?= esc_url(home_url('/rto-admin/reports/?report=revenue')) ?>" class="rto-btn rto-btn-outline">📊 Revenue Report</a>
  </div>

  <?php if (!empty($pendingRefunds)): ?>
  <!-- ENTERPRISE GAP FIX (Section 3/8): pending refunds now have a real
       admin surface with both Approve and Reject actions. Previously
       approveRefund() was a fully orphaned AJAX handler with no UI anywhere
       calling it, and there was no reject action at all. -->
  <div class="rto-card rto-card-highlight rto-mb-4" id="rtoPendingRefunds">
    <div class="rto-card-header">
      <h3>⏳ Pending Refund Requests <span class="rto-badge rto-badge-warning"><?= count($pendingRefunds) ?></span></h3>
    </div>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr>
          <th scope="col">Requested</th><th scope="col">Order</th><th scope="col">Client</th>
          <th scope="col">Amount</th><th scope="col">Reason</th><th scope="col">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pendingRefunds as $r): ?>
        <tr data-refund-row="<?= (int)$r['id'] ?>">
          <td data-label="Requested"><?= esc_html(rto_date($r['created_at'])) ?></td>
          <td data-label="Order"><a href="<?= esc_url(home_url('/rto-admin/leads/'.(int)$r['lead_id'])) ?>" class="rto-link"><?= esc_html($r['lead_number']??'—') ?></a></td>
          <td data-label="Client"><?= esc_html($r['client_name']??'—') ?></td>
          <td data-label="Amount"><strong><?= esc_html(rto_format_inr((float)$r['amount'])) ?></strong></td>
          <td data-label="Reason" class="rto-small rto-muted"><?= esc_html($r['reason']??'—') ?></td>
          <td data-label="Actions">
            <button type="button" class="rto-btn rto-btn-xs rto-btn-success refund-approve-btn" data-id="<?= (int)$r['id'] ?>">✓ Approve</button>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-danger refund-reject-btn" data-id="<?= (int)$r['id'] ?>">✗ Reject</button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Summary row -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
    <?php foreach ([
      ['Total Payments',   $summary['count'] ?? 0],
      ['Total Revenue',    rto_format_inr((float)($summary['total'] ?? 0))],
      ['Total GST',        rto_format_inr((float)($summary['gst']   ?? 0))],
    ] as [$l,$v]): ?>
    <div class="rto-card" style="padding:16px;text-align:center">
      <div style="font-size:20px;font-weight:700;color:var(--navy)"><?= esc_html($v) ?></div>
      <div class="rto-small rto-muted"><?= esc_html($l) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <form method="GET" class="rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="payments">
    <div class="rto-filter-row">
      <div class="rto-form-group">
        <label class="rto-label" for="fMethod">Method</label>
        <select id="fMethod" name="method" class="rto-select">
          <option value="">All</option>
          <?php foreach (['razorpay','bank_transfer','upi','cash','cheque','neft','rtgs'] as $m): ?>
          <option value="<?= esc_attr($m) ?>" <?= $method===$m?'selected':'' ?>><?= esc_html(ucfirst(str_replace('_',' ',$m))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group">
        <label class="rto-label" for="fFrom">From</label>
        <input type="date" id="fFrom" name="date_from" class="rto-input" value="<?= esc_attr($from) ?>">
      </div>
      <div class="rto-form-group">
        <label class="rto-label" for="fTo">To</label>
        <input type="date" id="fTo" name="date_to" class="rto-input" value="<?= esc_attr($to) ?>">
      </div>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
        <a href="<?= esc_url(home_url('/rto-admin/payments/')) ?>" class="rto-btn rto-btn-outline">Reset</a>
      </div>
    </div>
  </form>

  <div class="rto-card">
    <div class="rto-card-header">
      <h3>All Payments <span class="rto-badge rto-badge-secondary"><?= number_format($total) ?></span></h3>
    </div>
    <?php if (empty($payments)): ?>
    <div class="rto-empty-state"><p>No payments found for the selected filters.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr>
          <th scope="col">Date</th><th scope="col">Order</th><th scope="col">Client</th>
          <th scope="col">Amount</th><th scope="col">GST</th>
          <th scope="col">Method</th><th scope="col">Txn ID</th><th scope="col">Status</th><th scope="col">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($payments as $py): ?>
        <tr>
          <td data-label="Date"><?= esc_html(rto_date($py['created_at'])) ?></td>
          <td data-label="Order"><a href="<?= esc_url(home_url('/rto-admin/leads/'.(int)$py['lead_id'])) ?>" class="rto-link"><?= esc_html($py['lead_number']??'—') ?></a></td>
          <td data-label="Client"><?= esc_html($py['client_name']??'—') ?></td>
          <td data-label="Amount"><strong><?= esc_html(rto_format_inr((float)$py['amount'])) ?></strong></td>
          <td data-label="GST"><?= esc_html(rto_format_inr((float)$py['gst_amount'])) ?></td>
          <td data-label="Method"><?= esc_html(ucfirst(str_replace('_',' ',$py['method']??''))) ?></td>
          <td data-label="Txn ID" class="rto-small rto-muted"><?= esc_html($py['txn_id']??'—') ?></td>
          <td data-label="Status"><span class="rto-badge rto-badge-<?= $py['status']==='completed'?'success':'warning' ?>"><?= esc_html(ucfirst($py['status']??'')) ?></span></td>
          <td data-label="Actions">
            <!-- ENTERPRISE GAP FIX: this is the origination step the pending-
                 refunds panel above was missing — see Router::initiateRefund()
                 for the full trace. Only offered on a completed payment,
                 since a refund against an unpaid/failed payment makes no
                 sense. -->
            <?php if (($py['status'] ?? '') === 'completed'): ?>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-outline refund-initiate-btn"
                    data-id="<?= (int)$py['id'] ?>" data-amount="<?= esc_attr((float)$py['amount']) ?>">↩ Refund</button>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="rto-pagination">
      <?php for ($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
      <a href="<?= esc_url(add_query_arg('paged',$i)) ?>"
         class="rto-page-link <?= $i===$page?'active':'' ?>" <?= $i===$page?'aria-current="page"':'' ?>><?= $i ?></a>
      <?php endfor; ?>
      <span class="rto-page-info">Page <?= $page ?> of <?= $pages ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php if (!empty($pendingRefunds) || !empty($payments)): ?>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '';
  function post(action, refundId, extra){
    var body = new URLSearchParams(Object.assign({
      action: 'rto_admin', rto_area: 'admin', rto_action: action,
      refund_id: refundId, rto_nonce: nonce
    }, extra || {}));
    return fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body: body})
      .then(function(r){ return r.json(); });
  }
  // ENTERPRISE GAP FIX: initiate a new refund request against a completed
  // payment — see Router::initiateRefund(). Uses payment_id (not
  // refund_id, since no refund exists yet); post() above already sends
  // rto_area/rto_action/rto_nonce, so pass payment_id via the extra map
  // and reuse the same helper rather than duplicating the fetch() call.
  document.querySelectorAll('.refund-initiate-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var maxAmount = parseFloat(btn.dataset.amount || '0');
      var amountStr = prompt('Refund amount (max ₹' + maxAmount.toFixed(2) + '):', maxAmount.toFixed(2));
      if (amountStr === null) return;
      var amount = parseFloat(amountStr);
      if (!amount || amount <= 0 || amount > maxAmount) { alert('Enter a valid amount up to ₹' + maxAmount.toFixed(2) + '.'); return; }
      var reason = prompt('Reason for this refund:');
      if (!reason) return;
      btn.disabled = true;
      var body = new URLSearchParams({
        action: 'rto_admin', rto_area: 'admin', rto_action: 'initiate_refund',
        payment_id: btn.dataset.id, amount: amount, reason: reason, rto_nonce: nonce
      });
      fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body: body})
        .then(function(r){ return r.json(); })
        .then(function(res){
          btn.disabled = false;
          if (res.success) { alert(res.data && res.data.message ? res.data.message : 'Refund request created.'); location.reload(); }
          else { alert(res.data && res.data.message ? res.data.message : 'Failed to initiate refund.'); }
        }).catch(function(){ alert('Network error. Please try again.'); btn.disabled = false; });
    });
  });
  document.querySelectorAll('.refund-approve-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Approve this refund? The client will be notified immediately.')) return;
      btn.disabled = true;
      post('approve_refund', btn.dataset.id).then(function(res){
        if (res.success) {
          var row = document.querySelector('[data-refund-row="'+btn.dataset.id+'"]');
          if (row) row.remove();
        } else {
          alert(res.data && res.data.message ? res.data.message : 'Failed to approve refund.');
          btn.disabled = false;
        }
      }).catch(function(){ alert('Network error. Please try again.'); btn.disabled = false; });
    });
  });
  document.querySelectorAll('.refund-reject-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var reason = prompt('Reason for rejecting this refund (shown to the client):');
      if (!reason) return;
      btn.disabled = true;
      post('reject_refund', btn.dataset.id, {reason: reason}).then(function(res){
        if (res.success) {
          var row = document.querySelector('[data-refund-row="'+btn.dataset.id+'"]');
          if (row) row.remove();
        } else {
          alert(res.data && res.data.message ? res.data.message : 'Failed to reject refund.');
          btn.disabled = false;
        }
      }).catch(function(){ alert('Network error. Please try again.'); btn.disabled = false; });
    });
  });
})();
</script>
<?php endif; ?>
<?php rto_help_box('payments'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
