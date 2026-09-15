<?php if (!defined('ABSPATH')) exit;
/** @var array $services @var array $categories @var string $category @var string $search */
$pageTitle = 'Services';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$saved = isset($_GET['saved']);
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">RTO Services</h1>
    <div>
      <a href="<?= esc_url(add_query_arg(['export' => 'csv'])) ?>" class="rto-btn rto-btn-outline">Export CSV</a>
      <a href="<?= esc_url(home_url('/rto-admin/services/?action=new')) ?>" class="rto-btn rto-btn-primary">+ Add Service</a>
    </div>
  </div>

  <?php if ($saved): ?>
  <div class="rto-msg rto-msg-success rto-mb-4" role="status">Service saved successfully.</div>
  <?php endif; ?>

  <!-- Filter -->
  <form method="GET" class="rto-filter-form rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="services">
    <div class="rto-filter-row">
      <div class="rto-form-group" style="flex:2">
        <label class="rto-label" for="sSearch">Search</label>
        <input type="text" id="sSearch" name="search" class="rto-input" value="<?= esc_attr($search) ?>" placeholder="Service name…">
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="sCat">Category</label>
        <select id="sCat" name="category" class="rto-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= esc_attr($c) ?>" <?= $category===$c?'selected':'' ?>><?= esc_html($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
        <a href="<?= esc_url(home_url('/rto-admin/services/')) ?>" class="rto-btn rto-btn-outline">Reset</a>
      </div>
    </div>
  </form>

  <!-- Table -->
  <div class="rto-card">
    <div class="rto-card-header">
      <h3>Services <span class="rto-badge rto-badge-secondary"><?= count($services) ?></span></h3>
    </div>
    <?php if (empty($services)): ?>
    <div class="rto-empty-state"><p>No services found. <a href="<?= esc_url(home_url('/rto-admin/services/?action=new')) ?>">Add your first service</a>.</p></div>
    <?php else: ?>
    <div id="svcBulkBar" class="rto-bulk-bar" style="display:none;padding:10px 20px;background:var(--gray-50);border-bottom:1px solid var(--gray-200);display:none;align-items:center;gap:10px">
      <span class="rto-small"><span id="svcBulkCount">0</span> selected</span>
      <button type="button" class="rto-btn rto-btn-xs rto-btn-outline" data-bulk-active="1">Activate</button>
      <button type="button" class="rto-btn rto-btn-xs rto-btn-outline" data-bulk-active="0">Deactivate</button>
    </div>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr>
          <th scope="col"><input type="checkbox" id="svcSelectAll" aria-label="Select all services"></th>
          <th scope="col">Service Name</th>
          <th scope="col">Category</th>
          <th scope="col">Base Price</th>
          <th scope="col">GST</th>
          <th scope="col">SLA</th>
          <th scope="col">Vendor %</th>
          <th scope="col">Status</th>
          <th scope="col"><span class="rto-visually-hidden">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php
        $currentCat = '';
        foreach ($services as $svc):
        ?>
        <tr>
          <td data-label="Select"><input type="checkbox" class="svc-check" value="<?= esc_attr($svc['id']) ?>" aria-label="Select <?= esc_attr($svc['name']) ?>"></td>
          <td data-label="Service Name">
            <strong><?= esc_html($svc['name']) ?></strong>
            <?php if ($svc['description']): ?>
            <div class="rto-small rto-muted"><?= esc_html(mb_strimwidth($svc['description'], 0, 80, '…')) ?></div>
            <?php endif; ?>
            <?php $ovr = $overrideCounts[(int)$svc['id']] ?? 0; if ($ovr > 0): ?>
            <!-- Known Limitations audit fix: "An admin viewing the Services
                 screen alone cannot see whether a city-level override
                 exists for any given service." -->
            <a href="<?= esc_url(home_url('/rto-admin/city-pricing/')) ?>" class="rto-badge rto-badge-warning" style="margin-top:4px;display:inline-block" title="This service's price/visibility is overridden for specific cities on City/Service Pricing">
              ⓘ overridden in <?= (int)$ovr ?> <?= $ovr === 1 ? 'city' : 'cities' ?>
            </a>
            <?php endif; ?>
          </td>
          <td data-label="Category"><span class="rto-badge rto-badge-secondary"><?= esc_html($svc['category']) ?></span></td>
          <td data-label="Base Price"><?= esc_html(rto_format_inr((float)$svc['base_price'])) ?></td>
          <td data-label="GST"><?= $svc['gst_applicable'] ? '<span class="rto-badge rto-badge-info">18%</span>' : '<span class="rto-muted">—</span>' ?></td>
          <td data-label="SLA"><?= esc_html($svc['sla_days']) ?> days</td>
          <td data-label="Vendor %"><?= esc_html($svc['vendor_share']) ?>%</td>
          <td data-label="Status">
            <label class="rto-toggle" title="Toggle active/inactive">
              <input type="checkbox" class="svc-toggle" data-id="<?= esc_attr($svc['id']) ?>"
                aria-label="Toggle active status for <?= esc_attr($svc['name']) ?>"
                <?= $svc['is_active'] ? 'checked' : '' ?>>
              <span class="rto-toggle-slider"></span>
            </label>
          </td>
          <td data-label="Actions">
            <a href="<?= esc_url(home_url('/rto-admin/services/' . (int)$svc['id'] . '/?action=edit')) ?>"
               class="rto-btn rto-btn-xs rto-btn-outline">Edit</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (($lastPage ?? 1) > 1): ?>
      <div class="rto-pagination">
        <?php for ($i = max(1, ($page ?? 1) - 2); $i <= min($lastPage, ($page ?? 1) + 2); $i++): ?>
          <?php $url = add_query_arg(['category' => $category, 'search' => $search, 'paged' => $i]); ?>
          <a href="<?= esc_url($url) ?>" class="rto-page-link <?= $i === ($page ?? 1) ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <span class="rto-page-info">Page <?= (int)($page ?? 1) ?> of <?= (int)$lastPage ?> (<?= number_format((int)($total ?? 0)) ?> total)</span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Part 5.2 UI/UX audit fix: .rto-toggle's CSS used to be duplicated here
     and in city-pricing/configure.php. Centralized into admin.css so the
     two copies can no longer silently drift apart — see admin.css's own
     comment at that rule for the full rationale. -->

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.querySelectorAll('.svc-toggle').forEach(function(chk) {
  chk.addEventListener('change', function() {
    var id = chk.dataset.id;
    var active = chk.checked ? 1 : 0;
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin');
    fd.append('rto_action','toggle_service'); fd.append('id', id);
    fd.append('active', active); fd.append('rto_nonce', rtoflowAdmin.nonce);
    fetch(rtoflowAdmin.ajax_url, {method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){ if (!r.success) { chk.checked = !chk.checked; window.rtoToast('Failed to update.','error'); } })
      .catch(function() { chk.checked = !chk.checked; window.rtoToast('Request failed.','error'); });
  });
});

// Bulk activate/deactivate
(function() {
  var bar = document.getElementById('svcBulkBar');
  var countEl = document.getElementById('svcBulkCount');
  var selectAll = document.getElementById('svcSelectAll');
  function checks() { return Array.prototype.slice.call(document.querySelectorAll('.svc-check')); }
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
  document.querySelectorAll('[data-bulk-active]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var ids = checks().filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
      if (!ids.length) return;
      var active = btn.getAttribute('data-bulk-active');
      if (!confirm((active==='1'?'Activate ':'Deactivate ') + ids.length + ' service(s)?')) return;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin');
      fd.append('rto_action','bulk_toggle_service'); fd.append('active', active);
      ids.forEach(function(id){ fd.append('service_ids[]', id); });
      fd.append('rto_nonce', rtoflowAdmin.nonce);
      fetch(rtoflowAdmin.ajax_url, {method:'POST',credentials:'same-origin',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (r.success) { window.rtoToast(r.data.message,'success'); setTimeout(function(){location.reload();},800); }
          else window.rtoToast(r.data ? r.data.message : 'Failed.','error');
        })
        .catch(function(){ window.rtoToast('Request failed.','error'); });
    });
  });
})();
</script>

<?php rto_help_box('services'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
