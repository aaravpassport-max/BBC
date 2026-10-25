/**
 * Replaces infinite splash with actionable errors (external — not blocked by CSP).
 */
(function () {
  'use strict';

  function showFail(msg) {
    var root = document.getElementById('s2nri-root');
    if (!root || !root.querySelector('.s2nri-splash')) return;
    var err = (window.S2NRI_CONFIG && window.S2NRI_CONFIG.bootError) || '';
    var ver =
      (window.S2NRI_BOOT && window.S2NRI_BOOT.pluginVersion) ||
      (root.dataset && root.dataset.s2nriVersion) ||
      '?';
    var stamp = (root.dataset && root.dataset.s2nriBuild) || '';
    root.innerHTML =
      '<div style="padding:48px 24px;text-align:center;font-family:system-ui,sans-serif;max-width:640px;margin:0 auto">' +
      '<p style="font-weight:700;color:#1e293b;font-size:18px">App did not start</p>' +
      '<p style="color:#64748b;font-size:14px;line-height:1.5">' +
      (msg ||
        'JavaScript failed to load. Reinstall services2nri-full-source.zip and purge CDN cache.') +
      '</p>' +
      (err
        ? '<pre style="text-align:left;font-size:11px;background:#f1f5f9;padding:12px;border-radius:8px;overflow:auto;max-height:160px">' +
          String(err).replace(/</g, '&lt;') +
          '</pre>'
        : '') +
      '<p style="font-size:12px;color:#94a3b8">Plugin v' +
      String(ver).replace(/</g, '') +
      (stamp ? ' · build ' + String(stamp).replace(/</g, '') : '') +
      '</p>' +
      '<p style="font-size:12px;color:#64748b">Check <code>/?s2nri_boot_diag=1</code> on your site for asset status.</p>' +
      '<button type="button" onclick="location.reload()" style="margin-top:12px;padding:10px 20px;border-radius:8px;border:none;background:#4A6FA5;color:#fff;font-weight:600;cursor:pointer">Reload</button></div>';
  }

  if (!window.S2NRI_CONFIG) {
    showFail('S2NRI_CONFIG missing — boot-config.js did not run (blocked script or wrong HTML order).');
  } else if (window.S2NRI_CONFIG.bootError) {
    showFail(String(window.S2NRI_CONFIG.bootError));
  }

  var t = setTimeout(function () {
    showFail('Loading timed out after 8 seconds.');
  }, 8000);

  window.addEventListener(
    's2nri-app-mounted',
    function () {
      clearTimeout(t);
    },
    { once: true }
  );

  window.addEventListener(
    'error',
    function (e) {
      var m = e.message || '';
      if (m.indexOf('does not provide an export named') !== -1) {
        showFail(
          'Mixed JS files (CDN or partial upload). Install v4.7.22+ zip, purge CDN for wp-content/plugins/services2nri/assets/release/*, then hard refresh.'
        );
        return;
      }
      if (
        e.filename &&
        (e.filename.indexOf('app.js') !== -1 || e.filename.indexOf('booking.js') !== -1)
      ) {
        showFail(m || 'app.js / chunk load error');
      }
    },
    true
  );

  window.addEventListener('unhandledrejection', function (e) {
    showFail(String((e.reason && e.reason.message) || e.reason || 'Module load failed'));
  });

  var mod = document.querySelector('script[type=module][src*="app.js"]');
  if (mod) {
    mod.addEventListener('error', function () {
      showFail(
        'app.js failed to load (404, CDN block, or mixed assets). In Network tab, app.js and chunks/booking.js must share the same ?v= build stamp.'
      );
    });
  }
})();
