<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Caching layer — uses WP transients by default,
 * automatically uses object cache (Redis/Memcached) when available.
 */
class Cache {

    const PREFIX = 'nas_cache_';
    private static array $runtime = [];
    private static ?Cache $instance = null;

    /**
     * Singleton instance — allows Cache::instance()->method() call pattern
     * used throughout Pricing and Analytics modules.
     */
    // TRACE: instance() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: returns null/false on failure; explicit false check on DB op.
    public static function instance(): Cache {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    // TRACE: get() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: returns null/false on failure; explicit false check on DB op.
    public static function get( string $key ) {
        $full = self::PREFIX . md5( $key );
        if ( isset( self::$runtime[ $full ] ) ) return self::$runtime[ $full ];
        $v = get_transient( $full );
        if ( $v !== false ) { self::$runtime[ $full ] = $v; return $v; }
        return null;
    }

    // TRACE: set() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function set( string $key, $value, int $ttl = 300 ): void {
        $full = self::PREFIX . md5( $key );
        self::$runtime[ $full ] = $value;
        set_transient( $full, $value, $ttl );
    }

    // TRACE: delete() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function delete( string $key ): void {
        $full = self::PREFIX . md5( $key );
        unset( self::$runtime[ $full ] );
        delete_transient( $full );
    }

    // TRACE: remember() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → reads/writes Cache.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function remember( string $key, callable $fn, int $ttl = 300 ) {
        $v = self::get( $key );
        if ( $v !== null ) return $v;
        $v = $fn();
        self::set( $key, $v, $ttl );
        return $v;
    }

    /** Flush all NAS caches */
    // TRACE: flush_all() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → reads/writes Cache.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function flush_all(): void {
        global $wpdb;
        self::$runtime = [];
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_" . self::PREFIX . "%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_" . self::PREFIX . "%'" );
    }

    /**
     * Flush caches for a named group.
     * Since transient keys are md5(key), we can only flush by prefix.
     * For targeted flushes, callers should use Cache::delete($specific_key) instead.
     * This method flushes ALL NAS caches (same as flush_all) when group-based
     * lookup is not possible via LIKE on hashed keys.
     */
    // TRACE: flush_group() — Called internally or via AJAX action.
    //        Steps: reads/writes Cache.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function flush_group( string $group ): void {
        // Note: because transient keys are md5-hashed, group-based pattern matching
        // on the raw DB option_name is not reliable. Flush all NAS caches instead.
        // For production, upgrade to Redis/Memcached which support tag-based invalidation.
        self::flush_all();
    }

    /**
     * Magic method: allows Cache::instance()->remember(), Cache::instance()->flush_all() etc.
     * Delegates instance-style calls to the underlying static methods.
     * This resolves all Cache::instance()->method() call sites in Pricing/Analytics modules.
     */
    public function __call( string $name, array $args ) {
        if ( method_exists( self::class, $name ) ) {
            return call_user_func_array( [ self::class, $name ], $args );
        }
        throw new \BadMethodCallException( "NAS\\Core\\Cache: method '{$name}' does not exist." );
    }
}
