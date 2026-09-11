<?php 
/** NAS Admin — Vendors */ 
if (!defined('ABSPATH')) exit;
global $wpdb; 
$db       = \NAS\Core\Database::instance();
$vendors  = $wpdb->get_results("SELECT * FROM `{$db->prefix('vendors')}` ORDER BY name", OBJECT);
$cities   = $wpdb->get_results("SELECT id,name,state FROM `{$db->prefix('cities')}` WHERE is_active=1 ORDER BY name", OBJECT);
$papers   = $wpdb->get_results("SELECT id,name FROM `{$db->prefix('newspapers')}` WHERE is_active=1 ORDER BY name", OBJECT);
// Get all WP users for dropdown (editors/admins/vendors)
$wp_users = get_users(['fields' => ['ID','display_name','user_email'], 'number' => 500, 'orderby' => 'display_name']);
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-truck"></i> Vendors <span class="nas-count-badge"><?php echo count($vendors); ?></span></h1>
  <button class="nas-btn nas-btn-primary" onclick="nvOpenModal()">+ Add Vendor</button>
</div>

<div class="nas-card">
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#1d4ed8;display:flex;align-items:flex-start;gap:10px">
    <i class="fa-solid fa-circle-info" style="margin-top:2px;flex-shrink:0"></i>
    <div><strong>Vendor Portal Setup:</strong> To give a vendor access to the Vendor Dashboard, create the vendor profile below and link it to their WordPress user account. They can then log in and visit <strong>/vendor-dashboard/</strong> to manage their assigned bookings.</div>
  </div>
  <div class="nas-table-wrap">
  <table class="nas-table nas-table-hover">
    <thead>
      <tr>
        <th>Vendor</th><th>Contact</th><th>Portal User</th>
        <th>Cities</th><th>Orders</th><th>Rating</th><th>Status</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($vendors as $v):
      $vc = count(json_decode($v->cities_supported??'[]',true)??[]);
      $wu = $v->wp_user_id ? get_userdata($v->wp_user_id) : null;
    ?>
    <tr>
      <td>
        <strong><?php echo esc_html($v->name); ?></strong>
        <?php if($v->company_name): ?><br><small style="color:#6b7280"><?php echo esc_html($v->company_name); ?></small><?php endif; ?>
      </td>
      <td>
        <?php echo esc_html($v->phone); ?>
        <?php if($v->email): ?><br><small style="color:#6b7280"><?php echo esc_html($v->email); ?></small><?php endif; ?>
      </td>
      <td>
        <?php if($wu): ?>
          <div style="display:flex;align-items:center;gap:6px">
            <span style="color:#059669;font-size:11px;font-weight:700">✅ Linked</span>
          </div>
          <div style="font-size:12px;color:#374151"><?php echo esc_html($wu->display_name); ?></div>
          <div style="font-size:11px;color:#94a3b8"><?php echo esc_html($wu->user_email); ?></div>
        <?php elseif($v->wp_user_id): ?>
          <span style="color:#ef4444;font-size:12px">⚠️ User deleted (ID:<?php echo $v->wp_user_id; ?>)</span>
        <?php else: ?>
          <span style="color:#94a3b8;font-size:12px">— Not linked</span>
          <div style="font-size:11px;color:#d97706">Edit to link WP user</div>
        <?php endif; ?>
      </td>
      <td><?php echo $vc; ?> cities</td>
      <td><?php echo intval($v->total_orders??0); ?></td>
      <td><?php echo $v->rating ? '⭐ '.$v->rating : '—'; ?></td>
      <td>
        <span class="nas-badge" style="background:<?php echo $v->is_active?'#10b981':'#ef4444'; ?>;color:#fff;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700">
          <?php echo $v->is_active?'Active':'Inactive'; ?>
        </span>
      </td>
      <td class="nas-actions" style="white-space:nowrap">
        <button class="nas-btn nas-btn-xs nas-btn-primary" onclick="nvEdit(<?php echo $v->id; ?>)">Edit</button>
        <?php if($wu): ?>
          <a class="nas-btn nas-btn-xs" href="<?php echo esc_url(home_url('/vendor-dashboard/')); ?>" target="_blank" style="background:#202C39;color:#fff;text-decoration:none">Portal ↗</a>
        <?php endif; ?>
        <button class="nas-btn nas-btn-xs" onclick="nvDelete(<?php echo $v->id; ?>)" style="background:#ef4444;color:#fff">Del</button>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($vendors)): ?>
      <tr><td colspan="8" class="nas-empty">No vendors yet. Add your first vendor and link a WordPress user to enable portal access.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Vendor Modal -->
<div id="nv-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content nas-modal-lg">
  <div class="nas-modal-header">
    <h3 id="nv-modal-title">Add Vendor</h3>
    <button class="nas-modal-close" onclick="document.getElementById('nv-modal').style.display='none'">✕</button>
  </div>
  <div class="nas-modal-body">
    <input type="hidden" id="nv-id">

    <!-- WP User Link - most important field -->
    <div class="nas-form-row" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;margin-bottom:16px">
      <label style="font-weight:700;color:#15803d;margin-bottom:6px;display:block">
        <i class="fa-solid fa-link"></i> Link to WordPress User Account
        <small style="font-weight:400;color:#6b7280;display:block;margin-top:3px">This user will be able to log in and access the Vendor Portal at /vendor-dashboard/</small>
      </label>
      <select id="nv-wp-user" class="nas-select" style="width:100%">
        <option value="">— No portal access (no WP user linked) —</option>
        <?php foreach($wp_users as $u): ?>
          <option value="<?php echo $u->ID; ?>"><?php echo esc_html($u->display_name.' ('.$u->user_email.')'); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="nas-modal-grid-2">
      <div class="nas-form-row"><label>Vendor Name *</label><input type="text" id="nv-name" class="nas-input" placeholder="Vendor/Agent name"></div>
      <div class="nas-form-row"><label>Phone *</label><input type="tel" id="nv-phone" class="nas-input" placeholder="10-digit mobile"></div>
      <div class="nas-form-row"><label>Email</label><input type="email" id="nv-email" class="nas-input"></div>
      <div class="nas-form-row"><label>WhatsApp</label><input type="tel" id="nv-whatsapp" class="nas-input" placeholder="91XXXXXXXXXX"></div>
      <div class="nas-form-row"><label>Company</label><input type="text" id="nv-company" class="nas-input"></div>
      <div class="nas-form-row"><label>GST Number</label><input type="text" id="nv-gst" class="nas-input" placeholder="22AAAAA0000A1Z5"></div>
    </div>
    <div class="nas-form-row"><label>Address</label><textarea id="nv-address" class="nas-textarea" rows="2"></textarea></div>
    <div class="nas-form-row"><label>Notes</label><textarea id="nv-notes" class="nas-textarea" rows="2"></textarea></div>
    <div class="nas-modal-grid-2">
      <div class="nas-form-row">
        <label>Cities Covered</label>
        <select id="nv-cities" class="nas-select" multiple style="height:130px;width:100%">
          <?php foreach($cities as $c): ?><option value="<?php echo $c->id; ?>"><?php echo esc_html($c->name.', '.$c->state); ?></option><?php endforeach; ?>
        </select>
        <small style="color:#94a3b8">Hold Ctrl/Cmd for multiple</small>
      </div>
      <div class="nas-form-row">
        <label>Newspapers Handled</label>
        <select id="nv-papers" class="nas-select" multiple style="height:130px;width:100%">
          <?php foreach($papers as $p): ?><option value="<?php echo $p->id; ?>"><?php echo esc_html($p->name); ?></option><?php endforeach; ?>
        </select>
        <small style="color:#94a3b8">Hold Ctrl/Cmd for multiple</small>
      </div>
    </div>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('nv-modal').style.display='none'">Cancel</button>
    <button class="nas-btn nas-btn-primary" onclick="nvSave()"><i class="fa-solid fa-floppy-disk"></i> Save Vendor</button>
  </div>
</div>
</div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const vendorData = <?php echo wp_json_encode($vendors); ?>;

window.nvOpenModal = function(){
  document.getElementById('nv-id').value = '';
  document.getElementById('nv-modal-title').textContent = 'Add Vendor';
  document.getElementById('nv-wp-user').value = '';
  ['name','phone','email','whatsapp','company','gst','address','notes'].forEach(f=>{
    const el = document.getElementById('nv-'+f); if(el) el.value='';
  });
  Array.from(document.getElementById('nv-cities').options).forEach(o=>o.selected=false);
  Array.from(document.getElementById('nv-papers').options).forEach(o=>o.selected=false);
  document.getElementById('nv-modal').style.display='flex';
};

window.nvEdit = function(id){
  const v = vendorData.find(x=>x.id==id); if(!v) return;
  document.getElementById('nv-id').value = v.id;
  document.getElementById('nv-modal-title').textContent = 'Edit: ' + v.name;
  document.getElementById('nv-wp-user').value = v.wp_user_id || '';
  document.getElementById('nv-name').value    = v.name||''
  document.getElementById('nv-phone').value   = v.phone||'';
  document.getElementById('nv-email').value   = v.email||'';
  document.getElementById('nv-whatsapp').value = v.whatsapp_number||'';
  document.getElementById('nv-company').value  = v.company_name||'';
  document.getElementById('nv-gst').value      = v.gst_number||'';
  document.getElementById('nv-address').value  = v.address||'';
  document.getElementById('nv-notes').value    = v.notes||'';
  const cities = JSON.parse(v.cities_supported||'[]');
  const papers = JSON.parse(v.newspapers_supported||'[]');
  Array.from(document.getElementById('nv-cities').options).forEach(o=>o.selected=cities.includes(parseInt(o.value)));
  Array.from(document.getElementById('nv-papers').options).forEach(o=>o.selected=papers.includes(parseInt(o.value)));
  document.getElementById('nv-modal').style.display='flex';
};

window.nvSave = function(){
  const name = document.getElementById('nv-name').value.trim();
  if (!name) { nasAdminToast('Vendor name is required', 'error'); return; }
  const cities = Array.from(document.getElementById('nv-cities').selectedOptions).map(o=>parseInt(o.value));
  const papers = Array.from(document.getElementById('nv-papers').selectedOptions).map(o=>parseInt(o.value));
  const fd = new FormData();
  fd.append('action','nas_admin_save_vendor');
  fd.append('nonce',C.nonce);
  fd.append('id',document.getElementById('nv-id').value);
  fd.append('wp_user_id',document.getElementById('nv-wp-user').value);
  fd.append('name',name);
  fd.append('phone',document.getElementById('nv-phone').value);
  fd.append('email',document.getElementById('nv-email').value);
  fd.append('whatsapp',document.getElementById('nv-whatsapp').value);
  fd.append('company',document.getElementById('nv-company').value);
  fd.append('gst_number',document.getElementById('nv-gst').value);
  fd.append('address',document.getElementById('nv-address').value);
  fd.append('notes',document.getElementById('nv-notes').value);
  fd.append('cities',JSON.stringify(cities));
  fd.append('newspapers',JSON.stringify(papers));
  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(res=>{
    if(res.success){
      nasAdminToast('Vendor saved!','success');
      setTimeout(()=>location.reload(), 800);
    } else nasAdminToast(res.data?.message||'Error saving vendor','error');
  }).catch(()=>nasAdminToast('Network error','error'));
};

window.nvDelete = function(id){
  if(!confirm('Deactivate this vendor? They will no longer appear in assignments.')) return;
  const fd=new FormData();
  fd.append('action','nas_admin_delete_vendor'); fd.append('nonce',C.nonce); fd.append('id',id);
  var _nc2=new AbortController();setTimeout(function(){_nc2.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc2.signal}).then(r=>r.json()).then(res=>{
    if(res.success){ nasAdminToast('Vendor deactivated','success'); setTimeout(()=>location.reload(),600); }
    else nasAdminToast(res.data?.message||'Error','error');
  });
};
})();
</script>
