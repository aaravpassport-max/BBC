<?php if (!defined('ABSPATH')) exit;
/** @var array $vehicles @var string|null $vehicleError @var bool $vehicleSaved
 * ENTERPRISE GAP FIX (Phase 4, item 2 — "no saved addresses or vehicle
 * registry for repeat clients"): see migration 2024_01_01_000037 for scope
 * (this registry is real and usable now; automatic Apply-form prefill is a
 * separate follow-up — the Apply form's fields are schema-driven per
 * service category, not a fixed set this table can safely assume).
 */
require RTOFLOW_DIR . 'resources/views/layouts/client-header.php';
?>
<div class="rto-page-wrap" style="max-width:640px">
  <h1 class="rto-page-title">My Vehicles &amp; Addresses</h1>

  <?php if ($vehicleSaved): ?><div class="rto-msg rto-msg-success rto-mb-4">Saved.</div><?php endif; ?>
  <?php if ($vehicleError): ?><div class="rto-msg rto-msg-error rto-mb-4"><?= esc_html($vehicleError) ?></div><?php endif; ?>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Save a Vehicle / Address</h3></div>
    <div class="rto-card-body">
      <p class="rto-small rto-muted" style="margin:0 0 12px">Keep your vehicle number and address on file so you can copy them into future service requests instead of retyping.</p>
      <form method="POST">
        <?php wp_nonce_field('rtoflow_client_vehicle', 'rtoflow_vehicle_nonce'); ?>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="vLabel">Label <span class="rto-small rto-muted">(e.g. "My Car", "Office Address")</span></label>
          <input type="text" id="vLabel" name="label" class="rto-input">
        </div>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="vNum">Vehicle Number</label>
          <input type="text" id="vNum" name="vehicle_number" class="rto-input" style="text-transform:uppercase" placeholder="e.g. DL01AB1234">
        </div>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="vMake">Make / Model</label>
          <input type="text" id="vMake" name="make_model" class="rto-input" placeholder="e.g. Maruti Swift">
        </div>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="vAddr">Address</label>
          <textarea id="vAddr" name="address" class="rto-input" rows="2"></textarea>
        </div>
        <button type="submit" class="rto-btn rto-btn-primary">Save</button>
      </form>
    </div>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>Saved</h3></div>
    <?php if (empty($vehicles)): ?>
    <div class="rto-empty-state"><p>Nothing saved yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" id="rto-vehicles-table" data-rto-responsive="cards">
        <thead><tr><th>Label</th><th>Vehicle No.</th><th>Make/Model</th><th>Address</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($vehicles as $v): ?>
        <tr>
          <td data-label="Label"><?= esc_html($v['label'] ?: '—') ?></td>
          <td data-label="Vehicle No."><?= esc_html($v['vehicle_number'] ?: '—') ?></td>
          <td data-label="Make/Model"><?= esc_html($v['make_model'] ?: '—') ?></td>
          <td data-label="Address" style="max-width:220px;white-space:normal"><?= esc_html($v['address'] ?: '—') ?></td>
          <td data-label="">
            <form method="POST" class="rto-vehicle-delete-form" style="display:inline">
              <?php wp_nonce_field('rtoflow_client_vehicle', 'rtoflow_vehicle_nonce'); ?>
              <input type="hidden" name="delete_vehicle_id" value="<?= (int)$v['id'] ?>">
              <button type="submit" class="rto-btn rto-btn-xs rto-btn-outline">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('rto-vehicles-table').addEventListener('submit', function(e){
  var form = e.target.closest('.rto-vehicle-delete-form');
  if (!form) return;
  if (!confirm('Remove this saved entry?')) e.preventDefault();
});
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/client-footer.php'; ?>
