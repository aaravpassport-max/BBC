<?php
/**
 * NAS Admin — Team Accounts
 * Create/manage Vendor, Staff, and Manager accounts without touching wp-admin
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Fetch all NAS-role users
$roles    = [ 'nas_vendor', 'nas_staff', 'nas_manager' ];
$wp_users = get_users( [ 'role__in' => $roles, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500 ] );

// Get all vendors to show link status
global $wpdb;
$db          = \NAS\Core\Database::instance();
$vendor_map  = [];
$vendor_rows = $wpdb->get_results( "SELECT id, wp_user_id, name FROM `{$db->prefix('vendors')}` WHERE wp_user_id > 0", OBJECT );
foreach ( $vendor_rows as $v ) {
    $vendor_map[ (int) $v->wp_user_id ] = $v;
}

$role_labels = [
    'nas_vendor'  => [ 'label' => 'Vendor',  'color' => '#f97316', 'icon' => 'fa-truck',         'dash' => '/vendor-dashboard/' ],
    'nas_staff'   => [ 'label' => 'Staff',   'color' => '#2563eb', 'icon' => 'fa-user-tie',      'dash' => '/staff-dashboard/' ],
    'nas_manager' => [ 'label' => 'Manager', 'color' => '#7c3aed', 'icon' => 'fa-shield-halved', 'dash' => '/moderation-dashboard/' ],
];
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-users-gear"></i> Team Accounts</h1>
  <button class="nas-btn nas-btn-primary" onclick="tmOpenModal()">+ Create Account</button>
</div>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#1d4ed8;display:flex;align-items:flex-start;gap:10px">
  <i class="fa-solid fa-circle-info" style="margin-top:2px;flex-shrink:0"></i>
  <div>
    <strong>Standalone Account Management</strong> — Create and manage Vendor, Staff, and Manager accounts entirely from here. No need to touch WordPress admin. Each account gets login credentials and access to their respective portal automatically.
    <div style="margin-top:8px;display:flex;gap:16px;font-size:12px;font-weight:600">
      <span style="color:#f97316"><i class="fa-solid fa-truck"></i> Vendor → /vendor-dashboard/</span>
      <span style="color:#2563eb"><i class="fa-solid fa-user-tie"></i> Staff → /staff-dashboard/</span>
      <span style="color:#7c3aed"><i class="fa-solid fa-shield-halved"></i> Manager → /moderation-dashboard/</span>
    </div>
  </div>
</div>

<div class="nas-card">
  <div class="nas-table-wrap">
  <table class="nas-table nas-table-hover">
    <thead>
      <tr>
        <th>Name</th><th>Email / Username</th><th>Role</th><th>Portal</th>
        <th>Vendor Profile</th><th>Created</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if ( empty($wp_users) ): ?>
      <tr><td colspan="7" class="nas-empty">No team accounts yet. Create your first vendor, staff, or manager account above.</td></tr>
    <?php else: ?>
    <?php foreach ( $wp_users as $u ):
      $primary_role = array_intersect( (array)$u->roles, $roles );
      $primary_role = reset($primary_role) ?: 'nas_vendor';
      $role_info    = $role_labels[$primary_role] ?? $role_labels['nas_vendor'];
      $vendor_link  = $vendor_map[$u->ID] ?? null;
    ?>
    <tr>
      <td>
        <strong><?php echo esc_html( $u->display_name ); ?></strong>
      </td>
      <td>
        <div style="font-size:13px"><?php echo esc_html( $u->user_email ); ?></div>
        <div style="font-size:11px;color:#94a3b8">@<?php echo esc_html( $u->user_login ); ?></div>
      </td>
      <td>
        <span style="background:<?php echo $role_info['color']; ?>18;color:<?php echo $role_info['color']; ?>;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700">
          <i class="fa-solid <?php echo $role_info['icon']; ?>"></i> <?php echo $role_info['label']; ?>
        </span>
      </td>
      <td>
        <a href="<?php echo esc_url( home_url( $role_info['dash'] ) ); ?>" target="_blank"
           style="font-size:12px;color:<?php echo $role_info['color']; ?>;font-weight:600;text-decoration:none">
          <?php echo esc_html( $role_info['dash'] ); ?> ↗
        </a>
      </td>
      <td>
        <?php if ( $primary_role === 'nas_vendor' ): ?>
          <?php if ( $vendor_link ): ?>
            <span style="color:#059669;font-size:12px;font-weight:600">✅ <?php echo esc_html($vendor_link->name); ?></span>
          <?php else: ?>
            <span style="color:#d97706;font-size:12px">⚠️ No vendor profile</span>
            <div style="font-size:11px;color:#94a3b8">Go to Vendors page to link</div>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:#94a3b8;font-size:12px">—</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:#94a3b8"><?php echo date('d M Y', strtotime($u->user_registered)); ?></td>
      <td class="nas-actions" style="white-space:nowrap">
        <button class="nas-btn nas-btn-xs nas-btn-primary" onclick="tmEdit(<?php echo $u->ID; ?>)">Edit</button>
        <button class="nas-btn nas-btn-xs" onclick="tmResetPwd(<?php echo $u->ID; ?>, '<?php echo esc_js($u->user_email); ?>')" style="background:#059669;color:#fff" title="Send password reset email">🔑 Reset</button>
        <button class="nas-btn nas-btn-xs" onclick="tmDelete(<?php echo $u->ID; ?>, '<?php echo esc_js($u->display_name); ?>')" style="background:#ef4444;color:#fff">Delete</button>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
</div>

<!-- Create / Edit Modal -->
<div id="tm-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content" style="max-width:560px">
  <div class="nas-modal-header">
    <h3 id="tm-modal-title">Create Team Account</h3>
    <button class="nas-modal-close" onclick="document.getElementById('tm-modal').style.display='none'">✕</button>
  </div>
  <div class="nas-modal-body">
    <input type="hidden" id="tm-id" value="">

    <div class="nas-form-row">
      <label>Account Type *</label>
      <div style="display:flex;gap:10px;margin-top:4px" id="tm-role-btns">
        <button type="button" class="nas-btn" onclick="tmSelectRole('nas_vendor',this)" id="tm-role-vendor"
          style="flex:1;background:#fff3ed;color:#f97316;border:2px solid #fed7aa">
          <i class="fa-solid fa-truck"></i> Vendor
        </button>
        <button type="button" class="nas-btn" onclick="tmSelectRole('nas_staff',this)" id="tm-role-staff"
          style="flex:1;background:#fff;color:#2563eb;border:2px solid #e2e8f0">
          <i class="fa-solid fa-user-tie"></i> Staff
        </button>
        <button type="button" class="nas-btn" onclick="tmSelectRole('nas_manager',this)" id="tm-role-manager"
          style="flex:1;background:#fff;color:#7c3aed;border:2px solid #e2e8f0">
          <i class="fa-solid fa-shield-halved"></i> Manager
        </button>
      </div>
      <input type="hidden" id="tm-role" value="nas_vendor">
    </div>

    <div class="nas-modal-grid-2" style="margin-top:12px">
      <div class="nas-form-row"><label>Full Name *</label><input type="text" id="tm-display-name" class="nas-input" placeholder="e.g. Rajesh Kumar"></div>
      <div class="nas-form-row"><label>Username *</label><input type="text" id="tm-username" class="nas-input" placeholder="e.g. rajesh.kumar" autocomplete="off"></div>
      <div class="nas-form-row" style="grid-column:1/-1"><label>Email Address *</label><input type="email" id="tm-email" class="nas-input" placeholder="vendor@email.com"></div>
    </div>

    <div id="tm-pw-section">
      <div class="nas-modal-grid-2">
        <div class="nas-form-row">
          <label>Password <span id="tm-pw-required">*</span></label>
          <input type="password" id="tm-password" class="nas-input" placeholder="Min 8 characters" autocomplete="new-password">
        </div>
        <div class="nas-form-row">
          <label>Confirm Password <span id="tm-cw-required">*</span></label>
          <input type="password" id="tm-password2" class="nas-input" placeholder="Repeat password">
        </div>
      </div>
      <div style="font-size:12px;color:#6b7280;margin-top:4px"><i class="fa-solid fa-circle-info"></i> Leave blank when editing to keep existing password</div>
    </div>

    <div id="tm-vendor-note" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;margin-top:12px;font-size:13px;color:#92400e;display:block">
      <i class="fa-solid fa-triangle-exclamation"></i> <strong>After creating this vendor account,</strong> go to <strong>Vendors</strong> page, edit the vendor profile, and link this user to their vendor profile so they can see their assigned bookings.
    </div>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('tm-modal').style.display='none'">Cancel</button>
    <button class="nas-btn nas-btn-primary" id="tm-save-btn" onclick="tmSave()"><i class="fa-solid fa-user-plus"></i> <span id="tm-save-label">Create Account</span></button>
  </div>
</div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const ROLE_COLORS = { nas_vendor:'#f97316', nas_staff:'#2563eb', nas_manager:'#7c3aed' };

/* ── Role selector ──────────────────────────────────────────────── */
window.tmSelectRole = function(role, btn) {
  document.getElementById('tm-role').value = role;
  document.querySelectorAll('#tm-role-btns .nas-btn').forEach(function(b){
    b.style.borderColor='#e2e8f0'; b.style.fontWeight='500';
    b.style.background='#fff'; b.style.color=ROLE_COLORS[b.id.replace('tm-role-','nas_')];
  });
  if(btn){btn.style.borderColor=ROLE_COLORS[role];btn.style.background=ROLE_COLORS[role]+'18';btn.style.fontWeight='700';}
  document.getElementById('tm-vendor-note').style.display = (role==='nas_vendor') ? 'block' : 'none';
};

/* ── Open / Edit ────────────────────────────────────────────────── */
window.tmOpenModal = function() {
  document.getElementById('tm-id').value = '';
  document.getElementById('tm-modal-title').textContent = 'Create Team Account';
  document.getElementById('tm-save-label').textContent = 'Create Account';
  document.getElementById('tm-display-name').value = '';
  document.getElementById('tm-email').value = '';
  document.getElementById('tm-password').value = '';
  document.getElementById('tm-password2').value = '';
  document.getElementById('tm-pw-required').style.display = '';
  document.getElementById('tm-cw-required').style.display = '';
  // Show username field for new users
  var uEl = document.getElementById('tm-username');
  if(uEl){ uEl.parentElement.style.display=''; uEl.value=''; }
  // Default to vendor role
  tmSelectRole('nas_vendor', document.getElementById('tm-role-vendor'));
  document.getElementById('tm-modal').style.display='flex';
};

window.tmEdit = function(id) {
  // For edit, just show email and display name + optional password change
  document.getElementById('tm-id').value = id;
  document.getElementById('tm-modal-title').textContent = 'Edit Team Account';
  document.getElementById('tm-save-label').textContent = 'Save Changes';
  document.getElementById('tm-password').value = '';
  document.getElementById('tm-password2').value = '';
  document.getElementById('tm-pw-required').style.display = 'none';
  document.getElementById('tm-cw-required').style.display = 'none';
  // Hide username field when editing
  var uEl = document.getElementById('tm-username');
  if(uEl) uEl.parentElement.style.display='none';
  document.getElementById('tm-modal').style.display='flex';
  // Load user data
  const fd=new FormData();
  fd.append('action','nas_admin_get_team_user'); fd.append('nonce',C.nonce); fd.append('id',id);
  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(res=>{
    if(res.success){
      document.getElementById('tm-display-name').value = res.data.display_name||'';
      document.getElementById('tm-email').value = res.data.email||'';
      var role = res.data.role||'nas_vendor';
      document.getElementById('tm-role').value = role;
      var btn = document.getElementById('tm-role-'+role.replace('nas_',''));
      if(btn) tmSelectRole(role, btn);
    }
  }).catch(function(){});
};

/* ── Save ───────────────────────────────────────────────────────── */
window.tmSave = function() {
  var id           = document.getElementById('tm-id').value;
  var display_name = document.getElementById('tm-display-name').value.trim();
  var email        = document.getElementById('tm-email').value.trim();
  var role         = document.getElementById('tm-role').value;
  var password     = document.getElementById('tm-password').value;
  var password2    = document.getElementById('tm-password2').value;
  var username     = (document.getElementById('tm-username')?.value||'').trim();

  if(!display_name){nasAdminToast('Full name is required','error');return;}
  if(!email){nasAdminToast('Email is required','error');return;}
  if(!id && !username){nasAdminToast('Username is required for new accounts','error');return;}
  if(!id && !password){nasAdminToast('Password is required for new accounts','error');return;}
  if(password && password !== password2){nasAdminToast('Passwords do not match','error');return;}
  if(password && password.length < 8){nasAdminToast('Password must be at least 8 characters','error');return;}

  var btn=document.getElementById('tm-save-btn');
  var orig=btn.innerHTML; btn.disabled=true; btn.innerHTML='<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:tmSpin .6s linear infinite;vertical-align:middle;margin-right:6px"></span>Saving…';

  var fd=new FormData();
  fd.append('action', id ? 'nas_admin_update_team_user' : 'nas_admin_create_team_user');
  fd.append('nonce',C.nonce);
  if(id) fd.append('id',id);
  fd.append('display_name',display_name);
  fd.append('email',email);
  fd.append('role',role);
  if(!id) fd.append('username', username || email.split('@')[0].replace(/[^a-z0-9]/gi,'_').toLowerCase());
  if(password) fd.append('password',password);

  var _nc2=new AbortController();setTimeout(function(){_nc2.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc2.signal}).then(r=>r.json()).then(res=>{
    btn.disabled=false; btn.innerHTML=orig;
    if(res.success){
      nasAdminToast(res.data?.message||(id?'Account updated!':'Account created!'),'success');
      setTimeout(()=>location.reload(),900);
    } else {
      nasAdminToast(res.data?.message||'Error saving account','error');
    }
  }).catch(function(){btn.disabled=false;btn.innerHTML=orig;nasAdminToast('Network error','error');});
};

/* ── Reset password ─────────────────────────────────────────────── */
window.tmResetPwd = function(id, email) {
  if(!confirm('Send a password reset email to '+email+'?')) return;
  var fd=new FormData();
  fd.append('action','nas_admin_reset_team_pwd'); fd.append('nonce',C.nonce); fd.append('id',id); fd.append('email',email);
  var _nc3=new AbortController();setTimeout(function(){_nc3.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc3.signal}).then(r=>r.json()).then(res=>{
    nasAdminToast(res.success?(res.data?.message||'Reset email sent!'):(res.data?.message||'Error'), res.success?'success':'error');
  });
};

/* ── Delete ─────────────────────────────────────────────────────── */
window.tmDelete = function(id, name) {
  if(!confirm('Permanently delete account "'+name+'"?\n\nThis will remove their WordPress login. Their assigned bookings will remain. This cannot be undone.')) return;
  var fd=new FormData();
  fd.append('action','nas_admin_delete_team_user'); fd.append('nonce',C.nonce); fd.append('id',id);
  var _nc4=new AbortController();setTimeout(function(){_nc4.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc4.signal}).then(r=>r.json()).then(res=>{
    if(res.success){nasAdminToast(res.data?.message||'Account deleted','success');setTimeout(()=>location.reload(),700);}
    else nasAdminToast(res.data?.message||'Error deleting','error');
  });
};

/* Auto-fill username from display name */
document.getElementById('tm-display-name')?.addEventListener('input',function(){
  var uEl=document.getElementById('tm-username');
  if(uEl&&!document.getElementById('tm-id').value){
    uEl.value=this.value.toLowerCase().replace(/[^a-z0-9]/g,'_').replace(/_+/g,'_').replace(/^_|_$/g,'');
  }
});
})();
</script>
<style>@keyframes tmSpin{to{transform:rotate(360deg)}}</style>
