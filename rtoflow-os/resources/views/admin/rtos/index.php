<?php
if (!defined('ABSPATH')) exit;
$pageTitle = 'RTO Management';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
global $wpdb;
$p       = $wpdb->prefix;
$city_id = (int)($_GET['city_id'] ?? 0);
$nonce   = wp_create_nonce('rto_admin_lead');

$cities = $wpdb->get_results(
    "SELECT c.id, c.name, s.name as state_name FROM {$p}rto_cities c
     LEFT JOIN {$p}rto_states s ON s.id=c.state_id
     WHERE c.is_active=1 ORDER BY s.name, c.name",
    ARRAY_A
) ?: [];

$city = $rtos = $services = null;
if ($city_id) {
    $city = $wpdb->get_row($wpdb->prepare("SELECT c.*, s.name as state_name FROM {$p}rto_cities c LEFT JOIN {$p}rto_states s ON s.id=c.state_id WHERE c.id=%d", $city_id), ARRAY_A);
    $rtos = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}rto_rtos WHERE city_id=%d ORDER BY name", $city_id), ARRAY_A) ?: [];
    $services = $wpdb->get_results("SELECT id, name, category FROM {$p}rto_services WHERE is_active=1 ORDER BY category, name", ARRAY_A) ?: [];
    foreach ($rtos as &$rto) {
        $raw = get_option("rtoflow_rto_{$rto['id']}_services", null);
        $rto['service_overrides'] = is_string($raw) ? json_decode($raw, true) : null;
    }
    unset($rto);
}
?>
<div class="rto-page-header">
  <div>
    <h2>RTO Management</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">Manage RTOs per city — enable/disable RTOs and control service availability</p>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="rto_area" value="admin">
      <input type="hidden" name="rto_page" value="rtos">
      <div style="min-width:240px">
        <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">Select City</label>
        <select name="city_id" id="rtoCitySelect" style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px">
          <option value="">— Choose a city —</option>
          <?php foreach ($cities as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $city_id===$c['id']?'selected':'' ?>><?= esc_html($c['name']) ?> (<?= esc_html($c['state_name']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="rto-btn rto-btn--primary">View RTOs</button>
    </form>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var rtoCitySelectEl = document.getElementById('rtoCitySelect');
if (rtoCitySelectEl) rtoCitySelectEl.addEventListener('change', function(){ this.form.submit(); });
</script>

<?php if ($city && $rtos !== null): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
  <h3 style="margin:0;font-size:16px;font-weight:700"><?= esc_html($city['name']) ?> RTOs <span style="font-size:13px;color:#64748b;font-weight:400">(<?= count($rtos) ?> RTOs)</span></h3>
  <button id="addRtoOpenBtn" class="rto-btn rto-btn--primary rto-btn--sm">+ Add RTO</button>
</div>

<?php if (empty($rtos)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:12px;border:1px solid #e2e8f0">
  <div style="font-size:48px;margin-bottom:12px">🏛</div>
  <h3 style="color:#1B2A6B">No RTOs for <?= esc_html($city['name']) ?></h3>
  <p style="color:#64748b">Click "Add RTO" to add the first RTO for this city.</p>
</div>
<?php else: ?>
<div style="display:grid;gap:12px">
  <?php foreach ($rtos as $rto): ?>
  <div class="rto-card" id="rto-card-<?= $rto['id'] ?>" style="<?= !$rto['is_active']?'opacity:0.6':'' ?>">
    <div class="rto-card__body" style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start">
      <div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
          <span style="font-weight:700;font-size:15px"><?= esc_html($rto['name']) ?></span>
          <code style="background:#f3f4f6;padding:2px 8px;border-radius:10px;font-size:11px"><?= esc_html($rto['code']) ?></code>
          <span id="rto-badge-<?= $rto['id'] ?>" style="background:<?= $rto['is_active']?'#DCFCE7':'#FEE2E2' ?>;color:<?= $rto['is_active']?'#166534':'#DC2626' ?>;font-size:11px;font-weight:700;padding:2px 10px;border-radius:10px">
            <?= $rto['is_active'] ? 'Active' : 'Inactive' ?>
          </span>
        </div>
        <?php if ($rto['working_hrs']): ?><div style="font-size:12px;color:#64748b;margin-bottom:6px">🕐 <?= esc_html($rto['working_hrs']) ?></div><?php endif; ?>
        <div style="font-size:12px;color:#374151">
          <strong>Services:</strong>
          <?php if ($rto['service_overrides'] !== null): ?>
          <span style="color:#7c3aed"><?= count($rto['service_overrides']) ?> custom services</span>
          <?php else: ?>
          <span style="color:#16a34a">All <?= count($services) ?> global services</span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px">
          <input type="checkbox" class="rto-active-toggle" data-rto-id="<?= (int)$rto['id'] ?>" data-currently-active="<?= $rto['is_active']?'1':'0' ?>" aria-label="<?= $rto['is_active']?'Deactivate':'Activate' ?> RTO: <?= esc_attr($rto['name']) ?>" <?= $rto['is_active']?'checked':'' ?>>
          <?= $rto['is_active']?'Active':'Inactive' ?>
        </label>
        <!-- Known Limitations audit fix: previously the only way to see/edit
             WHICH services made up a branch's custom override was direct
             database/option access. openManageServices() now opens a real
             checklist against this same page's already-loaded $services. -->
        <button class="rto-btn rto-btn--sm rto-manage-services-btn" data-rto-id="<?= (int)$rto['id'] ?>" data-overrides="<?= esc_attr(wp_json_encode($rto['service_overrides'])) ?>" data-rto-name="<?= esc_attr($rto['name']) ?>" aria-label="Manage services for RTO: <?= esc_attr($rto['name']) ?>" style="font-size:11px">⚙ Manage Services</button>
        <!-- Known Limitations audit fix (found while building Manage
             Services above): esc_js() escapes JS special characters but
             does NOT add surrounding quotes — the second argument here was
             a bare, unquoted token, so deleteRto() broke with a JS syntax
             error for any RTO name containing a space (the overwhelming
             majority — even this file's own placeholder example,
             "Saraswati Vihar RTO", would have broken it). Now wrapped in
             single quotes so it is a real string literal. -->
        <button class="rto-btn rto-btn--sm rto-delete-rto-btn" data-rto-id="<?= (int)$rto['id'] ?>" data-rto-name="<?= esc_attr($rto['name']) ?>" aria-label="Delete RTO: <?= esc_attr($rto['name']) ?>" style="font-size:11px;color:#dc2626;border-color:#fca5a5">🗑 Delete</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add RTO Modal -->
<div id="addRtoModal" role="dialog" aria-modal="true" aria-labelledby="addRtoModalTitle" tabindex="-1" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:28px;min-width:420px;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <h3 id="addRtoModalTitle" style="margin:0 0 16px">Add New RTO — <?= esc_html($city['name']??'') ?></h3>
    <div style="margin-bottom:12px">
      <label for="newRtoCode" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">RTO Code *</label>
      <input type="text" id="newRtoCode" style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;text-transform:uppercase" placeholder="e.g. DL-01">
    </div>
    <div style="margin-bottom:12px">
      <label for="newRtoName" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">RTO Name *</label>
      <input type="text" id="newRtoName" style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px" placeholder="e.g. Saraswati Vihar RTO">
    </div>
    <div style="margin-bottom:16px">
      <label for="newRtoHours" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">Working Hours</label>
      <input type="text" id="newRtoHours" style="width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px" placeholder="e.g. Mon-Sat 10am-5pm">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button id="addRtoCancelBtn" class="rto-btn">Cancel</button>
      <button id="addRtoSubmitBtn" class="rto-btn rto-btn--primary">Add RTO</button>
    </div>
  </div>
</div>

<!-- Manage Services Modal — Known Limitations audit fix: previously there
     was no in-screen way to see or edit WHICH services made up a branch's
     custom override, only a bare count; this replaces direct database/
     option access with a real checklist against the city's own service list. -->
<div id="manageServicesModal" role="dialog" aria-modal="true" aria-labelledby="manageServicesTitle" tabindex="-1" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:28px;min-width:420px;max-width:520px;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <h3 id="manageServicesTitle" style="margin:0 0 6px">Manage Services</h3>
    <p class="rto-small rto-muted" style="margin:0 0 14px">Uncheck "Use all global services" to give this branch a custom, restricted service list.</p>
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0">
      <input type="checkbox" id="msUseAll">
      Use all global services (no custom override)
    </label>
    <div id="msChecklist" style="display:grid;gap:6px">
      <?php foreach ($services as $svc): ?>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px">
        <input type="checkbox" class="ms-service-cb" value="<?= (int)$svc['id'] ?>">
        <?= esc_html($svc['name']) ?> <span style="color:#94a3b8;font-size:11px">(<?= esc_html($svc['category']) ?>)</span>
      </label>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button id="msCancelBtn" class="rto-btn">Cancel</button>
      <button id="msSaveBtn" class="rto-btn rto-btn--primary">Save</button>
    </div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var addRtoModalEl = document.getElementById('addRtoModal');
var addRtoReturnFocus = null;
function openAddRtoModal(triggerEl){
  addRtoReturnFocus = triggerEl;
  addRtoModalEl.style.display='flex';
  addRtoModalEl.focus();
}
function closeAddRtoModal(){
  addRtoModalEl.style.display='none';
  if (addRtoReturnFocus && typeof addRtoReturnFocus.focus === 'function') { addRtoReturnFocus.focus(); addRtoReturnFocus = null; }
}
addRtoModalEl.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeAddRtoModal();
});
// ENTERPRISE GAP FIX (critical, previously undocumented): all three
// functions below were posting to the WRONG admin AJAX actions —
// toggleRto() hit admin.toggle_service (silently flipping whatever SERVICE
// has id=1), addRto() hit admin.add_city (which requires state_id/name and
// so always failed), and deleteRto() hit admin.delete_city with a param
// named rto_id that MastersController::deleteCity() never reads, so it
// always tried to delete CITY id=1 instead. Now wired to real RTO-branch
// CRUD (Router::addRtoBranch()/toggleRtoBranch()/deleteRtoBranch()),
// scoped correctly to the rto_rtos table.
function toggleRto(id, currentlyActive, el){
  var fd=new FormData();
  fd.append('action','rto_admin');
  fd.append('rto_area','admin');
  fd.append('rto_action','toggle_rto_branch');
  fd.append('rto_id',id);
  fd.append('active', currentlyActive ? '0' : '1');
  fd.append('rto_nonce','<?= $nonce ?>');
  if (el) el.disabled = true;
  fetch('<?= admin_url('admin-ajax.php') ?>',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
      if (d.success) { location.reload(); }
      else { if (el) el.disabled = false; alert(d.message || 'Error updating RTO.'); }
    })
    .catch(function(){ if (el) el.disabled = false; alert('Request failed.'); });
}
function addRto(){
  var code=document.getElementById('newRtoCode').value.trim().toUpperCase();
  var name=document.getElementById('newRtoName').value.trim();
  var hours=document.getElementById('newRtoHours').value.trim();
  if(!code||!name){alert('Code and name are required');return;}
  var fd=new FormData();
  fd.append('action','rto_admin');fd.append('rto_area','admin');fd.append('rto_action','add_rto_branch');
  fd.append('city_id','<?= $city_id ?>');fd.append('rto_code',code);fd.append('rto_name',name);fd.append('working_hrs',hours);fd.append('rto_nonce','<?= $nonce ?>');
  fetch('<?= admin_url('admin-ajax.php') ?>',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){location.reload();}else{alert(d.message||'Error adding RTO');}});
}
var manageServicesModalEl = document.getElementById('manageServicesModal');
var currentMsRtoId = null;
var manageServicesReturnFocus = null;
function openManageServices(rtoId, overrides, name){
  currentMsRtoId = rtoId;
  manageServicesReturnFocus = document.activeElement;
  document.getElementById('manageServicesTitle').textContent = 'Manage Services — ' + name;
  var useAll = document.getElementById('msUseAll');
  var checklist = document.getElementById('msChecklist');
  var boxes = document.querySelectorAll('.ms-service-cb');
  if (overrides === null) {
    useAll.checked = true;
    boxes.forEach(function(cb){ cb.checked = false; });
  } else {
    useAll.checked = false;
    var overrideIds = (overrides || []).map(String);
    boxes.forEach(function(cb){ cb.checked = overrideIds.indexOf(cb.value) !== -1; });
  }
  checklist.style.opacity = useAll.checked ? '0.4' : '1';
  checklist.style.pointerEvents = useAll.checked ? 'none' : 'auto';
  manageServicesModalEl.style.display = 'flex';
  manageServicesModalEl.focus();
}
function closeManageServices(){
  manageServicesModalEl.style.display = 'none';
  currentMsRtoId = null;
  if (manageServicesReturnFocus && typeof manageServicesReturnFocus.focus === 'function') { manageServicesReturnFocus.focus(); manageServicesReturnFocus = null; }
}
manageServicesModalEl.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeManageServices();
});
function saveManageServices(){
  if (!currentMsRtoId) return;
  var useAll = document.getElementById('msUseAll').checked;
  var ids = useAll ? [] : Array.prototype.slice.call(document.querySelectorAll('.ms-service-cb:checked')).map(function(cb){ return cb.value; });
  if (!useAll && ids.length === 0) { alert('Select at least one service, or check "Use all global services".'); return; }
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','update_rto_service_override');
  fd.append('rto_id', currentMsRtoId);
  fd.append('use_all', useAll ? '1' : '0');
  ids.forEach(function(id){ fd.append('service_ids[]', id); });
  fd.append('rto_nonce','<?= $nonce ?>');
  fetch('<?= admin_url('admin-ajax.php') ?>',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
      if (d.success) { location.reload(); }
      else { alert(d.message || 'Error saving services.'); }
    })
    .catch(function(){ alert('Request failed.'); });
}
function deleteRto(id,name){
  if(!confirm('Delete RTO "'+name+'"? This cannot be undone.'))return;
  var fd=new FormData();
  fd.append('action','rto_admin');fd.append('rto_area','admin');fd.append('rto_action','delete_rto_branch');
  fd.append('rto_id',id);fd.append('rto_nonce','<?= $nonce ?>');
  fetch('<?= admin_url('admin-ajax.php') ?>',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){document.getElementById('rto-card-'+id).remove();}else{alert(d.message||'Error');}
  });
}

var addRtoOpenBtnEl = document.getElementById('addRtoOpenBtn');
if (addRtoOpenBtnEl) addRtoOpenBtnEl.addEventListener('click', function(){ openAddRtoModal(this); });

var addRtoCancelBtnEl = document.getElementById('addRtoCancelBtn');
if (addRtoCancelBtnEl) addRtoCancelBtnEl.addEventListener('click', function(){ closeAddRtoModal(); });

var addRtoSubmitBtnEl = document.getElementById('addRtoSubmitBtn');
if (addRtoSubmitBtnEl) addRtoSubmitBtnEl.addEventListener('click', function(){ addRto(); });

var msUseAllEl = document.getElementById('msUseAll');
if (msUseAllEl) msUseAllEl.addEventListener('change', function(){
  document.getElementById('msChecklist').style.opacity = this.checked ? '0.4' : '1';
  document.getElementById('msChecklist').style.pointerEvents = this.checked ? 'none' : 'auto';
});

var msCancelBtnEl = document.getElementById('msCancelBtn');
if (msCancelBtnEl) msCancelBtnEl.addEventListener('click', function(){ closeManageServices(); });

var msSaveBtnEl = document.getElementById('msSaveBtn');
if (msSaveBtnEl) msSaveBtnEl.addEventListener('click', function(){ saveManageServices(); });

document.querySelectorAll('.rto-active-toggle').forEach(function(cb){
  cb.addEventListener('change', function(){
    toggleRto(parseInt(cb.dataset.rtoId,10), cb.dataset.currentlyActive === '1', cb);
  });
});

document.querySelectorAll('.rto-manage-services-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var overrides = JSON.parse(btn.dataset.overrides);
    openManageServices(parseInt(btn.dataset.rtoId,10), overrides, btn.dataset.rtoName);
  });
});

document.querySelectorAll('.rto-delete-rto-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    deleteRto(parseInt(btn.dataset.rtoId,10), btn.dataset.rtoName);
  });
});
</script>
<?php endif; ?>
<?php rto_help_box('rtos'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
