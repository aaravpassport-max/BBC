<?php
/**
 * NAS Admin — PWA & Mobile App Settings
 * Standalone dark-themed page matching marketplace-os AdminSettings.jsx style.
 * ThemePicker: hover-to-preview, click-to-select, active checkmark
 * BgPicker: preset grid + custom hex input + live preview strip
 * Saves via nas_pwa_save_settings AJAX → response contains theme_css for instant apply
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can('manage_options') ) { wp_die('Unauthorised'); }

use NAS\Modules\PWA\NASTheme;
use NAS\Core\Config;

$cfg        = Config::instance();
$pwa_theme  = $cfg->get('pwa_theme',     'green');
$pwa_bg     = $cfg->get('pwa_portal_bg', '#0e1117');
$pwa_accent = $cfg->get('pwa_accent_color','');
$pwa_btn    = $cfg->get('pwa_button_color','');
$palette    = NASTheme::palette($pwa_theme);
$primary    = $pwa_accent ?: $palette['primary'];
$btn_color  = $pwa_btn ?: $primary;
$nonce      = wp_create_nonce('nas_admin_nonce');
$pwa_url    = home_url('/nas-app/');
$brand      = $cfg->get('brand_name', get_bloginfo('name'));
?>
<div class="wrap" id="nas-pwa-settings-wrap">
<style>
/* === Scoped to #nas-pwa-settings-wrap — no theme bleed === */
#nas-pwa-settings-wrap *{box-sizing:border-box}
#nas-pwa-settings-wrap{font-family:'Inter',-apple-system,sans-serif;padding:24px 0;max-width:820px}
#nas-pwa-settings-wrap h1{font-size:22px;font-weight:800;color:#0f172a;margin-bottom:6px}
#nas-pwa-settings-wrap p.desc{font-size:14px;color:#64748b;margin-bottom:24px;line-height:1.5}
/* Section card — matches marketplace-os Section component */
.pwa-section{border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:16px;background:#fff}
.pwa-section-hdr{display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid #f1f5f9;background:#f8fafc}
.pwa-section-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.pwa-section-title{font-size:14px;font-weight:700;color:#1e293b}
.pwa-section-desc{font-size:12px;color:#94a3b8;margin-top:2px}
.pwa-section-body{padding:20px;background:#fff}
/* Preview bar */
.pwa-preview-bar{border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;margin-top:12px}
.pwa-preview-top{padding:12px 16px;display:flex;align-items:center;justify-content:space-between;transition:background .2s}
.pwa-preview-logo{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;flex-shrink:0;transition:background .2s}
.pwa-preview-name{font-size:14px;font-weight:800;transition:color .2s}
.pwa-preview-btn{border:none;border-radius:8px;padding:7px 16px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;transition:background .2s}
/* ThemePicker */
.theme-picker{display:flex;flex-direction:column;gap:8px}
.theme-option{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:11px;border:1.5px solid #e2e8f0;cursor:pointer;transition:all .15s;background:#fff;position:relative}
.theme-option:hover{border-color:#cbd5e1;background:#f8fafc}
.theme-option.active{background:#fafafa}
.theme-swatch{width:24px;height:24px;border-radius:50%;flex-shrink:0;transition:box-shadow .15s}
.theme-name{flex:1;font-size:14px;font-weight:600;color:#374151}
.theme-check{width:20px;height:20px;border-radius:50%;background:#00d084;color:#fff;display:none;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0}
.theme-option.active .theme-check{display:flex}
.theme-option.active .theme-swatch{box-shadow:0 0 0 3px rgba(0,0,0,.08)}
/* BgPicker */
.bg-presets-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
.bg-preset{border-radius:10px;border:2px solid transparent;overflow:hidden;cursor:pointer;transition:all .15s}
.bg-preset:hover{border-color:#94a3b8;transform:translateY(-1px)}
.bg-preset.active{border-color:#6366f1}
.bg-preset-swatch{height:36px;transition:background .2s}
.bg-preset-label{padding:4px 8px;font-size:11px;font-weight:600;color:#64748b;text-align:center;background:#f8fafc}
.bg-preset.active .bg-preset-label{color:#6366f1}
/* Color inputs */
.color-row{display:flex;align-items:center;gap:10px}
.color-picker-input{width:44px;height:44px;border:2px solid #e2e8f0;padding:2px;cursor:pointer;border-radius:10px;background:none}
.color-text-input{flex:1;padding:10px 14px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:monospace;color:#374151;transition:border-color .15s}
.color-text-input:focus{border-color:#6366f1;outline:none}
.color-reset-btn{padding:8px 14px;border:1px solid #e2e8f0;background:#fff;border-radius:9px;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;white-space:nowrap;font-family:inherit;transition:all .15s}
.color-reset-btn:hover{background:#f1f5f9}
/* Save button */
.pwa-save-btn{display:flex;align-items:center;gap:8px;padding:12px 28px;background:#0f172a;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s;margin-top:4px}
.pwa-save-btn:hover{background:#1e293b}
.pwa-save-btn:disabled{opacity:.5;cursor:not-allowed}
/* PWA link */
.pwa-link-banner{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0;border-radius:12px;padding:14px 16px;margin-bottom:20px;display:flex;align-items:center;gap:12px}
.pwa-link-url{font-size:13px;font-family:monospace;background:#fff;padding:6px 12px;border-radius:7px;border:1px solid #bbf7d0;color:#166534;flex:1}
/* Toggle */
.n-toggle{display:flex;align-items:flex-start;gap:12px;cursor:pointer;user-select:none;padding:4px 0}
.n-toggle-track{position:relative;width:44px;height:24px;border-radius:99px;flex-shrink:0;margin-top:2px;transition:all .2s;cursor:pointer;border:none;font-family:inherit}
.n-toggle-thumb{position:absolute;top:2px;width:20px;height:20px;border-radius:50%;transition:all .2s;box-shadow:0 1px 4px rgba(0,0,0,.2)}
.n-toggle-lbl{font-size:14px;font-weight:500;color:#374151}
.n-toggle-hint{font-size:12px;color:#94a3b8;margin-top:2px}
/* Divider */
.pwa-divider{height:1px;background:#f1f5f9;margin:16px 0}
/* msg */
.pwa-save-msg{font-size:13px;font-weight:600;margin-left:8px;display:none}
.pwa-save-msg.success{color:#16a34a}.pwa-save-msg.error{color:#dc2626}
</style>

<h1>📱 PWA &amp; Mobile App Settings</h1>
<p class="desc">Appearance changes apply instantly — click Save to make them permanent. Changes are visible at <strong><?php echo esc_html($pwa_url); ?></strong></p>

<!-- Site-Wide Mode Toggle -->
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:16px">
  <span style="font-size:28px;flex-shrink:0">⚙️</span>
  <div style="flex:1">
    <div style="font-size:14px;font-weight:700;color:#92400e;margin-bottom:4px">Site-Wide App Mode</div>
    <div style="font-size:13px;color:#92400e;opacity:.8;line-height:1.5">When enabled, ALL public pages on your website are served through the PWA shell — giving every visitor an app-like experience. Portal pages (dashboard, booking, admin) keep their own templates.</div>
  </div>
  <label style="display:flex;align-items:center;gap:10px;cursor:pointer;flex-shrink:0">
    <div style="font-size:13px;font-weight:600;color:#92400e"><?php echo $cfg->get('nas_pwa_site_wide',0)?'Enabled':'Disabled'; ?></div>
    <button type="button" id="pwa-sitewide-toggle"
      onclick="pwaSitewideToggle(this)"
      style="width:48px;height:26px;border-radius:99px;background:<?php echo $cfg->get('nas_pwa_site_wide',0)?'#00d084':'#e2e8f0'; ?>;border:none;position:relative;cursor:pointer;transition:background .2s"
      data-value="<?php echo (int)$cfg->get('nas_pwa_site_wide',0); ?>">
      <span style="position:absolute;top:3px;left:<?php echo $cfg->get('nas_pwa_site_wide',0)?'26px':'3px'; ?>;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.2);transition:left .2s"></span>
    </button>
    <input type="hidden" id="pwa-sitewide-val" value="<?php echo (int)$cfg->get('nas_pwa_site_wide',0); ?>">
  </label>
</div>

<!-- PWA Link Banner -->
<div class="pwa-link-banner">
  <span style="font-size:24px">📲</span>
  <div style="flex:1">
    <div style="font-size:13px;font-weight:700;color:#166534;margin-bottom:4px">Your mobile app is live</div>
    <code class="pwa-link-url"><?php echo esc_html($pwa_url); ?></code>
  </div>
  <a href="<?php echo esc_url($pwa_url); ?>" target="_blank"
     style="padding:8px 16px;background:#166534;color:#fff;border-radius:9px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap">Open App ↗</a>
</div>

<!-- Section 1: APPEARANCE — ThemePicker + BgPicker + preview (marketplace-os AdminSettings Section component) -->
<div class="pwa-section">
  <div class="pwa-section-hdr">
    <div class="pwa-section-icon" id="sec1-icon" style="background:<?php echo esc_attr($primary); ?>20">
      <span style="color:<?php echo esc_attr($primary); ?>">🎨</span>
    </div>
    <div>
      <div class="pwa-section-title">Appearance</div>
      <div class="pwa-section-desc">Change accent colour and portal background — preview updates instantly</div>
    </div>
  </div>
  <div class="pwa-section-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px">

      <!-- ThemePicker — matches marketplace-os ThemePicker.jsx -->
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:12px">Accent / Primary Colour</div>
        <div class="theme-picker" id="pwa-theme-picker">
          <?php foreach ( NASTheme::palettes() as $key => $p ): ?>
          <label class="theme-option<?php echo $key===$pwa_theme?' active':''; ?>"
                 data-key="<?php echo esc_attr($key); ?>"
                 onmouseenter="pwaPrevTheme('<?php echo esc_js($key); ?>')"
                 onmouseleave="pwaRestoreTheme()">
            <input type="radio" name="pwa_theme" value="<?php echo esc_attr($key); ?>"
                   <?php checked($key,$pwa_theme); ?> style="display:none"
                   onchange="pwaSelectTheme('<?php echo esc_js($key); ?>')">
            <span class="theme-swatch" style="background:<?php echo esc_attr($p['primary']); ?>;box-shadow:0 0 0 3px <?php echo esc_attr($p['primary']); ?>40"></span>
            <span class="theme-name"><?php echo esc_html($p['name']); ?></span>
            <span class="theme-check">✓</span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Right column: BgPicker + accent/btn overrides + preview -->
      <div>
        <!-- BgPicker — matches marketplace-os BgPicker.jsx -->
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:10px">Portal Background</div>
        <div class="bg-presets-grid" id="pwa-bg-presets">
          <?php foreach ( NASTheme::bg_presets() as $preset ):
            if($preset['key']==='custom') continue;
            $is_active = $preset['value'] === $pwa_bg;
          ?>
          <div class="bg-preset<?php echo $is_active?' active':''; ?>"
               onclick="pwaSelectBg('<?php echo esc_js($preset['value']); ?>',this)">
            <div class="bg-preset-swatch" style="background:<?php echo esc_attr($preset['value']); ?>"></div>
            <div class="bg-preset-label"><?php echo esc_html($preset['label']); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <!-- Custom hex -->
        <div class="color-row" style="margin-bottom:16px">
          <input type="color" id="pwa-bg-picker" class="color-picker-input"
                 value="<?php echo esc_attr($pwa_bg); ?>"
                 oninput="pwaSelectBg(this.value,null,true);document.getElementById('pwa-bg-hex').value=this.value">
          <input type="text" id="pwa-bg-hex" class="color-text-input"
                 value="<?php echo esc_attr($pwa_bg); ?>" placeholder="#0e1117" maxlength="7"
                 oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){pwaSelectBg(this.value,null,true);document.getElementById('pwa-bg-picker').value=this.value}">
          <div id="pwa-bg-swatch" style="width:36px;height:36px;border-radius:8px;background:<?php echo esc_attr($pwa_bg); ?>;border:1px solid #e2e8f0;flex-shrink:0"></div>
        </div>

        <!-- Accent override -->
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:8px">Custom Accent (overrides theme primary)</div>
        <div class="color-row" style="margin-bottom:14px">
          <input type="color" id="pwa-accent-picker" class="color-picker-input"
                 value="<?php echo esc_attr($pwa_accent ?: $primary); ?>"
                 oninput="document.getElementById('pwa-accent-hex').value=this.value;pwaLiveAccent(this.value)">
          <input type="text" id="pwa-accent-hex" class="color-text-input"
                 value="<?php echo esc_attr($pwa_accent); ?>" placeholder="Leave blank = use theme colour"
                 oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById('pwa-accent-picker').value=this.value;pwaLiveAccent(this.value)}">
          <button class="color-reset-btn" onclick="pwaResetAccent()">Reset</button>
        </div>

        <!-- Button colour -->
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:8px">Button / CTA Colour (can differ from accent)</div>
        <div class="color-row" style="margin-bottom:16px">
          <input type="color" id="pwa-btn-picker" class="color-picker-input"
                 value="<?php echo esc_attr($pwa_btn ?: $btn_color); ?>"
                 oninput="document.getElementById('pwa-btn-hex').value=this.value;pwaLiveBtn(this.value)">
          <input type="text" id="pwa-btn-hex" class="color-text-input"
                 value="<?php echo esc_attr($pwa_btn); ?>" placeholder="Leave blank = use accent colour"
                 oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById('pwa-btn-picker').value=this.value;pwaLiveBtn(this.value)}">
          <button class="color-reset-btn" onclick="pwaResetBtn()">Reset</button>
        </div>

        <!-- Live Preview strip — matches marketplace-os preview strip in AdminSettings.jsx -->
        <div class="pwa-preview-bar" id="pwa-preview-bar">
          <div class="pwa-preview-top" id="pwa-preview-top" style="background:<?php echo esc_attr($pwa_bg); ?>">
            <div style="display:flex;align-items:center;gap:8px">
              <div class="pwa-preview-logo" id="pwa-preview-logo" style="background:<?php echo esc_attr($primary); ?>;color:#060c18">📰</div>
              <span class="pwa-preview-name" id="pwa-preview-name" style="color:#e8f4fc"><?php echo esc_html($brand); ?></span>
            </div>
            <button class="pwa-preview-btn" id="pwa-preview-btn" style="background:<?php echo esc_attr($btn_color); ?>;color:#060c18">Book Now</button>
          </div>
          <div id="pwa-preview-bottom" style="padding:10px 14px;font-size:12px;color:#64748b;background:#f8fafc;border-top:1px solid #f1f5f9;text-align:center">← Live preview — changes appear instantly</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Section 2: App Icons -->
<div class="pwa-section">
  <div class="pwa-section-hdr">
    <div class="pwa-section-icon" style="background:#f0f9ff">
      <span style="color:#0ea5e9">🖼️</span>
    </div>
    <div>
      <div class="pwa-section-title">App Icons</div>
      <div class="pwa-section-desc">Shown when users install the app on their home screen</div>
    </div>
  </div>
  <div class="pwa-section-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:6px">App Icon 192×192 (PNG)</label>
        <input type="url" id="pwa-icon-192" class="color-text-input" style="font-family:inherit;font-size:13px"
               value="<?php echo esc_attr($cfg->get('pwa_icon_192','')); ?>"
               placeholder="https://yourdomain.com/icon-192.png">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:6px">App Icon 512×512 (PNG)</label>
        <input type="url" id="pwa-icon-512" class="color-text-input" style="font-family:inherit;font-size:13px"
               value="<?php echo esc_attr($cfg->get('pwa_icon_512','')); ?>"
               placeholder="https://yourdomain.com/icon-512.png">
      </div>
    </div>
  </div>
</div>

<!-- Section 3: Push Notifications / VAPID -->
<div class="pwa-section">
  <div class="pwa-section-hdr">
    <div class="pwa-section-icon" style="background:#fef3c7">
      <span style="color:#d97706">🔔</span>
    </div>
    <div>
      <div class="pwa-section-title">Push Notifications</div>
      <div class="pwa-section-desc">VAPID keys enable server-sent push to subscribed users. Generate at <a href="https://vapidkeys.com" target="_blank" style="color:#6366f1">vapidkeys.com</a> — keep private key secret.</div>
    </div>
  </div>
  <div class="pwa-section-body">
    <div style="display:grid;grid-template-columns:1fr;gap:14px">
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <label style="font-size:12px;font-weight:600;color:#64748b">VAPID Public Key</label>
        <button onclick="generateAndFillVAPID()" style="font-size:11px;font-weight:700;color:#6366f1;background:none;border:none;cursor:pointer;font-family:inherit;padding:0">⚡ Generate Keys</button>
      </div>
        <input type="text" id="vapid-public-key" class="color-text-input" style="font-family:monospace;font-size:12px;width:100%"
               value="<?php echo esc_attr($cfg->get('vapid_public_key','')); ?>"
               placeholder="BH... (your base64url VAPID public key)">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:6px">VAPID Private Key <span style="color:#ef4444;font-size:11px">(keep secret)</span></label>
        <input type="password" id="vapid-private-key" class="color-text-input" style="font-family:monospace;font-size:12px;width:100%"
               value="<?php echo esc_attr($cfg->get('vapid_private_key','')); ?>"
               placeholder="Your VAPID private key">
      </div>
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px;font-size:12px;color:#92400e;line-height:1.6">
        <strong>Push subscribers:</strong>
        <?php
        global $wpdb;
        $sub_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nas_pwa_push_subscriptions");
        echo esc_html($sub_count);
        ?> users subscribed to push notifications.
      </div>
    </div>
    <!-- Manual Campaign Sender -->
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9">
      <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:12px">Send Manual Push Notification</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:5px">Title</label>
          <input type="text" id="push-title" class="color-text-input" placeholder="Notification title" style="font-family:inherit;font-size:14px;width:100%">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:5px">Target</label>
          <select id="push-target" class="color-text-input" style="font-family:inherit;font-size:14px;width:100%">
            <option value="all">All Subscribers</option>
            <option value="clients">Clients Only</option>
          </select>
        </div>
      </div>
      <div style="margin-bottom:12px">
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:5px">Message Body</label>
        <textarea id="push-body" class="color-text-input" rows="3" placeholder="Push notification message…" style="font-family:inherit;font-size:14px;width:100%;resize:vertical"></textarea>
      </div>
      <div style="margin-bottom:14px">
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:5px">Action URL (optional)</label>
        <input type="url" id="push-url" class="color-text-input" placeholder="https://yourdomain.com/nas-app/" style="font-family:inherit;font-size:14px;width:100%">
      </div>
      <button class="pwa-save-btn" onclick="sendPushCampaign()" id="push-send-btn" style="background:#d97706">
        🔔 Send Push Notification
      </button>
      <span class="pwa-save-msg" id="push-send-msg"></span>
      <!-- Campaign History -->
      <div id="push-campaigns" style="margin-top:16px;display:none">
        <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px">Recent Campaigns</div>
        <div id="push-campaign-list"></div>
      </div>
    </div>
  </div>
</div>

<!-- Section 4: Analytics Overview -->
<div class="pwa-section">
  <div class="pwa-section-hdr">
    <div class="pwa-section-icon" style="background:#f0f9ff">
      <span style="color:#0ea5e9">📊</span>
    </div>
    <div>
      <div class="pwa-section-title">PWA Analytics (Last 30 days)</div>
      <div class="pwa-section-desc">Screen views, sessions, installs and booking funnel</div>
    </div>
  </div>
  <div class="pwa-section-body">
    <div id="pwa-analytics-area">
      <div style="text-align:center;padding:20px;color:#94a3b8;font-size:13px">Loading analytics…</div>
    </div>
  </div>
</div>

<!-- Save Button -->
<div style="display:flex;align-items:center;gap:12px;margin-top:8px">
  <button class="pwa-save-btn" id="pwa-save-btn" onclick="pwaSave()">
    💾 Save PWA Settings
  </button>
  <span class="pwa-save-msg" id="pwa-save-msg"></span>
</div>

<script>
(function(){
const AJAX  = <?php echo wp_json_encode(get_permalink() ?: home_url('/')); ?>;
const NONCE = <?php echo wp_json_encode($nonce); ?>;
const THEMES= <?php echo wp_json_encode(NASTheme::palettes()); ?>;
let currentTheme = <?php echo wp_json_encode($pwa_theme); ?>;
let currentBg    = <?php echo wp_json_encode($pwa_bg); ?>;
let previewState = null; // stores before-preview state for restoreTheme

/* === Layer 3 theme engine — same as shell.php and marketplace-os === */
function blend(hex,pct){const n=parseInt(hex.replace('#',''),16),rr=(n>>16)&0xff,gg=(n>>8)&0xff,bb=n&0xff,m=c=>Math.round(c+(255-c)*pct).toString(16).padStart(2,'0');return'#'+m(rr)+m(gg)+m(bb);}
function lum(hex){const n=parseInt(hex.replace('#',''),16);return 0.2126*((n>>16)&0xff)/255+0.7152*((n>>8)&0xff)/255+0.0722*(n&0xff)/255;}

/* Preview strip update */
function updatePreview(bg, accent, btnColor){
  const top   = document.getElementById('pwa-preview-top');
  const logo  = document.getElementById('pwa-preview-logo');
  const name  = document.getElementById('pwa-preview-name');
  const btn   = document.getElementById('pwa-preview-btn');
  if(top)  top.style.background = bg;
  if(logo) logo.style.background= accent;
  if(btn)  btn.style.background = btnColor||accent;
  // Derive ink colour from bg luminance
  const inkColor = lum(bg)>0.4 ? '#1e293b' : '#e8f4fc';
  if(name) name.style.color = inkColor;
}

/* ThemePicker: hover to preview (previewTheme), mouse leave to restore (restoreTheme) */
function pwaPrevTheme(key){
  const p=THEMES[key]; if(!p) return;
  const btnOverride = document.getElementById('pwa-btn-hex')?.value||'';
  const accentOverride = document.getElementById('pwa-accent-hex')?.value||'';
  updatePreview(currentBg, accentOverride||p.primary, btnOverride||accentOverride||p.primary);
  // Update icon in section header
  const ico=document.getElementById('sec1-icon');
  if(ico){ico.style.background=p.primary+'20';ico.querySelector('span').style.color=p.primary;}
}
function pwaRestoreTheme(){
  const accentOverride = document.getElementById('pwa-accent-hex')?.value||'';
  const btnOverride    = document.getElementById('pwa-btn-hex')?.value||'';
  const p=THEMES[currentTheme]||THEMES.green;
  updatePreview(currentBg, accentOverride||p.primary, btnOverride||accentOverride||p.primary);
}
function pwaSelectTheme(key){
  currentTheme=key;
  // Remove active from all, set on selected
  document.querySelectorAll('.theme-option').forEach(el=>{
    el.classList.toggle('active', el.dataset.key===key);
  });
  const p=THEMES[key];if(!p)return;
  const accentOverride = document.getElementById('pwa-accent-hex')?.value||'';
  const btnOverride    = document.getElementById('pwa-btn-hex')?.value||'';
  // Update accent picker if no override set
  if(!accentOverride){
    document.getElementById('pwa-accent-picker').value=p.primary;
  }
  updatePreview(currentBg, accentOverride||p.primary, btnOverride||accentOverride||p.primary);
  const ico=document.getElementById('sec1-icon');
  if(ico){ico.style.background=p.primary+'20';ico.querySelector('span').style.color=p.primary;}
}

/* BgPicker: preset click or hex input */
function pwaSelectBg(hex, el, isCustom){
  currentBg=hex;
  // Update swatch + hex text
  const swatch=document.getElementById('pwa-bg-swatch');if(swatch)swatch.style.background=hex;
  if(!isCustom){
    document.getElementById('pwa-bg-hex').value=hex;
    document.getElementById('pwa-bg-picker').value=hex;
  }
  // Remove active from presets, activate clicked one
  document.querySelectorAll('.bg-preset').forEach(p=>p.classList.remove('active'));
  if(el)el.classList.add('active');
  else if(!isCustom){/* nothing */}
  // Update preview
  const accentOverride = document.getElementById('pwa-accent-hex')?.value||'';
  const btnOverride    = document.getElementById('pwa-btn-hex')?.value||'';
  const p=THEMES[currentTheme]||THEMES.green;
  updatePreview(hex, accentOverride||p.primary, btnOverride||accentOverride||p.primary);
}

/* Accent and button colour live update */
function pwaLiveAccent(hex){
  const btnOverride = document.getElementById('pwa-btn-hex')?.value||'';
  updatePreview(currentBg, hex, btnOverride||hex);
}
function pwaLiveBtn(hex){
  const accentOverride = document.getElementById('pwa-accent-hex')?.value||'';
  const p=THEMES[currentTheme]||THEMES.green;
  updatePreview(currentBg, accentOverride||p.primary, hex);
}
function pwaResetAccent(){
  document.getElementById('pwa-accent-hex').value='';
  const p=THEMES[currentTheme]||THEMES.green;
  document.getElementById('pwa-accent-picker').value=p.primary;
  pwaLiveAccent(p.primary);
}
function pwaResetBtn(){
  document.getElementById('pwa-btn-hex').value='';
  const accentOverride=document.getElementById('pwa-accent-hex').value||'';
  const p=THEMES[currentTheme]||THEMES.green;
  document.getElementById('pwa-btn-picker').value=accentOverride||p.primary;
}

/* Save — sends to nas_pwa_save_settings, response has theme_css for instant apply */
async function pwaSave(){
  const btn=document.getElementById('pwa-save-btn');
  const msg=document.getElementById('pwa-save-msg');
  btn.disabled=true;btn.textContent='Saving…';
  if(msg){msg.style.display='none';}
  const fd=new FormData();
  fd.append('action','nas_pwa_save_settings');
  fd.append('nonce', NONCE);
  fd.append('pwa_theme',        currentTheme);
  fd.append('pwa_portal_bg',    document.getElementById('pwa-bg-hex').value||currentBg);
  fd.append('pwa_bg_preset',    'custom');
  fd.append('pwa_accent_color', document.getElementById('pwa-accent-hex').value||'');
  fd.append('pwa_button_color', document.getElementById('pwa-btn-hex').value||'');
  fd.append('pwa_icon_192',     document.getElementById('pwa-icon-192').value||'');
  fd.append('pwa_icon_512',     document.getElementById('pwa-icon-512').value||'');
  fd.append('nas_pwa_site_wide', document.getElementById('pwa-sitewide-val')?.value||'0');
  fd.append('vapid_public_key', document.getElementById('vapid-public-key')?.value||'');
  fd.append('vapid_private_key',document.getElementById('vapid-private-key')?.value||'');
  try{
    const res=await fetch(AJAX,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
      if(msg){msg.textContent='✓ Settings saved successfully';msg.className='pwa-save-msg success';msg.style.display='inline';}
      // Inject updated theme CSS into the admin page's <head> so preview stays in sync
      let style=document.getElementById('nas-pwa-admin-theme');
      if(!style){style=document.createElement('style');style.id='nas-pwa-admin-theme';document.head.appendChild(style);}
      style.textContent=data.data?.theme_css||'';
    } else {
      if(msg){msg.textContent='✗ '+(data.data?.message||'Error saving');msg.className='pwa-save-msg error';msg.style.display='inline';}
    }
  }catch(e){
    if(msg){msg.textContent='✗ Network error. Please try again.';msg.className='pwa-save-msg error';msg.style.display='inline';}
  }
  btn.disabled=false;btn.textContent='💾 Save PWA Settings';
  setTimeout(()=>{if(msg)msg.style.display='none';},4000);
}

// Push campaign sender
async function sendPushCampaign() {
  const title = document.getElementById('push-title')?.value?.trim();
  const body  = document.getElementById('push-body')?.value?.trim();
  const url   = document.getElementById('push-url')?.value?.trim();
  const target= document.getElementById('push-target')?.value;
  const btn   = document.getElementById('push-send-btn');
  const msg   = document.getElementById('push-send-msg');
  if (!title || !body) { if(msg){msg.textContent='Title and body required';msg.className='pwa-save-msg error';msg.style.display='inline';}return; }
  if (!confirm(`Send "${title}" to all subscribed users?`)) return;
  if(btn){btn.disabled=true;btn.textContent='Sending…';}
  const fd=new FormData();fd.append('action','nas_pwa_send_push_campaign');fd.append('nonce',NONCE);fd.append('title',title);fd.append('body',body);fd.append('url',url||'');fd.append('target',target);
  try {
    const res=await fetch(AJAX,{method:'POST',body:fd});const data=await res.json();
    if(data.success){if(msg){msg.textContent=`✓ Sent to ${data.data.sent} of ${data.data.total} subscribers`;msg.className='pwa-save-msg success';msg.style.display='inline';}}
    else{if(msg){msg.textContent='✗ '+(data.data?.message||'Error');msg.className='pwa-save-msg error';msg.style.display='inline';}}
    loadCampaigns();
  } catch(e){if(msg){msg.textContent='✗ Network error';msg.className='pwa-save-msg error';msg.style.display='inline';}}
  if(btn){btn.disabled=false;btn.textContent='🔔 Send Push Notification';}
  setTimeout(()=>{if(msg)msg.style.display='none';},5000);
}

async function loadCampaigns() {
  const fd=new FormData();fd.append('action','nas_pwa_get_push_campaigns');fd.append('nonce',NONCE);
  const res=await fetch(AJAX,{method:'POST',body:fd});const data=await res.json();
  if(!data.success) return;
  const rows=data.data?.campaigns||[];
  const wrap=document.getElementById('push-campaigns');const list=document.getElementById('push-campaign-list');
  if(!rows.length){if(wrap)wrap.style.display='none';return;}
  if(wrap)wrap.style.display='block';
  if(list)list.innerHTML=rows.map(r=>`<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:12px"><div><span style="font-weight:700;color:#374151">${r.title}</span><span style="color:#94a3b8;margin-left:8px">${r.sent_at||r.created_at}</span></div><span style="background:#d1fae5;color:#065f46;border-radius:6px;padding:2px 8px;font-weight:700">${r.sent_count} sent</span></div>`).join('');
}

async function loadAnalytics() {
  const fd=new FormData();fd.append('action','nas_pwa_analytics_summary');fd.append('nonce',NONCE);fd.append('days','30');
  const area=document.getElementById('pwa-analytics-area');
  try {
    const res=await fetch(AJAX,{method:'POST',body:fd});const data=await res.json();
    if(!data.success){if(area)area.innerHTML='<div style="font-size:12px;color:#94a3b8">Analytics not available yet.</div>';return;}
    const d=data.data;
    const convRate = d.bookings_started>0?Math.round((d.bookings_done/d.bookings_started)*100):0;
    if(area)area.innerHTML=`<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
      ${[['👁️','Screen Views',d.total_views],['📱','Sessions',d.unique_sessions],['📲','App Installs',d.installs],['📋','Bookings Started',d.bookings_started],['✅','Bookings Done',d.bookings_done],['🔔','Push Subscribers',d.push_subs]].map(([ico,lbl,val])=>`<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px">${ico}</div><div style="font-size:20px;font-weight:800;color:#1e293b;margin:2px 0">${val||0}</div><div style="font-size:11px;color:#94a3b8">${lbl}</div></div>`).join('')}
    </div>
    <div style="background:#f8fafc;border-radius:10px;padding:12px;margin-bottom:12px;font-size:13px"><strong>Conversion Rate:</strong> ${convRate}% (bookings started → completed)</div>
    <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px">Top Screens</div>
    <div>${(d.top_screens||[]).map(s=>`<div style="display:flex;align-items:center;gap:8px;padding:5px 0"><div style="flex:1;font-size:12px;color:#374151">${s.screen||'(root)'}</div><div style="font-size:12px;font-weight:700;color:#6366f1">${s.views} views</div></div>`).join('')||'<div style="font-size:12px;color:#94a3b8">No screen data yet.</div>'}</div>`;
  } catch(e) { if(area) area.innerHTML='<div style="font-size:12px;color:#94a3b8">Analytics not available.</div>'; }
}

// Auto-load on page
loadCampaigns();
loadAnalytics();

// Expose for inline handlers
// VAPID key generation using WebCrypto
async function generateAndFillVAPID() {
  try {
    if (!confirm('This will generate a new VAPID key pair. All existing push subscriptions will need to re-subscribe. Continue?')) return;
    const kp = await crypto.subtle.generateKey({name:'ECDSA',namedCurve:'P-256'},true,['sign','verify']);
    const pub = await crypto.subtle.exportKey('raw', kp.publicKey);
    const priv = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
    const b64u = buf => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
    document.getElementById('vapid-public-key').value = b64u(pub);
    document.getElementById('vapid-private-key').value = b64u(priv);
    alert('VAPID keys generated! Click Save PWA Settings to store them.');
  } catch(e) {
    alert('Key generation failed: '+e.message+'. Use vapidkeys.com to generate keys manually.');
  }
}

function pwaSitewideToggle(btn) {
  var cur = parseInt(btn.dataset.value || '0');
  var next = cur ? 0 : 1;
  btn.dataset.value = next;
  btn.style.background = next ? '#00d084' : '#e2e8f0';
  btn.querySelector('span').style.left = next ? '26px' : '3px';
  document.getElementById('pwa-sitewide-val').value = next;
  btn.previousElementSibling.textContent = next ? 'Enabled' : 'Disabled';
}
window.pwaPrevTheme=pwaPrevTheme;window.pwaRestoreTheme=pwaRestoreTheme;
window.pwaSelectTheme=pwaSelectTheme;window.pwaSelectBg=pwaSelectBg;
window.pwaLiveAccent=pwaLiveAccent;window.pwaLiveBtn=pwaLiveBtn;
window.pwaResetAccent=pwaResetAccent;window.pwaResetBtn=pwaResetBtn;
window.pwaSave=pwaSave;
})();
</script>
</div>
