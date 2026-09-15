<?php if (!defined('ABSPATH')) exit;
/** @var array|null $vendor @var array $services @var array $states @var array $cities @var string $mode @var array $errors */
$isEdit = $mode === 'edit';
$v = fn(string $k, string $d='') => esc_attr($vendor[$k] ?? $d);
$pageTitle = 'Add Vendor';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap" style="max-width:760px">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Add New Vendor</h1>
    <a href="<?= esc_url(home_url('/rto-admin/vendors/')) ?>" class="rto-back-link">← All Vendors</a>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="rto-msg rto-msg-error rto-mb-4" role="alert">
    <strong>Please fix:</strong>
    <ul style="margin:6px 0 0 16px"><?php foreach ($errors as $e): ?><li><?= esc_html($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <form method="POST" action="<?= esc_url(home_url('/rto-admin/vendors/')) ?>">
    <?php wp_nonce_field('rtoflow_vendor_save','rtoflow_nonce'); ?>

    <!-- Personal Details -->
    <div class="rto-card rto-mb-4">
      <div class="rto-card-header"><h3>Personal Details</h3></div>
      <div class="rto-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="rto-form-group">
          <label class="rto-label" for="vName">Full Name <abbr title="Required">*</abbr></label>
          <input type="text" id="vName" name="full_name" class="rto-input <?= isset($errors['full_name'])?'rto-error':'' ?>"
                 value="<?= $v('full_name') ?>" required autocomplete="name">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vEmail">Email <abbr title="Required">*</abbr></label>
          <input type="email" id="vEmail" name="email" class="rto-input <?= isset($errors['email'])?'rto-error':'' ?>"
                 value="<?= $v('email') ?>" autocomplete="email">
          <div class="rto-small rto-muted" style="margin-top:4px">A WordPress account will be created if email doesn't exist.</div>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vMobile">Mobile <abbr title="Required">*</abbr></label>
          <input type="tel" id="vMobile" name="mobile" class="rto-input <?= isset($errors['mobile'])?'rto-error':'' ?>"
                 value="<?= $v('mobile') ?>" autocomplete="tel-national" placeholder="10-digit mobile">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vPan">PAN Number</label>
          <input type="text" id="vPan" name="pan" class="rto-input" value="<?= $v('pan') ?>"
                 placeholder="ABCDE1234F" maxlength="10" style="text-transform:uppercase">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vAadhaar">Aadhaar Number</label>
          <input type="text" id="vAadhaar" name="aadhaar" class="rto-input" value="<?= $v('aadhaar') ?>"
                 placeholder="12-digit Aadhaar" maxlength="12">
        </div>
        <div class="rto-form-group" style="grid-column:1/-1">
          <label class="rto-label" for="vAddr">Address</label>
          <textarea id="vAddr" name="address" class="rto-input" rows="2"><?= esc_textarea($vendor['address'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Bank Details -->
    <div class="rto-card rto-mb-4">
      <div class="rto-card-header"><h3>Bank Details (for payouts)</h3></div>
      <div class="rto-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="rto-form-group">
          <label class="rto-label" for="vBank">Bank Name</label>
          <input type="text" id="vBank" name="bank_name" class="rto-input" value="<?= $v('bank_name') ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vAcc">Account Number</label>
          <input type="text" id="vAcc" name="bank_account" class="rto-input" value="<?= $v('bank_account') ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vIfsc">IFSC Code</label>
          <input type="text" id="vIfsc" name="bank_ifsc" class="rto-input" value="<?= $v('bank_ifsc') ?>"
                 placeholder="e.g. SBIN0001234" maxlength="11" style="text-transform:uppercase">
        </div>
      </div>
    </div>

    <!-- Coverage -->
    <div class="rto-card rto-mb-4">
      <div class="rto-card-header"><h3>Service &amp; City Coverage</h3></div>
      <div class="rto-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <div>
          <h4 style="font-size:13px;font-weight:600;margin-bottom:10px">Services</h4>
          <div style="max-height:200px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:4px;padding:8px">
            <?php $cat = ''; foreach ($services as $s):
              if ($s['category'] !== $cat) { $cat = $s['category']; echo '<div class="rto-small" style="font-weight:600;color:var(--gray-500);text-transform:uppercase;margin:6px 0 2px">' . esc_html($cat) . '</div>'; }
            ?>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:13px;cursor:pointer">
              <input type="checkbox" name="service_ids[]" value="<?= esc_attr($s['id']) ?>">
              <?= esc_html($s['name']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <h4 style="font-size:13px;font-weight:600;margin-bottom:10px">Cities</h4>
          <div class="rto-form-group" style="margin-bottom:8px">
            <label class="rto-label" for="stateFilter">Filter by State</label>
            <select id="stateFilter" class="rto-select">
              <option value="">All States</option>
              <?php foreach ($states as $st): ?>
              <option value="<?= esc_attr($st['id']) ?>"><?= esc_html($st['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="cityCheckboxes" style="max-height:160px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:4px;padding:8px">
            <?php $cst = ''; foreach ($cities as $city):
              if ($city['state_name'] !== $cst) { $cst = $city['state_name']; echo '<div class="rto-small" style="font-weight:600;color:var(--gray-500);text-transform:uppercase;margin:6px 0 2px">' . esc_html($cst) . '</div>'; }
            ?>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:13px;cursor:pointer"
                   class="city-chk" data-state="<?= esc_attr($city['id']) /* wrong field - use state */ ?>">
              <input type="checkbox" name="city_ids[]" value="<?= esc_attr($city['id']) ?>">
              <?= esc_html($city['name']) ?> <span class="rto-muted rto-small"><?= esc_html($city['rto_code']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:12px">
      <button type="submit" class="rto-btn rto-btn-primary">Add Vendor</button>
      <a href="<?= esc_url(home_url('/rto-admin/vendors/')) ?>" class="rto-btn rto-btn-outline">Cancel</a>
    </div>
  </form>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var stateFilterEl = document.getElementById('stateFilter');
if (stateFilterEl) {
  stateFilterEl.addEventListener('change', function() { filterCities(this.value); });
}
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
