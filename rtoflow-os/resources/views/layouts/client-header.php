<?php if (!defined('ABSPATH')) exit;
if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }
$currentPage = get_query_var('rto_page', 'dashboard');
$company     = get_option('rtoflow_company_name', 'RTOFLOW');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc_html($pageTitle ?? ucfirst($currentPage)) ?> — <?= esc_html($company) ?></title>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/public.css')) ?>">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
<?php // ENTERPRISE GAP FIX (Phase 5, item 3 — PWA / offline support) ?>
<link rel="manifest" href="<?= esc_url(home_url('/rto-client-manifest.json')) ?>">
<meta name="theme-color" content="#1E3A5F">
</head>
<body class="rto-client-body">
<div class="rto-client-wrap">
  <header class="rto-client-header">
    <div class="rto-client-header-inner">
      <a href="<?= esc_url(home_url('/')) ?>" class="rto-client-logo"><?= esc_html($company) ?></a>
      <nav class="rto-client-nav" aria-label="Client navigation">
        <?php
        $navLinks = [
          ['url'=>home_url('/rto-dashboard/'),            'label'=>'Dashboard',  'page'=>'dashboard'],
          ['url'=>home_url('/rto-dashboard/orders/'),     'label'=>'My Orders',  'page'=>'orders'],
          ['url'=>home_url('/rto-dashboard/documents/'),  'label'=>'Documents',  'page'=>'documents'],
          ['url'=>home_url('/rto-dashboard/complaints/'), 'label'=>'Complaints', 'page'=>'complaints'],
          ['url'=>home_url('/rto-apply/'),                'label'=>'New Request','page'=>'apply'],
          ['url'=>home_url('/rto-dashboard/profile/'),    'label'=>'Profile',    'page'=>'profile'],
          // ENTERPRISE GAP FIX (Phase 4, item 2 — saved vehicles/addresses)
          ['url'=>home_url('/rto-dashboard/vehicles/'),   'label'=>'My Vehicles','page'=>'vehicles'],
        ];
        // FIX (integration pass follow-up): the Complaints nav link was
        // always shown even when 'grievance_portal' is disabled — the
        // backend now 404s the submit endpoint (ComplaintsController::store())
        // and blocks the list route (see Router.php 'complaints' arm), but
        // the link itself still pointed users at a dead-end page.
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('grievance_portal')) {
            $navLinks = array_values(array_filter($navLinks, fn($l) => $l['page'] !== 'complaints'));
        }
        foreach ($navLinks as $link):
        ?>
        <a href="<?= esc_url($link['url']) ?>"
           class="<?= $currentPage === $link['page'] ? 'active' : '' ?>"
           <?= $currentPage === $link['page'] ? 'aria-current="page"' : '' ?>>
          <?= esc_html($link['label']) ?>
        </a>
        <?php endforeach; ?>
        <span class="rto-client-user"><?= esc_html(wp_get_current_user()->display_name) ?></span>
        <a href="<?= esc_url(rto_logout_url()) ?>" class="rto-logout-link">Logout</a>
      </nav>
    </div>
  </header>
  <?php
    // MOBILE APP-SHELL: fixed bottom nav for the client dashboard. Icons
    // + 4 primary tabs mirror the top nav's own $navLinks array above so
    // the two navigations can never drift out of sync in what pages exist.
    $bottomIcons = ['dashboard'=>'🏠','orders'=>'📦','documents'=>'📄','apply'=>'➕','profile'=>'👤'];
    $bottomPages = ['dashboard','orders','documents','apply'];
    $bottomItems = array_values(array_filter($navLinks, fn($l) => in_array($l['page'], $bottomPages, true)));
  ?>
  <nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="client">
    <?php foreach ($bottomItems as $item): $active = $currentPage === $item['page'] ? 'active' : ''; ?>
    <a href="<?= esc_url($item['url']) ?>" class="rto-bn-item <?= $active ?>" data-rto-page="<?= esc_attr($item['page']) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
      <span class="rto-bn-icon" aria-hidden="true"><?= $bottomIcons[$item['page']] ?? '•' ?></span>
      <span class="rto-bn-label"><?= esc_html($item['label'] === 'My Orders' ? 'Orders' : ($item['label'] === 'New Request' ? 'New' : $item['label'])) ?></span>
    </a>
    <?php endforeach; ?>
    <a href="<?= esc_url(home_url('/rto-dashboard/profile/')) ?>" class="rto-bn-item <?= $currentPage === 'profile' ? 'active' : '' ?>" data-rto-page="profile" <?= $currentPage === 'profile' ? 'aria-current="page"' : '' ?>>
      <span class="rto-bn-icon" aria-hidden="true">👤</span>
      <span class="rto-bn-label">Profile</span>
    </a>
  </nav>
  <main class="rto-client-main" id="rtoContentRegion" data-rto-page="<?= esc_attr($currentPage) ?>">
