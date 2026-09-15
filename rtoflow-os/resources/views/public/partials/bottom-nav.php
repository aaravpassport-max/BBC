<?php if (!defined('ABSPATH')) exit;
/**
 * Shared mobile bottom-nav partial for the standalone public pages that
 * build their own <html>/<head>/<body> instead of going through
 * layouts/website-header.php (home.php, apply.php, apply-dynamic.php,
 * track.php, rto-login.php). Kept as one file so the tab set / icons /
 * active-state logic only needs to be defined once and stays identical
 * to the version wired into layouts/website-header.php.
 *
 * Usage: <?php require RTOFLOW_DIR . 'resources/views/public/partials/bottom-nav.php'; ?>
 * right after the page's opening <body> tag (or its own <header>, if it
 * has one) — no variables need to be passed in.
 */
$rtoBnCurrent = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$rtoBnActive = [
    'home'     => ($rtoBnCurrent === '/' || $rtoBnCurrent === ''),
    'services' => str_starts_with($rtoBnCurrent, '/rto-service'),
    'track'    => str_starts_with($rtoBnCurrent, '/rto-track'),
    'apply'    => str_starts_with($rtoBnCurrent, '/rto-apply'),
];
$rtoBnLoggedIn = is_user_logged_in();
if (!function_exists('rtoflow_portal_url_for_user')) {
    function rtoflow_portal_url_for_user(): string {
        if (rto_is_admin() || rto_is_staff())  return '/rto-admin/';
        if (rto_is_vendor())                   return '/rto-vendor/';
        return '/rto-dashboard/';
    }
}
?>
<nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="website">
  <a href="<?= home_url('/') ?>" class="rto-bn-item <?= $rtoBnActive['home'] ? 'active' : '' ?>" data-rto-page="home" <?= $rtoBnActive['home'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9a1 1 0 0 0 1 1H9a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h2.5a1 1 0 0 0 1-1v-9"/></svg>
    </span>
    <span class="rto-bn-label">Home</span>
  </a>
  <a href="<?= home_url('/rto-service/all') ?>" class="rto-bn-item <?= $rtoBnActive['services'] ? 'active' : '' ?>" data-rto-page="services" <?= $rtoBnActive['services'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></svg>
    </span>
    <span class="rto-bn-label">Services</span>
  </a>
  <a href="<?= home_url('/rto-track/') ?>" class="rto-bn-item <?= $rtoBnActive['track'] ? 'active' : '' ?>" data-rto-page="track" <?= $rtoBnActive['track'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.4 7-12a7 7 0 0 0-14 0c0 5.6 7 12 7 12Z"/><circle cx="12" cy="9" r="2.3"/></svg>
    </span>
    <span class="rto-bn-label">Track</span>
  </a>
  <a href="<?= home_url('/rto-apply/') ?>" class="rto-bn-item <?= $rtoBnActive['apply'] ? 'active' : '' ?>" data-rto-page="apply" <?= $rtoBnActive['apply'] ? 'aria-current="page"' : '' ?>>
    <span class="rto-bn-icon rto-bn-icon--apply" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
    </span>
    <span class="rto-bn-label">Apply</span>
  </a>
  <?php if ($rtoBnLoggedIn): ?>
  <a href="<?= home_url(rtoflow_portal_url_for_user()) ?>" class="rto-bn-item" data-rto-page="dashboard">
    <span class="rto-bn-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.4"/><path d="M4.8 20c1-3.6 4-5.6 7.2-5.6s6.2 2 7.2 5.6"/></svg>
    </span>
    <span class="rto-bn-label">Dashboard</span>
  </a>
  <?php else: ?>
  <a href="<?= home_url('/login') ?>" class="rto-bn-item" data-rto-page="login">
    <span class="rto-bn-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.4"/><path d="M4.8 20c1-3.6 4-5.6 7.2-5.6s6.2 2 7.2 5.6"/></svg>
    </span>
    <span class="rto-bn-label">Login</span>
  </a>
  <?php endif; ?>
</nav>
