<?php if (!defined('ABSPATH')) exit;
/** @var array $roles @var int $selectedId @var array $matrix @var array $modules
 * ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC): lists every
 * rto_staff_roles row (Permissions::allRoles()) and, when one is selected,
 * renders its view/edit/export/approve matrix per module for editing. Saves
 * post to Router::saveRolePermissions() (action=admin.save_role_permissions).
 * The system 'full_access' role's checkboxes are rendered disabled here —
 * the server independently rejects a save against it (see Router::
 * saveRolePermissions()), so this is defense-in-depth, not the only guard.
 */
$pageTitle = 'Staff Roles';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';

$selected = null;
foreach ($roles as $r) { if ($r['id'] === $selectedId) { $selected = $r; break; } }

$moduleLabels = [
    'leads' => 'Leads', 'vendors' => 'Vendors', 'vendors_pii' => 'Vendor PII / Bank Details',
    'payments' => 'Payments', 'payouts' => 'Payouts', 'settings' => 'Settings',
    'staff' => 'Staff', 'reports' => 'Reports', 'complaints' => 'Complaints', 'ratings' => 'Ratings',
];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Staff Roles &amp; Permissions</h1>
  </div>
  <p class="rto-small rto-muted rto-mb-4">
    Define named permission roles (e.g. Finance, Support, Auditor) and assign them to individual
    RTO Staff accounts from the <a href="<?= esc_url(home_url('/rto-admin/staff/')) ?>">Staff</a> screen.
    RTO Admin accounts are always full access and are not affected by this screen.
  </p>

  <div class="rto-grid" style="display:grid;grid-template-columns:260px 1fr;gap:20px;align-items:start">
    <div class="rto-card">
      <div class="rto-card-header"><h3>Roles</h3></div>
      <div class="rto-card-body" style="padding:0">
        <ul style="list-style:none;margin:0;padding:0">
          <?php foreach ($roles as $r): ?>
          <li>
            <a href="<?= esc_url(add_query_arg(['id' => $r['id']])) ?>"
               style="display:block;padding:10px 16px;text-decoration:none;color:#0f172a;<?= $r['id'] === $selectedId ? 'background:#F5F3FF;border-left:3px solid #7C3AED;font-weight:600' : 'border-left:3px solid transparent' ?>">
              <?= esc_html($r['name']) ?>
              <?php if ($r['is_system']): ?><span class="rto-badge rto-badge-info" style="margin-left:6px">System</span><?php endif; ?>
              <div class="rto-small rto-muted" style="font-weight:400"><?= esc_html($r['description']) ?></div>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="rto-card">
      <?php if (!$selected): ?>
      <div class="rto-empty-state"><p>Select a role on the left to view or edit its permission matrix.</p></div>
      <?php else: ?>
      <div class="rto-card-header"><h3><?= esc_html($selected['name']) ?> — Permission Matrix</h3></div>
      <div class="rto-card-body">
        <?php if ($selected['is_system']): ?>
        <div class="rto-msg rto-msg-info rto-mb-4">This is the system Full Access role — every permission is always on and cannot be changed.</div>
        <?php endif; ?>
        <form id="rbacMatrixForm">
          <input type="hidden" name="role_id" value="<?= esc_attr($selected['id']) ?>">
          <div class="rto-table-scroll">
            <table class="rto-table">
              <thead><tr><th>Module</th><th>View</th><th>Edit</th><th>Export</th><th>Approve</th></tr></thead>
              <tbody>
              <?php foreach ($modules as $m): $mm = $matrix[$m] ?? ['can_view'=>false,'can_edit'=>false,'can_export'=>false,'can_approve'=>false]; ?>
              <tr>
                <td><?= esc_html($moduleLabels[$m] ?? ucwords(str_replace('_',' ',$m))) ?></td>
                <?php foreach (['view'=>'can_view','edit'=>'can_edit','export'=>'can_export','approve'=>'can_approve'] as $key => $col): ?>
                <td style="text-align:center">
                  <input type="checkbox" name="matrix[<?= esc_attr($m) ?>][<?= esc_attr($key) ?>]" value="1"
                         <?= $mm[$col] ? 'checked' : '' ?> <?= $selected['is_system'] ? 'disabled' : '' ?>>
                </td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (!$selected['is_system']): ?>
          <button type="submit" class="rto-btn rto-btn-primary rto-mt-4">Save Permissions</button>
          <span id="rbacSaveMsg" class="rto-small" style="margin-left:10px"></span>
          <?php endif; ?>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if ($selected && !$selected['is_system']): ?>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';
  var form = document.getElementById('rbacMatrixForm');
  var msg  = document.getElementById('rbacSaveMsg');
  form.addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(form);
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','save_role_permissions');
    fd.append('rto_nonce', nonce);
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true; msg.textContent = 'Saving…'; msg.style.color = '#64748b';
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
      btn.disabled = false;
      msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
      msg.style.color = r.success ? '#16A34A' : '#DC2626';
    }).catch(function(){ btn.disabled = false; msg.textContent = 'Network error — please try again.'; msg.style.color = '#DC2626'; });
  });
})();
</script>
<?php endif; ?>
<?php rto_help_box('staff'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
