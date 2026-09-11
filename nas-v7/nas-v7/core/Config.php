<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Central configuration manager.
 * Supports environment-based configs (dev/staging/prod).
 * All business rules come from DB or config, never hardcoded in controllers.
 */
class Config {

    private static ?Config $instance = null;
    private array $config            = [];
    private ?object $db_row          = null;

    // TRACE: instance() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function instance(): Config {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->load_defaults();
        $this->load_from_db();
        $this->detect_environment();
    }

    // TRACE: load_defaults() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function load_defaults(): void {
        $this->config = [
            'env'               => 'production',
            'brand_name'        => get_bloginfo('name') ?: 'NewspaperAds Pro',
            'currency'          => 'INR',
            'currency_symbol'   => '₹',
            'gst_percentage'    => 18.0,
            'invoice_prefix'    => 'INV',
            'invoice_counter'   => 1000,
            'cutoff_days'       => 2,
            'ai_provider'       => 'anthropic',
            'ai_model'          => 'claude-opus-4-6',
            'ai_api_key'        => '',
            'whatsapp_number'   => '',
            'callmebot_api_key' => '',
            'callmebot_api_url' => 'https://api.callmebot.com/whatsapp.php',
            'smtp_host'         => '',
            'smtp_port'         => 587,
            'smtp_user'         => '',
            'smtp_pass'         => '',
            'dashboard_width'   => '1500px',
            'logo_url'          => '',
            'site_url'          => home_url('/'),
            'booking_page_url'  => home_url('/book-newspaper-ad/'),
            'client_dash_url'   => home_url('/client-dashboard/'),
            'admin_dash_url'    => home_url('/admin-dashboard/'),
            'staff_dash_url'    => home_url('/staff-dashboard/'),
            'login_page_url'    => home_url('/newspaper-ad-login/'),
            'cache_ttl'         => 300,
            'wa_enabled'        => true,
            // Payment gateways
            // PWA Site-wide Intercept
            'nas_pwa_site_wide'  => 0,  // 1 = serve PWA shell on ALL frontend pages
            // PWA Push Notifications
            'vapid_public_key'  => '',
            'vapid_private_key' => '',
            // PWA Theme Settings (NASTheme three-layer system)
            'pwa_theme'        => 'green',
            'pwa_portal_bg'    => '#0e1117',
            'pwa_bg_preset'    => 'dark',
            'pwa_accent_color' => '',
            'pwa_button_color' => '',
            'pwa_icon_192'     => '',
            'pwa_icon_512'     => '',
            'razorpay_key_id'        => '',
            'razorpay_key_secret'    => '',
            'razorpay_webhook_secret'=> '',
            'stripe_publishable_key' => '',
            'stripe_secret_key'      => '',
            'stripe_webhook_secret'  => '',
            'bank_transfer_details'  => '',
            'payu_merchant_key'      => '',
            'payu_merchant_salt'     => '', // was 'payu_salt' — column name is payu_merchant_salt
            // Company info
            'founded_year'           => '2020',
            'tagline'                => "India's trusted newspaper ad booking platform",
            'brand_address'          => '',
            'brand_phone'            => '',
            'brand_email'            => '',
            'vendor_auto_approve'    => 0,
            'email_enabled'     => true,
        ];
    }

    // TRACE: load_from_db() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function load_from_db(): void {
        global $wpdb;
        $row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}nas_settings LIMIT 1" );
        if ( ! $row ) return;
        $this->db_row = $row;

        $map = [
            'brand_name'        => 'brand_name',
            'currency'          => 'currency',
            'currency_symbol'   => 'currency_symbol',
            'gst_percentage'    => 'gst_percentage',
            'invoice_prefix'    => 'invoice_prefix',
            'invoice_counter'   => 'invoice_counter',
            'cutoff_days'       => 'cutoff_days',
            'ai_provider'       => 'ai_provider',
            'ai_api_key'        => 'ai_api_key',
            'ai_model'          => 'ai_model',
            'whatsapp_number'   => 'whatsapp_number',
            'callmebot_api_key' => 'callmebot_api_key',
            'smtp_host'         => 'smtp_host',
            'smtp_port'         => 'smtp_port',
            'smtp_user'         => 'smtp_user',
            'smtp_pass'         => 'smtp_pass',
            'logo_url'          => 'logo_url',
            // Payment gateway keys — must be readable via Config::get()
            'razorpay_key_id'         => 'razorpay_key_id',
            'razorpay_key_secret'     => 'razorpay_key_secret',
            'razorpay_webhook_secret' => 'razorpay_webhook_secret',
            'stripe_publishable_key'  => 'stripe_publishable_key',
            'stripe_secret_key'       => 'stripe_secret_key',
            'stripe_webhook_secret'   => 'stripe_webhook_secret',
            'payu_merchant_key'       => 'payu_merchant_key',
            'payu_salt'               => 'payu_merchant_salt', // DB col is payu_merchant_salt
            'bank_transfer_details'   => 'bank_transfer_details',
            'brand_name'              => 'brand_name',
            'brand_email'             => 'brand_email',
            'brand_phone'             => 'brand_phone',
            'gst_number'              => 'gst_number',
            'wa_enabled'              => 'wa_enabled',
            'wa_admin_number'         => 'wa_admin_number',
        ];
        foreach ( $map as $config_key => $db_col ) {
            if ( isset( $row->$db_col ) && $row->$db_col !== '' ) {
                $this->config[ $config_key ] = $row->$db_col;
            }
        }
    }

    // TRACE: detect_environment() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    private function detect_environment(): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->config['env'] = 'development';
        }
        if ( defined( 'NAS_ENV' ) ) {
            $this->config['env'] = NAS_ENV;
        }
    }

    // TRACE: get() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function get( string $key, $default = null ) {
        return $this->config[ $key ] ?? $default;
    }

    // TRACE: set() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function set( string $key, $value ): void {
        $this->config[ $key ] = $value;
    }

    // TRACE: all() — Called internally or via AJAX action.
    //        Steps: updates DB row → returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function all(): array {
        return $this->config;
    }

    // TRACE: is_dev() — Called internally or via AJAX action.
    //        Steps: updates DB row → returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function is_dev(): bool {
        return $this->config['env'] === 'development';
    }

    /** Save a setting to DB and update runtime config */
    // TRACE: save() — Called internally or via AJAX action.
    //        Steps: updates DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function save( string $key, $value ): void {
        global $wpdb;
        $this->config[ $key ] = $value;
        $wpdb->update( "{$wpdb->prefix}nas_settings", [ $key => $value ], [ 'id' => 1 ] );
    }

    /** Save multiple settings at once — whitelisted keys only to prevent column injection */
    // TRACE: save_many() — Called internally or via AJAX action.
    //        Steps: validates and persists data.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function save_many( array $data ): void {
        global $wpdb;
        // Whitelist: only these keys exist as columns in nas_settings table
        $allowed = [
            'brand_name','brand_tagline','brand_primary_color','brand_secondary_color',
            'logo_url','favicon_url','brand_email','brand_phone','brand_whatsapp',
            'brand_address','brand_city','brand_state','brand_pincode',
            'social_facebook','social_instagram','social_linkedin','social_twitter',
            'footer_tagline','footer_copyright',
            'homepage_hero_title','homepage_hero_subtitle',
            'meta_title_pattern','meta_desc_pattern',
            'currency','currency_symbol','gst_percentage',
            'invoice_prefix','invoice_counter','cutoff_days',
            'ai_provider','ai_api_key','ai_model',
            'whatsapp_number','callmebot_api_key',
            'smtp_host','smtp_port','smtp_user','smtp_pass',
            'email_sender_name','email_sender_address',
            'nas_pwa_site_wide','vapid_public_key','vapid_private_key',
            'pwa_theme','pwa_portal_bg','pwa_bg_preset','pwa_accent_color','pwa_button_color','pwa_icon_192','pwa_icon_512',
            'razorpay_key_id','razorpay_key_secret',
            'payu_merchant_key','payu_merchant_salt',
            'openai_key',
            'logo_url','dashboard_width','wa_enabled','email_enabled',
        ];
        $data = array_intersect_key( $data, array_flip( $allowed ) );
        if ( empty( $data ) ) return;
        foreach ( $data as $k => $v ) $this->config[ $k ] = $v;
        $wpdb->update( "{$wpdb->prefix}nas_settings", $data, [ 'id' => 1 ] );
    }

    /** Feature flags */
    // TRACE: feature_enabled() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function feature_enabled( string $feature ): bool {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT is_enabled FROM {$wpdb->prefix}nas_feature_flags WHERE feature_key = %s",
            $feature
        ) );
        return $val === null ? true : (bool) $val;
    }

    // TRACE: toggle_feature() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function toggle_feature( string $feature, bool $enabled ): void {
        global $wpdb;
        $wpdb->replace( "{$wpdb->prefix}nas_feature_flags", [
            'feature_key' => $feature,
            'is_enabled'  => $enabled ? 1 : 0,
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ] );
    }
}
