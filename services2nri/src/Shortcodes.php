<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes — WordPress shortcode registration.
 * M-19: [s2nri_quote_form] — embeddable standalone quote form widget.
 *
 * TRACE: Bootstrap::init() calls Shortcodes::register() on init hook →
 *        add_shortcode() registers [s2nri_quote_form] →
 *        rendering builds HTML widget with inline JS that POSTs to public/quote-request.
 * PRECONDITIONS: S2NRI_DIR, home_url, wp_json_encode available.
 * POSTCONDITIONS: Returns HTML string (not echoed).
 * EDGE CASES: shortcode called without active services → shows service dropdown disabled.
 *             AJAX fail → shows inline error message.
 */
class Shortcodes {

    public static function register(): void {
        add_shortcode( 's2nri_quote_form', [ self::class, 'quoteForm' ] );
    }

    /**
     * [s2nri_quote_form]
     * Attributes:
     *   heading       = "Get Estimated Quote Instantly"
     *   cta           = "⚡ Submit Request"
     *   show_services = "yes"
     *   primary_color = "" (uses platform setting if empty)
     *
     * Renders a compact card-style multi-field quote form that submits to
     * POST /wp-json/s2nri/v1/public/quote-request via fetch().
     */
    public static function quoteForm( array $atts = [] ): string {
        $a = shortcode_atts([
            'heading'       => 'Get Estimated Quote Instantly',
            'cta'           => '⚡ Submit Request',
            'show_services' => 'yes',
            'primary_color' => '',
        ], $atts, 's2nri_quote_form');

        $color     = sanitize_hex_color($a['primary_color']) ?: \S2NRI\Models\Setting::get('primary_color','#4A6FA5');
        $heading_h = esc_html($a['heading']);
        $cta_h     = esc_html($a['cta']);
        $api_base  = rest_url('s2nri/v1');
        $nonce     = wp_create_nonce('s2nri_api');
        $uid       = 's2nri_qf_' . substr(md5(uniqid()),0,8); // unique per instance

        // Fetch services for dropdown (if show_services = yes)
        $services_json = '[]';
        if ( $a['show_services'] === 'yes' ) {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT s.id, CONCAT(c.name, ' — ', s.name) AS label
                 FROM {$wpdb->prefix}s2nri_services s
                 JOIN {$wpdb->prefix}s2nri_categories c ON c.id=s.category_id
                 WHERE s.is_active=1 ORDER BY c.sort_order ASC, s.sort_order ASC LIMIT 200",
                ARRAY_A
            );
            $services_json = wp_json_encode($rows ?: []);
        }

        $services_html = '';
        if ( $a['show_services'] === 'yes' ) {
            $services_html = '<div class="s2nri-qf-field"><select name="service_id" class="s2nri-qf-input" required>
              <option value="">Select Service *</option>
            </select></div>';
        }

        ob_start();
        ?>
<div id="<?php echo esc_attr($uid); ?>" class="s2nri-quote-widget" style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto">
  <div class="s2nri-qf-card" style="background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);overflow:hidden">
    <div class="s2nri-qf-header" style="background:<?php echo esc_attr($color); ?>;padding:20px 24px;text-align:center">
      <h3 style="color:#fff;margin:0;font-size:18px;font-weight:800"><?php echo $heading_h; ?></h3>
      <p style="color:rgba(255,255,255,.8);margin:6px 0 0;font-size:13px">Trusted by 10,000+ NRIs worldwide</p>
    </div>
    <div class="s2nri-qf-body" style="padding:24px">
      <form id="<?php echo esc_attr($uid); ?>_form" novalidate>
        <div class="s2nri-qf-field" style="margin-bottom:12px">
          <input type="text" name="name" placeholder="Your Full Name *" required class="s2nri-qf-input"
            style="width:100%;padding:11px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;box-sizing:border-box">
        </div>
        <div class="s2nri-qf-field" style="margin-bottom:12px">
          <input type="email" name="email" placeholder="Email Address *" required class="s2nri-qf-input"
            style="width:100%;padding:11px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;box-sizing:border-box">
        </div>
        <div class="s2nri-qf-field" style="margin-bottom:12px">
          <input type="tel" name="phone" placeholder="WhatsApp Number (with country code)" class="s2nri-qf-input"
            style="width:100%;padding:11px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;box-sizing:border-box">
        </div>
        <?php if ( $a['show_services'] === 'yes' ) : ?>
        <div class="s2nri-qf-field" style="margin-bottom:12px">
          <select name="service_id" id="<?php echo esc_attr($uid); ?>_svc" class="s2nri-qf-input"
            style="width:100%;padding:11px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;box-sizing:border-box;background:#fff;color:#374151">
            <option value="">Select Service</option>
          </select>
        </div>
        <?php endif; ?>
        <div class="s2nri-qf-field" style="margin-bottom:16px">
          <textarea name="message" rows="3" placeholder="Briefly describe your requirement" class="s2nri-qf-input"
            style="width:100%;padding:11px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;resize:vertical;box-sizing:border-box;font-family:inherit"></textarea>
        </div>
        <div id="<?php echo esc_attr($uid); ?>_error" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px;font-size:13px;color:#dc2626;margin-bottom:12px"></div>
        <button type="submit" id="<?php echo esc_attr($uid); ?>_btn"
          style="width:100%;background:<?php echo esc_attr($color); ?>;color:#fff;border:none;padding:14px 24px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;transition:opacity .2s">
          <?php echo $cta_h; ?>
        </button>
        <p style="text-align:center;font-size:12px;color:#9ca3af;margin:12px 0 0">🔒 SSL Secured &nbsp;|&nbsp; 📋 Instant Confirmation</p>
      </form>
      <div id="<?php echo esc_attr($uid); ?>_success" style="display:none;text-align:center;padding:20px">
        <div style="font-size:48px;margin-bottom:12px">✅</div>
        <h4 style="color:#15803d;margin:0 0 8px;font-size:18px">Request Submitted!</h4>
        <p id="<?php echo esc_attr($uid); ?>_msg" style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 16px"></p>
        <a id="<?php echo esc_attr($uid); ?>_track" href="#" target="_blank"
          style="display:inline-block;background:<?php echo esc_attr($color); ?>;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:700">
          Track My Application →
        </a>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var uid     = <?php echo wp_json_encode($uid); ?>;
  var apiBase = <?php echo wp_json_encode($api_base); ?>;
  var nonce   = <?php echo wp_json_encode($nonce); ?>;
  var svcs    = <?php echo $services_json; ?>;

  // Populate service dropdown
  if (svcs.length) {
    var sel = document.getElementById(uid+'_svc');
    if (sel) {
      svcs.forEach(function(s){
        var o = document.createElement('option');
        o.value = s.id; o.textContent = s.label;
        sel.appendChild(o);
      });
    }
  }

  var form    = document.getElementById(uid+'_form');
  var btn     = document.getElementById(uid+'_btn');
  var errDiv  = document.getElementById(uid+'_error');
  var success = document.getElementById(uid+'_success');
  var msgEl   = document.getElementById(uid+'_msg');
  var trackEl = document.getElementById(uid+'_track');

  form.addEventListener('submit', function(e){
    e.preventDefault();
    errDiv.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Submitting…';

    var data = {};
    new FormData(form).forEach(function(v,k){ data[k]=v; });

    fetch(apiBase+'/public/quote-request', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify(data),
      credentials:'include'
    }).then(function(r){ return r.json(); }).then(function(res){
      if (res.success) {
        form.style.display = 'none';
        success.style.display = 'block';
        msgEl.textContent = res.message || 'Your request has been received.';
        if (res.tracking_url) trackEl.href = res.tracking_url;
      } else {
        errDiv.textContent = res.error || 'Submission failed. Please try again.';
        errDiv.style.display = 'block';
        btn.disabled = false;
        btn.textContent = <?php echo wp_json_encode($a['cta']); ?>;
      }
    }).catch(function(){
      errDiv.textContent = 'Connection error. Please check your internet and try again.';
      errDiv.style.display = 'block';
      btn.disabled = false;
      btn.textContent = <?php echo wp_json_encode($a['cta']); ?>;
    });
  });
})();
</script>
<?php
        return ob_get_clean();
    }
}
