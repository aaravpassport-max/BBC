<?php

namespace RTOFLOW\Config;

if (!defined('ABSPATH')) exit;

/**
 * Central Configuration Manager
 *
 * Reads from three sources in priority order:
 *   1. Environment constants (.env / wp-config.php defines)
 *   2. Database wp_options (admin-editable)
 *   3. Built-in defaults
 *
 * Usage: Config::get('razorpay_key'), Config::set('sms_enabled', '1')
 */
class Config
{
    private static array $cache  = [];

    // ── Built-in defaults ────────────────────────────────────────────────────
    private static array $defaults = [
        // Company
        'company_name'              => 'RTOASSIST',
        'company_phone'             => '',
        'company_email'             => '',
        'support_email'             => '',
        'company_gstin'             => '',
        'company_address'           => '',
        'company_state'             => '',

        // Razorpay
        'razorpay_enabled'          => '0',
        'razorpay_key'              => '',
        'razorpay_secret'           => '',

        // WhatsApp
        'whatsapp_enabled'          => '0',
        'whatsapp_phone_id'         => '',
        'whatsapp_token'            => '',
        'whatsapp_verify_token'     => 'rtoflow_wh',

        // SMS
        'sms_enabled'               => '0',
        'sms_provider'              => 'msg91',
        'sms_api_key'               => '',
        'sms_sender_id'             => 'RTOFLW',

        // Operations
        'sla_days'                  => 15,
        'vendor_share'              => 45,

        // Performance
        'cache_ttl_short'           => 120,
        'cache_ttl_medium'          => 300,
        'cache_ttl_long'            => 3600,
    ];

    // ── Mapping: config key → wp_option key ─────────────────────────────────
    private static array $option_map = [
        'company_name'          => 'rtoflow_company_name',
        'company_phone'         => 'rtoflow_company_phone',
        'company_email'         => 'rtoflow_company_email',
        'support_email'         => 'rtoflow_support_email',
        'company_gstin'         => 'rtoflow_company_gstin',
        'company_address'       => 'rtoflow_company_address',
        'company_state'         => 'rtoflow_company_state',
        'razorpay_enabled'      => 'rtoflow_razorpay_enabled',
        'razorpay_key'          => 'rtoflow_razorpay_key',
        'razorpay_secret'       => 'rtoflow_razorpay_secret',
        'whatsapp_enabled'      => 'rtoflow_whatsapp_enabled',
        'whatsapp_phone_id'     => 'rtoflow_whatsapp_phone_id',
        'whatsapp_token'        => 'rtoflow_whatsapp_token',
        'whatsapp_verify_token' => 'rtoflow_whatsapp_verify_token',
        'sms_enabled'           => 'rtoflow_sms_enabled',
        'sms_provider'          => 'rtoflow_sms_provider',
        'sms_api_key'           => 'rtoflow_sms_api_key',
        'sms_sender_id'         => 'rtoflow_sms_sender_id',
        'sla_days'              => 'rtoflow_sla_days',
        'vendor_share'          => 'rtoflow_vendor_share',
    ];

    // ── Mapping: config key → .env variable name ─────────────────────────────
    private static array $env_map = [
        'razorpay_key'    => 'RAZORPAY_KEY',
        'razorpay_secret' => 'RAZORPAY_SECRET',
        'whatsapp_token'  => 'WHATSAPP_TOKEN',
        'sms_api_key'     => 'MSG91_API_KEY',
    ];

    public static function get(string $key, mixed $fallback = null): mixed
    {
        if (isset(self::$cache[$key])) return self::$cache[$key];

        // 1. .env file (highest priority — never overridable from UI)
        if (isset(self::$env_map[$key])) {
            $v = Env::get(self::$env_map[$key]);
            if ($v !== null && $v !== '') return self::$cache[$key] = $v;
        }

        // 2. DB option
        if (isset(self::$option_map[$key])) {
            $v = get_option(self::$option_map[$key], null);
            if ($v !== null && $v !== '') return self::$cache[$key] = $v;
        }

        // 3. Default
        if (array_key_exists($key, self::$defaults)) {
            return self::$cache[$key] = self::$defaults[$key];
        }

        return $fallback;
    }

    public static function set(string $key, mixed $value): void
    {
        unset(self::$cache[$key]);
        if (isset(self::$option_map[$key])) {
            update_option(self::$option_map[$key], $value);
        }
    }

    public static function all_for_tab(string $tab): array
    {
        $tabs = [
            'general'  => ['company_name', 'company_phone', 'company_email', 'support_email', 'company_gstin', 'company_address', 'company_state'],
            'payment'  => ['razorpay_enabled', 'razorpay_key', 'razorpay_secret'],
            'whatsapp' => ['whatsapp_enabled', 'whatsapp_phone_id', 'whatsapp_token', 'whatsapp_verify_token'],
            'sms'      => ['sms_enabled', 'sms_provider', 'sms_api_key', 'sms_sender_id'],
            'ops'      => ['sla_days', 'vendor_share'],
        ];
        $result = [];
        foreach ($tabs[$tab] ?? [] as $k) {
            $result[$k] = self::get($k);
        }
        return $result;
    }

    public static function save_tab(string $tab, array $posted): void
    {
        $tabs = [
            'general'  => ['company_name', 'company_phone', 'company_email', 'support_email', 'company_gstin', 'company_address', 'company_state'],
            'payment'  => ['razorpay_enabled', 'razorpay_key', 'razorpay_secret'],
            'whatsapp' => ['whatsapp_enabled', 'whatsapp_phone_id', 'whatsapp_token', 'whatsapp_verify_token'],
            'sms'      => ['sms_enabled', 'sms_provider', 'sms_api_key', 'sms_sender_id'],
            'ops'      => ['sla_days', 'vendor_share'],
        ];
        foreach ($tabs[$tab] ?? [] as $k) {
            // Skip env-backed keys — can't override from UI
            if (isset(self::$env_map[$k]) && Env::get(self::$env_map[$k]) !== null) continue;
            $raw = $posted[$k] ?? null;
            if ($raw !== null) self::set($k, sanitize_text_field((string)$raw));
        }
        self::$cache = [];
    }
}
