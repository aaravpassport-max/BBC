<?php if (!defined('ABSPATH')) exit;
/** @var string $tab @var array $states @var array $cities @var int $stateId @var string $search */
$pageTitle = 'Cities & RTOs';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Master Data</h1>
  </div>

  <!-- Tabs -->
  <div class="rto-tabs rto-mb-4">
    <?php foreach (['cities'=>'Cities / RTOs','states'=>'States'] as $t=>$l): ?>
    <a href="<?= esc_url(add_query_arg('tab',$t,home_url('/rto-admin/masters/'))) ?>"
       class="rto-tab <?= $tab===$t?'active':'' ?>"><?= esc_html($l) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab==='cities'): ?>
  <!-- Add City -->
  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3 id="cityFormTitle">Add City / RTO Office</h3></div>
    <div class="rto-card-body">
      <div id="cityAddMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>
      <!-- ENTERPRISE GAP FIX (Section 8 — "Master-data (cities/RTOs) has no
           update/versioning path"): this single form now doubles as the
           edit form (Edit button below populates it and flips
           #cityEditId), rather than duplicating the same
           state/name/RTO-code fields and validation in a second form. -->
      <input type="hidden" id="cityEditId" value="">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="rto-form-group" style="min-width:160px">
          <label class="rto-label" for="newState">State <abbr title="Required">*</abbr></label>
          <select id="newState" class="rto-select">
            <option value="">— Select —</option>
            <?php foreach ($states as $s): ?>
            <option value="<?= esc_attr($s['id']) ?>"><?= esc_html($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group" style="flex:2;min-width:200px">
          <label class="rto-label" for="newCityName">City Name <abbr title="Required">*</abbr></label>
          <input type="text" id="newCityName" class="rto-input" placeholder="e.g. Pune">
        </div>
        <div class="rto-form-group" style="min-width:140px">
          <label class="rto-label" for="newRtoCode">RTO Code</label>
          <input type="text" id="newRtoCode" class="rto-input" placeholder="e.g. MH-12" maxlength="10">
        </div>
        <div class="rto-form-group" style="align-self:flex-end">
          <button id="addCityBtn" class="rto-btn rto-btn-primary">Add City</button>
          <button id="cancelEditCityBtn" class="rto-btn rto-btn-outline" style="display:none">Cancel Edit</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Filter + List -->
  <div class="rto-card">
    <div class="rto-card-header" style="display:flex;justify-content:space-between;align-items:center">
      <h3>Cities <span class="rto-badge rto-badge-secondary"><?= number_format((int)($total ?? count($cities))) ?></span></h3>
      <div style="display:flex;gap:8px">
        <!-- Bulk delete — see MastersController::bulkDeleteCities(). Hidden
             until at least one row checkbox is checked. -->
        <button type="button" id="bulkDeleteCitiesBtn" class="rto-btn rto-btn-xs rto-btn-danger" style="display:none"><span id="bulkDeleteLabel">Delete Selected (<span id="bulkDeleteCount">0</span>)</span></button>
        <!-- ENTERPRISE GAP FIX: closes the "no bulk-import pipeline anywhere"
             gap — see MastersController::importCitiesCsv(). The exported
             file's own header format is what's expected on re-upload, so
             it doubles as the import template with no separate download. -->
        <button type="button" id="importCitiesBtn" class="rto-btn rto-btn-xs rto-btn-outline">Import CSV</button>
        <a href="<?= esc_url(add_query_arg(['tab' => 'cities', 'export' => 'csv'])) ?>" class="rto-btn rto-btn-xs rto-btn-outline">Export CSV</a>
        <?php // ENTERPRISE GAP FIX (Phase 6, item — "no merge/dedupe tooling
              // for accidental duplicates"): see MastersController::mergeCities(). ?>
        <button type="button" id="mergeCitiesBtn" class="rto-btn rto-btn-xs rto-btn-outline">Merge Duplicates</button>
      </div>
    </div>
    <div id="bulkDeleteCitiesResult" style="display:none;padding:10px 20px;font-size:13px"></div>
    <div id="mergeCitiesPanel" style="display:none;padding:16px 20px;background:var(--gray-50);border-bottom:1px solid var(--gray-200)">
      <p class="rto-small rto-muted" style="margin:0 0 10px">Move every order, RTO office, price override, and vendor coverage entry off the duplicate city, then delete it. This cannot be undone.</p>
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div class="rto-form-group">
          <label class="rto-label" for="mergeFromCity">Duplicate city (will be deleted)</label>
          <select id="mergeFromCity" class="rto-select" style="min-width:200px">
            <option value="">Select city…</option>
            <?php foreach (($allCitiesForMerge ?? []) as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= esc_html($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="mergeIntoCity">Canonical city (keeps everything)</label>
          <select id="mergeIntoCity" class="rto-select" style="min-width:200px">
            <option value="">Select city…</option>
            <?php foreach (($allCitiesForMerge ?? []) as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= esc_html($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="button" id="mergeCitiesSubmitBtn" class="rto-btn rto-btn-danger rto-btn-xs">Merge &amp; Delete Duplicate</button>
        <button type="button" id="mergeCitiesCancelBtn" class="rto-btn rto-btn-outline rto-btn-xs">Cancel</button>
      </div>
      <div id="mergeCitiesResult" style="display:none;margin-top:12px;font-size:13px"></div>
    </div>
    <div id="importCitiesPanel" style="display:none;padding:16px 20px;background:var(--gray-50);border-bottom:1px solid var(--gray-200)">
      <p class="rto-small rto-muted" style="margin:0 0 10px">
        Upload a CSV with <strong>State</strong> and <strong>City</strong> columns (an <strong>RTO Code</strong> column is optional).
        Use "Export CSV" above to get a file in the exact format expected — edit it and re-upload to add more cities.
      </p>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="file" id="importCitiesFile" accept=".csv,text/csv">
        <button type="button" id="importCitiesSubmitBtn" class="rto-btn rto-btn-primary rto-btn-xs">Upload &amp; Import</button>
        <button type="button" id="importCitiesCancelBtn" class="rto-btn rto-btn-outline rto-btn-xs">Cancel</button>
      </div>
      <div id="importCitiesResult" style="display:none;margin-top:12px;font-size:13px"></div>
    </div>
    <form method="GET" style="padding:12px 20px;background:var(--gray-50);border-bottom:1px solid var(--gray-200);display:flex;gap:12px;align-items:flex-end">
      <input type="hidden" name="rto_area" value="admin">
      <input type="hidden" name="rto_page" value="masters">
      <input type="hidden" name="tab" value="cities">
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="filterState">State</label>
        <select id="filterState" name="state_id" class="rto-select">
          <option value="">All States</option>
          <?php foreach ($states as $s): ?>
          <option value="<?= esc_attr($s['id']) ?>" <?= $stateId===(int)$s['id']?'selected':'' ?>><?= esc_html($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="flex:2">
        <label class="rto-label" for="filterSearch">Search</label>
        <input type="text" id="filterSearch" name="search" class="rto-input" value="<?= esc_attr($search) ?>" placeholder="City name or RTO code…">
      </div>
      <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
    </form>
    <?php if (empty($cities)): ?>
    <div class="rto-empty-state"><p>No cities found.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr>
          <th style="width:32px"><input type="checkbox" id="selectAllCities" aria-label="Select all cities on this page"></th>
          <th>State</th><th>City / RTO Office</th><th>RTO Code</th><th><span class="rto-visually-hidden">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($cities as $city): ?>
        <tr data-city-row-id="<?= esc_attr($city['id']) ?>">
          <td data-label=""><input type="checkbox" class="city-row-check" value="<?= esc_attr($city['id']) ?>" data-name="<?= esc_attr($city['name']) ?>" aria-label="Select <?= esc_attr($city['name']) ?>"></td>
          <td data-label="State"><?= esc_html($city['state_name']) ?></td>
          <td data-label="City / RTO Office"><?= esc_html($city['name']) ?></td>
          <td data-label="RTO Code"><code><?= esc_html($city['rto_code']) ?></code></td>
          <td data-label="Actions">
            <button class="rto-btn rto-btn-xs rto-btn-outline edit-city"
                    data-id="<?= esc_attr($city['id']) ?>" data-name="<?= esc_attr($city['name']) ?>"
                    data-state-id="<?= esc_attr($city['state_id']) ?>" data-rto-code="<?= esc_attr($city['rto_code']) ?>"
                    aria-label="Edit <?= esc_attr($city['name']) ?>">Edit</button>
            <button class="rto-btn rto-btn-xs rto-btn-danger delete-city"
                    data-id="<?= esc_attr($city['id']) ?>" data-name="<?= esc_attr($city['name']) ?>"
                    aria-label="Delete <?= esc_attr($city['name']) ?>">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (($lastPage ?? 1) > 1): ?>
      <div class="rto-pagination">
        <?php for ($i = max(1, ($page ?? 1) - 2); $i <= min($lastPage, ($page ?? 1) + 2); $i++): ?>
          <?php $url = add_query_arg(['tab' => 'cities', 'state_id' => $stateId, 'search' => $search, 'paged' => $i]); ?>
          <a href="<?= esc_url($url) ?>" class="rto-page-link <?= $i === ($page ?? 1) ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <span class="rto-page-info">Page <?= (int)($page ?? 1) ?> of <?= (int)$lastPage ?> (<?= number_format((int)($total ?? 0)) ?> total)</span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php elseif ($tab==='states'): ?>
  <div class="rto-card">
    <div class="rto-card-header"><h3>States &amp; Union Territories (<?= count($states) ?>)</h3></div>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>State</th><th>Code</th><th>GST Code</th><th>Cities</th></tr></thead>
        <tbody>
        <?php global $wpdb; foreach ($states as $s):
          $cityCount = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities WHERE state_id=%d", $s['id']));
        ?>
        <tr>
          <td data-label="State"><?= esc_html($s['name']) ?></td>
          <td data-label="Code"><strong><?= esc_html($s['code']) ?></strong></td>
          <td data-label="GST Code"><?= esc_html($s['gst_code']) ?></td>
          <td data-label="Cities">
            <a href="<?= esc_url(add_query_arg(['tab'=>'cities','state_id'=>$s['id']],home_url('/rto-admin/masters/'))) ?>">
              <?= $cityCount ?> cities
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var nonce = rtoflowAdmin.nonce; var ajaxUrl = rtoflowAdmin.ajax_url;

// Add / Update city — the same form handles both (ENTERPRISE GAP FIX,
// Section 8): #cityEditId is empty for a new city, or holds the city id
// while editing an existing one (see the Edit button handler below).
document.getElementById('addCityBtn')?.addEventListener('click', function() {
  var editId   = document.getElementById('cityEditId').value;
  var stateId  = document.getElementById('newState').value;
  var name     = document.getElementById('newCityName').value.trim();
  var rtoCode  = document.getElementById('newRtoCode').value.trim();
  var msg      = document.getElementById('cityAddMsg');
  if (!stateId || !name) { window.rtoToast('State and city name are required.','error'); return; }
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin');
  fd.append('rto_action', editId ? 'update_city' : 'add_city');
  if (editId) fd.append('city_id', editId);
  fd.append('state_id',stateId);
  fd.append('name',name); fd.append('rto_code',rtoCode); fd.append('rto_nonce',nonce);
  document.getElementById('addCityBtn').disabled = true;
  fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r){
      msg.className = 'rto-msg rto-msg-' + (r.success?'success':'error');
      msg.innerHTML = r.data ? r.data.message : 'Done';
      // Known Limitations audit fix (still applies to a brand-new city):
      // adding a city has zero automatic effect on service availability
      // there — only show the City Pricing follow-up prompt on a real add,
      // not when just fixing a typo in an existing city's name/code.
      if (r.success && !editId) {
        msg.innerHTML += ' This only adds the location — no service is bookable there yet. <a href="<?= esc_url(home_url('/rto-admin/city-pricing/')) ?>" style="font-weight:600">Configure which services are visible in this city →</a>';
      }
      msg.style.display = 'block';
      if (r.success) { setTimeout(function(){location.reload();},1500); }
    })
    .catch(function(){ window.rtoToast('Request failed.','error'); })
    .finally(function(){ document.getElementById('addCityBtn').disabled=false; });
});

// Edit city — populates the Add-City form above in edit mode.
document.querySelectorAll('.edit-city').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('cityEditId').value = btn.dataset.id;
    document.getElementById('newState').value = btn.dataset.stateId;
    document.getElementById('newCityName').value = btn.dataset.name;
    document.getElementById('newRtoCode').value = btn.dataset.rtoCode;
    document.getElementById('cityFormTitle').textContent = 'Edit City / RTO Office';
    document.getElementById('addCityBtn').textContent = 'Save Changes';
    document.getElementById('cancelEditCityBtn').style.display = '';
    document.getElementById('newCityName').scrollIntoView({behavior:'smooth', block:'center'});
  });
});
document.getElementById('cancelEditCityBtn')?.addEventListener('click', function() {
  document.getElementById('cityEditId').value = '';
  document.getElementById('newState').value = '';
  document.getElementById('newCityName').value = '';
  document.getElementById('newRtoCode').value = '';
  document.getElementById('cityFormTitle').textContent = 'Add City / RTO Office';
  document.getElementById('addCityBtn').textContent = 'Add City';
  this.style.display = 'none';
});

// Delete city
document.querySelectorAll('.delete-city').forEach(function(btn) {
  btn.addEventListener('click', function() {
    if (!confirm('Delete "' + btn.dataset.name + '"? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin');
    fd.append('rto_action','delete_city'); fd.append('city_id',btn.dataset.id); fd.append('rto_nonce',nonce);
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (r.success) btn.closest('tr').remove();
        else window.rtoToast(r.data ? r.data.message : 'Could not delete.','error');
      });
  });
});

// Bulk delete — "create options so I can delete cities in bulk" (see
// MastersController::bulkDeleteCities()). Checking any row checkbox reveals
// the "Delete Selected (N)" button with a live count; "select all" only
// affects rows on the current (paginated) page, matching how the rest of
// this table already behaves per-page.
var bulkBtn = document.getElementById('bulkDeleteCitiesBtn');
var bulkCount = document.getElementById('bulkDeleteCount');
var selectAllBox = document.getElementById('selectAllCities');
function rowChecks() { return Array.prototype.slice.call(document.querySelectorAll('.city-row-check')); }
function refreshBulkDeleteUI() {
  var checked = rowChecks().filter(function(c){ return c.checked; });
  bulkCount.textContent = checked.length;
  bulkBtn.style.display = checked.length ? '' : 'none';
  if (selectAllBox) selectAllBox.checked = checked.length > 0 && checked.length === rowChecks().length;
}
rowChecks().forEach(function(c){ c.addEventListener('change', refreshBulkDeleteUI); });
selectAllBox?.addEventListener('change', function(){
  rowChecks().forEach(function(c){ c.checked = selectAllBox.checked; });
  refreshBulkDeleteUI();
});
bulkBtn?.addEventListener('click', function(){
  var checked = rowChecks().filter(function(c){ return c.checked; });
  if (!checked.length) return;
  var names = checked.map(function(c){ return c.dataset.name; });
  var preview = names.slice(0, 5).join(', ') + (names.length > 5 ? ', and ' + (names.length - 5) + ' more' : '');
  if (!confirm('Delete ' + checked.length + ' selected cit' + (checked.length === 1 ? 'y' : 'ies') + ' (' + preview + ')? A city still in use (active orders, vendor coverage, or a pricing override) will be skipped and reported. This cannot be undone.')) return;
  var ids = checked.map(function(c){ return parseInt(c.value, 10); });
  var resultBox = document.getElementById('bulkDeleteCitiesResult');
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','delete_cities_bulk');
  fd.append('rto_nonce', nonce);
  fd.append('city_ids', JSON.stringify(ids));
  var label = document.getElementById('bulkDeleteLabel');
  bulkBtn.disabled = true; label.textContent = 'Deleting…';
  fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r){
      var data = r.data || {};
      (data.deleted || []).forEach(function(d){
        var row = document.querySelector('tr[data-city-row-id="' + d.id + '"]');
        if (row) row.remove();
      });
      resultBox.style.display = 'block';
      var html = '<div class="rto-msg rto-msg-' + (r.success ? 'success' : 'error') + '">' + (r.message || 'Done') + '</div>';
      if (data.skipped && data.skipped.length) {
        html += '<ul style="margin:8px 0 0;padding-left:18px;color:#92400e">' +
          data.skipped.map(function(s){ return '<li>' + s.name.replace(/</g,'&lt;') + ' — ' + s.reason.replace(/</g,'&lt;') + '</li>'; }).join('') +
          '</ul>';
      }
      resultBox.innerHTML = html;
    })
    .catch(function(){
      resultBox.style.display = 'block';
      resultBox.innerHTML = '<div class="rto-msg rto-msg-error">Connection error. Please try again.</div>';
    })
    .finally(function(){
      bulkBtn.disabled = false;
      if (selectAllBox) selectAllBox.checked = false;
      refreshBulkDeleteUI();
      label.textContent = 'Delete Selected (' + bulkCount.textContent + ')';
    });
});

// ENTERPRISE GAP FIX: bulk CSV import (see MastersController::importCitiesCsv()).
document.getElementById('importCitiesBtn')?.addEventListener('click', function(){
  document.getElementById('importCitiesPanel').style.display = '';
  document.getElementById('importCitiesResult').style.display = 'none';
});
document.getElementById('importCitiesCancelBtn')?.addEventListener('click', function(){
  document.getElementById('importCitiesPanel').style.display = 'none';
  document.getElementById('importCitiesFile').value = '';
});
document.getElementById('importCitiesSubmitBtn')?.addEventListener('click', function(){
  var fileInput = document.getElementById('importCitiesFile');
  var resultBox = document.getElementById('importCitiesResult');
  var btn = this;
  if (!fileInput.files.length) { window.rtoToast('Choose a CSV file first.','error'); return; }
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','import_cities_csv');
  fd.append('rto_nonce', nonce);
  fd.append('csv_file', fileInput.files[0]);
  btn.disabled = true; btn.textContent = 'Importing…';
  fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r){
      btn.disabled = false; btn.textContent = 'Upload & Import';
      resultBox.style.display = 'block';
      if (r.success) {
        // NOTE: the human-readable text lives on the top-level r.message
        // (both rto_json_ok() and rto_json_err() put it there, not inside
        // r.data) — r.data here is the {imported, skipped, errors} object.
        var d = r.data || {};
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
      resultBox.innerHTML = '<div class="rto-msg rto-msg-error">Connection error. Please try again.</div>';
    });
});

// ENTERPRISE GAP FIX (Phase 6, item — merge/dedupe tooling): see
// MastersController::mergeCities().
document.getElementById('mergeCitiesBtn')?.addEventListener('click', function(){
  document.getElementById('mergeCitiesPanel').style.display = '';
  document.getElementById('mergeCitiesResult').style.display = 'none';
});
document.getElementById('mergeCitiesCancelBtn')?.addEventListener('click', function(){
  document.getElementById('mergeCitiesPanel').style.display = 'none';
});
document.getElementById('mergeCitiesSubmitBtn')?.addEventListener('click', function(){
  var fromSel = document.getElementById('mergeFromCity');
  var intoSel = document.getElementById('mergeIntoCity');
  var resultBox = document.getElementById('mergeCitiesResult');
  var btn = this;
  if (!fromSel.value || !intoSel.value) { window.rtoToast('Select both a duplicate and a canonical city.','error'); return; }
  if (fromSel.value === intoSel.value) { window.rtoToast('The duplicate and canonical city must be different.','error'); return; }
  if (!confirm('Merge "' + fromSel.options[fromSel.selectedIndex].text + '" into "' + intoSel.options[intoSel.selectedIndex].text + '"? The duplicate city will be permanently deleted. This cannot be undone.')) return;
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','merge_cities');
  fd.append('rto_nonce', nonce);
  fd.append('from_city_id', fromSel.value);
  fd.append('into_city_id', intoSel.value);
  btn.disabled = true; btn.textContent = 'Merging…';
  fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r){
      btn.disabled = false; btn.textContent = 'Merge & Delete Duplicate';
      resultBox.style.display = 'block';
      resultBox.innerHTML = '<div class="rto-msg ' + (r.success ? 'rto-msg-success' : 'rto-msg-error') + '">' + (r.message || (r.success ? 'Merged.' : 'Merge failed.')) + '</div>';
      if (r.success) setTimeout(function(){ location.reload(); }, 1500);
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Merge & Delete Duplicate';
      resultBox.style.display = 'block';
      resultBox.innerHTML = '<div class="rto-msg rto-msg-error">Connection error. Please try again.</div>';
    });
});
</script>

<?php rto_help_box('masters'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
