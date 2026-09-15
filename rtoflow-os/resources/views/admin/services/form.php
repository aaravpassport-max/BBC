<?php if (!defined('ABSPATH')) exit;
/** @var array|null $service @var array $categories @var string $mode @var array $errors */
$isEdit = $mode === 'edit';
$title  = $isEdit ? 'Edit Service' : 'Add New Service';
$action = $isEdit
    ? home_url('/rto-admin/services/' . (int)($service['id'] ?? 0) . '/')
    : home_url('/rto-admin/services/');
$v = fn(string $k, string $d = '') => esc_attr($service[$k] ?? $d);
$pageTitle = 'Service';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap" style="max-width:680px">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title"><?= esc_html($title) ?></h1>
    <a href="<?= esc_url(home_url('/rto-admin/services/')) ?>" class="rto-back-link">← All Services</a>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="rto-msg rto-msg-error rto-mb-4" role="alert">
    <strong>Please fix these errors:</strong>
    <ul style="margin:6px 0 0 16px"><?php foreach ($errors as $e): ?><li><?= esc_html($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <div class="rto-card">
    <div class="rto-card-body">
      <form method="POST" action="<?= esc_url($action) ?>">
        <?php wp_nonce_field('rtoflow_service_save', 'rtoflow_nonce'); ?>

        <div class="rto-form-row" style="display:grid;gap:16px;grid-template-columns:1fr 1fr">

          <!-- Name -->
          <div class="rto-form-group" style="grid-column:1/-1">
            <label class="rto-label" for="sName">Service Name <abbr title="Required">*</abbr></label>
            <input type="text" id="sName" name="name" class="rto-input <?= isset($errors['name'])?'rto-error':'' ?>"
                   value="<?= $v('name') ?>" required placeholder="e.g. RC Transfer (Same State)">
          </div>

          <!-- Category -->
          <div class="rto-form-group">
            <label class="rto-label" for="sCat">Category <abbr title="Required">*</abbr></label>
            <select id="sCat" name="category" class="rto-select">
              <option value="">— Select —</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?= esc_attr($cat) ?>" <?= ($service['category'] ?? '') === $cat ? 'selected' : '' ?>><?= esc_html($cat) ?></option>
              <?php endforeach; ?>
              <option value="__new">+ New Category…</option>
            </select>
            <div id="catNew" style="display:none;margin-top:8px">
              <input type="text" name="category_new" class="rto-input" placeholder="New category name">
            </div>
          </div>

          <!-- Display Order -->
          <div class="rto-form-group">
            <label class="rto-label" for="sOrder">Display Order<?= rto_field_tooltip('Controls only the sort order services appear in on public listing pages — lower numbers show first. It has no effect on pricing, eligibility, or vendor assignment.') ?></label>
            <input type="number" id="sOrder" name="display_order" class="rto-input" value="<?= $v('display_order','0') ?>" min="0">
          </div>

          <!-- Base Price -->
          <div class="rto-form-group">
            <label class="rto-label" for="sPrice">Base Price (₹) <abbr title="Required">*</abbr></label>
            <input type="number" id="sPrice" name="base_price" class="rto-input <?= isset($errors['base_price'])?'rto-error':'' ?>"
                   value="<?= $v('base_price','0') ?>" min="0" step="0.01" required>
          </div>

          <!-- SLA Days -->
          <div class="rto-form-group">
            <label class="rto-label" for="sSla">SLA (Business Days) <abbr title="Required">*</abbr><?= rto_field_tooltip('The deadline shown to staff/customers on every order for this service is created_at + this many days. It overrides the platform-wide default SLA set in Settings — leaving this at the default here does not defer to Settings once a service has been saved.') ?></label>
            <input type="number" id="sSla" name="sla_days" class="rto-input <?= isset($errors['sla_days'])?'rto-error':'' ?>"
                   value="<?= $v('sla_days','7') ?>" min="1" max="365" required>
          </div>

          <!-- Vendor Share -->
          <div class="rto-form-group">
            <label class="rto-label" for="sShare">Vendor Share (%)</label>
            <input type="number" id="sShare" name="vendor_share" class="rto-input"
                   value="<?= $v('vendor_share','45') ?>" min="0" max="100" step="0.5">
            <div class="rto-small rto-muted" style="margin-top:4px">Your company keeps <?= 100 - (int)($service['vendor_share'] ?? 45) ?>%</div>
          </div>

          <!-- GST & Active -->
          <div class="rto-form-group">
            <label class="rto-label" style="margin-bottom:12px">Options</label>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer">
              <input type="checkbox" name="gst_applicable" value="1" <?= ($service['gst_applicable'] ?? 1) ? 'checked' : '' ?>>
              <span>GST Applicable (18%)</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="is_active" value="1" <?= ($service['is_active'] ?? 1) ? 'checked' : '' ?>>
              <span>Active (visible in apply form)</span>
            </label>
          </div>

          <!-- Description -->
          <div class="rto-form-group" style="grid-column:1/-1">
            <label class="rto-label" for="sDesc">Description / What's Included</label>
            <textarea id="sDesc" name="description" class="rto-input" rows="3"
                      placeholder="Describe what this service includes…"><?= esc_textarea($service['description'] ?? '') ?></textarea>
          </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:12px">
          <button type="submit" class="rto-btn rto-btn-primary"><?= $isEdit ? 'Update Service' : 'Add Service' ?></button>
          <a href="<?= esc_url(home_url('/rto-admin/services/')) ?>" class="rto-btn rto-btn-outline">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var sCatEl = document.getElementById('sCat');
if (sCatEl) {
  sCatEl.addEventListener('change', function() {
    document.getElementById('catNew').style.display = this.value === '__new' ? 'block' : 'none';
  });
}
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
