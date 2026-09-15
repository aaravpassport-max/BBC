  </main>
  <footer class="rto-client-footer">
    <p><?= esc_html(get_option('rtoflow_company_name','RTOFLOW')) ?> Vendor Portal</p>
  </footer>
</div>
<?php require RTOFLOW_DIR . 'resources/views/partials/cookie-consent.php'; ?>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
function rtoToast(msg,type){
  var d=document.createElement('div');
  // FIX (mobile audit): bottom:20px;right:20px sat directly under/behind
  // the new fixed bottom nav on mobile, hiding both the toast and whatever
  // nav item was underneath it. Bumped clear of the bottom nav's height +
  // safe-area inset (see mobile-nav.css --rto-bn-height/--rto-bn-safe-bottom)
  // on narrow screens; desktop keeps the original 20px position.
  var isMobile = window.matchMedia('(max-width:782px)').matches;
  d.style.cssText='position:fixed;'+(isMobile?'bottom:76px;left:16px;right:16px;':'bottom:20px;right:20px;max-width:340px;')+'padding:12px 20px;border-radius:6px;color:#fff;font-size:14px;z-index:9999;background:'+(type==='error'?'#dc2626':'#16a34a');
  d.textContent=msg;document.body.appendChild(d);setTimeout(function(){d.remove();},3500);
}
window.rtoToast=rtoToast;
</script>
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
