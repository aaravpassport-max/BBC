<?php
$pageTitle = 'All Leads';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$result = $leads;
$data   = $result['data'];
$total  = $result['total'];
$page   = $result['page'];
$perPage= $result['per_page'];
$pages  = ceil($total / max(1, $perPage));
?>

<?php
// ENTERPRISE GAP FIX (Phase 6, item — "no saved/named filter views on any
// list screen"): see SavedFilterService and Router::savedFilterSave()/
// savedFilterDelete(). Applying a saved filter is just a link to this same
// screen with that filter's stored query string — no new load path.
?>
<div class="rto-card rto-mb-4" style="padding:10px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
  <strong class="rto-small">Saved Filters:</strong>
  <?php if (empty($savedFilters)): ?>
  <span class="rto-small rto-muted">None yet — set filters below, then "Save Current Filter".</span>
  <?php else: ?>
  <?php foreach ($savedFilters as $sf): ?>
  <span class="rto-badge rto-badge-secondary" style="display:inline-flex;align-items:center;gap:6px">
    <a href="<?= esc_url(home_url('/rto-admin/leads/?' . $sf['query_string'])) ?>" style="color:inherit;text-decoration:none"><?= esc_html($sf['name']) ?></a>
    <button type="button" class="saved-filter-delete" data-id="<?= (int)$sf['id'] ?>" aria-label="Delete saved filter: <?= esc_attr($sf['name']) ?>" style="background:none;border:0;cursor:pointer;color:inherit;padding:0;font-size:12px">✕</button>
  </span>
  <?php endforeach; ?>
  <?php endif; ?>
  <button type="button" id="saveCurrentFilterBtn" class="rto-btn rto-btn-xs rto-btn-outline" style="margin-left:auto">Save Current Filter</button>
</div>

<!-- Filters -->
<div class="rto-card rto-card-filters">
  <form method="GET" class="rto-filter-form">
    <input type="hidden" name="rto_area" value="admin">
    <input type="hidden" name="rto_page" value="leads">
    <div class="rto-filter-row">
      <input type="text" name="search" placeholder="Search by order, client, email…"
             value="<?= esc_attr($filters['search'] ?? '') ?>" class="rto-input">
      <?php
      // Known Limitations audit fix: "Filters combine only with AND logic
      // — there is no OR mode." LeadService::getLeads() has already
      // supported a comma-separated status list (a real OR within this one
      // filter) — the single-select dropdown here was the last piece never
      // updated to actually let staff use it. This is a multi-select
      // checkbox dropdown that writes the comma-separated list into the
      // hidden #statusFilterValue input the form actually submits.
      $statuses = ['created','payment_pending','payment_received','assigned','in_progress','docs_pending','docs_verified','rto_submitted','rto_processing','completed','cancelled','on_hold'];
      $selectedStatuses = array_values(array_filter(array_map('trim', explode(',', (string)($filters['status'] ?? '')))));
      ?>
      <input type="hidden" name="status" id="statusFilterValue" value="<?= esc_attr(implode(',', $selectedStatuses)) ?>">
      <div class="rto-multiselect" style="position:relative;display:inline-block">
        <button type="button" id="statusFilterBtn" class="rto-select" style="text-align:left;cursor:pointer;min-width:160px">
          <span id="statusFilterLabel"><?= $selectedStatuses ? esc_html(count($selectedStatuses) . ' status' . (count($selectedStatuses) > 1 ? 'es' : '') . ' selected') : 'All Statuses' ?></span>
        </button>
        <div id="statusFilterPanel" style="display:none;position:absolute;z-index:20;top:100%;left:0;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);padding:8px;min-width:220px;max-height:280px;overflow-y:auto">
          <div style="font-size:11px;color:#64748b;margin-bottom:6px">Select one or more — combined with OR</div>
          <?php foreach ($statuses as $s): ?>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:12px;padding:3px 0;cursor:pointer">
            <input type="checkbox" class="status-filter-cb" value="<?= esc_attr($s) ?>" <?= in_array($s, $selectedStatuses, true) ? 'checked' : '' ?>>
            <?= esc_html(rto_status_label($s)) ?>
          </label>
          <?php endforeach; ?>
          <div style="display:flex;gap:6px;margin-top:8px;border-top:1px solid #f1f5f9;padding-top:6px">
            <button type="button" class="rto-btn rto-btn-xs rto-btn-outline" id="statusFilterClear">Clear</button>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-primary" id="statusFilterApply">Apply</button>
          </div>
        </div>
      </div>
      <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
      (function(){
        var btn = document.getElementById('statusFilterBtn');
        var panel = document.getElementById('statusFilterPanel');
        var hidden = document.getElementById('statusFilterValue');
        var label = document.getElementById('statusFilterLabel');
        btn.addEventListener('click', function(e){ e.stopPropagation(); panel.style.display = panel.style.display === 'none' ? '' : 'none'; });
        document.addEventListener('click', function(e){ if (!panel.contains(e.target) && e.target !== btn) panel.style.display = 'none'; });
        function currentChecked(){ return Array.prototype.slice.call(document.querySelectorAll('.status-filter-cb:checked')).map(function(cb){ return cb.value; }); }
        document.getElementById('statusFilterClear').addEventListener('click', function(){
          document.querySelectorAll('.status-filter-cb').forEach(function(cb){ cb.checked = false; });
        });
        document.getElementById('statusFilterApply').addEventListener('click', function(){
          var vals = currentChecked();
          hidden.value = vals.join(',');
          label.textContent = vals.length ? (vals.length + ' status' + (vals.length > 1 ? 'es' : '') + ' selected') : 'All Statuses';
          panel.style.display = 'none';
          hidden.closest('form').submit();
        });
      })();
      </script>
      <input type="date" name="date_from" id="dateFrom" value="<?= esc_attr($filters['date_from'] ?? '') ?>" class="rto-input" placeholder="From date">
      <input type="date" name="date_to"   id="dateTo"   value="<?= esc_attr($filters['date_to']   ?? '') ?>" class="rto-input" placeholder="To date">
      <!-- Part 14-E build: smart date filter shortcuts -->
      <div class="rto-date-shortcuts" style="display:flex;gap:6px;flex-wrap:wrap">
        <button type="button" class="rto-btn rto-btn-xs rto-btn-outline rto-date-shortcut-btn"
                data-days-back="0" data-days-forward="0">Today</button>
        <button type="button" class="rto-btn rto-btn-xs rto-btn-outline rto-date-shortcut-btn"
                data-days-back="6" data-days-forward="0">Last 7 days</button>
        <button type="button" class="rto-btn rto-btn-xs rto-btn-outline rto-date-shortcut-btn"
                data-days-back="29" data-days-forward="0">Last 30 days</button>
        <button type="button" class="rto-btn rto-btn-xs rto-btn-outline rto-date-shortcut-btn"
                data-days-back="89" data-days-forward="0">Last 90 days</button>
      </div>
      <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
      function setDateRange(daysBack, daysForward) {
        var now = new Date();
        var to  = new Date(now); to.setDate(to.getDate() + daysForward);
        var from= new Date(now); from.setDate(from.getDate() - daysBack);
        var fmt = function(d){ return d.toISOString().slice(0,10); };
        document.getElementById('dateFrom').value = fmt(from);
        document.getElementById('dateTo').value   = fmt(to);
        document.getElementById('dateFrom').closest('form').submit();
      }
      document.querySelectorAll('.rto-date-shortcut-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          setDateRange(parseInt(btn.dataset.daysBack,10), parseInt(btn.dataset.daysForward,10));
        });
      });
      </script>
      <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
      <a href="<?= esc_url(home_url('/rto-admin/leads/')) ?>" class="rto-btn rto-btn-outline">Reset</a>
      <!-- Part 5.7: the only browse path back to archived orders — see
           LeadService::archiveLead()/getLeads()'s 'archived' filter. -->
      <a href="<?= esc_url(add_query_arg(['archived' => (($filters['archived'] ?? '') === 'only') ? '' : 'only'])) ?>"
         class="rto-btn <?= (($filters['archived'] ?? '') === 'only') ? 'rto-btn-primary' : 'rto-btn-outline' ?>">
        <?= (($filters['archived'] ?? '') === 'only') ? '← Active Orders' : 'Archived Orders' ?>
      </a>
      <!-- Part 11-D / 12-E build: CSV export of filtered results -->
      <a href="<?= esc_url(add_query_arg(['export' => 'csv'], strtok($_SERVER['REQUEST_URI'] ?? home_url('/rto-admin/leads/'), '?') . '?' . http_build_query(array_filter($filters ?? [])))) ?>"
         class="rto-btn rto-btn-outline rto-btn-icon" title="Export filtered results as CSV"
         aria-label="Export leads as CSV">
        ⬇ CSV
      </a>
    </div>
  </form>
</div>

<div class="rto-card">
  <div class="rto-card-header">
    <h3><?= (($filters['archived'] ?? '') === 'only') ? 'Archived Orders' : 'Leads' ?> <span class="rto-count"><?= number_format($total) ?></span></h3>
  </div>
  <div class="rto-card-body rto-table-scroll">
    <?php if (empty($data)): ?>
    <div class="rto-empty-state">
      <div class="rto-empty-icon">📋</div>
      <p>No leads found matching your filters.</p>
    </div>
    <?php else: ?>
    <!-- Part 12-E / 13-E build: bulk operations bar -->
    <div id="bulkBar" style="display:none;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;padding:10px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px">
      <span id="bulkCount" style="font-size:13px;font-weight:700;color:#1D4ED8">0 selected</span>
      <select id="bulkStatus" class="rto-select rto-select-sm" style="max-width:180px">
        <option value="">— Change Status —</option>
        <?php foreach (['payment_received','assigned','in_progress','completed','cancelled','on_hold'] as $s): ?>
        <option value="<?= esc_attr($s) ?>"><?= esc_html(rto_status_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="rto-btn rto-btn-sm rto-btn-primary" id="bulkApplyBtn">Apply to Selected</button>
      <?php
      // ENTERPRISE GAP FIX (Phase 6, item — "no bulk vendor reassignment
      // ... on Leads"): reuses LeadsController::bulkReassignVendor(), which
      // itself reuses LeadService::assignVendor() per lead.
      ?>
      <select id="bulkVendor" class="rto-select rto-select-sm" style="max-width:200px">
        <option value="">— Reassign Vendor —</option>
        <?php foreach (($reassignVendors ?? []) as $rv): ?>
        <option value="<?= (int)$rv['id'] ?>"><?= esc_html($rv['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="rto-btn rto-btn-sm rto-btn-primary" id="bulkReassignBtn">Reassign Selected</button>
      <?php if (($filters['archived'] ?? '') === 'only'): ?>
      <button type="button" class="rto-btn rto-btn-sm rto-btn-success" id="bulkRestoreBtn">Restore Selected</button>
      <?php else: ?>
      <button type="button" class="rto-btn rto-btn-sm rto-btn-danger" id="bulkArchiveBtn">Archive Selected</button>
      <?php endif; ?>
      <button type="button" class="rto-btn rto-btn-sm rto-btn-outline" id="bulkClearBtn">Clear</button>
    </div>
    <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
    var bulkIds = new Set();
    function toggleBulk(id, cb) {
      if (cb.checked) bulkIds.add(id); else bulkIds.delete(id);
      document.getElementById('bulkCount').textContent = bulkIds.size + ' selected';
      document.getElementById('bulkBar').style.display = bulkIds.size > 0 ? 'flex' : 'none';
    }
    function toggleAllBulk(cb) {
      document.querySelectorAll('.bulk-cb').forEach(function(c) {
        c.checked = cb.checked;
        var id = parseInt(c.dataset.id);
        if (cb.checked) bulkIds.add(id); else bulkIds.delete(id);
      });
      document.getElementById('bulkCount').textContent = bulkIds.size + ' selected';
      document.getElementById('bulkBar').style.display = bulkIds.size > 0 ? 'flex' : 'none';
    }
    function clearBulkSelection() {
      bulkIds.clear();
      document.querySelectorAll('.bulk-cb, #bulkSelectAll').forEach(function(c){ c.checked=false; });
      document.getElementById('bulkBar').style.display = 'none';
    }
    function applyBulkStatus() {
      var status = document.getElementById('bulkStatus').value;
      if (!status) { alert('Please select a target status.'); return; }
      if (!bulkIds.size) { alert('No leads selected.'); return; }
      if (!confirm('Change ' + bulkIds.size + ' lead(s) to: ' + status + '?')) return;
      var btn = document.getElementById('bulkApplyBtn');
      btn.disabled = true; btn.textContent = 'Applying…';
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin');
      fd.append('rto_action','bulk_status'); fd.append('status', status);
      fd.append('rto_nonce',(window.rtoflowAdmin||{}).nonce||'');
      bulkIds.forEach(function(id){ fd.append('lead_ids[]', id); });
      fetch((window.rtoflowAdmin||{}).ajax_url||'/wp-admin/admin-ajax.php',
            {method:'POST',credentials:'same-origin',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (r.success) {
            window.rtoToast && rtoToast(r.data&&r.data.message||'Done.','success');
            setTimeout(function(){ location.reload(); }, 1200);
          } else {
            window.rtoToast && rtoToast(r.data&&r.data.message||'Failed.','error');
            btn.disabled=false; btn.textContent='Apply to Selected';
          }
        })
        .catch(function(){ window.rtoToast && rtoToast('Request failed.','error'); btn.disabled=false; btn.textContent='Apply to Selected'; });
    }
    function applyBulkReassign() {
      var vendorId = document.getElementById('bulkVendor').value;
      if (!vendorId) { alert('Please select a vendor.'); return; }
      if (!bulkIds.size) { alert('No leads selected.'); return; }
      if (!confirm('Reassign ' + bulkIds.size + ' lead(s) to this vendor?')) return;
      var btn = document.getElementById('bulkReassignBtn');
      btn.disabled = true; btn.textContent = 'Reassigning…';
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin');
      fd.append('rto_action','bulk_reassign_vendor'); fd.append('vendor_id', vendorId);
      fd.append('rto_nonce',(window.rtoflowAdmin||{}).nonce||'');
      bulkIds.forEach(function(id){ fd.append('lead_ids[]', id); });
      fetch((window.rtoflowAdmin||{}).ajax_url||'/wp-admin/admin-ajax.php',
            {method:'POST',credentials:'same-origin',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (r.success) {
            window.rtoToast && rtoToast(r.data&&r.data.message||'Done.','success');
            setTimeout(function(){ location.reload(); }, 1200);
          } else {
            window.rtoToast && rtoToast(r.data&&r.data.message||'Failed.','error');
            btn.disabled=false; btn.textContent='Reassign Selected';
          }
        })
        .catch(function(){ window.rtoToast && rtoToast('Request failed.','error'); btn.disabled=false; btn.textContent='Reassign Selected'; });
    }
    // Bulk archive/restore from the Leads list — mirrors applyBulkStatus()
    // above; hits admin.bulk_archive_lead / admin.bulk_restore_lead which
    // do the whole selection in one WHERE id IN (...) UPDATE server-side.
    function applyBulkArchiveAction(rtoAction) {
      if (!bulkIds.size) { alert('No leads selected.'); return; }
      var isRestore = rtoAction === 'restore_lead';
      var verb = isRestore ? 'restore' : 'archive';
      if (!confirm('Are you sure you want to ' + verb + ' ' + bulkIds.size + ' lead(s)?')) return;
      var btnId = isRestore ? 'bulkRestoreBtn' : 'bulkArchiveBtn';
      var btn = document.getElementById(btnId);
      var origText = btn.textContent;
      btn.disabled = true; btn.textContent = (isRestore ? 'Restoring…' : 'Archiving…');
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin');
      fd.append('rto_action', isRestore ? 'bulk_restore_lead' : 'bulk_archive_lead');
      fd.append('rto_nonce',(window.rtoflowAdmin||{}).nonce||'');
      bulkIds.forEach(function(id){ fd.append('lead_ids[]', id); });
      fetch((window.rtoflowAdmin||{}).ajax_url||'/wp-admin/admin-ajax.php',
            {method:'POST',credentials:'same-origin',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (r.success) {
            window.rtoToast && rtoToast(r.data&&r.data.message||'Done.','success');
            setTimeout(function(){ location.reload(); }, 1200);
          } else {
            window.rtoToast && rtoToast(r.data&&r.data.message||'Failed.','error');
            btn.disabled=false; btn.textContent=origText;
          }
        })
        .catch(function(){ window.rtoToast && rtoToast('Request failed.','error'); btn.disabled=false; btn.textContent=origText; });
    }
    // Part 5.7: restore an archived order straight from the Archived list —
    // same AJAX endpoint (admin.restore_lead) the order-detail Restore
    // button uses.
    document.querySelectorAll('.restore-lead-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var id = btn.getAttribute('data-id');
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action','rto_admin'); fd.append('rto_area','admin');
        fd.append('rto_action','restore_lead'); fd.append('lead_id', id);
        fd.append('rto_nonce',(window.rtoflowAdmin||{}).nonce||'');
        fetch((window.rtoflowAdmin||{}).ajax_url||'/wp-admin/admin-ajax.php',
              {method:'POST',credentials:'same-origin',body:fd})
          .then(function(r){ return r.json(); })
          .then(function(r){
            window.rtoToast && rtoToast((r.data&&r.data.message)||(r.success?'Restored.':'Failed.'), r.success?'success':'error');
            if (r.success) setTimeout(function(){ location.reload(); }, 800); else btn.disabled = false;
          })
          .catch(function(){ window.rtoToast && rtoToast('Request failed.','error'); btn.disabled=false; });
      });
    });

    document.querySelectorAll('.bulk-cb').forEach(function(cb){
      cb.addEventListener('change', function(){ toggleBulk(parseInt(cb.dataset.id,10), cb); });
    });
    var bulkSelectAllEl = document.getElementById('bulkSelectAll');
    if (bulkSelectAllEl) bulkSelectAllEl.addEventListener('change', function(){ toggleAllBulk(this); });
    var bulkApplyBtnEl = document.getElementById('bulkApplyBtn');
    if (bulkApplyBtnEl) bulkApplyBtnEl.addEventListener('click', function(){ applyBulkStatus(); });
    var bulkReassignBtnEl = document.getElementById('bulkReassignBtn');
    if (bulkReassignBtnEl) bulkReassignBtnEl.addEventListener('click', function(){ applyBulkReassign(); });
    var bulkRestoreBtnEl = document.getElementById('bulkRestoreBtn');
    if (bulkRestoreBtnEl) bulkRestoreBtnEl.addEventListener('click', function(){ applyBulkArchiveAction('restore_lead'); });
    var bulkArchiveBtnEl = document.getElementById('bulkArchiveBtn');
    if (bulkArchiveBtnEl) bulkArchiveBtnEl.addEventListener('click', function(){ applyBulkArchiveAction('archive_lead'); });
    var bulkClearBtnEl = document.getElementById('bulkClearBtn');
    if (bulkClearBtnEl) bulkClearBtnEl.addEventListener('click', function(){ clearBulkSelection(); });
    </script>
    <?php
      // MOBILE REDESIGN (not just shrunk): under the mobile breakpoint this
      // table becomes a stack of labeled cards (see mobile-nav.css's
      // table[data-rto-responsive="cards"] rules) instead of a 10-column
      // table nobody can read on a phone. Every <td> below carries
      // data-label so each card shows "Field: Value" instead of a bare
      // value. Desktop rendering (a normal <table>) is completely
      // unchanged — data-rto-responsive="cards" and data-label are inert
      // attributes above the mobile breakpoint.
    ?>
    <table class="rto-table rto-table-hover" data-rto-responsive="cards">
      <thead>
        <tr>
          <th style="width:32px"><input type="checkbox" id="bulkSelectAll" title="Select all" aria-label="Select all leads"></th>
          <th>Order #</th>
          <th>Service</th>
          <th>City</th>
          <th>Client</th>
          <th>Amount</th>
          <th>Payment</th>
          <th>Status</th>
          <th>SLA</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data as $lead): ?>
        <tr class="<?= $lead['sla_breached'] ? 'rto-row-danger' : '' ?>">
          <td data-label="Select"><input type="checkbox" class="bulk-cb" data-id="<?= (int)$lead['id'] ?>"
                     aria-label="Select lead <?= esc_attr($lead['lead_number']) ?>"></td>
          <td data-label="Order #">
            <a href="<?= esc_url(home_url('/rto-admin/leads/' . $lead['id'])) ?>" class="rto-link rto-fw-600">
              <?= esc_html($lead['lead_number']) ?>
            </a>
            <?php if ($lead['sla_breached']): ?><span class="rto-badge rto-badge-danger rto-badge-xs">SLA!</span><?php endif; ?>
          </td>
          <td data-label="Service"><?= esc_html($lead['service_name']) ?></td>
          <td data-label="City"><?= esc_html($lead['city_name']) ?></td>
          <td data-label="Client"><?= esc_html($lead['client_name']) ?></td>
          <td data-label="Amount"><?= esc_html(rto_format_inr((float)$lead['total_amount'])) ?></td>
          <td data-label="Payment">
            <span class="rto-badge rto-badge-<?= $lead['payment_status'] === 'paid' ? 'success' : ($lead['payment_status'] === 'partial' ? 'warning' : 'secondary') ?>">
              <?= esc_html(ucfirst($lead['payment_status'])) ?>
            </span>
          </td>
          <td data-label="Status">
            <span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($lead['status'])) ?>">
              <?= esc_html(rto_status_label($lead['status'])) ?>
            </span>
          </td>
          <td data-label="SLA" class="<?= $lead['sla_breached'] ? 'rto-danger' : '' ?>"><?= esc_html(rto_date($lead['sla_deadline'] ?? '')) ?></td>
          <td data-label="Created" class="rto-muted"><?= esc_html(rto_date($lead['created_at'])) ?></td>
          <td data-label="Actions">
            <a href="<?= esc_url(home_url('/rto-admin/leads/' . $lead['id'])) ?>" class="rto-btn rto-btn-sm rto-btn-outline">View</a>
            <?php if (!empty($lead['deleted_at'])): ?>
            <button type="button" class="rto-btn rto-btn-sm rto-btn-success restore-lead-btn" data-id="<?= (int)$lead['id'] ?>">Restore</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="rto-pagination">
      <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
        <?php $url = add_query_arg(array_merge($filters, ['paged' => $i])); ?>
        <a href="<?= esc_url($url) ?>" class="rto-page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <span class="rto-page-info">Page <?= $page ?> of <?= $pages ?> (<?= number_format($total) ?> total)</span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>


<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
// ENTERPRISE GAP FIX (Phase 6, item — "no saved/named filter views on any
// list screen")
document.getElementById('saveCurrentFilterBtn')?.addEventListener('click', function() {
  var name = prompt('Name this filter (e.g. "SLA breached — Mumbai"):');
  if (!name || !name.trim()) return;
  var qs = window.location.search.replace(/^\?/, '');
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin');
  fd.append('rto_action', 'saved_filter_save');
  fd.append('screen', 'leads'); fd.append('name', name.trim()); fd.append('query_string', qs);
  fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
  fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(r) {
      if (r.success) { location.reload(); } else { alert(r.message || 'Could not save this filter.'); }
    })
    .catch(function() { alert('Request failed.'); });
});
document.querySelectorAll('.saved-filter-delete').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    if (!confirm('Delete this saved filter?')) return;
    var fd = new FormData();
    fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin');
    fd.append('rto_action', 'saved_filter_delete'); fd.append('id', btn.dataset.id);
    fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
    fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(r) { if (r.success) location.reload(); else alert(r.message || 'Could not delete this filter.'); })
      .catch(function() { alert('Request failed.'); });
  });
});
</script>
<?php rto_help_box('leads'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
