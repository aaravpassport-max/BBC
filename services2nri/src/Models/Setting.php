<?php
namespace S2NRI\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Setting — platform settings read helper with 1-hour transient cache.
 *
 * TRACE: getPublic() → CacheService::remember('settings_public',3600) → DB query → returns key=>value map.
 *        getAll() → single DB query, no cache (admin only).
 *        get(key) → getAll()[key] ?? default.
 *        Preconditions: s2nri_settings table exists.
 *        Postconditions: Returns associative array of setting_key => setting_value.
 */
class Setting {

    /** In-process static cache: survives multiple calls within one PHP request. */
    private static ?array $_public_cache  = null;
    private static ?array $_all_cache     = null;

    /**
     * Returns public settings. Uses a two-tier cache:
     *   Tier 1: static property — zero DB queries after first call in same request.
     *   Tier 2: WP transient — survives across requests for 1 hour.
     * BEFORE: buildMeta() and getJsConfig() each called getPublic() → 2 transient reads per SPA load.
     * AFTER:  First call reads transient (or DB on miss), second call returns $_public_cache instantly.
     */
    public static function getPublic(): array {
        if ( self::$_public_cache !== null ) return self::$_public_cache;
        self::$_public_cache = \S2NRI\Services\CacheService::remember( 'settings_public', 3600, function () {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT setting_key, setting_value FROM {$wpdb->prefix}s2nri_settings WHERE is_public = 1",
                ARRAY_A
            );
            $out = [];
            foreach ( $rows as $r ) { $out[ $r['setting_key'] ] = $r['setting_value']; }
            return $out;
        } );
        return self::$_public_cache;
    }

    /** Bust the static cache (call after saving settings). */
    public static function bustCache(): void {
        self::$_public_cache = null;
        self::$_all_cache    = null;
        \S2NRI\Services\CacheService::delete( 'settings_public' );
    }

    public static function getAll(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT setting_key, setting_value, is_public FROM {$wpdb->prefix}s2nri_settings ORDER BY setting_key",
            ARRAY_A
        );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ $r['setting_key'] ] = [
                'value'     => $r['setting_value'],
                'is_public' => (bool) $r['is_public'],
            ];
        }
        return $out;
    }

    public static function get( string $key, string $default = '' ): string {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}s2nri_settings WHERE setting_key = %s LIMIT 1", $key
        ) );
        return $val !== null ? (string) $val : $default;
    }

    public static function set( string $key, string $value, bool $is_public = false ): void {
        global $wpdb;
        $t = $wpdb->prefix . 's2nri_settings';
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$t} (setting_key, setting_value, is_public)
             VALUES (%s, %s, %d)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_public = VALUES(is_public)",
            sanitize_key( $key ),
            $value,
            $is_public ? 1 : 0
        ) );
        self::bustCache();
    }
}
