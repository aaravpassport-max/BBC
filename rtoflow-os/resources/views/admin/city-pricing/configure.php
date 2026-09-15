<?php
/**
 * City Service Config — Configure individual city
 * @var array  $city
 * @var array  $services
 * @var array  $configs      keyed by service_id
 * @var array  $byCategory   services grouped by category
 * @var bool   $saved
 */
if (!defined('ABSPATH')) exit;
?>

<div class="rto-page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
  <div>
    <a href="<?= esc_url(home_url('/rto-admin/city-pricing/')) ?>" class="rto-back-link">← All Cities</a>
    <h1 class="rto-page-title">⚙ Configure: <?= esc_html($city['name']) ?></h1>
    <p class="rto-muted" style="margin:4px 0 0">
      <?= esc_html($city['state_name'] ?? '') ?> &nbsp;|&nbsp;
      Changes here only affect <strong><?= esc_html($city['name']) ?></strong>.
    </p>
  </div>
  <div style="display:flex;gap:8px">
    <button type="button" class="rto-btn rto-btn-outline" id="showAllBtn">
      Show All Services
    </button>
    <button type="button" class="rto-btn rto-btn-outline" id="hideAllBtn">
      Hide All
    </button>
  </div>
</div>

<?php if ($saved): ?>
<div class="rto-msg rto-msg-success rto-mb-4" role="alert">
  ✅ Configuration saved successfully for <strong><?= esc_html($city['name']) ?></strong>.
</div>
<?php endif; ?>

<!-- Legend -->
<div class="rto-card rto-mb-4" style="background:#f8fafc">
  <div class="rto-card-body" style="padding:14px 18px">
    <div style="display:flex;flex-wrap:wrap;gap:20px;font-size:13px">
      <div><strong>Service Visible</strong> — service appears on the <?= esc_html($city['name']) ?> page</div>
      <div><strong>Show Price</strong> — price is displayed (requires service to be visible)</div>
      <div><strong>Govt Fee</strong> — city-specific government fee (blank = use service default)</div>
      <div><strong>Service Charge</strong> — your charge for this city (blank = use service default)</div>
    </div>
  </div>
</div>

<form method="POST" id="configForm">
  <?php wp_nonce_field('rtoflow_city_pricing_' . (int)$city['id'], '_rto_nonce'); ?>

  <?php if (empty($services)): ?>
  <div class="rto-card">
    <div class="rto-empty-state">
      <div class="rto-empty-icon">🛠</div>
      <p>No active services found. <a href="<?= esc_url(home_url('/rto-admin/services/')) ?>">Add services first.</a></p>
    </div>
  </div>
  <?php else: ?>

  <?php foreach ($byCategory as $category => $catServices): ?>
  <div class="rto-card rto-mb-4">
    <div class="rto-card-header" style="display:flex;justify-content:space-between;align-items:center">
      <h3><?= esc_html(ucwords(str_replace('_', ' ', $category))) ?></h3>
      <span class="rto-muted rto-small"><?= count($catServices) ?> services</span>
    </div>
    <div class="rto-card-body" style="padding:0">

      <!-- Table header -->
      <div style="display:grid;grid-template-columns:1fr 110px 110px 150px 150px;gap:0;background:#f1f5f9;padding:8px 16px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase">
        <div>Service</div>
        <div style="text-align:center">Visible</div>
        <div style="text-align:center">Show Price</div>
        <div style="text-align:center">Govt Fee (₹)<?= rto_field_tooltip('Overrides this service\'s default government fee for this city only. Leave blank to use the service\'s default fee — a blank does not mean ₹0, it means "inherit the default."') ?></div>
        <div style="text-align:center">Service Charge (₹)<?= rto_field_tooltip('Overrides this service\'s default charge (your markup) for this city only. Leave blank to use the service\'s default charge. The price shown to the customer is Govt Fee + Service Charge — this field alone is not the full price.') ?></div>
      </div>

      <?php foreach ($catServices as $svc):
        $svcId  = (int)$svc['id'];
        $cfg    = $configs[$svcId] ?? [];
        $isVis  = !empty($cfg['is_visible'])  ? 1 : 0;
        $showPr = !empty($cfg['show_price'])   ? 1 : 0;
        $gFee   = $cfg['govt_fee']       ?? '';
        $sChg   = $cfg['service_charge'] ?? '';
        $notes  = $cfg['notes']          ?? '';
        $rowId  = 'svc_' . $svcId;
      ?>
      <div class="rto-config-row <?= $isVis ? 'row-visible' : 'row-hidden' ?>"
           id="<?= esc_attr($rowId) ?>"
           style="display:grid;grid-template-columns:1fr 110px 110px 150px 150px;gap:0;padding:12px 16px;border-top:1px solid #e2e8f0;align-items:center">

        <!-- Service name + defaults -->
        <div>
          <div style="font-weight:600;font-size:14px"><?= esc_html($svc['name']) ?></div>
          <div class="rto-muted rto-small">
            Default: Govt ₹<?= number_format((float)$svc['govt_fee'], 0) ?>
            + Charge ₹<?= number_format((float)$svc['base_price'], 0) ?>
            = ₹<?= number_format((float)$svc['govt_fee'] + (float)$svc['base_price'], 0) ?>
          </div>
          <?php if ($svc['description']): ?>
          <div class="rto-muted" style="font-size:11px;margin-top:2px"><?= esc_html(substr($svc['description'], 0, 80)) ?></div>
          <?php endif; ?>
        </div>

        <!-- Service Visible toggle -->
        <div style="text-align:center">
          <label class="rto-toggle" title="Show this service on <?= esc_attr($city['name']) ?> page">
            <input type="checkbox" name="services[<?= $svcId ?>][is_visible]" value="1"
                   <?= $isVis ? 'checked' : '' ?>
                   class="rto-vis-toggle" data-svc-id="<?= $svcId ?>"
                   id="vis_<?= $svcId ?>">
            <span class="rto-toggle-slider"></span>
          </label>
        </div>

        <!-- Show Price toggle -->
        <div style="text-align:center">
          <label class="rto-toggle" title="Show price for this service in <?= esc_attr($city['name']) ?>">
            <input type="checkbox" name="services[<?= $svcId ?>][show_price]" value="1"
                   <?= $showPr ? 'checked' : '' ?>
                   <?= !$isVis ? 'disabled' : '' ?>
                   id="price_<?= $svcId ?>">
            <span class="rto-toggle-slider <?= !$isVis ? 'rto-toggle-disabled' : '' ?>"></span>
          </label>
        </div>

        <!-- Govt Fee override -->
        <div style="text-align:center">
          <input type="number" name="services[<?= $svcId ?>][govt_fee]"
                 class="rto-input rto-input-sm" style="width:110px;text-align:right"
                 value="<?= esc_attr($gFee) ?>"
                 placeholder="Default: <?= number_format((float)$svc['govt_fee'], 0) ?>"
                 min="0" step="0.01">
        </div>

        <!-- Service Charge override -->
        <div style="text-align:center">
          <input type="number" name="services[<?= $svcId ?>][service_charge]"
                 class="rto-input rto-input-sm" style="width:110px;text-align:right"
                 value="<?= esc_attr($sChg) ?>"
                 placeholder="Default: <?= number_format((float)$svc['base_price'], 0) ?>"
                 min="0" step="0.01">
          <!-- Hidden notes field -->
          <input type="hidden" name="services[<?= $svcId ?>][notes]" value="<?= esc_attr($notes) ?>">
        </div>

      </div>
      <?php endforeach; ?>

    </div>
  </div>
  <?php endforeach; ?>

  <!-- Save bar -->
  <div style="position:sticky;bottom:0;background:#fff;border-top:2px solid #e2e8f0;padding:16px;display:flex;justify-content:space-between;align-items:center;z-index:10">
    <div class="rto-muted rto-small">
      Changes are isolated to <strong><?= esc_html($city['name']) ?></strong> only.
    </div>
    <div style="display:flex;gap:10px">
      <a href="<?= esc_url(home_url('/rto-admin/city-pricing/')) ?>" class="rto-btn rto-btn-outline">
        Cancel
      </a>
      <button type="submit" class="rto-btn rto-btn-primary" id="saveConfigBtn">
        💾 Save Configuration
      </button>
    </div>
  </div>

  <?php endif; ?>
</form>

<?php
// FIX (Config Versioning wiring, City/Service Pricing): every save via
// this form now records a version keyed to this city
// (CityServiceConfigController::saveConfig()), and rollback genuinely
// re-applies that city's pricing config (applyVersionedPayload()) rather
// than only rewriting history — see Bootstrap.php's
// 'rtoflow_config_published' listener.
$configVersionKey = 'city_pricing_' . (int)$city['id'];
require RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>

<!-- Part 5.2 UI/UX audit fix: this file previously carried its OWN copy of
     .rto-toggle's CSS, with a width (44px), slider color (#cbd5e1), and
     checked-color (var(--rto-accent, #16a34a) — note --rto-accent was never
     actually defined anywhere in this codebase, so this always silently
     fell back to the literal #16a34a) that had already drifted from the
     near-identical copy in services/index.php (40px, #ccc, var(--green)).
     This is precisely the "component-specific CSS drifting" risk flagged
     in admin.css's own comment on the now-centralized .rto-toggle rule —
     it had already happened here, not just a theoretical risk. Removed in
     favor of the single centralized definition; only this screen's own
     row-state and hover styling (genuinely specific to City Pricing) stays
     local. -->
<style>
.row-visible { background: #fff; }
.row-hidden  { background: #fafafa; opacity: .7; }
.rto-config-row:hover { background: #f8fafc; }
</style>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
function onVisibilityChange(svcId) {
  var visEl   = document.getElementById('vis_' + svcId);
  var priceEl = document.getElementById('price_' + svcId);
  var row     = document.getElementById('svc_' + svcId);

  if (!visEl || !priceEl) return;

  var isVisible = visEl.checked;

  // If service is hidden, price must also be hidden
  if (!isVisible) {
    priceEl.checked  = false;
    priceEl.disabled = true;
    if (priceEl.nextElementSibling) priceEl.nextElementSibling.classList.add('rto-toggle-disabled');
  } else {
    priceEl.disabled = false;
    if (priceEl.nextElementSibling) priceEl.nextElementSibling.classList.remove('rto-toggle-disabled');
  }

  if (row) {
    row.className = row.className.replace(/row-visible|row-hidden/g, '').trim();
    row.classList.add(isVisible ? 'row-visible' : 'row-hidden');
  }
}

function toggleAllVisible(state) {
  document.querySelectorAll('[name$="[is_visible]"]').forEach(function(cb) {
    var svcId = cb.name.match(/\[(\d+)\]/)[1];
    cb.checked = (state === 1);
    onVisibilityChange(parseInt(svcId));
  });
}

// Submit protection
document.getElementById('configForm').addEventListener('submit', function() {
  var btn = document.getElementById('saveConfigBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
});

document.querySelectorAll('.rto-vis-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() { onVisibilityChange(parseInt(cb.dataset.svcId, 10)); });
});

var showAllBtnEl = document.getElementById('showAllBtn');
if (showAllBtnEl) showAllBtnEl.addEventListener('click', function() { toggleAllVisible(1); });
var hideAllBtnEl = document.getElementById('hideAllBtn');
if (hideAllBtnEl) hideAllBtnEl.addEventListener('click', function() { toggleAllVisible(0); });
</script>
