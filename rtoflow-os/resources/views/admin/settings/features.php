<?php
if (!defined('ABSPATH')) exit;
$pageTitle = 'Feature Flags';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
use RTOFLOW\Config\FeatureFlags;
$flags = FeatureFlags::all();
$nonce = wp_create_nonce('rtoflow_features');
?>
<div class="rto-page-header">
  <div>
    <h2>Feature Flags</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">Enable or disable modules without touching code. Core modules cannot be disabled.</p>
  </div>
</div>

<?php if (!empty($_GET['saved'])): ?>
<div style="background:#DCFCE7;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600">✅ Feature flags saved successfully.</div>
<?php endif; ?>

<form method="POST" id="rto-features-form">
  <?php wp_nonce_field('rtoflow_features','_rto_nonce') ?>
  <input type="hidden" name="rto_action" value="save_features">
  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px">
    <?php foreach ($flags as $key => $flag): ?>
    <div style="background:#fff;border:1px solid <?= $flag['core']?'#BFDBFE':'#e2e8f0' ?>;border-radius:10px;padding:16px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
      <div style="flex:1">
        <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:2px">
          <?= esc_html($flag['label']) ?>
          <?php if ($flag['core']): ?><span style="background:#DBEAFE;color:#1d4ed8;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:6px">Core</span><?php endif; ?>
          <?php if (empty($flag['wired'])): ?><span title="Defined in code but no functionality currently reads this flag — toggling it has no effect." style="background:#FEF3C7;color:#92400e;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:6px">Not yet wired</span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:#64748b"><?= esc_html($flag['description']) ?></div>
      </div>
      <label style="position:relative;display:inline-flex;align-items:center;cursor:<?= $flag['core']?'default':'pointer' ?>;flex-shrink:0;gap:8px">
        <input type="checkbox" name="flags[<?= esc_attr($key) ?>]" value="1"
               aria-label="Toggle feature: <?= esc_attr($flag['label']) ?>"
               data-label="<?= esc_attr($flag['label']) ?>"
               data-was="<?= $flag['enabled'] ? '1' : '0' ?>"
               class="rto-flag-checkbox"
               <?= $flag['enabled'] ? 'checked' : '' ?>
               <?= $flag['core'] ? 'disabled' : '' ?>>
        <span style="font-size:12px;font-weight:600;color:<?= $flag['enabled']?'#16a34a':'#64748b' ?>"><?= $flag['enabled']?'ON':'OFF' ?></span>
      </label>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="padding-top:16px;border-top:1px solid #e2e8f0">
    <button type="submit" class="rto-btn rto-btn--primary">Save Feature Flags</button>
  </div>
</form>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
// FIX (Known Limitations: "No confirmation or change-summary is shown
// before a multi-flag save is applied"). Purely client-side: compares each
// checkbox's current state against the value it loaded with (data-was) and,
// if anything actually changed, shows an explicit "you are about to
// change: X, Y, Z" confirmation before the form submits. Cancelling leaves
// every checkbox untouched and does not submit. If nothing changed (e.g.
// re-submitting an unmodified form) it submits without interrupting.
(function () {
  var form = document.getElementById('rto-features-form');
  if (!form) return;
  // CSP fix: 'nonce-...' only covers <script> elements, not inline
  // onchange= attributes — bind via delegation on the form instead so the
  // ON/OFF label span still updates live as flags are toggled.
  form.addEventListener('change', function (e) {
    if (!e.target.classList || !e.target.classList.contains('rto-flag-checkbox')) return;
    var span = e.target.closest('label').querySelector('span');
    if (span) span.style.background = e.target.checked ? '#16a34a' : '#d1d5db';
  });
  form.addEventListener('submit', function (e) {
    var changed = [];
    form.querySelectorAll('.rto-flag-checkbox').forEach(function (cb) {
      var was = cb.getAttribute('data-was') === '1';
      if (cb.checked !== was) {
        changed.push(cb.getAttribute('data-label') + ': ' + (was ? 'ON' : 'OFF') + ' → ' + (cb.checked ? 'ON' : 'OFF'));
      }
    });
    if (changed.length === 0) return;
    var msg = 'You are about to change ' + changed.length + ' flag' + (changed.length > 1 ? 's' : '') + ':\n\n' + changed.join('\n') + '\n\nApply now?';
    if (!window.confirm(msg)) {
      e.preventDefault();
    }
  });
})();
</script>

<?php
// FIX (Config Versioning wiring, follow-up): every save now records a
// version via ConfigVersionService (see Router.php's 'features' route
// handler), and rollback genuinely re-applies a past flag state via
// FeatureFlags::applyBooleanMap() (see Bootstrap.php's
// 'rtoflow_config_published' listener) rather than only rewriting history.
$configVersionKey = 'feature_flags';
include RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>

<?php rto_help_box('features'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
