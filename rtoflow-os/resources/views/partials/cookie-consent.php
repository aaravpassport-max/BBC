<?php if (!defined('ABSPATH')) exit;
/**
 * ENTERPRISE GAP FIX (Phase 9, item — "no cookie-consent / tracking-consent
 * banner"): site-wide, revocable, auditable consent banner for
 * analytics/marketing tracking scripts (necessary cookies — session, auth,
 * nonce — are never gated by this; they aren't a consent choice under
 * GDPR/DPDP). See ConsentService for the server-side record and
 * resources/assets/js/cookie-consent.js for the client behaviour: it shows
 * the banner only when no decision is stored yet (cookie + localStorage),
 * posts the decision to Router::saveConsent() on Accept All / Reject
 * Non-Essential / Save Preferences, and exposes window.rtoConsent.has(cat)
 * so any future analytics snippet can check consent before it loads a
 * tracking script.
 */
?>
<div id="rto-cookie-consent" class="rto-cookie-consent" hidden role="dialog" aria-live="polite" aria-label="Cookie preferences">
  <div class="rto-cookie-consent__inner">
    <div class="rto-cookie-consent__text">
      <p>
        We use necessary cookies to run this site, and — only with your permission —
        analytics and marketing cookies to understand usage and improve our services.
        See our <a href="<?= home_url('/privacy-policy') ?>">Privacy Policy</a>.
      </p>
    </div>
    <div class="rto-cookie-consent__prefs" id="rto-cookie-consent__prefs" hidden>
      <label><input type="checkbox" checked disabled> Necessary (always on)</label>
      <label><input type="checkbox" id="rto-consent-analytics"> Analytics</label>
      <label><input type="checkbox" id="rto-consent-marketing"> Marketing</label>
    </div>
    <div class="rto-cookie-consent__actions">
      <button type="button" id="rto-consent-customize" class="rto-cookie-consent__btn rto-cookie-consent__btn--ghost">Customize</button>
      <button type="button" id="rto-consent-reject" class="rto-cookie-consent__btn rto-cookie-consent__btn--ghost">Reject Non-Essential</button>
      <button type="button" id="rto-consent-save" class="rto-cookie-consent__btn rto-cookie-consent__btn--ghost" hidden>Save Preferences</button>
      <button type="button" id="rto-consent-accept" class="rto-cookie-consent__btn rto-cookie-consent__btn--primary">Accept All</button>
    </div>
  </div>
</div>
<style>
.rto-cookie-consent{position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#0f172a;color:#e2e8f0;box-shadow:0 -2px 12px rgba(0,0,0,.25)}
.rto-cookie-consent[hidden]{display:none!important}
.rto-cookie-consent__inner{max-width:1100px;margin:0 auto;padding:14px 18px;display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px}
.rto-cookie-consent__text{flex:1 1 320px;font-size:13px;line-height:1.5}
.rto-cookie-consent__text a{color:#7dd3fc}
.rto-cookie-consent__prefs{flex:1 1 100%;display:flex;gap:16px;font-size:13px;flex-wrap:wrap}
.rto-cookie-consent__prefs label{display:flex;align-items:center;gap:6px}
.rto-cookie-consent__actions{display:flex;gap:8px;flex-wrap:wrap}
.rto-cookie-consent__btn{border:1px solid #475569;background:transparent;color:#e2e8f0;padding:8px 14px;border-radius:6px;font-size:13px;cursor:pointer}
.rto-cookie-consent__btn--primary{background:#2563eb;border-color:#2563eb;color:#fff}
.rto-cookie-consent__btn--ghost:hover{background:#1e293b}
@media (max-width:600px){.rto-cookie-consent__actions{width:100%}.rto-cookie-consent__btn{flex:1}}
</style>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
window.RTOFLOW_CONSENT_AJAX = {
  url: (window.rtoflow && window.rtoflow.ajax_url) ? window.rtoflow.ajax_url : <?= json_encode(admin_url('admin-ajax.php')) ?>
};
</script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/cookie-consent.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/cookie-consent.js')) ?>" nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>"></script>
