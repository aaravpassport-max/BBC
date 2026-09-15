<?php
if (!defined('ABSPATH')) exit;
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-header">
  <h2>Users & Staff</h2>
  <a href="<?= home_url('/rto-admin/users/') ?>?action=new" class="rto-btn rto-btn--primary rto-btn--sm">+ Add User</a>
</div>

<?php if (isset($_GET['vendor_row_failed'])): ?>
<div class="rto-msg rto-msg-error rto-mb-4" role="alert">
  The user account was created, but the vendor profile could not be saved due to a database error.
  This account can log in but will not appear correctly on the Vendors screen and cannot be assigned jobs
  until its vendor profile is created. Please check the error log, then retry from
  <a href="<?= home_url('/rto-admin/vendors/') ?>">Vendors</a> or contact support.
</div>
<?php elseif (isset($_GET['created'])): ?>
<div class="rto-msg rto-msg-success rto-mb-4" role="status">User created successfully.</div>
<?php elseif (isset($_GET['create_failed'])): ?>
<div class="rto-msg rto-msg-error rto-mb-4" role="alert">Could not create the user — the email address may already be registered, or the request could not be completed.</div>
<?php endif; ?>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="rto_area" value="admin">
      <input type="hidden" name="rto_page" value="users">
      <select name="role" id="userRoleFilter" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px">
        <option value="">All Roles</option>
        <option value="rto_admin"  <?= ($_GET['role']??'')==='rto_admin' ?'selected':'' ?>>RTO Admin</option>
        <option value="rto_staff"  <?= ($_GET['role']??'')==='rto_staff' ?'selected':'' ?>>RTO Staff</option>
        <option value="rto_vendor" <?= ($_GET['role']??'')==='rto_vendor'?'selected':'' ?>>RTO Vendor</option>
        <option value="rto_client" <?= ($_GET['role']??'')==='rto_client'?'selected':'' ?>>RTO Client</option>
        <option value="administrator" <?= ($_GET['role']??'')==='administrator'?'selected':'' ?>>Administrator (filter only)</option>
      </select>
      <!-- Known Limitations audit fix: "Administrator" is a real WordPress
           core role this filter can find (e.g. the site owner's own login),
           but the "+ Add User" form on this same screen can never create
           one — only rto_admin/rto_staff/rto_vendor/rto_client are
           assignable there. The "(filter only)" label above makes that
           explicit instead of leaving an admin to discover it by trying. -->
      <?php if (($_GET['role'] ?? '') === 'administrator'): ?>
      <span class="rto-small rto-muted" style="align-self:center">Filtering for WordPress's own core "Administrator" role — this screen's "+ Add User" form cannot create one; it only assigns RTO Admin/Staff/Vendor/Client.</span>
      <?php endif; ?>
      <input type="text" name="s" value="<?= esc_attr($_GET['s']??'') ?>" placeholder="Search name or email..." style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;min-width:200px">
      <button type="submit" class="rto-btn rto-btn--primary rto-btn--sm">Search</button>
    </form>
  </div>
</div>

<?php
$role   = sanitize_key($_GET['role'] ?? '');
$search = sanitize_text_field($_GET['s'] ?? '');
$page   = max(1,(int)($_GET['paged'] ?? 1));
$args   = ['number' => 25, 'offset' => ($page-1)*25, 'orderby' => 'registered', 'order' => 'DESC'];
if ($role)   $args['role']   = $role;
if ($search) $args['search'] = '*' . $search . '*';
$users = get_users($args);
?>
<div class="rto-card">
  <div class="rto-card__body" style="padding:0">
    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Mobile</th><th>Joined</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td data-label="Name" style="font-weight:600"><?= esc_html($u->display_name) ?></td>
          <td data-label="Email"><?= esc_html($u->user_email) ?></td>
          <td data-label="Roles">
            <?php foreach ($u->roles as $r): ?>
            <span style="background:#EFF6FF;color:#2563EB;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin:1px;display:inline-block"><?= esc_html($r) ?></span>
            <?php endforeach; ?>
          </td>
          <td data-label="Mobile"><?= esc_html(get_user_meta($u->ID,'rtoflow_mobile',true) ?: '—') ?></td>
          <td data-label="Joined"><?= date('d M Y',strtotime($u->user_registered)) ?></td>
          <td data-label="Actions"><a href="<?= admin_url('user-edit.php?user_id='.$u->ID) ?>" class="rto-btn rto-btn--sm rto-btn--xs">Edit</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
        <tr><td colspan="6" style="text-align:center;padding:32px;color:#94a3b8">No users found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php rto_help_box('users'); ?>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var userRoleFilterEl = document.getElementById('userRoleFilter');
if (userRoleFilterEl) {
  userRoleFilterEl.addEventListener('change', function() { this.form.submit(); });
}
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
