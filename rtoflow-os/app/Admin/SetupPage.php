<?php

namespace RTOFLOW\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Setup & Status page — shown in WP Admin under RTOFLOW OS → Setup & Status.
 * Gives administrators a single view of plugin health, portal URLs, and
 * first-time configuration steps.
 */
class SetupPage
{
    public static function render(): void
    {
        global $wpdb;

        // ── Gather status ────────────────────────────────────────────────────
        $envKey      = \RTOFLOW\Config\Env::string('ENCRYPTION_KEY', '');
        $hasEnvKey   = strlen($envKey) === 64 && ctype_xdigit($envKey);
        $envFile     = RTOFLOW_DIR . '.env';
        $hasEnvFile  = file_exists($envFile);

        // Check tables exist
        $leadTable  = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}rto_leads'");
        $tablesOk   = !empty($leadTable);

        // Check pages exist
        $dashPage = get_posts(['name' => 'rto-dashboard', 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 1]);
        $applyPage = get_posts(['name' => 'rto-apply', 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 1]);

        // Check rewrite rules are active
        $rules   = get_option('rewrite_rules', []);
        $rulesOk = is_array($rules) && array_key_exists('^rto-admin/?$', $rules);

        // Check roles
        $rolesOk = get_role('rto_admin') && get_role('rto_vendor') && get_role('rto_client');

        // Company settings
        $companyName = get_option('rtoflow_company_name', '');
        $adminUserId = (int) get_option('rtoflow_admin_user_id', 0);

        // URLs
        $adminUrl  = home_url('/rto-admin/');
        $navUrl    = home_url('/rto-admin/nav/');
        $dashUrl   = home_url('/rto-dashboard/');
        $applyUrl  = home_url('/rto-apply/');
        $vendorUrl = home_url('/rto-vendor/');
        $loginUrl  = home_url('/rto-login/');
        $permUrl   = admin_url('options-permalink.php');

        $ok  = '<span style="color:#166534;font-weight:600">✅</span>';
        $err = '<span style="color:#991b1b;font-weight:600">❌</span>';
        $warn = '<span style="color:#854d0e;font-weight:600">⚠️</span>';
        ?>
        <div class="wrap" style="max-width:860px">
          <h1 style="color:#1E3A5F;display:flex;align-items:center;gap:10px">
            🚦 RTOFLOW OS — Setup &amp; Status
          </h1>

          <?php if (!$tablesOk || !$rulesOk || !$hasEnvKey || !$companyName): ?>
          <div class="notice notice-warning inline" style="margin:16px 0;padding:12px 16px">
            <strong>Action required</strong> — complete the steps below marked ❌ or ⚠️ before using the plugin.
          </div>
          <?php else: ?>
          <div class="notice notice-success inline" style="margin:16px 0;padding:12px 16px">
            <strong>All checks passed</strong> — the plugin is fully configured and operational.
          </div>
          <?php endif; ?>

          <!-- ── Checklist ────────────────────────────────────────────────── -->
          <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px;margin-bottom:24px">
            <h2 style="margin:0 0 16px;font-size:16px">Setup Checklist</h2>
            <table style="width:100%;border-collapse:collapse">
              <tbody>
                <tr style="border-bottom:1px solid #f0f0f0">
                  <td style="padding:10px 0;width:32px"><?= $tablesOk ? $ok : $err ?></td>
                  <td style="padding:10px 12px;font-weight:600">Database Tables</td>
                  <td style="padding:10px 0;color:#555">
                    <?= $tablesOk
                        ? 'All plugin tables created successfully.'
                        : 'Tables are missing. <strong>Deactivate and re-activate the plugin</strong> to run migrations.' ?>
                  </td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0">
                  <td style="padding:10px 0"><?= $rolesOk ? $ok : $err ?></td>
                  <td style="padding:10px 12px;font-weight:600">User Roles</td>
                  <td style="padding:10px 0;color:#555">
                    <?= $rolesOk
                        ? 'rto_admin, rto_staff, rto_vendor, rto_client roles registered.'
                        : 'Roles missing. Deactivate and re-activate to register them.' ?>
                  </td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0">
                  <td style="padding:10px 0"><?= $rulesOk ? $ok : $err ?></td>
                  <td style="padding:10px 12px;font-weight:600">Permalink / URL Rules</td>
                  <td style="padding:10px 0;color:#555">
                    <?php if ($rulesOk): ?>
                      Portal URLs are active (rto-admin, rto-vendor, rto-dashboard).
                    <?php else: ?>
                      <strong>Portal URLs are not active.</strong>
                      <a href="<?= esc_url($permUrl) ?>" class="button button-small" style="margin-left:8px">
                        Go to Settings → Permalinks and click Save
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0">
                  <td style="padding:10px 0"><?= \RTOFLOW\Security\Encryption::isUsingEnvKey() ? $ok : $warn ?></td>
                  <td style="padding:10px 12px;font-weight:600">Encryption Key</td>
                  <td style="padding:10px 0;color:#555">
                    <?php if (\RTOFLOW\Security\Encryption::isUsingEnvKey()): ?>
                      Using .env key — most secure. PII encryption is active.
                    <?php else: ?>
                      Using an <strong>auto-generated key</strong> stored in the database.
                      The plugin is fully functional. For production you can optionally move
                      the key to a <code>.env</code> file (not required).
                      <br><small style="color:#888">To migrate: copy the value of
                      <code>rtoflow_encryption_key</code> from wp_options to your .env as
                      <code>ENCRYPTION_KEY=&lt;value&gt;</code>, then delete the wp_options row.</small>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0">
                  <td style="padding:10px 0"><?= $companyName ? $ok : $warn ?></td>
                  <td style="padding:10px 12px;font-weight:600">Company Settings</td>
                  <td style="padding:10px 0;color:#555">
                    <?= $companyName
                        ? 'Company name: <strong>' . esc_html($companyName) . '</strong>'
                        : '<a href="' . esc_url(admin_url('admin.php?page=rtoflow-settings')) . '">Configure company name, GSTIN, and address</a> for invoices.' ?>
                  </td>
                </tr>
                <tr>
                  <td style="padding:10px 0"><?= $adminUserId ? $ok : $warn ?></td>
                  <td style="padding:10px 12px;font-weight:600">Admin User</td>
                  <td style="padding:10px 0;color:#555">
                    <?= $adminUserId
                        ? 'Admin user set (ID ' . esc_html($adminUserId) . ').'
                        : 'No admin user configured. <a href="' . esc_url(admin_url('admin.php?page=rtoflow-settings')) . '">Set your admin user ID</a> to receive SLA alerts.' ?>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- ── Portal URLs ───────────────────────────────────────────────── -->
          <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px;margin-bottom:24px">
            <h2 style="margin:0 0 16px;font-size:16px">Portal URLs</h2>
            <table style="width:100%;border-collapse:collapse">
              <?php
              $portals = [
                  ['🏢', 'Admin Portal',      'Staff and administrators', $adminUrl,  'rto_admin or administrator role required'],
                  ['👷', 'Vendor Portal',     'For assigned vendors',     $vendorUrl, 'rto_vendor role required'],
                  ['👤', 'Client Dashboard',  'For service clients',      $dashUrl,   'rto_client role required'],
                  ['📝', 'Apply Form',       'Public service request form',    $applyUrl,  'No login required'],
                  ['🔑', 'Login Page',       'Standalone custom login (no wp-login)', $loginUrl, 'No WP login page used'],
                  ['🗺', 'All Pages / Nav',  'Quick access to all plugin pages', $navUrl,   'rto_admin role'],
              ];
              foreach ($portals as [$icon, $label, $desc, $url, $note]):
              ?>
              <tr style="border-bottom:1px solid #f0f0f0">
                <td style="padding:10px 0;width:32px;font-size:18px"><?= $icon ?></td>
                <td style="padding:10px 12px;font-weight:600;width:180px"><?= esc_html($label) ?></td>
                <td style="padding:10px 0">
                  <a href="<?= esc_url($url) ?>" target="_blank"><?= esc_html($url) ?></a>
                  <span style="color:#888;font-size:12px;display:block;margin-top:2px">
                    <?= esc_html($desc) ?> · <?= esc_html($note) ?>
                  </span>
                </td>
                <td style="padding:10px 0;width:120px;text-align:right">
                  <?= $rulesOk
                      ? '<a href="' . esc_url($url) . '" class="button button-small" target="_blank">Open →</a>'
                      : '<span style="color:#854d0e;font-size:12px">Flush permalinks first</span>' ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
          </div>

          <!-- ── Quick Setup: First Admin Account ─────────────────────────── -->
          <?php
          $rtoAdmins = get_users(['role' => 'rto_admin', 'number' => 5]);
          if (empty($rtoAdmins)):
          ?>
          <div style="background:#fef9c3;border:1px solid #d97706;border-radius:6px;padding:20px;margin-bottom:24px">
            <h2 style="margin:0 0 12px;font-size:16px;color:#854d0e">⚠️ No Admin Users Found</h2>
            <p style="margin:0 0 12px;color:#555">
              No WordPress users have the <code>rto_admin</code> role yet. Assign it to yourself
              or another user to access the Admin Portal.
            </p>
            <?php
            $currentUser = wp_get_current_user();
            $nonce = wp_create_nonce('rtoflow_assign_self_admin');
            ?>
            <form method="POST" action="">
              <input type="hidden" name="rtoflow_action" value="assign_admin">
              <input type="hidden" name="rtoflow_nonce" value="<?= esc_attr($nonce) ?>">
              <input type="hidden" name="user_id" value="<?= esc_attr($currentUser->ID) ?>">
              <button type="submit" class="button button-primary">
                Assign rto_admin role to myself (<?= esc_html($currentUser->user_login) ?>)
              </button>
            </form>
          </div>
          <?php endif; ?>

          <!-- ── System Info ───────────────────────────────────────────────── -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px">
            <h3 style="margin:0 0 8px;font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.5px">System</h3>
            <code style="font-size:12px;color:#475569">
              RTOFLOW OS v<?= esc_html(RTOFLOW_VERSION) ?> &nbsp;|&nbsp;
              PHP <?= esc_html(PHP_VERSION) ?> &nbsp;|&nbsp;
              WordPress <?= esc_html(get_bloginfo('version')) ?> &nbsp;|&nbsp;
              MySQL <?= esc_html($wpdb->db_version()) ?>
            </code>
          </div>
        </div>
        <?php
    }

    /**
     * Handle the "assign admin to self" form submission on the setup page.
     * Hooked via admin_init.
     */
    public static function handleSetupActions(): void
    {
        if (!isset($_POST['rtoflow_action']) || $_POST['rtoflow_action'] !== 'assign_admin') return;
        if (!current_user_can('administrator')) return;
        if (!wp_verify_nonce($_POST['rtoflow_nonce'] ?? '', 'rtoflow_assign_self_admin')) return;

        $userId = (int)($_POST['user_id'] ?? 0);
        if (!$userId) return;

        $user = new \WP_User($userId);
        $user->add_role('rto_admin');

        // Set as admin user for notifications
        update_option('rtoflow_admin_user_id', $userId, false);

        wp_redirect(admin_url('admin.php?page=rtoflow-setup&assigned=1'));
        exit;
    }
}
