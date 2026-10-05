/**
 * Playwright-only: merge window.__S2NRI_E2E_USER__ into S2NRI_CONFIG after boot-config.js.
 */
(function () {
  if (typeof window.__S2NRI_E2E_USER__ === 'undefined' || !window.S2NRI_CONFIG) return
  window.S2NRI_CONFIG.currentUser = window.__S2NRI_E2E_USER__
})()
