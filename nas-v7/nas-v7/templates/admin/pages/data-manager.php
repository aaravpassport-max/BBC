<?php if (!defined('ABSPATH')) exit;
global $wpdb; $db = \NAS\Core\Database::instance();
$counts = [
  'cities'     => $wpdb->get_var("SELECT COUNT(*) FROM `{$db->prefix('cities')}` WHERE is_active=1"),
  'newspapers' => $wpdb->get_var("SELECT COUNT(*) FROM `{$db->prefix('newspapers')}` WHERE is_active=1"),
  'categories' => $wpdb->get_var("SELECT COUNT(*) FROM `{$db->prefix('categories')}` WHERE is_active=1"),
  'templates'  => $wpdb->get_var("SELECT COUNT(*) FROM `{$db->prefix('templates')}` WHERE is_active=1"),
  'bookings'   => $wpdb->get_var("SELECT COUNT(*) FROM `{$db->prefix('bookings')}`"),
];
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-database"></i> Data Manager</h1>
</div>

<!-- Current Stats -->
<div class="nas-quick-stats" style="grid-template-columns:repeat(5,1fr)">
  <?php foreach($counts as $label=>$count): ?>
  <div class="nas-qs-card">
    <strong><?php echo intval($count); ?></strong>
    <span><?php echo ucfirst($label); ?></span>
  </div>
  <?php endforeach; ?>
</div>

<div class="nas-charts-row" style="margin-top:24px">
  <!-- Seeder -->
  <div class="nas-card" style="flex:1">
    <div class="nas-card-header"><h3><i class="fa-solid fa-seedling"></i> Seed Default Data</h3></div>
    <div style="padding:20px">
      <p style="font-size:14px;color:#64748b;margin-bottom:16px">Populate your database with 300 Indian cities, 95+ newspapers across 14 languages, 12 ad categories, 22 templates, 17 sample ads, and 8 combo offers.</p>
      <p style="font-size:12px;color:#94a3b8;margin-bottom:16px">⚠️ This will only add data if tables are empty. It will not overwrite existing data.</p>
      <button class="nas-btn nas-btn-primary" onclick="dmRunSeeder()"><i class="fa-solid fa-seedling"></i> Run Full Seeder</button>
    </div>
  </div>

  <!-- Export -->
  <div class="nas-card" style="flex:1">
    <div class="nas-card-header"><h3><i class="fa-solid fa-download"></i> Export Data</h3></div>
    <div style="padding:20px;display:flex;flex-direction:column;gap:12px">
      <button class="nas-btn nas-btn-outline" onclick="dmExport('bookings')"><i class="fa-solid fa-file-csv"></i> Export All Bookings CSV</button>
      <button class="nas-btn nas-btn-outline" onclick="dmExport('clients')"><i class="fa-solid fa-users"></i> Export Clients CSV</button>
    </div>
  </div>

  <!-- Bulk Import -->
  <div class="nas-card" style="flex:1.5">
    <div class="nas-card-header"><h3><i class="fa-solid fa-upload"></i> Bulk Import CSV</h3></div>
    <div style="padding:20px">
      <div class="nas-form-row">
        <label>Import Type</label>
        <select id="dm-type" class="nas-select" onchange="dmUpdateTemplate()">
          <option value="cities">Cities</option>
          <option value="newspapers">Newspapers</option>
          <option value="templates">Ad Templates</option>
        </select>
      </div>
      <div class="nas-form-row">
        <label>CSV Data (paste below)</label>
        <div id="dm-template-hint" style="font-size:11px;color:#94a3b8;margin-bottom:6px;font-family:monospace"></div>
        <textarea id="dm-csv" class="nas-textarea" rows="8" placeholder="Paste CSV data here..."></textarea>
      </div>
      <button class="nas-btn nas-btn-primary" onclick="dmImport()"><i class="fa-solid fa-upload"></i> Import</button>
      <div id="dm-result" style="margin-top:12px"></div>
    </div>
  </div>
</div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const templates = {
  cities: 'name,state,tier,population\nNew Delhi,Delhi,1,11034555\nMumbai,Maharashtra,1,12442373',
  newspapers: 'name,language,rate_classified,rate_display,min_charge,circulation,editions\nTimes of India,English,80,450,500,3000000,Delhi|Mumbai|Chennai',
  templates: 'name,category,tone,word_limit,content\nBride Wanted,Matrimonial,formal,60,"Looking for well-educated bride..."',
};

window.dmUpdateTemplate = function(){
  const type = document.getElementById('dm-type').value;
  document.getElementById('dm-template-hint').textContent = 'Required columns: ' + templates[type].split('\n')[0];
};
dmUpdateTemplate();

window.dmRunSeeder = function(){
  if(!confirm('Run the full seeder? This will add default data if tables are empty.')) return;
  const fd=new FormData(); fd.append('action','nas_admin_run_seeder'); fd.append('nonce',C.nonce);
  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(res=>{
    nasAdminToast(res.success?res.data.message:res.data?.message||'Error', res.success?'success':'error');
    if(res.success) setTimeout(()=>location.reload(),2000);
  });
};

window.dmExport = function(type){
  const fd=new FormData(); fd.append('action','nas_admin_export_bookings'); fd.append('nonce',C.nonce); fd.append('type',type);
  var _nc2=new AbortController();setTimeout(function(){_nc2.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc2.signal}).then(r=>r.blob()).then(blob=>{
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
    a.download=type+'-'+new Date().toISOString().split('T')[0]+'.csv'; a.click();
  });
};

window.dmImport = function(){
  const csv = document.getElementById('dm-csv').value.trim();
  if(!csv){nasAdminToast('Paste CSV data first','error');return;}
  const fd=new FormData(); fd.append('action','nas_admin_bulk_import'); fd.append('nonce',C.nonce);
  fd.append('import_type',document.getElementById('dm-type').value); fd.append('csv_data',csv);
  var _nc3=new AbortController();setTimeout(function(){_nc3.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc3.signal}).then(r=>r.json()).then(res=>{
    const result = document.getElementById('dm-result');
    if(res.success){
      result.innerHTML = `<div class="nas-success-box">✅ ${res.data.message}${res.data.errors?.length?'<br>Errors: '+res.data.errors.join(', '):''}</div>`;
    } else {
      result.innerHTML = `<div class="nas-error-box">❌ ${res.data?.message||'Import failed'}</div>`;
    }
  });
};
})();
</script>
