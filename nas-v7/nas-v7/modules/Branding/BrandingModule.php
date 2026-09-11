<?php
namespace NAS\Modules\Branding;
use NAS\Core\{Module, Database, Security, Config};
if ( ! defined( 'ABSPATH' ) ) exit;

class BrandingModule extends Module {
    public function key(): string { return 'branding'; }
    public function register(): void {
        add_action('wp_ajax_nas_get_branding',  [BrandingController::class, 'get']);
        add_action('wp_ajax_nas_save_branding', [BrandingController::class, 'save']);
    }
    public function boot(): void {
        // Inject CSS custom properties for brand colors into frontend
        add_action('wp_head', [self::class, 'inject_css_vars']);
        add_action('wp_head', [self::class, 'inject_seo_meta']);
    }

    // TRACE: inject_css_vars() — Trigger: wp_ajax_inject_css_vars AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function inject_css_vars(): void {
        $cfg   = Config::instance();
        $raw   = $cfg->get('brand_primary_color','#1A3A5C');
        // Sanitize: only allow valid hex colors to prevent CSS injection
        $color = preg_match('/^#[0-9A-Fa-f]{3,8}$/', $raw) ? $raw : '#1A3A5C';
        echo "<style>:root{--nas-primary:{$color};--nas-primary-dark:{$color};}</style>\n";
    }

    // TRACE: inject_seo_meta() — Trigger: wp_ajax_inject_seo_meta AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function inject_seo_meta(): void {
        if (!is_singular() && !is_front_page()) return;
        $cfg         = Config::instance();
        $brand_name  = $cfg->get('brand_name','NewspaperAds Pro');
        $description = get_bloginfo('description') ?: "Book newspaper ads online across 300+ Indian newspapers. Classified, Display, Matrimonial, Property ads at best rates.";
        $og_image    = $cfg->get('logo_url','');
        echo "<meta name='description' content='" . esc_attr($description) . "'>\n";
        echo "<meta property='og:site_name' content='" . esc_attr($brand_name) . "'>\n";
        if ($og_image) echo "<meta property='og:image' content='" . esc_url($og_image) . "'>\n";
        echo "<meta name='twitter:card' content='summary_large_image'>\n";
    }
}

class BrandingController {
    private static array $BRANDING_KEYS = [
        'brand_name','brand_tagline','brand_primary_color','brand_secondary_color',
        'logo_url','favicon_url','brand_email','brand_phone','brand_whatsapp',
        'brand_address','brand_city','brand_state','brand_pincode',
        'social_facebook','social_instagram','social_linkedin','social_twitter',
        'footer_tagline','footer_copyright',
        'homepage_hero_title','homepage_hero_subtitle',
        'meta_title_pattern','meta_desc_pattern',
    ];

    // TRACE: get() — Trigger: wp_ajax_get AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        $cfg    = Config::instance();
        $result = [];
        foreach (self::$BRANDING_KEYS as $k) {
            $result[$k] = $cfg->get($k,'');
        }
        wp_send_json_success(['branding' => $result]);
    }

    // TRACE: save() — Trigger: wp_ajax_save AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        $cfg  = Config::instance();
        $data = [];
        foreach (self::$BRANDING_KEYS as $k) {
            if (isset($_POST[$k])) {
                $data[$k] = in_array($k, ['logo_url','favicon_url'])
                    ? esc_url_raw($_POST[$k])
                    : sanitize_text_field($_POST[$k]);
            }
        }
        $cfg->save_many($data);
        // Update WP site title if brand_name changed
        if (isset($data['brand_name'])) {
            update_option('blogname', $data['brand_name']);
        }
        wp_send_json_success(['message' => 'Branding saved successfully']);
    }
}
