<?php
/**
 * NAS Admin Shell — Standalone Admin Layout
 * Provides auth, nav, sidebar. All admin pages include this.
 * Zero WordPress admin dependency. Zero theme dependency.
 */
if (!defined('ABSPATH')) exit;

// ── Auth: require WordPress login + manage_options or nas_manage_bookings ──
if (!is_user_logged_in()) {
    wp_redirect(home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/admin-dashboard/'))));
    exit;
}
if (!current_user_can('manage_options') && !current_user_can('nas_manage_bookings')) {
    wp_redirect(home_url('/'));
    exit;
}

// ── Determine current page from query var ──────────────────────────────────
$nas_admin_page = get_query_var('nas_admin_page', 'dashboard');
$nas_sub_id     = (int) get_query_var('nas_admin_id', 0);

// ── Site config ──────────────────────────────────────────────────────────────
$site_name = get_bloginfo('name');
$admin_url = function(string $page, int $id = 0): string {
    $base = nas_get_page_url('nas_page_admin_dashboard', '/admin-dashboard/');
    return $base . '?nas_admin=' . $page . ($id ? '&id=' . $id : '');
};

$nav_items = [
    ['key' => 'dashboard',    'icon' => 'fa-gauge-high',      'label' => 'Dashboard'],
    ['key' => 'requests',     'icon' => 'fa-file-lines',      'label' => 'All Requests'],
    ['key' => 'clients',      'icon' => 'fa-users',           'label' => 'Clients'],
    ['key' => 'vendors',      'icon' => 'fa-truck',           'label' => 'Vendors'],
    ['key' => 'team',         'icon' => 'fa-users-gear',      'label' => 'Team Accounts'],
    ['key' => 'newspapers',   'icon' => 'fa-newspaper',       'label' => 'Newspapers'],
    ['key' => 'categories',   'icon' => 'fa-tags',            'label' => 'Categories'],
    ['key' => 'templates',    'icon' => 'fa-file-alt',        'label' => 'Templates'],
    ['key' => 'analytics',    'icon' => 'fa-chart-bar',       'label' => 'Analytics'],
    ['key' => 'calendar',     'icon' => 'fa-calendar-days',   'label' => 'Pub. Calendar'],
    ['key' => 'vendor-payouts','icon'=> 'fa-money-bill-transfer','label'=> 'Vendor Payouts'],
    ['key' => 'data-manager', 'icon' => 'fa-database',        'label' => 'Data Manager'],
    ['key' => 'settings',     'icon' => 'fa-cog',             'label' => 'Settings'],
    ['key' => 'tickets',        'icon' => 'fa-ticket',              'label' => 'Support Tickets'],
    ['key' => 'blog',           'icon' => 'fa-blog',                'label' => 'Blog'],
    ['key' => 'faq',            'icon' => 'fa-circle-question',     'label' => 'FAQ'],
    ['key' => 'contacts',       'icon' => 'fa-inbox',               'label' => 'Contact Inbox'],
    ['key' => 'branding',       'icon' => 'fa-palette',             'label' => 'Branding'],
    ['key' => 'email-templates','icon' => 'fa-envelope',            'label' => 'Email Templates'],
];

$current_user = wp_get_current_user();
$admin_nonce  = wp_create_nonce('nas_admin_nonce');
$pdf_nonce    = wp_create_nonce('nas_pdf');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — <?php echo esc_html($site_name); ?></title>
<meta name="robots" content="noindex,nofollow">
<?php wp_head(); ?>
<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css'>
<!-- Config injected into <head> so all inline page scripts can access it synchronously -->
<script id="nas-admin-config" type="application/json">
{
  "ajaxUrl": "<?php echo esc_js( function_exists('nas_get_ajax_url') ? nas_get_ajax_url( (int) get_option('nas_page_admin_dashboard') ?: null ) : admin_url('admin-ajax.php') ); ?>",
  "nonce": "<?php echo esc_js($admin_nonce); ?>",
  "pdfNonce": "<?php echo esc_js($pdf_nonce); ?>",
  "adminUrl": "<?php echo esc_js(nas_get_page_url('nas_page_admin_dashboard', '/admin-dashboard/')); ?>",
  "currency": "\u20b9",
  "userId": <?php echo (int) $current_user->ID; ?>
}
</script>
<style id="nas-admin-isolation">
/* ══ NAS ADMIN — COMPLETE THEME ISOLATION ══════════════════════════════════
   This block runs AFTER all theme CSS in wp_head() and uses !important to
   neutralize every common theme override that breaks our layout.
   ════════════════════════════════════════════════════════════════════════ */

/* 1. Hard-reset html/body to full viewport — undo any theme max-width/margins */
html.nas-html,
html {
  margin-top: 0 !important;
  padding-top: 0 !important;
  overflow-x: hidden;
}
body.nas-admin-body,
body.nas-admin-body * {
  box-sizing: border-box !important;
}
body.nas-admin-body {
  margin: 0 !important;
  padding: 0 !important;
  background: #f1f5f9 !important;
  font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif !important;
  color: #1e293b !important;
  min-height: 100vh !important;
  overflow-x: hidden;
}

/* 2. Kill WordPress admin bar everywhere */
#wpadminbar, .wpadminbar { display: none !important; }

/* 3. Neutralize common theme wrappers that bleed through wp_head().
   These selectors match every major WordPress theme family. They must be
   invisible and must not constrain the admin shell layout. */
body.nas-admin-body #page,
body.nas-admin-body #wrapper,
body.nas-admin-body .site,
body.nas-admin-body .hfeed,
body.nas-admin-body #content,
body.nas-admin-body .site-content,
body.nas-admin-body #primary,
body.nas-admin-body .content-area,
body.nas-admin-body #main,
body.nas-admin-body .site-main,
body.nas-admin-body main.site-main,
body.nas-admin-body .container,
body.nas-admin-body .container-fluid,
body.nas-admin-body .elementor-section-wrap,
body.nas-admin-body .ast-container,
body.nas-admin-body .ast-content-layout-wrap,
body.nas-admin-body .entry-content,
body.nas-admin-body .post-content,
body.nas-admin-body #masthead,
body.nas-admin-body .site-header,
body.nas-admin-body .ast-above-header-wrap,
body.nas-admin-body header.site-header,
body.nas-admin-body footer.site-footer,
body.nas-admin-body #colophon,
body.nas-admin-body .site-footer {
  display: none !important;
  width: 0 !important;
  height: 0 !important;
  overflow: hidden !important;
  position: absolute !important;
  pointer-events: none !important;
  margin: 0 !important;
  padding: 0 !important;
  border: none !important;
}

/* 4. Admin shell — enforce correct flex layout regardless of theme interference */
body.nas-admin-body #nas-admin-shell,
body.nas-admin-body .nas-admin-shell {
  display: flex !important;
  width: 100% !important;
  max-width: 100% !important;
  min-height: 100vh !important;
  margin: 0 !important;
  padding: 0 !important;
  position: relative !important;
  box-sizing: border-box !important;
}

/* 5. Sidebar — fixed position, correct width, z-index above theme overlays */
body.nas-admin-body #nas-admin-sidebar,
body.nas-admin-body .nas-admin-sidebar {
  display: flex !important;
  flex-direction: column !important;
  width: 240px !important;
  min-width: 240px !important;
  max-width: 240px !important;
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  bottom: 0 !important;
  height: 100vh !important;
  background: #202C39 !important;
  z-index: 1000 !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  margin: 0 !important;
  padding: 0 !important;
  flex-shrink: 0 !important;
  box-sizing: border-box !important;
}

/* 6. Main content — correct margin to clear fixed sidebar */
body.nas-admin-body #nas-admin-main,
body.nas-admin-body .nas-admin-main {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 !important;
  min-width: 0 !important;
  margin-left: 240px !important;
  min-height: 100vh !important;
  background: #f1f5f9 !important;
  box-sizing: border-box !important;
}

/* 7. Sidebar collapsed state */
body.nas-admin-body .nas-admin-shell.nas-sidebar-collapsed #nas-admin-sidebar,
body.nas-admin-body .nas-admin-shell.nas-sidebar-collapsed .nas-admin-sidebar {
  width: 60px !important;
  min-width: 60px !important;
  max-width: 60px !important;
}
body.nas-admin-body .nas-admin-shell.nas-sidebar-collapsed #nas-admin-main,
body.nas-admin-body .nas-admin-shell.nas-sidebar-collapsed .nas-admin-main {
  margin-left: 60px !important;
}

/* 8. Topbar */
body.nas-admin-body .nas-admin-topbar {
  display: flex !important;
  align-items: center !important;
  height: 60px !important;
  background: #ffffff !important;
  border-bottom: 1px solid #e2e8f0 !important;
  padding: 0 24px !important;
  position: sticky !important;
  top: 0 !important;
  z-index: 100 !important;
  box-shadow: 0 1px 4px rgba(0,0,0,.05) !important;
  margin: 0 !important;
  width: 100% !important;
  box-sizing: border-box !important;
}

/* 9. Content area */
body.nas-admin-body .nas-admin-content {
  flex: 1 !important;
  padding: 24px !important;
  background: #f1f5f9 !important;
  min-width: 0 !important;
  box-sizing: border-box !important;
}

/* 10. KPI and card grids — ensure they render as grids not stacked text */
body.nas-admin-body .nas-kpi-grid {
  display: grid !important;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
  gap: 16px !important;
  margin-bottom: 28px !important;
}
body.nas-admin-body .nas-kpi-card {
  display: flex !important;
  background: #ffffff !important;
  border-radius: 12px !important;
  padding: 20px !important;
  box-shadow: 0 2px 12px rgba(0,0,0,.07) !important;
  align-items: center !important;
  gap: 16px !important;
  border-left-width: 4px !important;
  border-left-style: solid !important;
  min-height: 80px !important;
}
body.nas-admin-body .nas-kpi-icon {
  width: 48px !important;
  height: 48px !important;
  border-radius: 12px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-size: 22px !important;
  color: #ffffff !important;
  flex-shrink: 0 !important;
}
body.nas-admin-body .nas-kpi-body {
  flex: 1 !important;
  min-width: 0 !important;
}
body.nas-admin-body .nas-kpi-val {
  font-size: 24px !important;
  font-weight: 800 !important;
  display: block !important;
  line-height: 1 !important;
  margin-bottom: 4px !important;
  color: #111827 !important;
}
body.nas-admin-body .nas-kpi-label {
  font-size: 11px !important;
  color: #9ca3af !important;
  text-transform: uppercase !important;
  letter-spacing: .5px !important;
}

/* 11. Cards */
body.nas-admin-body .nas-card {
  background: #ffffff !important;
  border-radius: 12px !important;
  box-shadow: 0 2px 12px rgba(0,0,0,.07) !important;
  margin-bottom: 24px !important;
  overflow: hidden !important;
  border: 1px solid #f3f4f6 !important;
}
body.nas-admin-body .nas-card-header {
  padding: 16px 20px !important;
  background: #f9fafb !important;
  border-bottom: 1px solid #e5e7eb !important;
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
}
body.nas-admin-body .nas-card-header h3 {
  font-size: 15px !important;
  font-weight: 700 !important;
  margin: 0 !important;
  color: #1f2937 !important;
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
}

/* 12. Charts row */
body.nas-admin-body .nas-charts-row {
  display: flex !important;
  gap: 20px !important;
  margin-bottom: 24px !important;
  flex-wrap: wrap !important;
}

/* 13. Quick stats */
body.nas-admin-body .nas-quick-stats {
  display: grid !important;
  grid-template-columns: repeat(4, 1fr) !important;
  gap: 16px !important;
  margin-bottom: 24px !important;
}
body.nas-admin-body .nas-qs-card {
  background: #ffffff !important;
  border-radius: 12px !important;
  padding: 18px !important;
  text-align: center !important;
  box-shadow: 0 2px 12px rgba(0,0,0,.07) !important;
  border: 1px solid #f3f4f6 !important;
}

/* 14. Quick links */
body.nas-admin-body .nas-quick-links {
  display: grid !important;
  grid-template-columns: repeat(5, 1fr) !important;
  gap: 14px !important;
  margin-top: 24px !important;
}
body.nas-admin-body .nas-ql-card {
  background: #ffffff !important;
  border: 1.5px solid #e5e7eb !important;
  border-radius: 12px !important;
  padding: 18px !important;
  text-align: center !important;
  text-decoration: none !important;
  color: #374151 !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  gap: 8px !important;
}

/* 15. Sidebar nav items */
body.nas-admin-body .nas-nav-item {
  display: flex !important;
  align-items: center !important;
  gap: 12px !important;
  padding: 11px 16px !important;
  color: rgba(255,255,255,.7) !important;
  font-size: 13px !important;
  font-weight: 500 !important;
  white-space: nowrap !important;
  cursor: pointer !important;
  border-left: 3px solid transparent !important;
  text-decoration: none !important;
  transition: all .18s !important;
}
body.nas-admin-body .nas-nav-item.active {
  background: rgba(42,138,250,.3) !important;
  color: #ffffff !important;
  border-left-color: #2A8AFA !important;
}
body.nas-admin-body .nas-nav-item:hover {
  background: rgba(255,255,255,.08) !important;
  color: #ffffff !important;
}
body.nas-admin-body .nas-nav-item i {
  width: 18px !important;
  text-align: center !important;
  font-size: 15px !important;
  flex-shrink: 0 !important;
}
body.nas-admin-body .nas-nav-item span {
  display: block !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
}

/* 16. Sidebar brand */
body.nas-admin-body .nas-sidebar-brand {
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  padding: 20px 16px !important;
  border-bottom: 1px solid rgba(255,255,255,.1) !important;
}
body.nas-admin-body .nas-brand-name {
  color: #ffffff !important;
  font-size: 15px !important;
  font-weight: 700 !important;
  white-space: nowrap !important;
}

/* 17. Page structure */
body.nas-admin-body .nas-page-wrap {
  max-width: 1400px !important;
  margin: 0 auto !important;
}
body.nas-admin-body .nas-page-header {
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
  padding: 0 0 24px !important;
  border-bottom: 2px solid #e5e7eb !important;
  margin-bottom: 28px !important;
}
body.nas-admin-body .nas-page-title {
  font-size: 26px !important;
  font-weight: 800 !important;
  color: #111827 !important;
  margin: 0 !important;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
}
body.nas-admin-body .nas-page-actions {
  display: flex !important;
  gap: 10px !important;
  align-items: center !important;
}

/* 18. Table */
body.nas-admin-body .nas-table-wrap { overflow-x: auto !important; }
body.nas-admin-body .nas-table { width: 100% !important; border-collapse: collapse !important; }
body.nas-admin-body .nas-table th {
  padding: 12px 16px !important;
  text-align: left !important;
  font-size: 11px !important;
  font-weight: 700 !important;
  color: #6b7280 !important;
  text-transform: uppercase !important;
  background: #f9fafb !important;
  border-bottom: 2px solid #e5e7eb !important;
}
body.nas-admin-body .nas-table td {
  padding: 13px 16px !important;
  font-size: 13px !important;
  border-bottom: 1px solid #f3f4f6 !important;
  color: #374151 !important;
}

/* 19. Modals — explicit z-index and display control to override any theme interference */
body.nas-admin-body .nas-modal {
  position: fixed !important;
  inset: 0 !important;
  z-index: 99999 !important;
  background: rgba(0,0,0,.55) !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 20px !important;
}
body.nas-admin-body .nas-modal-content {
  position: relative !important;
  z-index: 100000 !important;
  background: #ffffff !important;
  border-radius: 14px !important;
  max-width: 600px !important;
  width: 100% !important;
  max-height: 90vh !important;
  overflow-y: auto !important;
}
body.nas-admin-body .nas-modal-lg {
  max-width: 820px !important;
}

/* 20. Responsive */
@media (max-width: 900px) {
  body.nas-admin-body .nas-admin-sidebar { width: 200px !important; min-width: 200px !important; }
  body.nas-admin-body .nas-admin-main { margin-left: 200px !important; }
  body.nas-admin-body .nas-quick-stats { grid-template-columns: repeat(2,1fr) !important; }
  body.nas-admin-body .nas-quick-links { grid-template-columns: repeat(3,1fr) !important; }
  body.nas-admin-body .nas-charts-row { flex-direction: column !important; }
}
@media (max-width: 640px) {
  body.nas-admin-body .nas-admin-sidebar { display: none !important; }
  body.nas-admin-body .nas-admin-main { margin-left: 0 !important; }
  body.nas-admin-body .nas-kpi-grid { grid-template-columns: 1fr !important; }
  body.nas-admin-body .nas-quick-stats { grid-template-columns: repeat(2,1fr) !important; }
  body.nas-admin-body .nas-quick-links { grid-template-columns: repeat(2,1fr) !important; }
}
</style>
</head>
<body class="nas-admin-body nas-app-shell nas-page-admin-dashboard">

<!-- ══ ADMIN SHELL ══════════════════════════════════════════════════════════ -->
<div class="nas-admin-shell" id="nas-admin-shell">

  <!-- Mobile overlay -->
  <div class="nas-sidebar-overlay"></div>

  <!-- Sidebar -->
  <aside class="nas-admin-sidebar" id="nas-admin-sidebar">
    <div class="nas-sidebar-brand">
      <span class="nas-brand-dot"></span>
      <span class="nas-brand-name"><?php echo esc_html($site_name); ?></span>
    </div>
    <nav class="nas-sidebar-nav" role="navigation">
      <?php foreach ($nav_items as $item): ?>
      <a href="<?php echo esc_url($admin_url($item['key'])); ?>"
         class="nas-nav-item <?php echo $nas_admin_page === $item['key'] ? 'active' : ''; ?>">
        <i class="fa-solid <?php echo $item['icon']; ?>"></i>
        <span><?php echo esc_html($item['label']); ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="nas-sidebar-footer">
      <a href="<?php echo nas_get_page_url('nas_page_booking', '/book-newspaper-ad/'); ?>" target="_blank" class="nas-sidebar-link">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> Booking Page
      </a>
      <a href="<?php echo esc_url(home_url('/client-dashboard/')); ?>" target="_blank" class="nas-sidebar-link">
        <i class="fa-solid fa-user"></i> Client Portal
      </a>
      <a href="<?php echo esc_url(home_url('/staff-dashboard/')); ?>" target="_blank" class="nas-sidebar-link">
        <i class="fa-solid fa-user-tie"></i> Staff Portal
      </a>
      <a href="<?php echo esc_url(home_url('/moderation-dashboard/')); ?>" target="_blank" class="nas-sidebar-link">
        <i class="fa-solid fa-scale-balanced"></i> Moderation
      </a>
      <a href="<?php echo esc_url(home_url('/vendor-dashboard/')); ?>" target="_blank" class="nas-sidebar-link" style="background:rgba(249,115,22,.15);color:#fb923c;border-radius:8px;margin:2px 0">
        <i class="fa-solid fa-truck"></i> Vendor Portal
        <i class="fa-solid fa-arrow-up-right-from-square" style="margin-left:auto;font-size:10px;opacity:.7"></i>
      </a>
      <a href="<?php echo wp_logout_url(home_url('/')); ?>" class="nas-sidebar-link nas-sidebar-logout">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
      </a>
    </div>
  </aside>

  <!-- Main Content Area -->
  <div class="nas-admin-main" id="nas-admin-main">

    <!-- Top Bar -->
    <header class="nas-admin-topbar">
      <button class="nas-sidebar-toggle" id="nas-sidebar-toggle" aria-label="Toggle sidebar">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div class="nas-topbar-center">
        <div class="nas-admin-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="nas-admin-search" placeholder="Search bookings, clients, ref..." autocomplete="off">
          <div class="nas-admin-search-results" id="nas-search-results"></div>
        </div>
      </div>
      <div class="nas-topbar-right">
        <div class="nas-admin-user">
          <div class="nas-admin-avatar"><?php echo esc_html(strtoupper(substr($current_user->display_name, 0, 1))); ?></div>
          <span><?php echo esc_html($current_user->display_name); ?></span>
        </div>
      </div>
    </header>

    <!-- Page Content -->
    <div class="nas-admin-content" id="nas-admin-content">
<?php
// ── Route to correct page ────────────────────────────────────────────────────
$page_map = [
    'dashboard'    => 'dashboard',
    'requests'     => 'requests',
    'request'      => 'request-detail',
    'clients'      => 'clients',
    'vendors'      => 'vendors',
    'team'         => 'team',
    'newspapers'   => 'newspapers',
    'categories'   => 'categories',
    'templates'    => 'templates',
    'analytics'    => 'analytics',
    'data-manager' => 'data-manager',
    'calendar'     => 'calendar',
    'vendor-payouts'=> 'vendor-payouts',
    'settings'     => 'settings',
    'tickets'         => 'tickets',
    'blog'            => 'blog',
    'faq'             => 'faq',
    'contacts'        => 'contacts',
    'branding'        => 'branding',
    'email-templates' => 'email-templates',
];
$page_file = $page_map[$nas_admin_page] ?? 'dashboard';
$page_path = NAS_PLUGIN_DIR . "templates/admin/pages/{$page_file}.php";
if (file_exists($page_path)) {
    include $page_path;
} else {
    echo '<div class="nas-page-wrap"><div class="nas-alert nas-alert-error">Page not found: ' . esc_html($page_file) . '</div></div>';
}
?>
    </div><!-- .nas-admin-content -->
  </div><!-- .nas-admin-main -->
</div><!-- .nas-admin-shell -->

<!-- ══ GLOBAL TOAST ═════════════════════════════════════════════════════════ -->
<div id="nas-admin-toast" class="nas-admin-toast" aria-live="polite"></div>


<?php
$GLOBALS['portal_active_nav'] = 'admin-dashboard';
if ( function_exists( 'nas_portal_bottom_nav' ) ) {
    nas_portal_bottom_nav();
} elseif ( file_exists( NAS_DIR . 'templates/partials/portal-bottom-nav.php' ) ) {
    include NAS_DIR . 'templates/partials/portal-bottom-nav.php';
}
wp_footer();
?>
<script>
// Init sidebar toggle
// Sidebar toggle handled by nas-admin.js v3.1
// Init search
const nasAdminSearch = document.getElementById('nas-admin-search');
if (nasAdminSearch) {
  let st;
  nasAdminSearch.addEventListener('input', () => {
    clearTimeout(st);
    st = setTimeout(() => {
      const q = nasAdminSearch.value.trim();
      if (q.length < 2) { document.getElementById('nas-search-results').style.display='none'; return; }
      const cfg = JSON.parse(document.getElementById('nas-admin-config').textContent);
      const fd = new FormData();
      fd.append('action','nas_admin_get_bookings'); fd.append('nas_action','1');
      fd.append('nonce', cfg.nonce);
      fd.append('search', q);
      fd.append('page', '1');
      const _ctrl=new AbortController();
      setTimeout(()=>_ctrl.abort(),30000);
      fetch(cfg.ajaxUrl, {method:'POST', body:fd, signal:_ctrl.signal})
        .then(r=>r.json())
        .then(res => {
          const wrap = document.getElementById('nas-search-results');
          if (!res.success || !res.data?.bookings?.length) { wrap.style.display='none'; return; }
          wrap.innerHTML = res.data.bookings.slice(0,6).map(b =>
            `<a href="${cfg.adminUrl+'?nas_admin=request'}&id=${b.id}" class="nas-sr-item">
               <strong>${b.uid||b.id}</strong>
               <span>${b.client_name||''} · ${b.np_name||''}</span>
             </a>`
          ).join('');
          wrap.style.display = 'block';
        });
    }, 220);
  });
  document.addEventListener('click', e => {
    if (!nasAdminSearch.closest('.nas-admin-search-wrap').contains(e.target))
      document.getElementById('nas-search-results').style.display='none';
  });
}
</script>
</body>
</html>
