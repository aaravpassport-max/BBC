<?php
/**
 * City Pricing & Visibility — All Cities Overview
 * @var array $cities
 */
if (!defined('ABSPATH')) exit;
?>
<div class="rto-page-wrap">
  <div class="rto-page-header">
    <div>
      <h1 class="rto-page-title">🏙 City Pricing &amp; Service Visibility</h1>
      <p class="rto-muted" style="margin:4px 0 0">
        Configure which services are visible and whether prices are shown — independently per city.
        <strong>By default, no prices are shown anywhere.</strong>
      </p>
    </div>
  </div>

<!-- Info banner -->
<div class="rto-msg rto-msg-info rto-mb-4" style="padding:14px 16px">
  <strong>How this works:</strong>
  <ul style="margin:6px 0 0 18px;line-height:1.7">
    <li><strong>Service Visibility</strong> — controls whether a service appears on a city page.</li>
    <li><strong>Price Visibility</strong> — controls whether the price is shown (can be hidden even if service is visible).</li>
    <li><strong>Changes are isolated</strong> — editing one city never affects any other city.</li>
    <li><strong>Default = hidden</strong> — nothing is shown until you explicitly enable it.</li>
  </ul>
</div>

<!-- Cities table -->
<div class="rto-card">
  <div class="rto-card-header">
    <h3>All Active Cities <span class="rto-count"><?= count($cities) ?></span></h3>
    <input type="text" id="citySearch" class="rto-input rto-input-sm"
           placeholder="Search city…" style="max-width:220px"
           aria-label="Search cities">
  </div>
  <div class="rto-card-body rto-table-scroll">
    <?php if (empty($cities)): ?>
    <div class="rto-empty-state">
      <div class="rto-empty-icon">🏙</div>
      <p>No active cities found. Add cities in <a href="<?= esc_url(home_url('/rto-admin/masters/')) ?>">Cities / RTOs</a>.</p>
    </div>
    <?php else: ?>
    <table class="rto-table rto-table-hover" id="citiesTable" data-rto-responsive="cards">
      <thead>
        <tr>
          <th>City</th>
          <th>State</th>
          <th style="text-align:center">Configured Services</th>
          <th style="text-align:center">Visible Services</th>
          <th style="text-align:center">Priced Services</th>
          <th style="text-align:center">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cities as $city): ?>
        <tr data-name="<?= esc_attr(strtolower($city['name'])) ?>">
          <td data-label="City">
            <strong><?= esc_html($city['name']) ?></strong>
          </td>
          <td data-label="State" class="rto-muted"><?= esc_html($city['state_name'] ?? '—') ?></td>
          <td data-label="Configured Services" style="text-align:center">
            <?php if ((int)$city['configured_services'] > 0): ?>
            <span class="rto-badge rto-badge-secondary"><?= (int)$city['configured_services'] ?></span>
            <?php else: ?>
            <span class="rto-muted">—</span>
            <?php endif; ?>
          </td>
          <td data-label="Visible Services" style="text-align:center">
            <?php if ((int)$city['visible_services'] > 0): ?>
            <span class="rto-badge rto-badge-success"><?= (int)$city['visible_services'] ?> visible</span>
            <?php else: ?>
            <span class="rto-badge rto-badge-secondary">Hidden</span>
            <?php endif; ?>
          </td>
          <td data-label="Priced Services" style="text-align:center">
            <?php if ((int)$city['priced_services'] > 0): ?>
            <span class="rto-badge rto-badge-warning"><?= (int)$city['priced_services'] ?> priced</span>
            <?php else: ?>
            <span class="rto-muted">No prices shown</span>
            <?php endif; ?>
          </td>
          <td data-label="Action" style="text-align:center">
            <a href="<?= esc_url(home_url('/rto-admin/city-pricing/' . (int)$city['id'] . '/')) ?>"
               class="rto-btn rto-btn-sm rto-btn-primary">
              Configure →
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
function filterCities(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#citiesTable tbody tr').forEach(function(tr) {
    tr.style.display = (!q || tr.dataset.name.includes(q)) ? '' : 'none';
  });
}
var citySearchEl = document.getElementById('citySearch');
if (citySearchEl) {
  citySearchEl.addEventListener('input', function() { filterCities(this.value); });
}
</script>

<?php rto_help_box('city-pricing'); ?>
