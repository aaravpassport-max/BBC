/**
 * Parses #s2nri-config-json into window.S2NRI_CONFIG (external file — works under strict CSP).
 */
(function () {
  var el = document.getElementById('s2nri-config-json');
  try {
    window.S2NRI_CONFIG = JSON.parse((el && el.textContent) || 'null');
    if (!window.S2NRI_CONFIG || typeof window.S2NRI_CONFIG !== 'object') {
      window.S2NRI_CONFIG = { bootError: 'S2NRI_CONFIG empty after parse' };
    }
  } catch (e) {
    window.S2NRI_CONFIG = { bootError: 'Config JSON parse: ' + String(e) };
  }
  window.S2NRI_BOOT = window.S2NRI_BOOT || {};
  var root = document.getElementById('s2nri-root');
  if (root && root.dataset.s2nriVersion) {
    window.S2NRI_BOOT.pluginVersion = root.dataset.s2nriVersion;
    window.S2NRI_BOOT.assetsUrl = root.dataset.s2nriAssets || '';
    window.S2NRI_BOOT.buildStamp = root.dataset.s2nriBuild || '';
  }
})();
