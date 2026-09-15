/**
 * ENTERPRISE GAP FIX (Phase 9, item — "no cookie-consent / tracking-consent
 * banner"): drives resources/views/partials/cookie-consent.php. Shows the
 * banner only when no local decision is stored yet; on Accept All / Reject
 * Non-Essential / Save Preferences it stores the decision locally (so the
 * banner does not reappear on the next page view) AND posts it to
 * Router::saveConsent() so there is a real, revocable, server-side record
 * (ConsentService) — the local copy alone is not an audit trail.
 *
 * Any future analytics/marketing snippet should gate itself on
 * window.rtoConsent.has('analytics') / .has('marketing') instead of loading
 * unconditionally.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'rto_consent_v1';
  var COOKIE_NAME = 'rto_consent_token';

  function readCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function writeCookie(name, value, days) {
    var expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
  }

  function readLocal() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }

  function writeLocal(decision) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(decision)); } catch (e) { /* private mode etc. — banner may reappear next visit, acceptable degradation */ }
  }

  function getToken() {
    var t = readCookie(COOKIE_NAME);
    if (t) return t;
    t = 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    return t;
  }

  function post(decision) {
    var ajax = window.RTOFLOW_CONSENT_AJAX;
    if (!ajax || !ajax.url) return;
    var body = new URLSearchParams();
    body.set('rto_area', 'public');
    body.set('rto_action', 'save_consent');
    body.set('token', decision.token);
    body.set('analytics', decision.analytics ? '1' : '0');
    body.set('marketing', decision.marketing ? '1' : '0');
    // Fire-and-forget: the local decision already governs this session's
    // behaviour immediately — the server call is the audit record, not a
    // gate the visitor should ever be blocked on.
    fetch(ajax.url, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () { /* recorded locally regardless; non-fatal */ });
  }

  function applyDecision(decision) {
    window.rtoConsent = {
      analytics: !!decision.analytics,
      marketing: !!decision.marketing,
      has: function (category) { return !!decision[category]; }
    };
    document.dispatchEvent(new CustomEvent('rto:consent-changed', { detail: decision }));
  }

  function save(analytics, marketing) {
    var token = getToken();
    var decision = { token: token, analytics: analytics, marketing: marketing, ts: Date.now() };
    writeCookie(COOKIE_NAME, token, 365);
    writeLocal(decision);
    applyDecision(decision);
    post(decision);
    hide();
  }

  var banner;
  function hide() { if (banner) banner.hidden = true; }
  function show() { if (banner) banner.hidden = false; }

  document.addEventListener('DOMContentLoaded', function () {
    banner = document.getElementById('rto-cookie-consent');
    if (!banner) return;

    var stored = readLocal();
    if (stored) {
      applyDecision(stored);
      return; // decision already made — banner stays hidden
    }

    // No local decision on file yet — always start from "nothing granted"
    // so any analytics/marketing snippet stays off until an explicit
    // choice is made, then show the banner.
    applyDecision({ analytics: false, marketing: false });
    show();

    var prefs      = document.getElementById('rto-cookie-consent__prefs');
    var customizeBtn = document.getElementById('rto-consent-customize');
    var saveBtn     = document.getElementById('rto-consent-save');
    var acceptBtn   = document.getElementById('rto-consent-accept');
    var rejectBtn   = document.getElementById('rto-consent-reject');
    var analyticsCb = document.getElementById('rto-consent-analytics');
    var marketingCb = document.getElementById('rto-consent-marketing');

    if (customizeBtn) customizeBtn.addEventListener('click', function () {
      prefs.hidden = false;
      customizeBtn.hidden = true;
      rejectBtn.hidden = true;
      saveBtn.hidden = false;
    });

    if (acceptBtn) acceptBtn.addEventListener('click', function () { save(true, true); });
    if (rejectBtn) rejectBtn.addEventListener('click', function () { save(false, false); });
    if (saveBtn) saveBtn.addEventListener('click', function () {
      save(!!(analyticsCb && analyticsCb.checked), !!(marketingCb && marketingCb.checked));
    });
  });
})();
