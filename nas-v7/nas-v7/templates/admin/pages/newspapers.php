<?php if (!defined('ABSPATH')) exit;
global $wpdb; $db = \NAS\Core\Database::instance();
$newspapers = $wpdb->get_results("SELECT * FROM `{$db->prefix('newspapers')}` WHERE is_active=1 ORDER BY language,name", OBJECT);
$cities     = $wpdb->get_results("SELECT id,name,state FROM `{$db->prefix('cities')}` WHERE is_active=1 ORDER BY name", OBJECT);
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-newspaper"></i> Newspapers <span class="nas-count-badge"><?php echo count($newspapers); ?></span></h1>
  <button class="nas-btn nas-btn-primary" onclick="nnOpen()">+ Add Newspaper</button>
</div>
<div class="nas-card"><div class="nas-table-wrap">
<table class="nas-table nas-table-hover">
  <thead><tr><th>Name</th><th>Language</th><th>Cities</th><th>Classified Rate</th><th>Display Rate</th><th>DC Rate</th><th>Min Charge</th><th>Circulation</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($newspapers as $p):
    $nc = count(json_decode($p->cities_supported??'[]',true)??[]);
    $ne = count(json_decode($p->editions??'[]',true)??[]);
  ?>
  <tr>
    <td><strong><?php echo esc_html($p->name); ?></strong><br><small><?php echo $ne; ?> editions</small></td>
    <td><span class="nas-lang-badge"><?php echo esc_html($p->language); ?></span></td>
    <td><?php echo $nc; ?> cities</td>
    <td>₹<?php echo number_format($p->base_rate_classified,2); ?>/word</td>
    <td>₹<?php echo number_format($p->base_rate_display,2); ?>/cm²</td>
    <td>₹<?php echo number_format($p->base_rate_dc??$p->base_rate_display,2); ?>/cm²</td>
    <td>₹<?php echo number_format($p->min_charge,2); ?></td>
    <td><?php echo $p->circulation ? number_format($p->circulation) : '—'; ?></td>
    <td class="nas-actions">
      <button class="nas-btn nas-btn-xs nas-btn-primary" onclick="nnEdit(<?php echo $p->id; ?>)">Edit</button>
      <button class="nas-btn nas-btn-xs" onclick="nnDelete(<?php echo $p->id; ?>)" style="background:#ef4444;color:#fff">Del</button>
    </td>
  </tr>
  <?php endforeach; if(empty($newspapers)): ?>
  <tr><td colspan="9" class="nas-empty">No newspapers yet. Add your first newspaper or run the seeder.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div></div>

<!-- Modal -->
<div id="nn-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content nas-modal-lg">
  <div class="nas-modal-header"><h3 id="nn-title">Add Newspaper</h3><button class="nas-modal-close" onclick="document.getElementById('nn-modal').style.display='none'">✕</button></div>
  <div class="nas-modal-body">
    <input type="hidden" id="nn-id">
    <div class="nas-modal-grid-2">
      <div class="nas-form-row"><label>Name *</label><input id="nn-name" class="nas-input" type="text" placeholder="e.g. Times of India"></div>
      <div class="nas-form-row"><label>Language</label>
        <select id="nn-lang" class="nas-select">
          <?php foreach(['English','Hindi','Marathi','Gujarati','Tamil','Telugu','Kannada','Malayalam','Bengali','Odia','Punjabi','Assamese','Urdu'] as $l): ?>
          <option value="<?php echo $l; ?>"><?php echo $l; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nas-form-row"><label>Classified Rate (₹/word)</label><input id="nn-cl" class="nas-input" type="number" step="0.01" placeholder="50"></div>
      <div class="nas-form-row"><label>Display Rate (₹/cm²)</label><input id="nn-di" class="nas-input" type="number" step="0.01" placeholder="400"></div>
      <div class="nas-form-row"><label>Display Classified Rate (₹/cm²)</label><input id="nn-dc" class="nas-input" type="number" step="0.01" placeholder="300"></div>
      <div class="nas-form-row"><label>Min Charge (₹)</label><input id="nn-min" class="nas-input" type="number" step="0.01" placeholder="300"></div>
      <div class="nas-form-row"><label>Circulation</label><input id="nn-circ" class="nas-input" type="number" placeholder="500000"></div>
    </div>
    <div class="nas-form-row"><label>Description</label><textarea id="nn-desc" class="nas-textarea" rows="2" placeholder="Brief description of this newspaper"></textarea></div>
    <div class="nas-form-row"><label>Editions (one per line)</label><textarea id="nn-editions" class="nas-textarea" rows="4" placeholder="Delhi&#10;Mumbai&#10;Kolkata&#10;Chennai"></textarea></div>
    <div class="nas-form-row">
      <label>Cities Covered <small style="color:#6b7280;font-weight:400">(search and select multiple)</small></label>
      <select id="nn-cities" class="nas-select" multiple style="width:100%">
        <?php foreach($cities as $c): ?><option value="<?php echo $c->id; ?>"><?php echo esc_html($c->name.', '.$c->state); ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('nn-modal').style.display='none'">Cancel</button>
    <button class="nas-btn nas-btn-primary" onclick="nnSave()"><i class="fa-solid fa-save"></i> Save Newspaper</button>
  </div>
</div></div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);

// Initialize Select2 for cities field
if(typeof jQuery !== 'undefined' && typeof jQuery.fn.select2 !== 'undefined') {
  jQuery('#nn-cities').select2({
    placeholder: 'Search and select cities…',
    allowClear: true,
    dropdownParent: document.getElementById('nn-modal'),
    width: '100%'
  });
} else {
  // Fallback: load select2 dynamically
  var s2css = document.createElement('link');s2css.rel='stylesheet';s2css.href='https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css';document.head.appendChild(s2css);
  var s2js = document.createElement('script');s2js.src='https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js';
  s2js.onload=function(){
    jQuery('#nn-cities').select2({placeholder:'Search and select cities…',allowClear:true,dropdownParent:document.getElementById('nn-modal'),width:'100%'});
  };
  document.head.appendChild(s2js);
}
const npData = <?php echo wp_json_encode($newspapers); ?>;

window.nnOpen = function(){
  document.getElementById('nn-id').value='';
  document.getElementById('nn-title').textContent='Add Newspaper';
  ['nn-name','nn-cl','nn-di','nn-dc','nn-min','nn-circ','nn-desc','nn-editions'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  try{jQuery('#nn-cities').val(null).trigger('change');}catch(e){Array.from(document.getElementById('nn-cities').options).forEach(o=>o.selected=false);}
  document.getElementById('nn-modal').style.display='flex';
};

window.nnEdit = function(id){
  const p = npData.find(x=>x.id==id); if(!p) return;
  document.getElementById('nn-id').value=p.id;
  document.getElementById('nn-title').textContent='Edit: '+p.name;
  document.getElementById('nn-name').value=p.name||'';
  document.getElementById('nn-lang').value=p.language||'English';
  document.getElementById('nn-cl').value=p.base_rate_classified||'';
  document.getElementById('nn-di').value=p.base_rate_display||'';
  document.getElementById('nn-dc').value=p.base_rate_dc||p.base_rate_display||'';
  document.getElementById('nn-min').value=p.min_charge||'';
  document.getElementById('nn-circ').value=p.circulation||'';
  document.getElementById('nn-desc').value=p.description||'';
  const editions = JSON.parse(p.editions||'[]');
  document.getElementById('nn-editions').value=editions.join('\n');
  const cities = JSON.parse(p.cities_supported||'[]');
  const cityIds = JSON.parse(p.cities_supported||'[]');
  try{jQuery('#nn-cities').val(cityIds.map(String)).trigger('change');}
  catch(e){Array.from(document.getElementById('nn-cities').options).forEach(o=>o.selected=cityIds.includes(parseInt(o.value)));}
  document.getElementById('nn-modal').style.display='flex';
};

window.nnSave = function(){
  const name = document.getElementById('nn-name').value.trim();
  if(!name){nasAdminToast('Name is required','error');return;}
  let cities;
  try{cities=jQuery('#nn-cities').val().map(v=>parseInt(v));}catch(e){cities=Array.from(document.getElementById('nn-cities').selectedOptions).map(o=>parseInt(o.value));}
  const editions = document.getElementById('nn-editions').value.split('\n').map(e=>e.trim()).filter(Boolean);
  const fd=new FormData();
  fd.append('action','nas_admin_save_newspaper'); fd.append('nonce',C.nonce);
  fd.append('id',document.getElementById('nn-id').value);
  fd.append('name',name);
  fd.append('language',document.getElementById('nn-lang').value);
  fd.append('rate_classified',document.getElementById('nn-cl').value);
  fd.append('rate_display',document.getElementById('nn-di').value);
  fd.append('rate_dc',document.getElementById('nn-dc').value);
  fd.append('min_charge',document.getElementById('nn-min').value);
  fd.append('circulation',document.getElementById('nn-circ').value);
  fd.append('description',document.getElementById('nn-desc').value);
  fd.append('cities',JSON.stringify(cities));
  fd.append('editions',JSON.stringify(editions));
  fetch(C.ajaxUrl,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){nasAdminToast('Newspaper saved!','success');setTimeout(()=>location.reload(),700);}
    else nasAdminToast(res.data?.message||'Error saving','error');
  });
};

window.nnDelete = function(id){
  if(!confirm('Deactivate this newspaper? It will no longer appear in bookings.'))return;
  const fd=new FormData();fd.append('action','nas_admin_delete_newspaper');fd.append('nonce',C.nonce);fd.append('id',id);
  fetch(C.ajaxUrl,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{if(res.success){nasAdminToast('Newspaper deactivated','success');setTimeout(()=>location.reload(),600);}});
};
})();
</script>
