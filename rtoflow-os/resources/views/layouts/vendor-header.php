<?php if (!defined('ABSPATH')) exit;
if (!rto_is_vendor()) { wp_redirect(rto_login_url()); exit; }
$currentPage = get_query_var('rto_page', 'dashboard');
$company     = get_option('rtoflow_company_name', 'RTOFLOW');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc_html($pageTitle ?? ucfirst($currentPage)) ?> — <?= esc_html($company) ?> Vendor</title>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/public.css')) ?>">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">var rtoflowVendor={ajax_url:'<?= esc_js(admin_url("admin-ajax.php")) ?>',nonce:'<?= esc_js(wp_create_nonce("rto_vendor_nonce")) ?>'};</script>
<?php // ENTERPRISE GAP FIX (Phase 5, item 3 — PWA / offline support) ?>
<link rel="manifest" href="<?= esc_url(home_url('/rto-vendor-manifest.json')) ?>">
<meta name="theme-color" content="#1E3A5F">
</head>
<body class="rto-client-body">
<div class="rto-client-wrap">
  <header class="rto-client-header">
    <div class="rto-client-header-inner">
      <a href="<?= esc_url(home_url('/')) ?>" class="rto-client-logo"><?= esc_html($company) ?> <span style="font-size:11px;opacity:.7">Vendor</span></a>
      <nav class="rto-client-nav" aria-label="Vendor navigation">
        <?php
        $navLinks = [
          ['url'=>home_url('/rto-vendor/'),          'label'=>'Dashboard', 'page'=>'dashboard'],
          ['url'=>home_url('/rto-vendor/jobs/'),     'label'=>'My Jobs',   'page'=>'jobs'],
          ['url'=>home_url('/rto-vendor/earnings/'), 'label'=>'Earnings',  'page'=>'earnings'],
          // ENTERPRISE GAP FIX (Phase 5, item 1 — vendor leaderboard)
          ['url'=>home_url('/rto-vendor/leaderboard/'), 'label'=>'Leaderboard', 'page'=>'leaderboard'],
          ['url'=>home_url('/rto-vendor/profile/'),  'label'=>'Profile',   'page'=>'profile'],
        ];
        foreach ($navLinks as $link):
        ?>
        <a href="<?= esc_url($link['url']) ?>"
           class="<?= $currentPage===$link['page']?'active':'' ?>"
           <?= $currentPage===$link['page']?'aria-current="page"':'' ?>>
          <?= esc_html($link['label']) ?>
        </a>
        <?php endforeach; ?>
        <span class="rto-client-user"><?= esc_html(wp_get_current_user()->display_name) ?></span>
        <a href="<?= esc_url(rto_logout_url()) ?>" class="rto-logout-link">Logout</a>
      </nav>
    </div>
  </header>
  <?php
    // MOBILE APP-SHELL: fixed bottom nav for the vendor dashboard, mirroring
    // the top nav's own $navLinks array 1:1 (only 4 items, so every one
    // gets a tab — unlike Admin/Client there's no "More" overflow needed).
    $bottomIcons = ['dashboard'=>'🏠','jobs'=>'🧰','earnings'=>'💰','profile'=>'👤'];
  ?>
  <nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="vendor">
    <?php foreach ($navLinks as $item): $active = $currentPage === $item['page'] ? 'active' : ''; ?>
    <a href="<?= esc_url($item['url']) ?>" class="rto-bn-item <?= $active ?>" data-rto-page="<?= esc_attr($item['page']) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
      <span class="rto-bn-icon" aria-hidden="true"><?= $bottomIcons[$item['page']] ?? '•' ?></span>
      <span class="rto-bn-label"><?= esc_html($item['label'] === 'My Jobs' ? 'Jobs' : $item['label']) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <main class="rto-client-main" id="rtoContentRegion" data-rto-page="<?= esc_attr($currentPage) ?>">
