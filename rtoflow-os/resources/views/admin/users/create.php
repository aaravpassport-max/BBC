<?php
if (!defined('ABSPATH')) exit;
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$pageTitle = 'Add New User';
?>
<div class="rto-page-header">
  <a href="<?= home_url('/rto-admin/') ?>?rto_page=staff" style="font-size:13px;color:#64748b;text-decoration:none">← Back to Users</a>
</div>
<h2 style="margin:12px 0 20px;font-size:20px;font-weight:800;color:#1B2A6B">Add New User / Staff</h2>

<?php if (!empty($_GET['error'])): ?>
<div style="background:#fee2e2;color:#dc2626;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px"><?= esc_html($_GET['error']) ?></div>
<?php endif; ?>

<div class="rto-card" style="max-width:600px">
  <div class="rto-card__body">
    <form method="POST" action="<?= home_url('/rto-admin/users/') ?>">
      <?php wp_nonce_field('rtoflow_create_user','_rto_nonce') ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
          <label for="newUserName" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">Full Name *</label>
          <input type="text" id="newUserName" name="name" class="rto-input" required>
        </div>
        <div>
          <label for="newUserEmail" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">Email *</label>
          <input type="email" id="newUserEmail" name="email" class="rto-input" required>
        </div>
        <div>
          <label for="newUserMobile" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">Mobile</label>
          <input type="tel" id="newUserMobile" name="mobile" class="rto-input" placeholder="10-digit">
        </div>
        <div>
          <!-- FIX (enterprise gap audit — blank-password bug): the password
               field was removed entirely. The account is created with an
               unusable random secret and WordPress emails the new user a
               secure "set your password" link (wp_new_user_notification) —
               nobody, including this admin, ever needs to know or transmit
               a real password for the account. -->
          <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">Password</label>
          <div class="rto-input" style="background:#f8fafc;color:#64748b;display:flex;align-items:center">✉ A secure password-set link will be emailed to this address</div>
        </div>
        <div style="grid-column:1/-1">
          <label for="newUserRole" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">Role *</label>
          <select id="newUserRole" name="role" class="rto-input" required>
            <option value="rto_staff">RTO Staff</option>
            <option value="rto_admin">RTO Admin</option>
            <option value="rto_vendor">RTO Vendor</option>
            <option value="rto_client">RTO Client</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0">
        <button type="submit" class="rto-btn rto-btn--primary">Create User</button>
        <a href="javascript:history.back()" class="rto-btn">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
