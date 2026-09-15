<?php
$ajaxUrl = admin_url('admin-ajax.php');
?>
  </main>
  <footer class="rto-client-footer">
    <p><?= esc_html(get_option('rtoflow_company_name','RTOFLOW')) ?> · All rights reserved</p>
  </footer>
</div>
<?php require RTOFLOW_DIR . 'resources/views/partials/cookie-consent.php'; ?>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<?php // ENTERPRISE GAP FIX (Phase 5, item 6 — "session-timeout warning banner") ?>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">window.RTOFLOW_IDLE = { warnAfterMs: 25*60*1000, graceMs: 5*60*1000, logoutUrl: <?= json_encode(rto_logout_url()) ?> };</script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/session-timeout.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/session-timeout.js')) ?>"></script>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(<?= json_encode(home_url('/rto-sw.js')) ?>).catch(function(){ /* offline shell is a progressive enhancement, never block the page on it */ });
  });
}
</script>
</body>
</html>
