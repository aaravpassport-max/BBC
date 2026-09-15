<?php if (!defined('ABSPATH')) exit;
/** @var array $payouts @var string $period @var string $status @var int $vendorId @var array $summary @var array $vendors */
$pageTitle = 'Payouts';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Vendor Payouts</h1>
    <div style="display:flex;gap:8px">
      <!-- ENTERPRISE GAP FIX (Section 8): every sibling list screen already
           has a CSV export; this one didn't, despite being the screen
           finance most needs to pull into a spreadsheet. -->
      <a href="<?= esc_url(add_query_arg(array_merge($_GET,['export'=>'csv']))) ?>" class="rto-btn rto-btn-outline">⬇ Export CSV</a>
      <button id="generateBtn" class="rto-btn rto-btn-primary">⚙ Generate Payouts for Period</button>
    </div>
  </div>

  <div id="payoutMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <!-- Summary -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
    <?php foreach ([
      ['Total Gross',    rto_format_inr((float)$summary['total_gross'])],
      ['TDS Deducted',   rto_format_inr((float)$summary['total_tds'])],
      ['Net to Pay',     rto_format_inr((float)$summary['total_net'])],
      ['Pending',        $summary['pending_count'] . ' payouts'],
    ] as [$l,$v]): ?>
    <div class="rto-card" style="padding:16px;text-align:center">
      <div style="font-size:18px;font-weight:700;color:var(--navy)"><?= esc_html($v) ?></div>
      <div class="rto-small rto-muted"><?= esc_html($l) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <form method="GET" class="rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="payouts">
    <div class="rto-filter-row">
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="pPeriod">Period (YYYY-MM)</label>
        <input type="month" id="pPeriod" name="period" class="rto-input" value="<?= esc_attr($period) ?>">
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="pStatus">Status</label>
        <select id="pStatus" name="status" class="rto-select">
          <option value="">All</option>
          <?php foreach (['pending'=>'Pending','paid'=>'Paid'] as $k=>$l): ?>
          <option value="<?= esc_attr($k) ?>" <?= $status===$k?'selected':'' ?>><?= esc_html($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="pVendor">Vendor</label>
        <select id="pVendor" name="vendor_id" class="rto-select">
          <option value="">All Vendors</option>
          <?php foreach ($vendors as $vnd): ?>
          <option value="<?= esc_attr($vnd['id']) ?>" <?= $vendorId===(int)$vnd['id']?'selected':'' ?>><?= esc_html($vnd['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
      </div>
    </div>
  </form>

  <div class="rto-card">
    <?php if (empty($payouts)): ?>
    <div class="rto-empty-state">
      <p>No payouts found. Click <strong>Generate Payouts</strong> to create payout records for vendors who completed jobs this period.</p>
    </div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr>
          <th scope="col">Vendor</th><th scope="col">Period</th>
          <th scope="col">Gross</th><th scope="col">TDS</th><th scope="col">Net</th>
          <th scope="col">Status</th><th scope="col"><span class="rto-visually-hidden">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($payouts as $po): ?>
        <tr>
          <td data-label="Vendor">
            <strong><?= esc_html($po['vendor_name']) ?></strong>
            <div class="rto-small rto-muted"><?= esc_html($po['vendor_number']) ?> · PAN: <?= esc_html($po['pan'] ?: '—') ?></div>
          </td>
          <td data-label="Period"><?= esc_html($po['period']) ?></td>
          <td data-label="Gross"><?= esc_html(rto_format_inr((float)$po['gross_amount'])) ?></td>
          <td data-label="TDS" class="rto-danger"><?= esc_html(rto_format_inr((float)$po['tds_amount'])) ?></td>
          <td data-label="Net"><strong><?= esc_html(rto_format_inr((float)$po['net_amount'])) ?></strong></td>
          <td data-label="Status">
            <span class="rto-badge rto-badge-<?= $po['status']==='paid'?'success':'warning' ?>"><?= esc_html(ucfirst($po['status'])) ?></span>
            <?php if ($po['status']==='pending' && ($po['approval_status'] ?? 'none') === 'pending'): ?>
            <div class="rto-small rto-muted">⏳ Requested by <?= esc_html($po['requested_by_name'] ?? 'another admin') ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Actions" style="display:flex;gap:6px;flex-direction:column;align-items:flex-start">
            <?php if ($po['status']==='pending'): ?>
              <?php $awaitingApproval = ($po['approval_status'] ?? 'none') === 'pending'; ?>
              <?php $isOwnRequest = $awaitingApproval && (int)($po['requested_by'] ?? 0) === (int)$currentUserId; ?>
              <?php if ($isOwnRequest): ?>
              <span class="rto-small rto-muted" style="max-width:160px">Awaiting a <em>different</em> admin's approval — a maker-checker control, you cannot approve your own request.</span>
              <?php else: ?>
              <button class="rto-btn rto-btn-xs <?= $awaitingApproval ? 'rto-btn-primary' : 'rto-btn-success' ?> mark-paid-btn"
                      data-id="<?= esc_attr($po['id']) ?>"
                      data-stage="<?= $awaitingApproval ? 'approve' : 'request' ?>"
                      aria-label="<?= $awaitingApproval ? 'Approve and pay' : 'Request payment for' ?> payout for <?= esc_attr($po['vendor_name']) ?>"><?= $awaitingApproval ? '✓ Approve & Pay' : 'Request Payment' ?></button>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ((float)$po['tds_amount'] > 0): ?>
            <a href="<?= esc_url(home_url('/rto-admin/payouts/' . (int)$po['id'] . '/')) ?>"
               class="rto-btn rto-btn-xs rto-btn-outline" target="_blank">TDS Cert</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (($lastPage ?? 1) > 1): ?>
      <div class="rto-pagination">
        <?php for ($i = max(1, ($page ?? 1) - 2); $i <= min($lastPage, ($page ?? 1) + 2); $i++): ?>
          <?php $url = add_query_arg(['period' => $period, 'status' => $status, 'vendor_id' => $vendorId, 'paged' => $i]); ?>
          <a href="<?= esc_url($url) ?>" class="rto-page-link <?= $i === ($page ?? 1) ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <span class="rto-page-info">Page <?= (int)($page ?? 1) ?> of <?= (int)$lastPage ?> (<?= number_format((int)($total ?? 0)) ?> total)</span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Mark Paid Modal -->
<div id="markPaidModal" role="dialog" aria-modal="true" aria-labelledby="markPaidModalTitle" tabindex="-1" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;padding:28px;max-width:400px;width:90%">
    <h3 id="markPaidModalTitle" style="margin:0 0 16px;color:var(--navy)">Mark Payout as Paid</h3>
    <div class="rto-form-group rto-mb-4">
      <label class="rto-label" for="payRef">Payment Reference <abbr title="Required">*</abbr></label>
      <input type="text" id="payRef" class="rto-input" placeholder="UTR / NEFT / IMPS reference">
    </div>
    <div class="rto-form-group rto-mb-4">
      <label class="rto-label" for="payMethod">Payment Method</label>
      <select id="payMethod" class="rto-select">
        <option value="bank_transfer">Bank Transfer (NEFT/RTGS)</option>
        <option value="imps">IMPS</option>
        <option value="upi">UPI</option>
        <option value="cheque">Cheque</option>
      </select>
    </div>
    <div style="display:flex;gap:12px">
      <button id="confirmPaidBtn" class="rto-btn rto-btn-success">Confirm Payment</button>
      <button id="cancelPaidBtn" class="rto-btn rto-btn-outline">Cancel</button>
    </div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var nonce = rtoflowAdmin.nonce; var ajaxUrl = rtoflowAdmin.ajax_url;
var currentPayoutId = null;
var msg = document.getElementById('payoutMsg');
var markPaidReturnFocus = null;
var markPaidModalEl = document.getElementById('markPaidModal');

function closeMarkPaidModal() {
  markPaidModalEl.style.display = 'none';
  if (markPaidReturnFocus) { markPaidReturnFocus.focus(); markPaidReturnFocus = null; }
}
markPaidModalEl.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeMarkPaidModal();
});

// Generate payouts
document.getElementById('generateBtn').addEventListener('click', function() {
  var period = '<?= esc_js($period) ?>';
  var input  = prompt('Generate payouts for period (YYYY-MM):', period);
  if (!input) return;
  if (!/^\d{4}-\d{2}$/.test(input)) { alert('Invalid format. Use YYYY-MM.'); return; }
  this.disabled = true; this.textContent = 'Generating…';
  var btn = this;
  var fd  = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin');
  fd.append('rto_action','generate_payouts'); fd.append('period',input); fd.append('rto_nonce',nonce);
  fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r){
      msg.className = 'rto-msg rto-msg-' + (r.success?'success':'error');
      msg.textContent = r.data ? r.data.message : 'Done';
      msg.style.display = 'block';
      if (r.success) setTimeout(function(){location.reload();},1500);
    })
    .catch(function(){ window.rtoToast('Request failed.','error'); })
    .finally(function(){ btn.disabled=false; btn.textContent='⚙ Generate Payouts for Period'; });
});

// Mark paid — two-step maker-checker: "request" needs no payment reference
// yet (no money has moved), so it fires directly with just a confirm();
// "approve" is the step that actually pays, so it still opens the modal
// requiring a payment reference, exactly as before.
document.querySelectorAll('.mark-paid-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    if (btn.dataset.stage === 'request') {
      if (!confirm('Request this payout for approval? A different admin will need to approve it before payment is marked as sent.')) return;
      btn.disabled = true;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin');
      fd.append('rto_action','mark_paid'); fd.append('payout_id',btn.dataset.id);
      fd.append('rto_nonce',nonce);
      fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (r.success) { window.rtoToast(r.data.message,'success'); setTimeout(function(){location.reload();},1200); }
          else { window.rtoToast(r.data?r.data.message:'Failed.','error'); btn.disabled=false; }
        })
        .catch(function(){ window.rtoToast('Request failed.','error'); btn.disabled=false; });
      return;
    }
    currentPayoutId = btn.dataset.id;
    markPaidReturnFocus = btn;
    document.getElementById('payRef').value = '';
    markPaidModalEl.style.display = 'flex';
    markPaidModalEl.focus();
  });
});
document.getElementById('cancelPaidBtn').addEventListener('click', function() {
  closeMarkPaidModal();
});
document.getElementById('confirmPaidBtn').addEventListener('click', function() {
  var ref = document.getElementById('payRef').value.trim();
  if (!ref) { alert('Payment reference is required.'); return; }
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin');
  fd.append('rto_action','mark_paid'); fd.append('payout_id',currentPayoutId);
  fd.append('payment_ref',ref); fd.append('method',document.getElementById('payMethod').value);
  fd.append('rto_nonce',nonce);
  document.getElementById('confirmPaidBtn').disabled = true;
  fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r){
      closeMarkPaidModal();
      if (r.success) { window.rtoToast(r.data.message || 'Marked as paid!','success'); setTimeout(function(){location.reload();},1200); }
      else window.rtoToast(r.data?r.data.message:'Failed.','error');
    })
    .catch(function(){ window.rtoToast('Request failed.','error'); })
    .finally(function(){ document.getElementById('confirmPaidBtn').disabled=false; });
});
</script>

<?php rto_help_box('payouts'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
