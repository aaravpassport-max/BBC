<?php if (!defined('ABSPATH')) exit;
/** @var array $slides @var array $settings */
$pageTitle = 'Hero Slider';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';

$fitOptions = [
    'cover'   => 'Cover — fill area, may crop',
    'contain' => 'Contain — full image, may show gaps',
    'fill'    => 'Fill — stretch to fit exactly',
    'auto'    => 'Auto / Original — natural size',
];
$positionOptions = [
    'center' => 'Center', 'center-top' => 'Center Top', 'center-bottom' => 'Center Bottom',
    'left' => 'Left', 'right' => 'Right',
    'top-left' => 'Top Left', 'top-right' => 'Top Right',
    'bottom-left' => 'Bottom Left', 'bottom-right' => 'Bottom Right',
    'custom' => 'Custom',
];
$contentPosOptions = [
    'center' => 'Center', 'center-left' => 'Center Left', 'center-right' => 'Center Right',
    'top-left' => 'Top Left', 'top-center' => 'Top Center', 'top-right' => 'Top Right',
    'bottom-left' => 'Bottom Left', 'bottom-center' => 'Bottom Center', 'bottom-right' => 'Bottom Right',
];
$transitionOptions = ['slide' => 'Slide', 'fade' => 'Fade', 'cube' => 'Cube', 'coverflow' => 'Coverflow'];
$firstActive = null;
foreach ($slides as $s) { if ((int)$s['is_active'] === 1) { $firstActive = $s; break; } }
if (!$firstActive && !empty($slides)) $firstActive = $slides[0];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Hero Slider Management</h1>
  </div>
  <p style="color:var(--gray-600,#64748b);font-size:13.5px;max-width:760px;margin:-8px 0 20px">
    Manage the Home page hero as a responsive slider. Desktop and Mobile use completely separate images and settings —
    a device will only ever load the image and configuration meant for it.
  </p>

  <div id="heroGlobalMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <!-- ===================== GENERAL SLIDER SETTINGS ===================== -->
  <div class="rto-card">
    <div class="rto-card-header"><h3>General Slider Settings</h3></div>
    <div class="rto-card-body">
      <div class="rto-form-group" style="max-width:280px">
        <label class="rto-label" for="hs_breakpoint">Responsive breakpoint (px)</label>
        <input type="number" id="hs_breakpoint" class="rto-input" min="320" max="1400" value="<?= (int)$settings['general']['breakpoint'] ?>">
        <small style="color:var(--gray-600,#64748b)">Viewport width at or below this switches to Mobile image + Mobile settings.</small>
      </div>
    </div>
  </div>

  <!-- ===================== DEVICE SETTINGS (Desktop + Mobile) ===================== -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" class="hs-device-grid">
    <?php foreach (['desktop' => 'Desktop Settings', 'mobile' => 'Mobile Settings'] as $dev => $label): $d = $settings[$dev]; ?>
    <div class="rto-card">
      <div class="rto-card-header"><h3><?= esc_html($label) ?></h3></div>
      <div class="rto-card-body" style="display:flex;flex-direction:column;gap:14px">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
          <div class="rto-form-group">
            <label class="rto-label">Height (px)</label>
            <input type="number" class="rto-input hs-dev-input" id="hs_<?= $dev ?>_height" min="100" max="2000" value="<?= (int)$d['height'] ?>">
          </div>
          <div class="rto-form-group">
            <label class="rto-label">Min height (px)</label>
            <input type="number" class="rto-input hs-dev-input" id="hs_<?= $dev ?>_min_height" min="80" max="2000" value="<?= (int)$d['min_height'] ?>">
          </div>
          <div class="rto-form-group">
            <label class="rto-label">Max height (px)</label>
            <input type="number" class="rto-input hs-dev-input" id="hs_<?= $dev ?>_max_height" min="100" max="3000" value="<?= (int)$d['max_height'] ?>">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="rto-form-group">
            <label class="rto-label">Default image fit <small>(pre-fills new slides)</small></label>
            <select class="rto-select" id="hs_<?= $dev ?>_default_fit">
              <?php foreach ($fitOptions as $v => $l): ?>
              <option value="<?= esc_attr($v) ?>" <?= $d['default_fit'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rto-form-group">
            <label class="rto-label">Default image position</label>
            <select class="rto-select" id="hs_<?= $dev ?>_default_position">
              <?php foreach ($positionOptions as $v => $l): ?>
              <option value="<?= esc_attr($v) ?>" <?= $d['default_position'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="rto-form-group">
            <label class="rto-label">Transition effect</label>
            <select class="rto-select" id="hs_<?= $dev ?>_transition_effect">
              <?php foreach ($transitionOptions as $v => $l): ?>
              <option value="<?= esc_attr($v) ?>" <?= $d['transition_effect'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rto-form-group">
            <label class="rto-label">Transition speed (ms)</label>
            <input type="number" class="rto-input" id="hs_<?= $dev ?>_transition_speed" min="100" max="5000" step="50" value="<?= (int)$d['transition_speed'] ?>">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="rto-form-group">
            <label class="rto-label">Autoplay interval (ms)</label>
            <input type="number" class="rto-input" id="hs_<?= $dev ?>_autoplay_interval" min="1000" max="20000" step="500" value="<?= (int)$d['autoplay_interval'] ?>">
          </div>
          <div></div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:16px;padding-top:4px;border-top:1px solid var(--gray-200,#e5e7eb)">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="hs_<?= $dev ?>_autoplay" <?= $d['autoplay'] ? 'checked' : '' ?>> Autoplay</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="hs_<?= $dev ?>_loop" <?= $d['loop'] ? 'checked' : '' ?>> Loop</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="hs_<?= $dev ?>_nav_arrows" <?= $d['nav_arrows'] ? 'checked' : '' ?>> Navigation arrows</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="hs_<?= $dev ?>_pagination_dots" <?= $d['pagination_dots'] ? 'checked' : '' ?>> Pagination dots</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="hs_<?= $dev ?>_swipe" <?= $d['swipe'] ? 'checked' : '' ?>> Swipe / touch</label>
          <?php if ($dev === 'desktop'): ?>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="hs_desktop_pause_on_hover" <?= $d['pause_on_hover'] ? 'checked' : '' ?>> Pause on hover</label>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="margin:16px 0 28px">
    <button type="button" id="hsSaveSettingsBtn" class="rto-btn rto-btn-primary">Save Slider Settings</button>
  </div>

  <!-- ===================== LIVE PREVIEW ===================== -->
  <div class="rto-card">
    <div class="rto-card-header"><h3>Preview</h3></div>
    <div class="rto-card-body">
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start" class="hs-preview-grid">
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--gray-600,#64748b);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Desktop Preview</div>
          <div id="hsPreviewDesktop" style="width:100%;border-radius:10px;overflow:hidden;background:#EEF3FB;position:relative;height:220px">
            <img id="hsPreviewDesktopImg" src="<?= esc_url($firstActive['desktop_image_url'] ?? '') ?>" alt="" style="display:block;width:100%;height:100%;object-fit:cover;object-position:center">
          </div>
        </div>
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--gray-600,#64748b);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Mobile Preview</div>
          <div id="hsPreviewMobile" style="width:150px;margin:0 auto;border-radius:16px;overflow:hidden;background:#EEF3FB;position:relative;height:280px;border:6px solid #1e293b">
            <img id="hsPreviewMobileImg" src="<?= esc_url($firstActive['mobile_image_url'] ?? '') ?>" alt="" style="display:block;width:100%;height:100%;object-fit:cover;object-position:center">
          </div>
        </div>
      </div>
      <p style="color:var(--gray-600,#64748b);font-size:12.5px;margin-top:12px">Preview reflects the first active slide's images with its current fit/position — scaled down to fit this panel. Actual on-site height follows the numbers set above.</p>
    </div>
  </div>

  <!-- ===================== INDIVIDUAL SLIDES ===================== -->
  <div class="rto-card">
    <div class="rto-card-header">
      <h3>Slides (<?= count($slides) ?>)</h3>
      <button type="button" id="hsAddSlideBtn" class="rto-btn rto-btn-success rto-btn-sm">+ Add Slide</button>
    </div>
    <div class="rto-card-body" id="hsSlidesWrap">
      <?php if (empty($slides)): ?>
      <p style="color:var(--gray-600,#64748b)">No slides yet. Click "+ Add Slide" to create the first one.</p>
      <?php endif; ?>
      <?php foreach ($slides as $i => $slide): ?>
        <?php require RTOFLOW_DIR . 'resources/views/admin/hero-slider/_slide-card.php'; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Template for a brand-new (unsaved) slide, cloned by JS -->
  <template id="hsNewSlideTemplate">
    <?php $slide = [
        'id' => '', 'desktop_image_id' => 0, 'desktop_image_url' => '',
        'mobile_image_id' => 0, 'mobile_image_url' => '',
        'desktop_fit' => $settings['desktop']['default_fit'], 'desktop_position' => $settings['desktop']['default_position'],
        'mobile_fit' => $settings['mobile']['default_fit'], 'mobile_position' => $settings['mobile']['default_position'],
        'heading' => '', 'description' => '', 'cta_text' => '', 'cta_url' => '',
        'overlay_enabled' => 0, 'overlay_color' => '#0A1628', 'overlay_opacity' => 30,
        'content_position_desktop' => 'center-left', 'content_position_mobile' => 'bottom-center',
        'is_active' => 1,
    ]; $i = '__NEW__'; ?>
    <?php require RTOFLOW_DIR . 'resources/views/admin/hero-slider/_slide-card.php'; ?>
  </template>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var nonce = rtoflowAdmin.nonce; var ajaxUrl = rtoflowAdmin.ajax_url;

function hsShowMsg(text, ok) {
  var box = document.getElementById('heroGlobalMsg');
  box.style.display = 'block';
  box.className = 'rto-msg ' + (ok ? 'rto-msg-success' : 'rto-msg-error');
  box.textContent = text;
  window.scrollTo({top: 0, behavior: 'smooth'});
}

// ── Save device + general settings ──────────────────────────────────────
document.getElementById('hsSaveSettingsBtn')?.addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'hero_settings_save');
  fd.append('rto_nonce', nonce);
  fd.append('breakpoint', document.getElementById('hs_breakpoint').value);
  ['desktop', 'mobile'].forEach(function (dev) {
    ['height', 'min_height', 'max_height', 'default_fit', 'default_position', 'transition_effect', 'transition_speed', 'autoplay_interval'].forEach(function (f) {
      var el = document.getElementById('hs_' + dev + '_' + f);
      if (el) fd.append(dev + '_' + f, el.value);
    });
    ['autoplay', 'loop', 'nav_arrows', 'pagination_dots', 'swipe'].forEach(function (f) {
      var el = document.getElementById('hs_' + dev + '_' + f);
      if (el) fd.append(dev + '_' + f, el.checked ? '1' : '0');
    });
  });
  var pauseEl = document.getElementById('hs_desktop_pause_on_hover');
  if (pauseEl) fd.append('desktop_pause_on_hover', pauseEl.checked ? '1' : '0');

  btn.disabled = true; var origText = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) {
      btn.disabled = false; btn.textContent = origText;
      hsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success);
    })
    .catch(function () { btn.disabled = false; btn.textContent = origText; hsShowMsg('Connection error. Please try again.', false); });
});

// ── Live preview: update from the first active slide's card fields ──────
function hsUpdatePreviewFrom(card) {
  var deskImg = card.querySelector('.hs-desktop-preview-img');
  var mobImg  = card.querySelector('.hs-mobile-preview-img');
  var deskFit = card.querySelector('.hs-desktop-fit').value;
  var deskPos = card.querySelector('.hs-desktop-position').value;
  var mobFit  = card.querySelector('.hs-mobile-fit').value;
  var mobPos  = card.querySelector('.hs-mobile-position').value;
  var pDesk = document.getElementById('hsPreviewDesktopImg');
  var pMob  = document.getElementById('hsPreviewMobileImg');
  if (deskImg && deskImg.src) { pDesk.src = deskImg.src; pDesk.style.objectFit = hsFitCss(deskFit); pDesk.style.objectPosition = hsPosCss(deskPos); }
  if (mobImg && mobImg.src)   { pMob.src  = mobImg.src;  pMob.style.objectFit  = hsFitCss(mobFit);  pMob.style.objectPosition  = hsPosCss(mobPos); }
}
function hsFitCss(fit) { return fit === 'auto' ? 'none' : fit; }
function hsPosCss(pos) {
  var map = {center:'center',  'center-top':'center top', 'center-bottom':'center bottom', left:'left center', right:'right center',
             'top-left':'left top', 'top-right':'right top', 'bottom-left':'left bottom', 'bottom-right':'right bottom', custom:'center'};
  return map[pos] || 'center';
}

// ── Slide upload / save / delete / toggle / reorder ─────────────────────
function hsBindSlideCard(card) {
  if (card.dataset.hsBound === '1') return;
  card.dataset.hsBound = '1';

  // Buttons that live inside <summary> (move/toggle/delete) must not also
  // trigger the <details> element's native open/close toggle when clicked.
  var summaryActions = card.querySelector('.hs-summary-actions');
  if (summaryActions) {
    summaryActions.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); });
    });
  }

  card.querySelectorAll('.hs-img-input').forEach(function (input) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;
      var device = input.dataset.device;
      var fd = new FormData();
      fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'hero_slide_upload');
      fd.append('rto_nonce', nonce);
      fd.append('image', file);
      var previewImg = card.querySelector('.hs-' + device + '-preview-img');
      var idField = card.querySelector('.hs-' + device + '-image-id');
      var urlField = card.querySelector('.hs-' + device + '-image-url');
      var status = card.querySelector('.hs-' + device + '-upload-status');
      if (status) status.textContent = 'Uploading…';
      fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (r.success) {
            if (previewImg) previewImg.src = r.data.url;
            if (idField) idField.value = r.data.attachment_id;
            if (urlField) urlField.value = r.data.url;
            if (status) status.textContent = 'Uploaded.';
            hsUpdatePreviewFrom(card);
          } else {
            if (status) status.textContent = '';
            hsShowMsg(r.message || 'Upload failed.', false);
          }
        })
        .catch(function () { if (status) status.textContent = ''; hsShowMsg('Connection error during upload.', false); });
    });
  });

  card.querySelectorAll('.hs-desktop-fit, .hs-desktop-position, .hs-mobile-fit, .hs-mobile-position').forEach(function (el) {
    el.addEventListener('change', function () { hsUpdatePreviewFrom(card); });
  });

  var ctaTextEl = card.querySelector('.hs-cta-text');
  var ctaUrlEl  = card.querySelector('.hs-cta-url');

  card.querySelector('.hs-save-btn')?.addEventListener('click', function () {
    var btn = this;
    var deskUrl = card.querySelector('.hs-desktop-image-url').value;
    var mobUrl  = card.querySelector('.hs-mobile-image-url').value;
    if (!deskUrl) { hsShowMsg('Upload a Desktop image before saving this slide.', false); return; }
    if (!mobUrl)  { hsShowMsg('Upload a Mobile image before saving this slide.', false); return; }
    if (ctaTextEl.value.trim() && !ctaUrlEl.value.trim()) { hsShowMsg('CTA button text was entered but the CTA URL is empty.', false); return; }

    var fd = new FormData();
    fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'hero_slide_save');
    fd.append('rto_nonce', nonce);
    fd.append('id', card.dataset.id || '0');
    fd.append('desktop_image_id', card.querySelector('.hs-desktop-image-id').value);
    fd.append('desktop_image_url', deskUrl);
    fd.append('mobile_image_id', card.querySelector('.hs-mobile-image-id').value);
    fd.append('mobile_image_url', mobUrl);
    fd.append('desktop_fit', card.querySelector('.hs-desktop-fit').value);
    fd.append('desktop_position', card.querySelector('.hs-desktop-position').value);
    fd.append('mobile_fit', card.querySelector('.hs-mobile-fit').value);
    fd.append('mobile_position', card.querySelector('.hs-mobile-position').value);
    fd.append('heading', card.querySelector('.hs-heading').value);
    fd.append('description', card.querySelector('.hs-description').value);
    fd.append('cta_text', ctaTextEl.value);
    fd.append('cta_url', ctaUrlEl.value);
    fd.append('overlay_enabled', card.querySelector('.hs-overlay-enabled').checked ? '1' : '0');
    fd.append('overlay_color', card.querySelector('.hs-overlay-color').value);
    fd.append('overlay_opacity', card.querySelector('.hs-overlay-opacity').value);
    fd.append('content_position_desktop', card.querySelector('.hs-content-position-desktop').value);
    fd.append('content_position_mobile', card.querySelector('.hs-content-position-mobile').value);
    fd.append('is_active', card.querySelector('.hs-is-active').checked ? '1' : '0');

    btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
    fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
      .then(function (r) { return r.json(); })
      .then(function (r) {
        btn.disabled = false; btn.textContent = orig;
        hsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success);
        if (r.success) { setTimeout(function () { location.reload(); }, 700); }
      })
      .catch(function () { btn.disabled = false; btn.textContent = orig; hsShowMsg('Connection error. Please try again.', false); });
  });

  card.querySelector('.hs-delete-btn')?.addEventListener('click', function () {
    if (!card.dataset.id) { card.remove(); return; }
    if (!confirm('Delete this slide permanently? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'hero_slide_delete');
    fd.append('rto_nonce', nonce); fd.append('id', card.dataset.id);
    fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
      .then(function (r) { return r.json(); })
      .then(function (r) { hsShowMsg(r.message || (r.success ? 'Deleted.' : 'Delete failed.'), r.success); if (r.success) setTimeout(function () { location.reload(); }, 600); })
      .catch(function () { hsShowMsg('Connection error. Please try again.', false); });
  });

  card.querySelector('.hs-toggle-btn')?.addEventListener('click', function () {
    var fd = new FormData();
    fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'hero_slide_toggle');
    fd.append('rto_nonce', nonce); fd.append('id', card.dataset.id);
    fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
      .then(function (r) { return r.json(); })
      .then(function (r) { hsShowMsg(r.message || (r.success ? 'Updated.' : 'Failed.'), r.success); if (r.success) setTimeout(function () { location.reload(); }, 600); })
      .catch(function () { hsShowMsg('Connection error. Please try again.', false); });
  });

  card.querySelector('.hs-move-up')?.addEventListener('click', function () {
    var prev = card.previousElementSibling;
    if (prev) { card.parentNode.insertBefore(card, prev); hsSaveOrder(); }
  });
  card.querySelector('.hs-move-down')?.addEventListener('click', function () {
    var next = card.nextElementSibling;
    if (next) { card.parentNode.insertBefore(next, card); hsSaveOrder(); }
  });
}

function hsSaveOrder() {
  var wrap = document.getElementById('hsSlidesWrap');
  var ids = Array.prototype.slice.call(wrap.querySelectorAll('.hs-slide-card')).map(function (c) { return c.dataset.id; }).filter(Boolean);
  if (!ids.length) return;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'hero_slide_reorder');
  fd.append('rto_nonce', nonce);
  ids.forEach(function (id) { fd.append('order[]', id); });
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { hsShowMsg(r.message || (r.success ? 'Order saved.' : 'Reorder failed.'), r.success); })
    .catch(function () { hsShowMsg('Connection error while saving order.', false); });
}

document.querySelectorAll('.hs-slide-card').forEach(hsBindSlideCard);
if (document.querySelector('.hs-slide-card')) hsUpdatePreviewFrom(document.querySelector('.hs-slide-card'));

document.getElementById('hsAddSlideBtn')?.addEventListener('click', function () {
  var tpl = document.getElementById('hsNewSlideTemplate');
  var clone = tpl.content.cloneNode(true);
  var wrap = document.getElementById('hsSlidesWrap');
  wrap.appendChild(clone);
  var card = wrap.lastElementChild;
  card.open = true;
  hsBindSlideCard(card);
  card.scrollIntoView({behavior: 'smooth', block: 'center'});
});
</script>

<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
