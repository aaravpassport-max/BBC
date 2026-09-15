<?php if (!defined('ABSPATH')) exit;
/** @var array $vendor @var array $leads @var array $services @var array $allCities
 *  @var array $vendorCities @var array $vendorServices @var array $stats */
$pageTitle = 'Vendor Detail';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$kycColors = ['verified'=>'success','rejected'=>'danger','pending'=>'warning'];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <div>
      <h1 class="rto-page-title"><?= esc_html($vendor['full_name']) ?></h1>
      <span class="rto-muted rto-small"><?= esc_html($vendor['vendor_number']) ?></span>
    </div>
    <a href="<?= esc_url(home_url('/rto-admin/vendors/')) ?>" class="rto-back-link">← All Vendors</a>
  </div>

  <div id="vendorMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

    <!-- Profile card -->
    <div class="rto-card">
      <div class="rto-card-header">
        <h3>Profile</h3>
        <span class="rto-badge rto-badge-<?= esc_attr($kycColors[$vendor['kyc_status']] ?? 'secondary') ?>">
          KYC: <?= esc_html(ucfirst($vendor['kyc_status'])) ?>
        </span>
      </div>
      <div class="rto-card-body">
        <div class="rto-detail-grid">
          <?php foreach ([
            ['Email',    $vendor['user_email']],
            ['Mobile',   $vendor['mobile']],
            ['PAN',      $vendor['pan'] ?: '—'],
            ['Aadhaar',  $vendor['aadhaar'] ? '****-****-' . substr($vendor['aadhaar'],-4) : '—'],
            ['Address',  $vendor['address'] ?: '—'],
            ['Rating',   number_format((float)$vendor['rating'],1) . ' ⭐'],
            ['Status',   ucfirst($vendor['status'])],
          ] as [$lbl,$val]): ?>
          <div class="rto-detail-item"><span><?= esc_html($lbl) ?></span><strong><?= esc_html($val) ?></strong></div>
          <?php endforeach; ?>
        </div>
        <hr style="margin:16px 0;border:none;border-top:1px solid var(--gray-200)">
        <h4 style="font-size:13px;font-weight:600;margin-bottom:8px">Bank Details</h4>
        <div class="rto-detail-grid">
          <?php foreach ([
            ['Bank',    $vendor['bank_name']    ?: '—'],
            ['Account', $vendor['bank_account'] ? 'XXXX' . substr($vendor['bank_account'],-4) : '—'],
            ['IFSC',    $vendor['bank_ifsc']    ?: '—'],
          ] as [$lbl,$val]): ?>
          <div class="rto-detail-item"><span><?= esc_html($lbl) ?></span><strong><?= esc_html($val) ?></strong></div>
          <?php endforeach; ?>
        </div>
        <!-- ENTERPRISE GAP FIX (Phase 1, item 5 — penny-drop bank
             verification): bank details were accepted and encrypted but
             never checked against a real account before payouts could be
             sent to it. This surfaces the real verification status and
             lets an admin (re)start it. -->
        <?php
          $bvStatus = $vendor['bank_verification_status'] ?? 'unverified';
          $bvColors = ['unverified' => 'secondary', 'pending' => 'warning', 'verified' => 'success', 'failed' => 'danger'];
        ?>
        <div style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span class="rto-badge rto-badge-<?= esc_attr($bvColors[$bvStatus] ?? 'secondary') ?>">Bank: <?= esc_html(ucfirst($bvStatus)) ?></span>
          <?php if (!empty($vendor['bank_verification_note'])): ?>
          <span class="rto-small rto-muted"><?= esc_html($vendor['bank_verification_note']) ?></span>
          <?php endif; ?>
          <?php if ($bvStatus !== 'pending' && !empty($vendor['bank_account'])): ?>
          <button type="button" id="verifyBankBtn" class="rto-btn rto-btn--sm rto-btn-outline" data-vendor-id="<?= (int)$vendor['id'] ?>">
            <?= $bvStatus === 'verified' ? 'Re-verify' : 'Verify Bank Account' ?>
          </button>
          <?php endif; ?>
        </div>
        <p class="rto-small rto-muted" style="margin-top:6px">Payouts to this vendor are blocked until this shows Verified — see PayoutService.</p>
      </div>
    </div>

    <!-- Stats + KYC actions -->
    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="rto-card">
        <div class="rto-card-header"><h3>Performance</h3></div>
        <div class="rto-card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <?php foreach ([
              ['Total Jobs',    $stats['total']     ?? 0],
              ['Completed',     $stats['completed'] ?? 0],
              ['Cancelled',     $stats['cancelled'] ?? 0],
              ['Avg Rating',    number_format((float)($stats['avg_rating'] ?? 0),1) . ' ⭐'],
            ] as [$lbl,$val]): ?>
            <div style="background:var(--gray-50);border-radius:6px;padding:12px;text-align:center">
              <div style="font-size:22px;font-weight:700;color:var(--navy)"><?= esc_html($val) ?></div>
              <div style="font-size:12px;color:var(--gray-500)"><?= esc_html($lbl) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- KYC Actions -->
      <div class="rto-card">
        <div class="rto-card-header"><h3>KYC Verification</h3></div>
        <div class="rto-card-body">
          <div class="rto-form-group">
            <label class="rto-label" for="kycStatus">Set KYC Status<?= rto_field_tooltip('A vendor with any status other than "Verified" is excluded from both manual and automatic job assignment, even if Account Status is Active — assignment requires status=active AND kyc_status=verified together.') ?></label>
            <select id="kycStatus" class="rto-select">
              <?php foreach (['pending'=>'Pending','verified'=>'Verified','rejected'=>'Rejected'] as $k=>$l): ?>
              <option value="<?= esc_attr($k) ?>" <?= $vendor['kyc_status']===$k?'selected':'' ?>><?= esc_html($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rto-form-group">
            <label class="rto-label" for="kycNote">Note (optional)</label>
            <input type="text" id="kycNote" class="rto-input" placeholder="Reason or reference…">
          </div>
          <button id="kycSaveBtn" class="rto-btn rto-btn-primary">Update KYC</button>
        </div>
      </div>

      <!-- ENTERPRISE GAP FIX (Section 1 — vendor KYC document-review
           workflow): before this, "KYC Verification" above was a status
           dropdown with nothing underneath it — no document was ever
           actually uploaded or reviewable. This panel is that missing
           evidence. -->
      <div class="rto-card">
        <div class="rto-card-header"><h3>KYC Documents</h3></div>
        <div class="rto-card-body">
          <?php if (empty($kycDocuments)): ?>
          <p class="rto-small rto-muted">No documents uploaded by this vendor yet.</p>
          <?php else: ?>
          <?php foreach ($kycDocuments as $doc): ?>
          <div class="rto-detail-item" data-doc-row="<?= (int)$doc['id'] ?>" style="border-bottom:1px solid #f1f5f9;padding:8px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <div>
              <strong><?= esc_html($doc['doc_label']) ?></strong>
              <span class="rto-badge rto-badge-<?= $doc['status']==='verified'?'success':($doc['status']==='rejected'?'danger':'warning') ?>" style="margin-left:6px"><?= esc_html(ucfirst($doc['status'])) ?></span>
              <div class="rto-small rto-muted"><?= esc_html(rto_date($doc['created_at'])) ?><?= $doc['status']==='rejected' && $doc['reject_reason'] ? ' — ' . esc_html($doc['reject_reason']) : '' ?></div>
            </div>
            <div style="display:flex;gap:6px">
              <a href="<?= esc_url(home_url('/rto-admin/vendor-kyc-doc/' . (int)$doc['id'] . '/')) ?>" class="rto-btn rto-btn-xs rto-btn-outline" target="_blank">View</a>
              <?php if ($doc['status'] === 'pending'): ?>
              <button class="rto-btn rto-btn-xs rto-btn-success kyc-doc-verify" data-doc-id="<?= (int)$doc['id'] ?>">Verify</button>
              <button class="rto-btn rto-btn-xs rto-btn-outline kyc-doc-reject" data-doc-id="<?= (int)$doc['id'] ?>" style="color:#DC2626;border-color:#DC2626">Reject</button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Account Status -->
      <div class="rto-card">
        <div class="rto-card-header"><h3>Account Status<?= rto_field_tooltip('Suspending a vendor requires a reason (logged and shown to the vendor). If they currently have active, non-terminal jobs, you will be offered a one-click option to automatically reassign those jobs to another eligible vendor before the suspension takes effect.') ?></h3></div>
        <div class="rto-card-body" style="display:flex;gap:8px;flex-wrap:wrap">
          <?php foreach (['active'=>'Activate','inactive'=>'Deactivate','suspended'=>'Suspend'] as $s=>$l): ?>
          <button class="rto-btn rto-btn-sm status-btn <?= $vendor['status']===$s?'rto-btn-primary':'rto-btn-outline' ?>"
                  data-status="<?= esc_attr($s) ?>"><?= esc_html($l) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Service & City Coverage -->
  <div class="rto-card rto-mb-4">
    <div class="rto-card-header">
      <h3>Service &amp; City Coverage</h3>
      <button id="saveCoverageBtn" class="rto-btn rto-btn-primary rto-btn-sm">Save Coverage</button>
    </div>
    <div class="rto-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
      <!-- Services -->
      <div>
        <h4 style="font-size:13px;font-weight:600;margin-bottom:10px">Services</h4>
        <div style="max-height:220px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:4px;padding:8px">
          <?php
          $currentCat = '';
          foreach ($services as $svc):
            if ($svc['category'] !== $currentCat) {
              if ($currentCat) echo '</div>';
              $currentCat = $svc['category'];
              echo '<div style="font-size:11px;font-weight:600;color:var(--gray-500);text-transform:uppercase;margin:8px 0 4px">' . esc_html($currentCat) . '</div>';
            }
          ?>
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;font-size:13px">
            <input type="checkbox" class="coverage-svc" value="<?= esc_attr($svc['id']) ?>"
                   <?= in_array((int)$svc['id'], $vendorServices, true) ? 'checked' : '' ?>>
            <?= esc_html($svc['name']) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- Cities -->
      <div>
        <h4 style="font-size:13px;font-weight:600;margin-bottom:10px">Cities</h4>
        <input type="text" id="cityFilter" class="rto-input rto-mb-2" placeholder="Filter cities…" style="font-size:12px">
        <div id="cityList" style="max-height:220px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:4px;padding:8px">
          <?php
          $currentState = '';
          foreach ($allCities as $city):
            if ($city['state_name'] !== $currentState) {
              if ($currentState) echo '</div>';
              $currentState = $city['state_name'];
              echo '<div style="font-size:11px;font-weight:600;color:var(--gray-500);text-transform:uppercase;margin:8px 0 4px">' . esc_html($currentState) . '</div>';
            }
          ?>
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;font-size:13px" class="city-item">
            <input type="checkbox" class="coverage-city" value="<?= esc_attr($city['id']) ?>"
                   <?= in_array((int)$city['id'], $vendorCities, true) ? 'checked' : '' ?>>
            <?= esc_html($city['name']) ?>
            <span class="rto-muted rto-small"><?= esc_html($city['rto_code']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:8px">
          <button type="button" id="coverageCityAllBtn" class="rto-btn rto-btn-xs rto-btn-outline">All</button>
          <button type="button" id="coverageCityNoneBtn" class="rto-btn rto-btn-xs rto-btn-outline">None</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Recent Jobs -->
  <div class="rto-card">
    <div class="rto-card-header"><h3>Recent Jobs</h3></div>
    <?php if (empty($leads)): ?>
    <div class="rto-empty-state"><p>No jobs assigned to this vendor yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th>Order</th><th>Service</th><th>City</th><th>Status</th><th>Value</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($leads as $lead): ?>
        <tr>
          <td data-label="Order"><a href="<?= esc_url(home_url('/rto-admin/leads/' . (int)$lead['id'])) ?>" class="rto-link"><?= esc_html($lead['lead_number']) ?></a></td>
          <td data-label="Service"><?= esc_html($lead['service_name'] ?? '—') ?></td>
          <td data-label="City"><?= esc_html($lead['city_name'] ?? '—') ?></td>
          <td data-label="Status"><span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($lead['status'])) ?>"><?= esc_html(rto_status_label($lead['status'])) ?></span></td>
          <td data-label="Value"><?= esc_html(rto_format_inr((float)$lead['total_amount'])) ?></td>
          <td data-label="Date"><?= esc_html(rto_date($lead['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var vendorId = <?= (int)$vendor['id'] ?>;
var nonce = rtoflowAdmin.nonce;
var ajaxUrl = rtoflowAdmin.ajax_url;

function vendorAjax(action, extra) {
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin');
  fd.append('rto_action', action); fd.append('vendor_id', vendorId);
  fd.append('rto_nonce', nonce);
  Object.keys(extra||{}).forEach(function(k){ fd.append(k, extra[k]); });
  return fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd}).then(function(r){ return r.json(); });
}

// ENTERPRISE GAP FIX (Phase 1, item 5 — penny-drop bank verification)
var verifyBankBtn = document.getElementById('verifyBankBtn');
if (verifyBankBtn) {
  verifyBankBtn.addEventListener('click', function(){
    if (!confirm('Start bank account verification with Razorpay for this vendor?')) return;
    verifyBankBtn.disabled = true;
    vendorAjax('verify_vendor_bank', {}).then(function(r){
      alert(r.message || (r.success ? 'Started.' : 'Failed.'));
      if (r.success) location.reload(); else verifyBankBtn.disabled = false;
    });
  });
}

// KYC save
document.getElementById('kycSaveBtn').addEventListener('click', function() {
  vendorAjax('vendor_kyc', {kyc_status: document.getElementById('kycStatus').value, note: document.getElementById('kycNote').value})
    .then(function(r) {
      var msg = document.getElementById('vendorMsg');
      msg.className = 'rto-msg rto-msg-' + (r.success ? 'success' : 'error');
      msg.textContent = r.data ? r.data.message : 'Done';
      msg.style.display = 'block';
    }).catch(function() { window.rtoToast('Request failed.','error'); });
});

// ENTERPRISE GAP FIX (Section 1 — vendor KYC document-review workflow)
document.querySelectorAll('.kyc-doc-verify').forEach(function(btn) {
  btn.addEventListener('click', function() {
    if (!confirm('Mark this document as verified?')) return;
    vendorAjax('verify_kyc_doc', {doc_id: btn.dataset.docId}).then(function(r) {
      if (r.success) { window.rtoToast(r.data.message,'success'); setTimeout(function(){location.reload();},1000); }
      else window.rtoToast(r.data?r.data.message:'Failed.','error');
    }).catch(function() { window.rtoToast('Request failed.','error'); });
  });
});
document.querySelectorAll('.kyc-doc-reject').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var reason = prompt('Reason for rejecting this document:');
    if (!reason) return;
    vendorAjax('reject_kyc_doc', {doc_id: btn.dataset.docId, reason: reason}).then(function(r) {
      if (r.success) { window.rtoToast(r.data.message,'success'); setTimeout(function(){location.reload();},1000); }
      else window.rtoToast(r.data?r.data.message:'Failed.','error');
    }).catch(function() { window.rtoToast('Request failed.','error'); });
  });
});

// Status buttons
// Known Limitations audit fix: VendorsController::updateStatus() has real,
// working server-side logic for both of this screen's documented
// limitations — it requires a suspension reason, and when a vendor being
// suspended has active leads it returns 409 asking the caller to resubmit
// with force_reassign=1 (which then unassigns and auto-reassigns those
// leads through the normal scoring path) — but this view's JS never
// collected a reason and never read the 409 response, so in practice every
// Suspend click here failed with "Suspension reason is required." and
// silently stopped, and the confirm-and-reassign flow the backend already
// supports was unreachable from the UI. That gap, not a missing backend
// feature, is why the documented limitation persisted.
function postVendorStatus(status, reason, forceReassign) {
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin');
  fd.append('rto_action','vendor_status'); fd.append('vendor_id', vendorId);
  fd.append('rto_nonce', nonce);
  fd.append('status', status);
  if (reason) fd.append('reason', reason);
  if (forceReassign) fd.append('force_reassign', '1');
  return fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body: fd})
    .then(function(r) { return r.json().then(function(body) { return {httpStatus: r.status, body: body}; }); });
}

document.querySelectorAll('.status-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var status = btn.dataset.status;
    var reason = '';
    if (status === 'suspended') {
      reason = prompt('Reason for suspending this vendor? (required — shown to the vendor and logged in the audit trail)');
      if (reason === null) return;
      reason = reason.trim();
      if (!reason) { window.rtoToast('A suspension reason is required.', 'error'); return; }
    } else {
      if (!confirm('Change vendor status to "' + status + '"?')) return;
    }

    postVendorStatus(status, reason, false).then(function(res) {
      if (res.body.success) { location.reload(); return; }

      // Real drill-down of the recommended fix: the vendor has active,
      // non-terminal leads — offer the one-click reassign the backend
      // already supports, instead of just showing the raw error.
      if (res.httpStatus === 409) {
        var proceed = confirm((res.body.data ? res.body.data.message : 'This vendor has active leads.')
          + '\n\nSuspend this vendor and automatically reassign those leads to another eligible vendor now?');
        if (!proceed) return;
        postVendorStatus(status, reason, true).then(function(res2) {
          if (res2.body.success) location.reload();
          else window.rtoToast(res2.body.data ? res2.body.data.message : 'Failed.', 'error');
        });
        return;
      }

      window.rtoToast(res.body.data ? res.body.data.message : 'Failed.', 'error');
    });
  });
});

// Coverage save
document.getElementById('saveCoverageBtn').addEventListener('click', function() {
  var cityIds  = Array.from(document.querySelectorAll('.coverage-city:checked')).map(function(c){ return parseInt(c.value); });
  var svcIds   = Array.from(document.querySelectorAll('.coverage-svc:checked')).map(function(c){ return parseInt(c.value); });
  vendorAjax('vendor_coverage', {city_ids: JSON.stringify(cityIds), service_ids: JSON.stringify(svcIds)})
    .then(function(r) {
      window.rtoToast(r.data ? r.data.message : 'Saved', r.success ? 'success' : 'error');
    }).catch(function(){ window.rtoToast('Request failed.','error'); });
});

// City filter
document.getElementById('cityFilter').addEventListener('input', function() {
  var q = this.value.toLowerCase();
  document.querySelectorAll('#cityList .city-item').forEach(function(el) {
    el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});

var coverageCityAllBtn = document.getElementById('coverageCityAllBtn');
if (coverageCityAllBtn) coverageCityAllBtn.addEventListener('click', function(){
  document.querySelectorAll('.coverage-city').forEach(function(c){ c.checked = true; });
});
var coverageCityNoneBtn = document.getElementById('coverageCityNoneBtn');
if (coverageCityNoneBtn) coverageCityNoneBtn.addEventListener('click', function(){
  document.querySelectorAll('.coverage-city').forEach(function(c){ c.checked = false; });
});
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
