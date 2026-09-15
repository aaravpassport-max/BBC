<?php
if (!defined('ABSPATH')) exit;

$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
$email   = get_option('rtoflow_support_email', '');
$current = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

// Portal URL helper
if (!function_exists('rtoflow_portal_url_for_user')) {
    function rtoflow_portal_url_for_user(): string {
        if (rto_is_admin() || rto_is_staff())  return '/rto-admin/';
        if (rto_is_vendor())                   return '/rto-vendor/';
        return '/rto-dashboard/';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc_html($meta_title ?? $page_title ?? $company) ?></title>
<meta name="description" content="<?= esc_attr($meta_desc ?? '') ?>">
<meta name="robots" content="index,follow">
<?php if (!empty($canonical)): ?><link rel="canonical" href="<?= esc_url($canonical) ?>"><?php endif; ?>
<meta property="og:title"       content="<?= esc_attr($meta_title ?? $page_title ?? $company) ?>">
<meta property="og:description" content="<?= esc_attr($meta_desc ?? '') ?>">
<meta property="og:type"        content="website">
<?php if (!empty($schema_json)): ?><script type="application/ld+json"><?= $schema_json ?></script><?php endif; ?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/public.css')) ?>">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/website.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/website.css')) ?>">
<?php
// FIX (mobile pass — public site bottom nav): the public/marketing site
// (about, all-cities, city-page, contact, how-it-works, login, pricing,
// privacy, terms — every page that runs through this shared layout) had
// only the existing hamburger top-nav toggle and no fixed bottom nav, even
// though Admin/Vendor/Client dashboards already got one. Wired in here so
// every page using this layout gets it in one place.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
<?php wp_head(); ?>
<style>
:root{
  --rto-primary:   <?= esc_attr(get_option('rtoflow_color_primary',   '#1B2A6B')) ?>;
  --rto-secondary: <?= esc_attr(get_option('rtoflow_color_secondary', '#E97B28')) ?>;
  --rto-accent:    <?= esc_attr(get_option('rtoflow_color_accent',    '#16A34A')) ?>;
  --rto-bg-dark:   <?= esc_attr(get_option('rtoflow_color_bg_dark',   '#0A1628')) ?>;
  --rto-text:      <?= esc_attr(get_option('rtoflow_color_text',      '#0f172a')) ?>;
}
html{margin-top:0!important}#wpadminbar{position:fixed!important}.admin-bar .rto-site-header{top:32px}@media(max-width:782px){.admin-bar .rto-site-header{top:46px}}
</style>
<?php
// Design & Typography Settings rollout — see DesignSettingsService's class
// docblock ROLLOUT note and buildSiteCss() for exactly what this does and
// doesn't affect on this shared layout. Loaded LAST so its :root/selector
// overrides win the cascade at equal specificity over website.css and the
// inline <style> above, the same mechanism home.php already uses for
// /rto-design.css.
?>
<link rel="stylesheet" href="<?= esc_url(home_url('/rto-design-site.css')) ?>?v=<?= esc_attr(\RTOFLOW\Services\DesignSettingsService::version()) ?>">
</head>
<body class="rto-website">

<!-- Info Topbar -->
<div class="rto-topbar-strip">
  <?php if ($phone): ?>📞 <a href="tel:<?= esc_attr($phone) ?>"><?= esc_html($phone) ?></a> &nbsp;|&nbsp;<?php endif; ?>
  🕐 Mon–Sat: 9AM–7PM &nbsp;|&nbsp; 🇮🇳 Pan India RTO Assistance
</div>

<!-- Main Header -->
<header class="rto-site-header">
  <div class="rto-site-header__inner">
    <a href="<?= home_url('/') ?>" class="rto-site-header__logo">
      <span style="display:flex;align-items:center;gap:8px">
        <span style="background:#E97B28;border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:18px">🔑</span>
        <span><?= esc_html($company) ?></span>
      </span>
    </a>
    <nav class="rto-site-nav" id="rto-site-nav">
      <a href="<?= home_url('/') ?>"                    class="rto-nav-link <?= $current==='/'||$current===''?'active':'' ?>">Home</a>
      <a href="<?= home_url('/rto-service/all') ?>"     class="rto-nav-link <?= str_starts_with($current,'/rto-service')?'active':'' ?>">Services</a>
      <a href="<?= home_url('/pricing') ?>"             class="rto-nav-link <?= $current==='/pricing'?'active':'' ?>">Pricing</a>
      <a href="<?= home_url('/how-it-works') ?>"        class="rto-nav-link <?= $current==='/how-it-works'?'active':'' ?>">How It Works</a>
      <a href="<?= home_url('/rto-services-cities') ?>" class="rto-nav-link">All Cities</a>
      <a href="<?= home_url('/about') ?>"               class="rto-nav-link <?= $current==='/about'?'active':'' ?>">About</a>
      <a href="<?= home_url('/contact') ?>"             class="rto-nav-link <?= $current==='/contact'?'active':'' ?>">Contact</a>
    </nav>
    <div class="rto-site-header__cta">
      <?php if (is_user_logged_in()): ?>
      <a href="<?= home_url(rtoflow_portal_url_for_user()) ?>" class="rto-btn rto-btn--sm">My Dashboard →</a>
      <?php else: ?>
      <a href="<?= home_url('/login') ?>" class="rto-btn rto-btn--sm">Login</a>
      <a href="<?= home_url('/rto-apply/') ?>" class="rto-btn rto-btn--primary rto-btn--sm">Get Quote →</a>
      <?php endif; ?>
    </div>
    <button class="rto-site-nav-toggle" id="rtoSiteNavToggle" aria-label="Toggle menu">☰</button>
  </div>
</header>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('rtoSiteNavToggle').addEventListener('click',function(){
  document.getElementById('rto-site-nav').classList.toggle('open');
});
</script>

<?php
  // Public-site mobile bottom nav: a smaller, purpose-built tab set (not a
  // 1:1 mirror of the 7-item top nav — that many tabs wouldn't fit/scan on
  // a phone). Home / Services / Track / Apply are the site's core visitor
  // actions; the last tab goes to the logged-in user's own dashboard, or to
  // Login for an anonymous visitor, mirroring the CTA area above.
  $publicNavActive = [
    'home'     => ($current === '/' || $current === ''),
    'services' => str_starts_with($current, '/rto-service'),
    'track'    => str_starts_with($current, '/rto-track'),
    'apply'    => str_starts_with($current, '/rto-apply'),
  ];
  $loggedIn = is_user_logged_in();
?>
<nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="website">
  <a href="<?= home_url('/') ?>" class="rto-bn-item <?= $publicNavActive['home'] ? 'active' : '' ?>" data-rto-page="home" <?= $publicNavActive['home'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon" aria-hidden="true">🏠</span><span class="rto-bn-label">Home</span>
  </a>
  <a href="<?= home_url('/rto-service/all') ?>" class="rto-bn-item <?= $publicNavActive['services'] ? 'active' : '' ?>" data-rto-page="services" <?= $publicNavActive['services'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon" aria-hidden="true">🧰</span><span class="rto-bn-label">Services</span>
  </a>
  <a href="<?= home_url('/rto-track/') ?>" class="rto-bn-item <?= $publicNavActive['track'] ? 'active' : '' ?>" data-rto-page="track" <?= $publicNavActive['track'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon" aria-hidden="true">📍</span><span class="rto-bn-label">Track</span>
  </a>
  <a href="<?= home_url('/rto-apply/') ?>" class="rto-bn-item <?= $publicNavActive['apply'] ? 'active' : '' ?>" data-rto-page="apply" <?= $publicNavActive['apply'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon" aria-hidden="true">➕</span><span class="rto-bn-label">Apply</span>
  </a>
  <?php if ($loggedIn): ?>
  <a href="<?= home_url(rtoflow_portal_url_for_user()) ?>" class="rto-bn-item" data-rto-page="dashboard">
    <span class="rto-bn-icon" aria-hidden="true">👤</span><span class="rto-bn-label">Dashboard</span>
  </a>
  <?php else: ?>
  <a href="<?= home_url('/login') ?>" class="rto-bn-item" data-rto-page="login">
    <span class="rto-bn-icon" aria-hidden="true">👤</span><span class="rto-bn-label">Login</span>
  </a>
  <?php endif; ?>
</nav>

<!-- Page Content -->
<main>
