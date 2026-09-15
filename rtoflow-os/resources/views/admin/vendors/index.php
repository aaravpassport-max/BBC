<?php if (!defined('ABSPATH')) exit;
global $wpdb; $p = $wpdb->prefix;
$search = \RTOFLOW\Security\Sanitiser::text($_GET['search'] ?? '');
$status = \RTOFLOW\Security\Sanitiser::text($_GET['status'] ?? '');
$where  = ['1=1']; $params = [];
if ($search) { $where[] = '(v.full_name LIKE %s OR v.vendor_number LIKE %s)'; $s='%'.$wpdb->esc_like($search).'%'; $params[]=$s; $params[]=$s; }
if ($status) { $where[] = 'v.status=%s'; $params[] = $status; }
$whereStr = implode(' AND ', $where);
$vendors = !empty($params)
    ? $wpdb->get_results($wpdb->prepare("SELECT v.*, u.user_email FROM {$p}rto_vendors v LEFT JOIN {$p}users u ON u.ID=v.user_id WHERE {$whereStr} ORDER BY v.rating DESC LIMIT 50", $params), ARRAY_A)
    : $wpdb->get_results("SELECT v.*, u.user_email FROM {$p}rto_vendors v LEFT JOIN {$p}users u ON u.ID=v.user_id ORDER BY v.rating DESC LIMIT 50", ARRAY_A);
$vendors = $vendors ?: [];
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$pageTitle = 'Vendors';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Vendors</h1>
    <div>
      <!-- ENTERPRISE GAP FIX: extends the bulk-import pipeline (previously
           built only for Cities/RTOs) to Vendors — see
           VendorsController::importCsv(). The exported file's own header
           format doubles as the import template. -->
      <button type="button" id="importVendorsBtn" class="rto-btn rto-btn-outline">Import CSV</button>
      <a href="<?= esc_url(add_query_arg(['export' => 'csv'])) ?>" class="rto-btn rto-btn-outline">Export CSV</a>
      <a href="<?= esc_url(home_url('/rto-admin/')) ?>" class="rto-back-link">← Dashboard</a>
    </div>
  </div>
  <div id="importVendorsPanel" class="rto-card rto-mb-4" style="display:none;padding:16px 20px;background:var(--gray-50)">
    <p class="rto-small rto-muted" style="margin:0 0 10px">
      Upload a CSV with <strong>Full Name</strong> and <strong>Mobile</strong> columns (an <strong>Email</strong> column is optional — vendors given one are emailed a "set your password" link). Use "Export CSV" above to get a file in the exact format expected.
    </p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="file" id="importVendorsFile" accept=".csv,text/csv">
      <button type="button" id="importVendorsSubmitBtn" class="rto-btn rto-btn-primary rto-btn-xs">Upload &amp; Import</button>
      <button type="button" id="importVendorsCancelBtn" class="rto-btn rto-btn-outline rto-btn-xs">Cancel</button>
    </div>
    <div id="importVendorsResult" style="display:none;margin-top:12px;font-size:13px"></div>
  </div>
  <div class="rto-card rto-mb-4">
    <form method="GET" class="rto-filter-form" role="search" aria-label="Filter vendors">
      <div class="rto-filter-row">
        <div class="rto-form-group" style="flex:2"><label class="rto-label" for="vSearch">Search</label><input type="text" id="vSearch" name="search" class="rto-input" value="<?= esc_attr($search) ?>" placeholder="Name or vendor number…"></div>
        <div class="rto-form-group" style="flex:1"><label class="rto-label" for="vStatus">Status</label>
          <select id="vStatus" name="status" class="rto-select"><option value="">All</option>
          <?php foreach (['active','inactive','suspended'] as $s): ?><option value="<?= esc_attr($s) ?>" <?= $status===$s?'selected':'' ?>><?= esc_html(ucfirst($s)) ?></option><?php endforeach; ?>
          </select></div>
        <div class="rto-form-group" style="align-self:flex-end"><button type="submit" class="rto-btn rto-btn-primary">Filter</button></div>
      </div>
    </form>
  </div>
  <div class="rto-card">
    <div class="rto-card-header"><h3>All Vendors <span class="rto-badge rto-badge-secondary"><?= number_format(count($vendors)) ?></span></h3></div>
    <?php if (empty($vendors)): ?>
    <div class="rto-empty-state"><p>No vendors found.</p></div>
    <?php else: ?>
    <div id="vndBulkBar" class="rto-bulk-bar" style="padding:10px 20px;background:var(--gray-50);border-bottom:1px solid var(--gray-200);display:none;align-items:center;gap:10px">
      <span class="rto-small"><span id="vndBulkCount">0</span> selected</span>
      <button type="button" class="rto-btn rto-btn-xs rto-btn-outline" data-bulk-status="active">Activate</button>
      <button type="button" class="rto-btn rto-btn-xs rto-btn-outline" data-bulk-status="inactive">Deactivate</button>
    </div>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th scope="col"><input type="checkbox" id="vndSelectAll" aria-label="Select all vendors"></th><th scope="col">Vendor #</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">KYC</th><th scope="col">Rating</th><th scope="col">Status</th><th scope="col"><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($vendors as $v): ?>
        <tr>
          <td data-label="Select"><input type="checkbox" class="vnd-check" value="<?= esc_attr($v['id']) ?>" aria-label="Select <?= esc_attr($v['full_name']??'vendor') ?>"></td>
          <td data-label="Vendor #"><strong><?= esc_html($v['vendor_number']??'—') ?></strong></td>
          <td data-label="Name"><?= esc_html($v['full_name']??'—') ?></td>
          <td data-label="Email"><?= esc_html($v['user_email']??'—') ?></td>
          <td data-label="KYC"><span class="rto-badge rto-badge-<?= $v['kyc_status']==='verified'?'success':($v['kyc_status']==='rejected'?'danger':'warning') ?>"><?= esc_html(ucfirst($v['kyc_status']??'pending')) ?></span></td>
          <td data-label="Rating">⭐ <?= number_format((float)($v['rating']??0),1) ?></td>
          <td data-label="Status"><span class="rto-badge rto-badge-<?= $v['status']==='active'?'success':'secondary' ?>"><?= esc_html(ucfirst($v['status']??'')) ?></span></td>
          <td data-label="Actions"></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (($lastPage ?? 1) > 1): ?>
      <div class="rto-pagination">
        <?php for ($i = max(1, ($page ?? 1) - 2); $i <= min($lastPage, ($page ?? 1) + 2); $i++): ?>
          <?php $url = add_query_arg(['search' => $search, 'status' => $status, 'kyc' => $kyc, 'paged' => $i]); ?>
          <a href="<?= esc_url($url) ?>" class="rto-page-link <?= $i === ($page ?? 1) ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <span class="rto-page-info">Page <?= (int)($page ?? 1) ?> of <?= (int)$lastPage ?> (<?= number_format((int)($total ?? 0)) ?> total)</span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function() {
  var bar = document.getElementById('vndBulkBar');
  if (!bar) return;
  var countEl = document.getElementById('vndBulkCount');
  var selectAll = document.getElementById('vndSelectAll');
  function checks() { return Array.prototype.slice.call(document.querySelectorAll('.vnd-check')); }
  function refresh() {
    var n = checks().filter(function(c){ return c.checked; }).length;
    countEl.textContent = n;
    bar.style.display = n > 0 ? 'flex' : 'none';
  }
  selectAll && selectAll.addEventListener('change', function() {
    checks().forEach(function(c){ c.checked = selectAll.checked; });
    refresh();
  });
  checks().forEach(function(c){ c.addEventListener('change', refresh); });
  document.querySelectorAll('[data-bulk-status]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var ids = checks().filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
      if (!ids.length) return;
      var status = btn.getAttribute('data-bulk-status');
      if (!confirm('Set ' + ids.length + ' vendor(s) to ' + status + '?')) return;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin');
      fd.append('rto_action','bulk_vendor_status'); fd.append('status', status);
      ids.forEach(function(id){ fd.append('vendor_ids[]', id); });
      fd.append('rto_nonce', rtoflowAdmin.nonce);
      fetch(rtoflowAdmin.ajax_url, {method:'POST',credentials:'same-origin',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          // FIX: rto_json_ok()/rto_json_err() both put the human-readable
          // text on the top-level r.message, not inside r.data (r.data here
          // is {updated: N}) — r.data.message was always undefined, so a
          // successful bulk status change toasted the literal word
          // "undefined" instead of a real confirmation.
          if (r.success) { window.rtoToast(r.message || 'Updated.','success'); setTimeout(function(){location.reload();},800); }
          else window.rtoToast(r.message || 'Failed.','error');
        })
        .catch(function(){ window.rtoToast('Request failed.','error'); });
    });
  });

  // ENTERPRISE GAP FIX: bulk CSV import for Vendors (see VendorsController::importCsv()).
  document.getElementById('importVendorsBtn')?.addEventListener('click', function(){
    document.getElementById('importVendorsPanel').style.display = '';
    document.getElementById('importVendorsResult').style.display = 'none';
  });
  document.getElementById('importVendorsCancelBtn')?.addEventListener('click', function(){
    document.getElementById('importVendorsPanel').style.display = 'none';
    document.getElementById('importVendorsFile').value = '';
  });
  document.getElementById('importVendorsSubmitBtn')?.addEventListener('click', function(){
    var fileInput = document.getElementById('importVendorsFile');
    var resultBox = document.getElementById('importVendorsResult');
    var btn = this;
    if (!fileInput.files.length) { window.rtoToast('Choose a CSV file first.','error'); return; }
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','import_vendors_csv');
    fd.append('rto_nonce', rtoflowAdmin.nonce);
    fd.append('csv_file', fileInput.files[0]);
    btn.disabled = true; btn.textContent = 'Importing…';
    fetch(rtoflowAdmin.ajax_url, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        btn.disabled = false; btn.textContent = 'Upload & Import';
        resultBox.style.display = 'block';
        // NOTE: same message-location contract as bulk_vendor_status above —
        // the human-readable text is on the top-level r.message, r.data is
        // {imported, skipped, errors}.
        var d = r.data || {};
        if (r.success) {
          var html = '<div class="rto-msg rto-msg-' + (d.imported > 0 ? 'success' : 'warning') + '">' +
            (r.message || 'Import complete.') + '</div>';
          if (d.errors && d.errors.length) {
            html += '<ul style="margin:10px 0 0;padding-left:18px;color:#92400e">' +
              d.errors.map(function(e){ return '<li>' + e.replace(/</g,'&lt;') + '</li>'; }).join('') + '</ul>';
          }
          resultBox.innerHTML = html;
          if (d.imported > 0) setTimeout(function(){ location.reload(); }, 2000);
        } else {
          resultBox.innerHTML = '<div class="rto-msg rto-msg-error">' + (r.message || 'Import failed.') + '</div>';
        }
      })
      .catch(function(){
        btn.disabled = false; btn.textContent = 'Upload & Import';
        resultBox.style.display = 'block';
        resultBox.innerHTML = '<div class="rto-msg rto-msg-error">Request failed. Please try again.</div>';
      });
  });
})();
</script>

<?php rto_help_box('vendors'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
