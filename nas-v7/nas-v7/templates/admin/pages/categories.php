<?php if (!defined('ABSPATH')) exit;
global $wpdb; $db = \NAS\Core\Database::instance();
$cats = $wpdb->get_results("SELECT * FROM `{$db->prefix('categories')}` WHERE is_active=1 ORDER BY sort_order,name", OBJECT);
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-tags"></i> Categories <span class="nas-count-badge"><?php echo count($cats); ?></span></h1>
  <button class="nas-btn nas-btn-primary" onclick="ncOpen()">+ Add Category</button>
</div>
<div class="nas-cat-grid-admin" id="nc-grid">
<?php foreach($cats as $c): ?>
<div class="nas-cat-dm-card">
  <span class="nas-cat-icon"><?php echo esc_html($c->icon??'📋'); ?></span>
  <strong><?php echo esc_html($c->name); ?></strong>
  <small><?php echo esc_html($c->description??''); ?></small>
  <div style="margin-top:10px;display:flex;gap:8px;justify-content:center">
    <button class="nas-btn nas-btn-xs nas-btn-primary" onclick="ncEdit(<?php echo $c->id; ?>)">Edit</button>
  </div>
</div>
<?php endforeach; if(empty($cats)): ?>
<div style="grid-column:1/-1;text-align:center;padding:40px;color:#94a3b8">No categories yet. <button class="nas-btn nas-btn-sm nas-btn-primary" onclick="ncRunSeeder()">Run Seeder</button></div>
<?php endif; ?>
</div>

<!-- Modal -->
<div id="nc-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content">
  <div class="nas-modal-header"><h3 id="nc-title">Add Category</h3><button class="nas-modal-close" onclick="document.getElementById('nc-modal').style.display='none'">✕</button></div>
  <div class="nas-modal-body">
    <input type="hidden" id="nc-id">
    <div class="nas-form-stack">
      <div class="nas-form-row"><label>Icon (emoji) *</label><input id="nc-icon" class="nas-input" type="text" placeholder="💍" style="font-size:24px;text-align:center;width:80px"></div>
      <div class="nas-form-row"><label>Name *</label><input id="nc-name" class="nas-input" type="text" placeholder="Category name"></div>
      <div class="nas-form-row"><label>Description</label><textarea id="nc-desc" class="nas-textarea" rows="2" placeholder="Brief description"></textarea></div>
      <div class="nas-form-row"><label>Sort Order</label><input id="nc-sort" class="nas-input" type="number" value="0"></div>
    </div>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('nc-modal').style.display='none'">Cancel</button>
    <button class="nas-btn nas-btn-primary" onclick="ncSave()">Save Category</button>
  </div>
</div></div>
</div>
<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const catData = <?php echo wp_json_encode($cats); ?>;

window.ncOpen=function(){document.getElementById('nc-id').value='';document.getElementById('nc-title').textContent='Add Category';['nc-icon','nc-name','nc-desc'].forEach(id=>document.getElementById(id).value='');document.getElementById('nc-sort').value=0;document.getElementById('nc-modal').style.display='flex';};
window.ncEdit=function(id){const c=catData.find(x=>x.id==id);if(!c)return;document.getElementById('nc-id').value=c.id;document.getElementById('nc-title').textContent='Edit: '+c.name;document.getElementById('nc-icon').value=c.icon||'';document.getElementById('nc-name').value=c.name||'';document.getElementById('nc-desc').value=c.description||'';document.getElementById('nc-sort').value=c.sort_order||0;document.getElementById('nc-modal').style.display='flex';};
window.ncSave=function(){
  const name=document.getElementById('nc-name').value.trim();if(!name){nasAdminToast('Name required','error');return;}
  const fd=new FormData();fd.append('action','nas_admin_save_category');fd.append('nonce',C.nonce);
  fd.append('id',document.getElementById('nc-id').value);fd.append('icon',document.getElementById('nc-icon').value);fd.append('name',name);fd.append('description',document.getElementById('nc-desc').value);fd.append('sort_order',document.getElementById('nc-sort').value);
  fetch(C.ajaxUrl,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{if(res.success){nasAdminToast('Category saved!','success');setTimeout(()=>location.reload(),700);}else nasAdminToast('Error','error');});
};
window.ncRunSeeder=function(){
  if(!confirm('Run the seeder to populate default categories, newspapers, cities, templates, and sample ads?'))return;
  const fd=new FormData();fd.append('action','nas_admin_run_seeder');fd.append('nonce',C.nonce);
  fetch(C.ajaxUrl,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{nasAdminToast(res.success?res.data.message:res.data?.message||'Error',res.success?'success':'error');if(res.success)setTimeout(()=>location.reload(),1500);});
};
})();
</script>
