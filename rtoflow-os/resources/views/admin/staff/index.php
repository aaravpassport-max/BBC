<?php if (!defined('ABSPATH')) exit;
/** @var array $staff @var array $allWpUsers @var array $rbacRoles
 * @var string $search @var int $page @var int $lastPage @var int $totalStaff
 * ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC): $rbacRoles comes
 * from Permissions::allRoles() via Router::loadStaff() — used below to let
 * an admin assign one of the granular permission-matrix roles to each
 * rto_staff account, independent of the rto_admin/rto_staff WP-role table
 * above. See app/Security/Permissions.php for how this is enforced.
 * ENTERPRISE GAP FIX (Phase 2, item 3): $search/$page/$lastPage/$totalStaff
 * back the new search box, pagination, and CSV export below — see
 * Router::loadStaff()/exportStaffCsv() for why this was a real gap. */
$pageTitle = 'Staff';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';

// FIX (Part 5.4 — Help Centre audit, real privilege-escalation bug found and
// fixed): this handler previously relied ONLY on the page-level rto_is_staff()
// gate in Router::routeAdmin() — which is also satisfied by an rto_staff
// account (explicitly described elsewhere on this same page as "RTO Staff
// (limited access)"). Because role assignment was never independently
// re-checked here, any rto_staff user could POST this exact form and
// promote ANY WordPress user — including their own account — to rto_admin
// (full access), with no additional gate anywhere in the path. Assigning or
// removing a staff/admin role is exactly the kind of consequential,
// privilege-affecting action that must be independently re-verified as
// admin-only, the same way LeadsController::recordPayment() already is (see
// the Payments Ledger article) — a page-level staff gate is not a
// substitute for an action-level admin gate on a sensitive action nested
// inside it.
if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('rtoflow_staff_save','rtoflow_nonce')) {
    if (!rto_is_admin()) {
        wp_die('Access denied. Only RTO Admin accounts can assign or remove staff/admin roles.', 403);
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $role   = sanitize_text_field($_POST['role'] ?? '');
    if ($userId && in_array($role,['rto_admin','rto_staff','rto_vendor','remove'],true)) {
        $user = new WP_User($userId);
        // FIX (Known Limitations audit — Staff/Users screen): this is the
        // single most privilege-affecting action in the whole admin (it
        // grants/revokes rto_admin itself), yet, unlike comparable
        // sensitive actions such as Router::forceLogout()'s
        // 'user.force_logout' entry, no audit trail was ever written here —
        // an rto_admin could grant or strip another account's admin/staff
        // role with zero record of who did it, when, or what the role was
        // before. Fixed by writing an immutable AuditService::log() entry,
        // matching the (action, leadId=0, newValue, oldValue, actor)
        // convention used elsewhere for non-lead admin actions.
        $oldRoles = array_values(array_intersect(['rto_admin','rto_staff'], $user->roles));
        if ($role==='remove') { foreach (['rto_admin','rto_staff'] as $r) $user->remove_role($r); }
        else { foreach (['rto_admin','rto_staff'] as $r) $user->remove_role($r); $user->add_role($role); }
        \RTOFLOW\Services\AuditService::log(
            $role==='remove' ? 'staff.role_removed' : 'staff.role_assigned',
            0,
            ['target_user_id' => $userId, 'role' => $role],
            ['target_user_id' => $userId, 'prior_roles' => $oldRoles],
            get_current_user_id()
        );
    }
    wp_redirect(home_url('/rto-admin/staff/?saved=1')); exit;
}
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Staff Management</h1>
  </div>

  <?php if (isset($_GET['saved'])): ?>
  <div class="rto-msg rto-msg-success rto-mb-4" role="status">Staff roles updated.</div>
  <?php endif; ?>

  <!-- Current Staff -->
  <div class="rto-card rto-mb-4">
    <div class="rto-card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h3>Current Staff (<?= (int)$totalStaff ?>)</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <form method="GET" style="display:flex;gap:6px">
          <input type="hidden" name="rto_area" value="admin">
          <input type="hidden" name="rto_page" value="staff">
          <input type="search" name="s" value="<?= esc_attr($search) ?>" placeholder="Search name or email…" class="rto-input rto-input-sm">
          <button type="submit" class="rto-btn rto-btn-outline rto-btn-xs">Search</button>
        </form>
        <a class="rto-btn rto-btn-outline rto-btn-xs" href="<?= esc_url(add_query_arg(['export' => 'csv'])) ?>">Export CSV</a>
      </div>
    </div>
    <?php if (empty($staff)): ?>
    <div class="rto-empty-state"><p><?= $search !== '' ? 'No staff members match "' . esc_html($search) . '".' : 'No staff members yet. Assign roles below.' ?></p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Role</th><th>Permission Role<?= rto_field_tooltip('The granular permission matrix this rto_staff account uses (see Staff Roles screen) — module-level view/edit/export/approve. Only applies to RTO Staff accounts; RTO Admin is always full access. If none is assigned, the account is treated as Full Access.') ?></th><th>Since</th><th><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($staff as $user):
          $currentRoleId = \RTOFLOW\Security\Permissions::roleIdFor($user->ID);
          $isStaffOnly   = in_array('rto_staff', $user->roles, true) && !in_array('rto_admin', $user->roles, true);
          // ENTERPRISE GAP FIX (Phase 6, item — "no staff account suspension
          // short of full role removal")
          $isSuspended   = get_user_meta($user->ID, 'rtoflow_staff_suspended', true) === '1';
        ?>
        <tr>
          <td data-label="Name"><strong><?= esc_html($user->display_name) ?></strong></td>
          <td data-label="Email"><?= esc_html($user->user_email) ?></td>
          <td data-label="Status">
            <?php if ($isSuspended): ?>
            <span class="rto-badge rto-badge-danger">Suspended</span>
            <?php else: ?>
            <span class="rto-badge rto-badge-success">Active</span>
            <?php endif; ?>
          </td>
          <td data-label="Role">
            <?php $roles = array_intersect(['rto_admin','rto_staff'], $user->roles);
            foreach ($roles as $r): ?>
            <span class="rto-badge rto-badge-<?= $r==='rto_admin'?'info':'secondary' ?>"><?= esc_html(ucwords(str_replace('_',' ',$r))) ?></span>
            <?php endforeach; ?>
          </td>
          <td data-label="Permission Role">
            <?php if ($isStaffOnly): ?>
            <select class="rto-select rto-select-xs rbac-role-select" data-user-id="<?= esc_attr($user->ID) ?>" style="min-width:150px">
              <?php foreach ($rbacRoles as $r): ?>
              <option value="<?= esc_attr($r['id']) ?>" <?= $currentRoleId === $r['id'] ? 'selected' : '' ?>><?= esc_html($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <span class="rto-small rto-muted">— (admin: full access)</span>
            <?php endif; ?>
          </td>
          <td data-label="Since" class="rto-small rto-muted"><?= esc_html(date('d M Y', strtotime($user->user_registered))) ?></td>
          <td data-label="Actions">
            <?php if ($user->ID !== get_current_user_id()): ?>
            <button type="button" class="rto-btn rto-btn-xs <?= $isSuspended ? 'rto-btn-success' : 'rto-btn-outline' ?> staff-suspend-btn"
                    data-user-id="<?= esc_attr($user->ID) ?>" data-suspended="<?= $isSuspended ? '1' : '0' ?>">
              <?= $isSuspended ? 'Reinstate' : 'Suspend' ?>
            </button>
            <?php endif; ?>
            <form method="POST" style="display:inline" class="staff-remove-role-form" data-confirm="Remove all RTOFLOW roles from <?= esc_attr($user->display_name) ?>?">
              <?php wp_nonce_field('rtoflow_staff_save','rtoflow_nonce'); ?>
              <input type="hidden" name="user_id" value="<?= esc_attr($user->ID) ?>">
              <input type="hidden" name="role" value="remove">
              <button type="submit" class="rto-btn rto-btn-xs rto-btn-danger">
                Remove Role
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($lastPage > 1): ?>
    <div class="rto-pagination" style="padding:12px 16px">
      <?php for ($i = 1; $i <= $lastPage; $i++): ?>
        <a class="rto-btn rto-btn--sm <?= $i === $page ? 'rto-btn--primary' : 'rto-btn-outline' ?>" href="<?= esc_url(add_query_arg(['paged' => $i])) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; // empty($staff) ?>
  </div>

  <!-- Assign new role -->
  <div class="rto-card">
    <div class="rto-card-header"><h3>Assign Staff Role</h3></div>
    <div class="rto-card-body">
      <form method="POST" style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
        <?php wp_nonce_field('rtoflow_staff_save','rtoflow_nonce'); ?>
        <div class="rto-form-group" style="flex:2;min-width:220px">
          <label class="rto-label" for="staffUserId">WordPress User</label>
          <select id="staffUserId" name="user_id" class="rto-select">
            <option value="">— Select user —</option>
            <?php $existing = array_map(fn($u)=>$u->ID, $staff);
            foreach ($allWpUsers as $user): ?>
            <option value="<?= esc_attr($user->ID) ?>" <?= in_array($user->ID,$existing)?'disabled':'' ?>>
              <?= esc_html($user->display_name) ?> (<?= esc_html($user->user_email) ?>)
              <?= in_array($user->ID,$existing)?'[already staff]':'' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group" style="flex:1;min-width:180px">
          <label class="rto-label" for="staffRole">Role<?= rto_field_tooltip('RTO Staff is blocked at the server (not just hidden in the UI) from the majority of admin-only screens — automation, eligibility rules, city pricing, email templates, and most other configuration areas all reject non-admin requests with a 403, regardless of what the UI shows. Grant RTO Admin only to users who genuinely need full configuration access.') ?></label>
          <select id="staffRole" name="role" class="rto-select">
            <option value="rto_admin">RTO Admin (full access)</option>
            <option value="rto_staff">RTO Staff (limited access)</option>
          </select>
        </div>
        <div class="rto-form-group" style="align-self:flex-end">
          <button type="submit" class="rto-btn rto-btn-primary">Assign Role</button>
        </div>
      </form>
    </div>
  </div>
  <p class="rto-small rto-muted rto-mb-4">Need a custom permission role, or to change what an existing one can do? Use the <a href="<?= esc_url(home_url('/rto-admin/staff-roles/')) ?>">Staff Roles</a> screen.</p>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';

  // CSP fix: 'nonce-...' only covers <script> elements, not inline
  // onclick= attributes — delegate the "Remove Role" confirm dialog.
  document.querySelectorAll('.staff-remove-role-form').forEach(function(form){
    form.addEventListener('submit', function(e){
      if (!confirm(form.dataset.confirm || 'Remove all RTOFLOW roles from this user?')) {
        e.preventDefault();
      }
    });
  });

  document.querySelectorAll('.rbac-role-select').forEach(function(sel){
    sel.addEventListener('change', function(){
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','assign_permission_role');
      fd.append('rto_nonce', nonce);
      fd.append('user_id', sel.dataset.userId);
      fd.append('role_id', sel.value);
      sel.disabled = true;
      fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
        sel.disabled = false;
        if (!r.success) alert(r.message || 'Unable to assign role.');
      }).catch(function(){ sel.disabled = false; alert('Network error — please try again.'); });
    });
  });

  // ENTERPRISE GAP FIX (Phase 6, item — "no staff account suspension short
  // of full role removal")
  document.querySelectorAll('.staff-suspend-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var suspending = btn.dataset.suspended === '0';
      if (!confirm((suspending ? 'Suspend' : 'Reinstate') + ' this staff account?')) return;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','toggle_staff_suspension');
      fd.append('rto_nonce', nonce);
      fd.append('user_id', btn.dataset.userId);
      btn.disabled = true;
      fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
        btn.disabled = false;
        if (r.success) { location.reload(); }
        else { alert(r.message || 'Unable to update this account.'); }
      }).catch(function(){ btn.disabled = false; alert('Network error — please try again.'); });
    });
  });
})();
</script>
<?php rto_help_box('staff'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
