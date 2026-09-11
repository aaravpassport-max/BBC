<?php
/**
 * NAS Admin — Branding & Identity
 * Restored from the orphaned templates/admin/dashboard.php (never wired into the live admin
 * nav — see audit notes). Backend (modules/Branding/BrandingModule.php) was already correct
 * except for a nonce action mismatch, fixed separately.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-palette"></i> Branding & Identity</h1>
</div>

<div class="nas-card" style="max-width:760px">
  <div class="nas-form-grid" id="br-form">
    <?php
    // Matches BrandingModule::$BRANDING_KEYS exactly (modules/Branding/BrandingModule.php:48-56)
    $fields = [
        'brand_name'             => ['Brand Name', 'text'],
        'brand_tagline'          => ['Tagline', 'text'],
        'brand_primary_color'    => ['Primary Colour', 'color'],
        'brand_secondary_color'  => ['Secondary Colour', 'color'],
        'logo_url'               => ['Logo URL', 'url'],
        'favicon_url'            => ['Favicon URL', 'url'],
        'brand_email'            => ['Contact Email', 'email'],
        'brand_phone'            => ['Phone Number', 'text'],
        'brand_whatsapp'         => ['WhatsApp Number', 'text'],
        'brand_address'          => ['Office Address', 'text_full'],
        'brand_city'             => ['City', 'text'],
        'brand_state'            => ['State', 'text'],
        'brand_pincode'          => ['Pincode', 'text'],
        'social_facebook'        => ['Facebook URL', 'url'],
        'social_instagram'       => ['Instagram URL', 'url'],
        'social_linkedin'        => ['LinkedIn URL', 'url'],
        'social_twitter'         => ['Twitter / X URL', 'url'],
        'footer_tagline'         => ['Footer Tagline', 'text'],
        'footer_copyright'       => ['Footer Copyright Text', 'text'],
        'homepage_hero_title'    => ['Homepage Hero Title', 'text_full'],
        'homepage_hero_subtitle' => ['Homepage Hero Subtitle', 'text_full'],
        'meta_title_pattern'     => ['SEO Meta Title Pattern', 'text_full'],
        'meta_desc_pattern'      => ['SEO Meta Description Pattern', 'text_full'],
    ];
    foreach ($fields as $key => list($label, $type)):
        $full = $type === 'text_full';
        $inputType = $type === 'text_full' ? 'text' : $type;
    ?>
    <div class="nas-form-row<?php echo $full ? ' nas-form-row--full' : ''; ?>">
      <label class="nas-label"><?php echo esc_html($label); ?></label>
      <input type="<?php echo esc_attr($inputType); ?>" id="br-<?php echo esc_attr($key); ?>" class="nas-input"
             <?php if ($type==='color') echo 'style="height:40px;padding:4px 8px"'; ?>>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
    <button class="nas-btn nas-btn-primary" id="br-save-btn" onclick="brSave()">Save Branding</button>
    <span id="br-status" style="font-size:12px;color:#64748b"></span>
  </div>
</div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const FIELD_IDS = <?php echo wp_json_encode(array_keys($fields)); ?>;

function post(action, data) {
  var fd = new FormData();
  fd.append('action', action);
  fd.append('nonce', C.nonce);
  Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });
  var ctrl = new AbortController(); setTimeout(function(){ ctrl.abort(); }, 30000);
  return fetch(C.ajaxUrl, { method:'POST', body:fd, signal:ctrl.signal })
    .then(function(r){ return r.json(); });
}

function load() {
  post('nas_get_branding', {}).then(function(res){
    if (!res.success) { nasAdminToast('Could not load branding settings.', 'error'); return; }
    var b = res.data.branding || {};
    FIELD_IDS.forEach(function(k){
      var el = document.getElementById('br-'+k);
      if (el) el.value = b[k] || '';
    });
  }).catch(function(){ nasAdminToast('Network error loading branding.', 'error'); });
}

window.brSave = function() {
  var btn = document.getElementById('br-save-btn');
  var orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Saving…';
  var data = {};
  FIELD_IDS.forEach(function(k){
    var el = document.getElementById('br-'+k);
    if (el) data[k] = el.value;
  });
  post('nas_save_branding', data).then(function(res){
    btn.disabled = false; btn.textContent = orig;
    if (res.success) {
      nasAdminToast(res.data?.message || 'Branding saved!', 'success');
      document.getElementById('br-status').textContent = 'Last saved just now.';
    } else {
      nasAdminToast(res.data?.message || 'Save failed.', 'error');
    }
  }).catch(function(){
    btn.disabled = false; btn.textContent = orig;
    nasAdminToast('Network error. Please try again.', 'error');
  });
};

document.addEventListener('DOMContentLoaded', load);
})();
</script>
