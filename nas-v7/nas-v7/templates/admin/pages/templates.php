<?php if (!defined('ABSPATH')) exit;
global $wpdb; $db = \NAS\Core\Database::instance();
$templates = $wpdb->get_results("SELECT t.*,c.name cat_name FROM `{$db->prefix('templates')}` t LEFT JOIN `{$db->prefix('categories')}` c ON c.id=t.category_id WHERE t.is_active=1 ORDER BY t.category_id,t.id", OBJECT);
$cats = $wpdb->get_results("SELECT id,name FROM `{$db->prefix('categories')}` WHERE is_active=1 ORDER BY name", OBJECT);
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-file-alt"></i> Ad Templates <span class="nas-count-badge"><?php echo count($templates); ?></span></h1>
  <button class="nas-btn nas-btn-primary" onclick="ntOpen()">+ Add Template</button>
</div>
<div class="nas-card"><div class="nas-table-wrap">
<table class="nas-table nas-table-hover">
  <thead><tr><th>Category</th><th>Template Name</th><th>Tone</th><th>Word Limit</th><th>Content Preview</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($templates as $t): ?>
  <tr>
    <td><?php echo esc_html($t->cat_name??'—'); ?></td>
    <td><strong><?php echo esc_html($t->name); ?></strong></td>
    <td><span class="nas-tone-badge nas-tone-<?php echo esc_attr($t->tone); ?>"><?php echo esc_html($t->tone); ?></span></td>
    <td><?php echo intval($t->word_limit); ?> words</td>
    <td><small><?php echo esc_html(substr($t->content??'',0,80)); ?>...</small></td>
    <td class="nas-actions">
      <button class="nas-btn nas-btn-xs nas-btn-primary" onclick="ntEdit(<?php echo $t->id; ?>)">Edit</button>
      <button class="nas-btn nas-btn-xs" onclick="ntDelete(<?php echo $t->id; ?>)" style="background:#ef4444;color:#fff">Del</button>
    </td>
  </tr>
  <?php endforeach; if(empty($templates)): ?>
  <tr><td colspan="6" class="nas-empty">No templates yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div></div>

<div id="nt-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content nas-modal-lg">
  <div class="nas-modal-header"><h3 id="nt-title">Add Template</h3><button class="nas-modal-close" onclick="document.getElementById('nt-modal').style.display='none'">✕</button></div>
  <div class="nas-modal-body">
    <input type="hidden" id="nt-id">
    <div class="nas-modal-grid-2">
      <div class="nas-form-row"><label>Template Name *</label><input id="nt-name" class="nas-input" type="text" placeholder="e.g. Matrimonial — Groom Wanted"></div>
      <div class="nas-form-row"><label>Category</label><select id="nt-cat" class="nas-select"><?php foreach($cats as $c): ?><option value="<?php echo $c->id; ?>"><?php echo esc_html($c->name); ?></option><?php endforeach; ?></select></div>
      <div class="nas-form-row"><label>Tone</label><select id="nt-tone" class="nas-select"><option value="formal">Formal</option><option value="attractive">Attractive</option><option value="urgent">Urgent</option><option value="simple">Simple</option></select></div>
      <div class="nas-form-row"><label>Word Limit</label><input id="nt-limit" class="nas-input" type="number" value="60"></div>
    </div>
    <div class="nas-form-row"><label>Template Content *</label><textarea id="nt-content" class="nas-textarea" rows="6" placeholder="Write the template. Use [NAME], [PHONE], [CITY] as placeholders."></textarea></div>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('nt-modal').style.display='none'">Cancel</button>
    <button class="nas-btn nas-btn-primary" onclick="ntSave()">Save Template</button>
  </div>
</div></div>
</div>
<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const tplData = <?php echo wp_json_encode($templates); ?>;
window.ntOpen=function(){document.getElementById('nt-id').value='';document.getElementById('nt-title').textContent='Add Template';['nt-name','nt-content'].forEach(id=>document.getElementById(id).value='');document.getElementById('nt-limit').value=60;document.getElementById('nt-modal').style.display='flex';};
window.ntEdit=function(id){const t=tplData.find(x=>x.id==id);if(!t)return;document.getElementById('nt-id').value=t.id;document.getElementById('nt-title').textContent='Edit: '+t.name;document.getElementById('nt-name').value=t.name||'';document.getElementById('nt-cat').value=t.category_id||'';document.getElementById('nt-tone').value=t.tone||'formal';document.getElementById('nt-limit').value=t.word_limit||60;document.getElementById('nt-content').value=t.content||'';document.getElementById('nt-modal').style.display='flex';};
window.ntSave=function(){
  const name=document.getElementById('nt-name').value.trim();if(!name){nasAdminToast('Name required','error');return;}
  const fd=new FormData();fd.append('action','nas_admin_save_template');fd.append('nonce',C.nonce);
  fd.append('id',document.getElementById('nt-id').value);fd.append('name',name);fd.append('category_id',document.getElementById('nt-cat').value);fd.append('tone',document.getElementById('nt-tone').value);fd.append('word_limit',document.getElementById('nt-limit').value);fd.append('content',document.getElementById('nt-content').value);
  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(res=>{if(res.success){nasAdminToast('Template saved!','success');setTimeout(()=>location.reload(),700);}else nasAdminToast('Error','error');});
};
window.ntDelete=function(id){if(!confirm('Delete this template?'))return;const fd=new FormData();fd.append('action','nas_admin_delete_template');fd.append('nonce',C.nonce);fd.append('id',id);fetch(C.ajaxUrl,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{if(res.success){nasAdminToast('Deleted','success');setTimeout(()=>location.reload(),600);}});};
})();
</script>
