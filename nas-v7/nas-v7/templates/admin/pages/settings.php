<?php
if ( ! defined('ABSPATH') ) exit;

// Load settings from the single-row column-based table
global $wpdb;
$row = $wpdb->get_row("SELECT * FROM `{$wpdb->prefix}nas_settings` LIMIT 1");
$s   = $row ? (array)$row : [];
function sv2($key, $default='') {
    global $s;
    return esc_attr($s[$key] ?? $default);
}
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-cog"></i> Settings</h1>
  <button class="nas-btn nas-btn-primary" id="st-save-btn" onclick="stSave(this)">
    <i class="fa-solid fa-floppy-disk"></i> Save All Settings
  </button>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">

  <!-- Brand & Identity -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-solid fa-building"></i> Brand & Identity</h3></div>
    <div class="nas-form-stack" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div class="nas-form-row">
        <label class="nas-label">Brand Name</label>
        <input type="text" id="st-brand_name" class="nas-input" value="<?php echo sv2('brand_name', get_bloginfo('name')); ?>">
      </div>
      <div class="nas-form-row">
        <label class="nas-label">Logo URL <small style="color:#94a3b8;font-weight:400">(direct URL to image)</small></label>
        <input type="url" id="st-logo_url" class="nas-input" value="<?php echo sv2('logo_url'); ?>" placeholder="https://yourdomain.com/logo.png">
        <?php if(!empty($s['logo_url'])): ?>
          <img src="<?php echo esc_url($s['logo_url']); ?>" style="height:40px;margin-top:8px;border-radius:6px;border:1px solid #e2e8f0" alt="Logo preview">
        <?php endif; ?>
      </div>
      <div class="nas-form-row">
        <label class="nas-label">Footer Text <small style="color:#94a3b8;font-weight:400">(shown on PDFs & emails)</small></label>
        <textarea id="st-footer_text" class="nas-textarea" rows="2"><?php echo esc_textarea($s['footer_text']??''); ?></textarea>
      </div>
    </div>
  </div>

  <!-- Financial -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-solid fa-indian-rupee-sign"></i> Financial Settings</h3></div>
    <div class="nas-form-stack" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div class="nas-form-row">
        <label class="nas-label">Default GST Percentage (%)</label>
        <input type="number" id="st-gst_rate" class="nas-input" value="<?php echo sv2('gst_percentage','18'); ?>" step="0.01" min="0" max="100">
        <small style="color:#94a3b8">Used as default when GST is enabled on a booking</small>
      </div>
      <div class="nas-form-row">
        <label class="nas-label">GST Registration Number</label>
        <input type="text" id="st-gst_number" class="nas-input" value="<?php echo sv2('gst_number'); ?>" placeholder="22AAAAA0000A1Z5">
      </div>
      <div class="nas-form-row">
        <label class="nas-label">Currency Symbol</label>
        <input type="text" id="st-currency_symbol" class="nas-input" value="<?php echo sv2('currency_symbol','₹'); ?>">
      </div>
      <div class="nas-form-row">
        <label class="nas-label">Invoice Number Prefix</label>
        <input type="text" id="st-invoice_prefix" class="nas-input" value="<?php echo sv2('invoice_prefix','INV'); ?>">
      </div>
      <div class="nas-form-row">
        <label class="nas-label">Booking Cutoff Days <small style="color:#94a3b8;font-weight:400">(min advance notice required)</small></label>
        <input type="number" id="st-cutoff_days" class="nas-input" value="<?php echo sv2('cutoff_days','2'); ?>" min="0" max="90">
      </div>
    </div>
  </div>

  <!-- Email -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-solid fa-envelope"></i> Email Configuration</h3></div>
    <div class="nas-form-stack" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div class="nas-form-row">
        <label class="nas-label">Sender Name</label>
        <input type="text" id="st-email_sender_name" class="nas-input" value="<?php echo sv2('email_sender_name', get_bloginfo('name')); ?>">
      </div>
      <div class="nas-form-row">
        <label class="nas-label">Sender Email</label>
        <input type="email" id="st-email_sender_address" class="nas-input" value="<?php echo sv2('email_sender_addr', get_option('admin_email')); ?>">
      </div>
      <div style="border-top:1px solid #f1f5f9;padding-top:12px">
        <div style="font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px">SMTP (optional — for reliable delivery)</div>
        <div style="display:flex;flex-direction:column;gap:10px">
          <div class="nas-form-row"><label class="nas-label">SMTP Host</label><input type="text" id="st-smtp_host" class="nas-input" value="<?php echo sv2('smtp_host'); ?>" placeholder="smtp.gmail.com"></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="nas-form-row"><label class="nas-label">Port</label><input type="number" id="st-smtp_port" class="nas-input" value="<?php echo sv2('smtp_port','587'); ?>"></div>
            <div class="nas-form-row"><label class="nas-label">Encryption</label>
              <select id="st-smtp_encryption" class="nas-select">
                <option value="tls" <?php echo sv2('smtp_encryption','tls')==='tls'?'selected':''; ?>>TLS</option>
                <option value="ssl" <?php echo sv2('smtp_encryption')==='ssl'?'selected':''; ?>>SSL</option>
                <option value="" <?php echo sv2('smtp_encryption')===''?'selected':''; ?>>None</option>
              </select>
            </div>
          </div>
          <div class="nas-form-row"><label class="nas-label">SMTP Username</label><input type="email" id="st-smtp_user" class="nas-input" value="<?php echo sv2('smtp_user'); ?>"></div>
          <div class="nas-form-row"><label class="nas-label">SMTP Password</label><input type="password" id="st-smtp_pass" class="nas-input" value="<?php echo sv2('smtp_pass'); ?>"></div>
        </div>
      </div>
      <div class="nas-alert-info" style="font-size:12px;padding:10px 12px;border-radius:8px">
        💡 Alternatively use a plugin like <strong>WP Mail SMTP</strong> or <strong>FluentSMTP</strong>
      </div>
    </div>
  </div>

  <!-- WhatsApp -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-brands fa-whatsapp"></i> WhatsApp Notifications</h3></div>
    <div class="nas-form-stack" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div class="nas-form-row">
        <label class="nas-label">Admin WhatsApp Number <small style="color:#94a3b8;font-weight:400">(with country code, no +)</small></label>
        <input type="text" id="st-whatsapp_number" class="nas-input" value="<?php echo sv2('whatsapp_number'); ?>" placeholder="919876543210">
      </div>
      <div class="nas-form-row">
        <label class="nas-label">CallMeBot API Key</label>
        <input type="password" id="st-whatsapp_api_key" class="nas-input" value="<?php echo sv2('callmebot_api_key'); ?>" placeholder="Get free key from callmebot.com">
      </div>
      <div class="nas-alert-info" style="font-size:12px;padding:10px 12px;border-radius:8px">
        💡 Get your free API key at <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" style="color:#1d4ed8">callmebot.com</a>
      </div>
    </div>
  </div>

  <!-- AI -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-solid fa-robot"></i> AI Configuration</h3></div>
    <div class="nas-form-stack" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div class="nas-form-row">
        <label class="nas-label">AI Provider</label>
        <select id="st-ai_provider" class="nas-select">
          <option value="anthropic" <?php echo sv2('ai_provider','anthropic')==='anthropic'?'selected':''; ?>>Anthropic (Claude)</option>
          <option value="openai"    <?php echo sv2('ai_provider')==='openai'?'selected':''; ?>>OpenAI (ChatGPT)</option>
          <option value="gemini"    <?php echo sv2('ai_provider')==='gemini'?'selected':''; ?>>Google Gemini</option>
        </select>
      </div>
      <div class="nas-form-row">
        <label class="nas-label">AI API Key</label>
        <input type="password" id="st-ai_api_key" class="nas-input" value="<?php echo sv2('ai_api_key'); ?>" placeholder="sk-... or claude key">
      </div>
      <div class="nas-form-row">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px">
          <input type="checkbox" id="st-ai_enabled" style="width:16px;height:16px;accent-color:#2A8AFA"
            <?php echo !empty($s['ai_enabled'])&&$s['ai_enabled']!=='0'?'checked':''; ?>>
          Enable AI Features (ad suggestions, SEO generation)
        </label>
      </div>
    </div>
  </div>

  <!-- Shortcodes reference -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-solid fa-code"></i> Portal Shortcodes</h3></div>
    <div style="padding:20px;display:flex;flex-direction:column;gap:10px">
      <?php
      $shortcodes = [
          '[nas_booking_wizard]'      => 'Booking form — place on your booking page',
          '[nas_client_dashboard]'    => 'Client portal — place on /client-dashboard/',
          '[nas_vendor_dashboard]'    => 'Vendor portal — served via /vendor-dashboard/ (auto)',
          '[nas_staff_dashboard]'     => 'Staff portal — place on /staff-dashboard/',
          '[nas_moderation_dashboard]'=> 'Moderation — place on /moderation-dashboard/',
          '[nas_login]'               => 'Login page — place on /newspaper-ad-login/',
      ];
      foreach ($shortcodes as $sc => $desc): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px">
          <code style="background:#202C39;color:#a5b4fc;padding:3px 8px;border-radius:5px;font-size:12px;cursor:pointer;user-select:all"
                onclick="navigator.clipboard.writeText('<?php echo esc_attr($sc); ?>').then(()=>nasAdminToast('Copied!','success'))">
            <?php echo esc_html($sc); ?>
          </code>
          <div style="font-size:12px;color:#64748b;margin-top:4px"><?php echo esc_html($desc); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>



  <!-- Payment Gateways -->
  <div class="nas-card" style="grid-column:1/-1">
    <div class="nas-card-header"><h3><i class="fa-solid fa-credit-card"></i> Payment Gateways</h3></div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;padding:20px">
      <!-- Razorpay -->
      <div>
        <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9"><img src="https://razorpay.com/favicon.ico" height="14" style="vertical-align:middle;margin-right:6px"> Razorpay (Recommended for India)</div>
        <div class="nas-form-row"><label class="nas-label">Key ID</label><input type="text" id="st-razorpay_key_id" class="nas-input" value="<?php echo sv2('razorpay_key_id'); ?>" placeholder="rzp_live_…"></div>
        <div class="nas-form-row"><label class="nas-label">Key Secret</label><input type="password" id="st-razorpay_key_secret" class="nas-input" value="<?php echo sv2('razorpay_key_secret'); ?>"></div>
        <div class="nas-form-row"><label class="nas-label">Webhook Secret</label><input type="password" id="st-razorpay_webhook_secret" class="nas-input" value="<?php echo sv2('razorpay_webhook_secret'); ?>" placeholder="From Razorpay Dashboard"></div>
      </div>
      <!-- Stripe -->
      <div>
        <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9">💳 Stripe (International cards)</div>
        <div class="nas-form-row"><label class="nas-label">Publishable Key</label><input type="text" id="st-stripe_publishable_key" class="nas-input" value="<?php echo sv2('stripe_publishable_key'); ?>" placeholder="pk_live_…"></div>
        <div class="nas-form-row"><label class="nas-label">Secret Key</label><input type="password" id="st-stripe_secret_key" class="nas-input" value="<?php echo sv2('stripe_secret_key'); ?>"></div>
        <div class="nas-form-row"><label class="nas-label">Webhook Secret</label><input type="password" id="st-stripe_webhook_secret" class="nas-input" value="<?php echo sv2('stripe_webhook_secret'); ?>"></div>
      </div>
      <!-- Bank Transfer -->
      <div>
        <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9">🏦 Bank Transfer / Manual</div>
        <div class="nas-form-row"><label class="nas-label">Bank Details (shown to client)</label><textarea id="st-bank_transfer_details" class="nas-textarea" rows="5" placeholder="Bank Name: XYZ Bank&#10;Account No: 1234567890&#10;IFSC: XYZB0001234&#10;Account Name: Your Company Name"><?php echo esc_textarea($s['bank_transfer_details']??''); ?></textarea></div>
        <div class="nas-form-row"><label class="nas-label">Platform Currency</label>
          <select id="st-currency" class="nas-select"><?php foreach(['INR'=>'₹ Indian Rupee','USD'=>'$ US Dollar','EUR'=>'€ Euro','GBP'=>'£ British Pound'] as $c=>$l): ?>
          <option value="<?= $c ?>" <?= ($s['currency']??'INR')===$c?'selected':'' ?>><?= esc_html($l) ?></option>
          <?php endforeach; ?></select>
        </div>
      </div>
    </div>
  </div>

  <!-- Company/About Settings -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-solid fa-building-user"></i> About / Company Info</h3></div>
    <div class="nas-form-stack" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div class="nas-form-row"><label class="nas-label">Founded Year</label><input type="text" id="st-founded_year" class="nas-input" value="<?php echo sv2('founded_year','2020'); ?>" placeholder="2020"></div>
      <div class="nas-form-row"><label class="nas-label">Tagline</label><input type="text" id="st-tagline" class="nas-input" value="<?php echo sv2('tagline'); ?>" placeholder="India's trusted newspaper ad platform"></div>
      <div class="nas-form-row"><label class="nas-label">Mission Statement</label><textarea id="st-mission" class="nas-textarea" rows="3"><?php echo esc_textarea($s['mission']??''); ?></textarea></div>
      <div class="nas-form-row"><label class="nas-label">Vision Statement</label><textarea id="st-vision" class="nas-textarea" rows="3"><?php echo esc_textarea($s['vision']??''); ?></textarea></div>
      <div class="nas-form-row"><label class="nas-label">Brand Address</label><textarea id="st-brand_address" class="nas-textarea" rows="2"><?php echo esc_textarea($s['brand_address']??''); ?></textarea></div>
      <div class="nas-form-row"><label class="nas-label">Brand Phone</label><input type="text" id="st-brand_phone" class="nas-input" value="<?php echo sv2('brand_phone'); ?>"></div>
      <div class="nas-form-row"><label class="nas-label">Brand Email</label><input type="email" id="st-brand_email" class="nas-input" value="<?php echo sv2('brand_email'); ?>"></div>
    </div>
  </div>

  <!-- Vendor Portal Settings -->
  <div class="nas-card">
    <div class="nas-card-header"><h3><i class="fa-solid fa-truck"></i> Vendor Portal</h3></div>
    <div class="nas-form-stack" style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div class="nas-form-row"><label class="nas-label">Auto-approve vendor registrations?</label>
        <select id="st-vendor_auto_approve" class="nas-select">
          <option value="0" <?= empty($s['vendor_auto_approve'])?'selected':'' ?>>No — Admin reviews and activates</option>
          <option value="1" <?= !empty($s['vendor_auto_approve'])&&$s['vendor_auto_approve']==='1'?'selected':'' ?>>Yes — Auto-activate on registration</option>
        </select>
      </div>
      <div class="nas-form-row"><label class="nas-label">Vendor portal URL</label>
        <div style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:monospace"><?php echo esc_html(home_url('/vendor-dashboard/')); ?></div>
      </div>
      <div class="nas-form-row"><label class="nas-label">Vendor registration URL</label>
        <div style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:monospace"><?php echo esc_html(home_url('/vendor-register/')); ?></div>
      </div>
    </div>
  </div>
</div><!-- /grid -->
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
window.stSave = function(btn){
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:stSpin .6s linear infinite;vertical-align:middle;margin-right:6px"></span>Saving…';

  const get = id => document.getElementById(id)?.value ?? '';
  const fd = new FormData();
  fd.append('action','nas_admin_save_settings');
  fd.append('nonce',C.nonce);
  // FIX (audit): every key below now matches the real settings table column name exactly
  // (cross-checked against database/Schema.php + SchemaV3.php + SchemaV4.php), and every field
  // that is rendered on this page (pre-filled from the same real columns via sv2()/$s[]) is now
  // actually sent. Previously, razorpay_*, stripe_*, bank_transfer_details, founded_year,
  // tagline, mission, vision, brand_address, brand_phone, brand_email, currency, and
  // vendor_auto_approve were all rendered and pre-filled correctly but never included in the
  // save request at all — editing them and clicking Save silently did nothing.
  fd.append('brand_name',              get('st-brand_name'));
  fd.append('logo_url',                get('st-logo_url'));
  fd.append('footer_text',             get('st-footer_text'));
  fd.append('gst_percentage',          get('st-gst_rate'));
  fd.append('gst_number',              get('st-gst_number'));
  fd.append('currency_symbol',         get('st-currency_symbol'));
  fd.append('currency',                get('st-currency'));
  fd.append('invoice_prefix',          get('st-invoice_prefix'));
  fd.append('cutoff_days',             get('st-cutoff_days'));
  fd.append('email_sender_name',       get('st-email_sender_name'));
  fd.append('email_sender_addr',       get('st-email_sender_address'));
  fd.append('whatsapp_number',         get('st-whatsapp_number'));
  fd.append('callmebot_api_key',       get('st-whatsapp_api_key'));
  fd.append('smtp_host',               get('st-smtp_host'));
  fd.append('smtp_port',               get('st-smtp_port'));
  fd.append('smtp_user',               get('st-smtp_user'));
  fd.append('smtp_pass',               get('st-smtp_pass'));
  fd.append('smtp_encryption',         get('st-smtp_encryption'));
  fd.append('ai_provider',             get('st-ai_provider'));
  fd.append('ai_api_key',              get('st-ai_api_key'));
  fd.append('ai_enabled', document.getElementById('st-ai_enabled')?.checked ? '1' : '0');
  fd.append('razorpay_key_id',         get('st-razorpay_key_id'));
  fd.append('razorpay_key_secret',     get('st-razorpay_key_secret'));
  fd.append('razorpay_webhook_secret', get('st-razorpay_webhook_secret'));
  fd.append('stripe_publishable_key',  get('st-stripe_publishable_key'));
  fd.append('stripe_secret_key',       get('st-stripe_secret_key'));
  fd.append('stripe_webhook_secret',   get('st-stripe_webhook_secret'));
  fd.append('bank_transfer_details',   get('st-bank_transfer_details'));
  fd.append('founded_year',            get('st-founded_year'));
  fd.append('tagline',                 get('st-tagline'));
  fd.append('mission',                 get('st-mission'));
  fd.append('vision',                  get('st-vision'));
  fd.append('brand_address',           get('st-brand_address'));
  fd.append('brand_phone',             get('st-brand_phone'));
  fd.append('brand_email',             get('st-brand_email'));
  fd.append('vendor_auto_approve',     get('st-vendor_auto_approve'));

  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal})
    .then(r=>r.json())
    .then(res=>{
      btn.disabled=false; btn.innerHTML=orig;
      nasAdminToast(res.success ? (res.data?.message||'Saved!') : (res.data?.message||'Error saving settings'),
                    res.success ? 'success' : 'error');
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML=orig; nasAdminToast('Network error. Please try again.','error'); });
};
})();
</script>
<style>@keyframes stSpin{to{transform:rotate(360deg)}}</style>
