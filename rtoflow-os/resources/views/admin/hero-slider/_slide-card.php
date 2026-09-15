<?php if (!defined('ABSPATH')) exit;
/**
 * Single slide editor card. Included both for existing slides (looped from
 * $slides) and once inside the <template id="hsNewSlideTemplate"> used to
 * clone a blank slide client-side — expects $slide (assoc array) and $i
 * (loop index or '__NEW__') in scope from the including view.
 */
$isNew = ($i === '__NEW__');
$sid   = $isNew ? '' : (int) $slide['id'];
$label = $isNew ? 'New Slide' : ('Slide #' . ($i + 1) . ($slide['heading'] !== '' ? ' — ' . $slide['heading'] : ''));
?>
<details class="hs-slide-card rto-card" data-id="<?= esc_attr($sid) ?>" style="margin-bottom:14px;<?= $isNew ? '' : '' ?>">
  <summary style="cursor:pointer;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:10px;list-style:none;background:#F8FAFC;border-bottom:1px solid var(--gray-200,#e5e7eb)">
    <span style="display:flex;align-items:center;gap:10px;font-weight:700;font-size:13.5px;color:var(--navy,#1B2A6B)">
      <?= esc_html($label) ?>
      <?php if (!$isNew): ?>
        <span class="rto-badge rto-badge-xs <?= $slide['is_active'] ? 'rto-badge-success' : 'rto-badge-secondary' ?>"><?= $slide['is_active'] ? 'Active' : 'Inactive' ?></span>
      <?php endif; ?>
    </span>
    <?php if (!$isNew): ?>
    <span style="display:flex;gap:6px" class="hs-summary-actions">
      <button type="button" class="rto-btn rto-btn-outline rto-btn-xs hs-move-up" title="Move up">&uarr;</button>
      <button type="button" class="rto-btn rto-btn-outline rto-btn-xs hs-move-down" title="Move down">&darr;</button>
      <button type="button" class="rto-btn rto-btn-outline rto-btn-xs hs-toggle-btn"><?= $slide['is_active'] ? 'Disable' : 'Enable' ?></button>
      <button type="button" class="rto-btn rto-btn-danger rto-btn-xs hs-delete-btn">Delete</button>
    </span>
    <?php endif; ?>
  </summary>

  <div class="rto-card-body" style="display:flex;flex-direction:column;gap:18px">

    <!-- Images -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px" class="hs-device-grid">
      <div>
        <label class="rto-label">Desktop Image <abbr title="Required">*</abbr></label>
        <div style="width:100%;height:120px;border-radius:8px;overflow:hidden;background:#EEF3FB;margin-bottom:8px">
          <img class="hs-desktop-preview-img" src="<?= esc_url($slide['desktop_image_url']) ?>" alt="" style="display:block;width:100%;height:100%;object-fit:cover">
        </div>
        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hs-img-input" data-device="desktop">
        <small class="hs-desktop-upload-status" style="display:block;color:var(--gray-600,#64748b);margin-top:4px"></small>
        <input type="hidden" class="hs-desktop-image-id" value="<?= esc_attr($slide['desktop_image_id']) ?>">
        <input type="hidden" class="hs-desktop-image-url" value="<?= esc_attr($slide['desktop_image_url']) ?>">
      </div>
      <div>
        <label class="rto-label">Mobile Image <abbr title="Required">*</abbr></label>
        <div style="width:100%;height:120px;border-radius:8px;overflow:hidden;background:#EEF3FB;margin-bottom:8px">
          <img class="hs-mobile-preview-img" src="<?= esc_url($slide['mobile_image_url']) ?>" alt="" style="display:block;width:100%;height:100%;object-fit:cover">
        </div>
        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hs-img-input" data-device="mobile">
        <small class="hs-mobile-upload-status" style="display:block;color:var(--gray-600,#64748b);margin-top:4px"></small>
        <input type="hidden" class="hs-mobile-image-id" value="<?= esc_attr($slide['mobile_image_id']) ?>">
        <input type="hidden" class="hs-mobile-image-url" value="<?= esc_attr($slide['mobile_image_url']) ?>">
      </div>
    </div>

    <!-- Fit & position -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px" class="hs-device-grid">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="rto-form-group">
          <label class="rto-label">Desktop fit</label>
          <select class="rto-select hs-desktop-fit">
            <?php foreach ($fitOptions as $v => $l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $slide['desktop_fit'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Desktop position</label>
          <select class="rto-select hs-desktop-position">
            <?php foreach ($positionOptions as $v => $l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $slide['desktop_position'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="rto-form-group">
          <label class="rto-label">Mobile fit</label>
          <select class="rto-select hs-mobile-fit">
            <?php foreach ($fitOptions as $v => $l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $slide['mobile_fit'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Mobile position</label>
          <select class="rto-select hs-mobile-position">
            <?php foreach ($positionOptions as $v => $l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $slide['mobile_position'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Content -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
      <div class="rto-form-group">
        <label class="rto-label">Heading <small>(optional)</small></label>
        <input type="text" class="rto-input hs-heading" maxlength="255" value="<?= esc_attr($slide['heading']) ?>">
      </div>
      <div class="rto-form-group">
        <label class="rto-label">Description <small>(optional)</small></label>
        <input type="text" class="rto-input hs-description" maxlength="2000" value="<?= esc_attr($slide['description']) ?>">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
      <div class="rto-form-group">
        <label class="rto-label">CTA button text <small>(optional)</small></label>
        <input type="text" class="rto-input hs-cta-text" maxlength="100" value="<?= esc_attr($slide['cta_text']) ?>">
      </div>
      <div class="rto-form-group">
        <label class="rto-label">CTA URL</label>
        <input type="url" class="rto-input hs-cta-url" value="<?= esc_attr($slide['cta_url']) ?>" placeholder="https://…">
      </div>
    </div>

    <!-- Overlay + content placement -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;align-items:end" class="hs-overlay-grid">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" class="hs-overlay-enabled" <?= $slide['overlay_enabled'] ? 'checked' : '' ?>> Overlay enabled</label>
      <div class="rto-form-group">
        <label class="rto-label">Overlay color</label>
        <input type="color" class="hs-overlay-color" value="<?= esc_attr($slide['overlay_color']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
      </div>
      <div class="rto-form-group">
        <label class="rto-label">Overlay opacity (%)</label>
        <input type="number" class="rto-input hs-overlay-opacity" min="0" max="100" value="<?= (int)$slide['overlay_opacity'] ?>">
      </div>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" class="hs-is-active" <?= $slide['is_active'] ? 'checked' : '' ?>> Slide active</label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
      <div class="rto-form-group">
        <label class="rto-label">Content position — Desktop</label>
        <select class="rto-select hs-content-position-desktop">
          <?php foreach ($contentPosOptions as $v => $l): ?>
          <option value="<?= esc_attr($v) ?>" <?= $slide['content_position_desktop'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group">
        <label class="rto-label">Content position — Mobile</label>
        <select class="rto-select hs-content-position-mobile">
          <?php foreach ($contentPosOptions as $v => $l): ?>
          <option value="<?= esc_attr($v) ?>" <?= $slide['content_position_mobile'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <button type="button" class="rto-btn rto-btn-primary hs-save-btn"><?= $isNew ? 'Add Slide' : 'Save Changes' ?></button>
      <?php if ($isNew): ?><button type="button" class="rto-btn rto-btn-outline hs-delete-btn" style="margin-left:8px">Discard</button><?php endif; ?>
    </div>
  </div>
</details>
