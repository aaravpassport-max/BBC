<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= esc_html($pageTitle ?? 'RTOFLOW OS') ?> — RTOFLOW OS</title>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/admin.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/admin.css')) ?>">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
<style>:root{--rto-primary:<?= esc_attr(get_option('rtoflow_color_primary','#1B2A6B')) ?>;--rto-secondary:<?= esc_attr(get_option('rtoflow_color_secondary','#E97B28')) ?>;--rto-accent:<?= esc_attr(get_option('rtoflow_color_accent','#16A34A')) ?>}</style>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
// Part 4.16 FIX (real bug — root cause of "Security check failed" persisting
// even after Part 4.15's check_ajax_referer query_arg fix): this used to be
// defined in admin-footer.php, which every admin view require()s at the very
// BOTTOM of the page — AFTER that same view's own inline <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>"> block
// (the one that does `var nonce = (window.rtoflowAdmin || {}).nonce || ...`)
// had already run. Inline <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>"> tags execute synchronously, in document
// order, as the browser parses them — so on every single admin page in this
// app, `window.rtoflowAdmin` was still undefined at the exact moment each
// page's own script tried to read `.nonce` from it. The `document.querySelector
// ('meta[name="rto-admin-nonce"]')` fallback those same scripts try next
// never helps either — that meta tag is referenced in 5 view files but is
// never actually emitted anywhere in this codebase. Net effect: `nonce`
// silently resolved to '' on every admin page, so every admin AJAX POST sent
// rto_nonce='' — which is why fixing check_ajax_referer's query_arg alone
// (Part 4.15) was necessary but not sufficient: the server was now finally
// looking at the right field, but the field's value was always empty. Moving
// this assignment into <head> — the very first thing the browser parses on
// every admin page, before the sidebar, before any page body, before any
// page-specific script — guarantees window.rtoflowAdmin.nonce is populated
// before any other script on the page can possibly run.
var rtoflowAdmin = {
  ajax_url: "<?= esc_url(admin_url('admin-ajax.php')) ?>",
  nonce:    "<?= esc_js(wp_create_nonce('rto_admin_lead')) ?>"
};
</script>
</head>
<body class="rto-admin-body">

<a href="#rto-main-content" class="rto-skip-link">Skip to main content</a>
<div class="rto-wrap">
  <?php
    // MOBILE APP-SHELL: fixed bottom nav, visible only under the mobile
    // breakpoint (see mobile-nav.css) — the desktop sidebar above is
    // completely unchanged and still renders for desktop/tablet widths.
    // Only the 4 most-used sections get a direct tab; "More" reveals the
    // full sidebar as a slide-up sheet by reusing the SAME toggle the
    // existing desktop hamburger button already calls, so there is exactly
    // one sidebar-open mechanism, not two competing ones.
    $bottomNavItems = [
      ['page'=>'dashboard', 'icon'=>'📊', 'label'=>'Home',    'url'=>home_url('/rto-admin/')],
      ['page'=>'leads',     'icon'=>'📋', 'label'=>'Orders',  'url'=>home_url('/rto-admin/leads/')],
      ['page'=>'vendors',   'icon'=>'👷', 'label'=>'Vendors', 'url'=>home_url('/rto-admin/vendors/')],
      ['page'=>'payments',  'icon'=>'💳', 'label'=>'Payments','url'=>home_url('/rto-admin/payments/')],
    ];
  ?>
  <nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="admin">
    <?php foreach ($bottomNavItems as $item): $active = $currentPage === $item['page'] ? 'active' : ''; ?>
    <a href="<?= esc_url($item['url']) ?>" class="rto-bn-item <?= $active ?>" data-rto-page="<?= esc_attr($item['page']) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
      <span class="rto-bn-icon" aria-hidden="true"><?= $item['icon'] ?></span>
      <span class="rto-bn-label"><?= esc_html($item['label']) ?></span>
    </a>
    <?php endforeach; ?>
    <button type="button" class="rto-bn-item rto-bn-more" id="rtoBottomNavMore" aria-label="More sections" aria-expanded="false" aria-controls="rtoSidebar">
      <span class="rto-bn-icon" aria-hidden="true">☰</span>
      <span class="rto-bn-label">More</span>
    </button>
  </nav>
  <!-- Sidebar -->
  <aside class="rto-sidebar" id="rtoSidebar">
    <div class="rto-sidebar-brand">
      <span class="rto-logo">RTOFLOW</span>
      <span class="rto-logo-sub">OS v<?= RTOFLOW_VERSION ?></span>
    </div>

    <nav class="rto-nav">
      <?php
      $currentPage = get_query_var('rto_page','dashboard');
      $navItems = [
        ['page'=>'nav',             'icon'=>'🗺','label'=>'All Pages',        'url'=>home_url('/rto-admin/nav/')],
        ['page'=>'dashboard',       'icon'=>'📊','label'=>'Dashboard',        'url'=>home_url('/rto-admin/')],
        ['page'=>'leads',           'icon'=>'📋','label'=>'Orders',           'url'=>home_url('/rto-admin/leads/')],
        ['page'=>'vendors',         'icon'=>'👷','label'=>'Vendors',          'url'=>home_url('/rto-admin/vendors/')],
        ['page'=>'services',        'icon'=>'🛠','label'=>'Services',         'url'=>home_url('/rto-admin/services/')],
        ['page'=>'masters',         'icon'=>'🗺','label'=>'Cities / RTOs',    'url'=>home_url('/rto-admin/masters/')],
        ['page'=>'hero-slider',     'icon'=>'🖼','label'=>'Hero Slider',      'url'=>home_url('/rto-admin/hero-slider/')],
        ['page'=>'design-settings', 'icon'=>'🎨','label'=>'Design & Typography', 'url'=>home_url('/rto-admin/design-settings/')],
        ['page'=>'city-pricing',      'icon'=>'💰','label'=>'City Pricing',       'url'=>home_url('/rto-admin/city-pricing/')],
        ['page'=>'payments',        'icon'=>'💳','label'=>'Payments',         'url'=>home_url('/rto-admin/payments/')],
        ['page'=>'payouts',         'icon'=>'💸','label'=>'Payouts',          'url'=>home_url('/rto-admin/payouts/')],
        ['page'=>'reports',         'icon'=>'📈','label'=>'Reports',          'url'=>home_url('/rto-admin/reports/')],
        ['page'=>'complaints',      'icon'=>'📣','label'=>'Complaints',       'url'=>home_url('/rto-admin/complaints/')],
        ['page'=>'ratings',         'icon'=>'⭐','label'=>'Ratings',          'url'=>home_url('/rto-admin/ratings/')],
        ['page'=>'email-templates', 'icon'=>'✉', 'label'=>'Email Templates',  'url'=>home_url('/rto-admin/email-templates/')],
        ['page'=>'staff',           'icon'=>'👥','label'=>'Staff / Users',    'url'=>home_url('/rto-admin/staff/')],
        ['page'=>'features',        'icon'=>'🎛','label'=>'Feature Flags',    'url'=>home_url('/rto-admin/features/')],
        ['page'=>'ai',              'icon'=>'🤖','label'=>'AI Insights',      'url'=>home_url('/rto-admin/ai/')],
        ['page'=>'settings',        'icon'=>'⚙', 'label'=>'Settings',         'url'=>home_url('/rto-admin/settings/')],
        // ENTERPRISE GAP FIX (Section 1 — 2FA setup UI): every admin/staff
        // account should be able to reach this, not just admins.
        ['page'=>'my-security',     'icon'=>'🔐','label'=>'My Security',      'url'=>home_url('/rto-admin/my-security/')],
      ];
      // ENTERPRISE GAP FIX (Section 8 — Audit Log viewer): admin-only, same
      // as the controller's own rto_is_admin() gate — a staff user should
      // never even see a link to a screen that will 403 them, and the log
      // can surface other staff members' actions/IPs which is deliberately
      // scoped to admins only.
      if (rto_is_admin()) {
          $navItems[] = ['page'=>'audit-log', 'icon'=>'🕵', 'label'=>'Audit Log', 'url'=>home_url('/rto-admin/audit-log/')];
          // ENTERPRISE GAP FIX (Phase 4, item 5 — vendor appeals queue)
          $navItems[] = ['page'=>'vendor-appeals', 'icon'=>'⚖', 'label'=>'Vendor Appeals', 'url'=>home_url('/rto-admin/vendor-appeals/')];
      }
      foreach ($navItems as $item):
        $active = $currentPage === $item['page'] ? 'active' : '';
      ?>
      <a href="<?= esc_url($item['url']) ?>" class="rto-nav-item <?= $active ?>" <?= $active ? 'aria-current="page"' : '' ?>>
        <span class="rto-nav-icon"><?= $item['icon'] ?></span>
        <span class="rto-nav-label"><?= esc_html($item['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="rto-sidebar-footer">
      <span><?= esc_html(wp_get_current_user()->display_name) ?></span>
      <a href="<?= esc_url(rto_logout_url()) ?>" class="rto-logout">Logout</a>
    </div>
  </aside>

  <!-- Main content -->
  <main class="rto-main" id="rto-main-content">
    <header class="rto-topbar">
      <button class="rto-sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation sidebar" aria-expanded="false" aria-controls="rtoSidebar"><span aria-hidden="true">☰</span></button>
      <h1 class="rto-page-title"><?= esc_html($pageTitle ?? 'Dashboard') ?></h1>
      <?php // ENTERPRISE GAP FIX (Phase 6, item — "no global cross-module
            // search"): finds a lead, vendor, or client by name/number
            // across the whole admin, instead of every screen only having
            // its own local search. See Router::globalSearchAjax(). ?>
      <div class="rto-global-search" style="position:relative;flex:1;max-width:360px;margin:0 16px">
        <input type="text" id="rtoGlobalSearch" class="rto-input" placeholder="Search leads, vendors, clients…"
               autocomplete="off" aria-label="Global search" style="width:100%">
        <div id="rtoGlobalSearchResults" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid var(--gray-200);border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:400px;overflow-y:auto;z-index:1000"></div>
      </div>
      <div class="rto-topbar-actions">
        <?php
$todayRevenue = get_transient('rtofl_today_revenue');
if ($todayRevenue === false) {
    global $wpdb;
    $todayRevenue = (float)$wpdb->get_var(
        "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}rto_payments WHERE DATE(created_at)=CURDATE() AND status='completed'"
    );
    set_transient('rtofl_today_revenue', $todayRevenue, 300);
}
?>
<span class="rto-topbar-kpi" title="Revenue collected today" style="font-size:13px">
  <span style="font-size:10px;color:var(--gray-500);display:block;line-height:1">Today</span>
  <?= esc_html(rto_format_inr($todayRevenue)) ?>
</span>
        <span class="rto-topbar-user"><?= esc_html(rto_user_role() ?? '') ?></span>
      </div>
    </header>

    <div class="rto-content" id="rtoContentRegion" data-rto-page="<?= esc_attr($currentPage) ?>">
