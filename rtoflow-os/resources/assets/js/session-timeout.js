/**
 * ENTERPRISE GAP FIX (Phase 5, item 6 — "session-timeout warning banner"):
 * neither the client nor vendor portal warned before a session silently
 * expired mid-task. This is a client-side IDLE-inactivity timer, not a
 * readout of the real WP auth-cookie expiry (that value isn't exposed to
 * client-side JS and this codebase has no separate server session to poll —
 * see Bootstrap/TwoFactor's own comments on 2FA state living in a
 * Redis-backed cache, not PHP sessions). Scope: warns after
 * RTOFLOW_IDLE.warnAfterMs of no mouse/keyboard/touch/scroll activity, then
 * auto-redirects to the configured logout URL after warnAfterMs+graceMs
 * total idle time unless the user clicks "Stay signed in" (which just
 * resets this timer — it does not and cannot extend the actual WP auth
 * cookie server-side).
 */
(function () {
  if (typeof window.RTOFLOW_IDLE === 'undefined') return;

  var cfg = window.RTOFLOW_IDLE;
  var warnAfterMs = cfg.warnAfterMs || (25 * 60 * 1000);
  var graceMs     = cfg.graceMs || (5 * 60 * 1000);
  var logoutUrl   = cfg.logoutUrl || '/';

  var warnTimer = null, logoutTimer = null, countdownInterval = null;
  var banner = null;

  function buildBanner() {
    if (banner) return banner;
    banner = document.createElement('div');
    banner.id = 'rtoIdleBanner';
    banner.setAttribute('role', 'alert');
    banner.style.cssText = 'display:none;position:fixed;bottom:0;left:0;right:0;z-index:10000;' +
      'background:#1E3A5F;color:#fff;padding:14px 16px;text-align:center;font-size:14px;' +
      'box-shadow:0 -2px 10px rgba(0,0,0,.15);';
    banner.innerHTML =
      '<span id="rtoIdleMsg">You’ve been inactive. For your security, you’ll be signed out in <strong id="rtoIdleCountdown"></strong>.</span> ' +
      '<button type="button" id="rtoIdleStayBtn" style="margin-left:12px;background:#E97B28;color:#fff;border:0;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:14px">Stay Signed In</button>';
    document.body.appendChild(banner);
    document.getElementById('rtoIdleStayBtn').addEventListener('click', resetTimers);
    return banner;
  }

  function showWarning() {
    var b = buildBanner();
    b.style.display = 'block';
    var remaining = graceMs;
    var countdownEl = document.getElementById('rtoIdleCountdown');
    function tick() {
      var mins = Math.floor(remaining / 60000);
      var secs = Math.floor((remaining % 60000) / 1000);
      countdownEl.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
      remaining -= 1000;
      if (remaining < 0) { clearInterval(countdownInterval); }
    }
    tick();
    countdownInterval = setInterval(tick, 1000);
    logoutTimer = setTimeout(function () {
      window.location.href = logoutUrl;
    }, graceMs);
  }

  function hideWarning() {
    if (banner) banner.style.display = 'none';
    if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
  }

  function resetTimers() {
    hideWarning();
    if (warnTimer) clearTimeout(warnTimer);
    if (logoutTimer) clearTimeout(logoutTimer);
    warnTimer = setTimeout(showWarning, warnAfterMs);
  }

  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(function (evt) {
    document.addEventListener(evt, resetTimers, { passive: true });
  });

  resetTimers();
})();
