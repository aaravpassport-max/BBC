<?php
namespace S2NRI\Api\Middleware;

defined( 'ABSPATH' ) || exit;

/**
 * Auth — session cookie + nonce authentication (NRI v6.3.1 style, no JWT).
 *
 * TRACE: Called by Dispatcher before every protected route.
 *        Reads WP session cookie OR X-WP-Nonce header.
 *        Returns user array or null.
 *        Preconditions: WordPress wp_get_current_user() available.
 *        Postconditions: Returns user array or null. Never throws.
 *        Edge cases: suspended user → null, non-existent user → null.
 */
class Auth {

    public function authenticate(): ?array {
        $user = $this->resolveUser();
        if ( ! $user ) return null;
        // Check suspension
        if ( get_user_meta( $user['wp_id'], 's2nri_disabled', true ) === '1' ) return null;
        return $user;
    }

    public function authenticateOptional(): ?array {
        try { return $this->resolveUser(); }
        catch ( \Exception $e ) { return null; }
    }

    private function resolveUser(): ?array {
        // ── Read portal token from every possible location ─────────────────────
        // CDN installs (Cloudways, Cloudflare, nginx reverse proxies) may strip
        // custom X- headers before they reach PHP's $_SERVER. We check all sources:
        //   1. $_SERVER['HTTP_X_S2NRI_TOKEN'] — standard PHP header normalisation
        //   2. getallheaders()['X-S2NRI-Token'] — Apache mod_php fallback
        //   3. $_GET['_s2nri_token']           — URL param fallback (CDN strips headers)
        //   4. $_POST['_s2nri_token']          — body param fallback
        $portal_token = '';

        // Source 1: Standard PHP $_SERVER header normalisation
        if ( ! empty( $_SERVER['HTTP_X_S2NRI_TOKEN'] ) ) {
            $portal_token = sanitize_text_field( $_SERVER['HTTP_X_S2NRI_TOKEN'] );
        }

        // Source 2: getallheaders() — works on Apache where $_SERVER may miss custom headers
        if ( ! $portal_token && function_exists( 'getallheaders' ) ) {
            $all_headers = getallheaders();
            // Header names are case-insensitive per HTTP spec
            foreach ( $all_headers as $name => $value ) {
                if ( strtolower( $name ) === 'x-s2nri-token' ) {
                    $portal_token = sanitize_text_field( $value );
                    break;
                }
            }
        }

        // Source 3: URL query param — fallback when CDN strips ALL custom headers.
        // api.js appends ?_s2nri_token=TOKEN to every request as insurance.
        if ( ! $portal_token && ! empty( $_GET['_s2nri_token'] ) ) {
            $portal_token = sanitize_text_field( $_GET['_s2nri_token'] );
        }

        // Source 4: POST body param (for POST requests where GET params may not apply)
        if ( ! $portal_token && ! empty( $_POST['_s2nri_token'] ) ) {
            $portal_token = sanitize_text_field( $_POST['_s2nri_token'] );
        }

        // Method 1: Portal session token
        if ( $portal_token && strlen( $portal_token ) === 64 ) {
            // Direct DB query — faster than get_users() and works even when
            // object cache plugins (W3TC, Redis) return stale results.
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT m.user_id, exp.meta_value AS expiry
                   FROM {$wpdb->usermeta} m
                   JOIN {$wpdb->usermeta} exp
                     ON exp.user_id = m.user_id
                    AND exp.meta_key = 's2nri_portal_token_exp'
                  WHERE m.meta_key = 's2nri_portal_token'
                    AND m.meta_value = %s
                  LIMIT 1",
                $portal_token
            ), ARRAY_A );
            $users = $row ? [ (int) $row['user_id'] ] : [];
            if ( ! empty( $users ) ) {
                $uid = (int) $users[0];
                $exp = $row ? (int) $row['expiry'] : 0;
                if ( $exp > time() ) {
                    // Set current user FIRST so WP loads roles with correct blog prefix.
                    // get_user_by() before wp_set_current_user() returns WP_User with empty roles.
                    wp_set_current_user( $uid );
                    $wp_user = wp_get_current_user();
                    if ( $wp_user && $wp_user->exists() ) {
                        $built = \S2NRI\Models\User::buildUser( $wp_user );
                        return $built;
                    }
                } else {
                    // Token expired — clean up
                    delete_user_meta( $uid, 's2nri_portal_token' );
                    delete_user_meta( $uid, 's2nri_portal_token_exp' );
                }
            }
        }

        // Method 2: WP Nonce header (standard WP session)
        // Portal.php injects wp_create_nonce('wp_rest') — must verify the same action.
        // SEO.php (public website) injects wp_create_nonce('s2nri_api').
        // Both are valid — check both nonce actions.
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
        if ( $nonce && (
            wp_verify_nonce( $nonce, 'wp_rest' ) ||
            wp_verify_nonce( $nonce, 's2nri_api' )
        ) ) {
            $wp_user = wp_get_current_user();
            if ( $wp_user->exists() ) return \S2NRI\Models\User::buildUser( $wp_user );
        }

        // Method 3: WP session cookie (standard WP login)
        if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
            $wp_user = wp_get_current_user();
            if ( $wp_user->exists() ) {
                // Force role reload: wp_set_current_user() re-initialises capabilities
                // with the correct blog prefix. Without this, roles may be empty on
                // REST API requests where WP cookie auth ran before our code.
                wp_set_current_user( $wp_user->ID );
                $wp_user = wp_get_current_user();
                $built = \S2NRI\Models\User::buildUser( $wp_user );
                return $built;
            }
        }

        // Method 4: Direct cookie validation (fallback)
        foreach ( $_COOKIE as $key => $value ) {
            if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
                $user_id = wp_validate_auth_cookie( $value, 'logged_in' );
                if ( $user_id ) {
                    wp_set_current_user( $user_id );
                    $wp_user = wp_get_current_user();
                    if ( $wp_user && $wp_user->exists() ) return \S2NRI\Models\User::buildUser( $wp_user );
                }
                break;
            }
        }

        return null;
    }

    // ── Role guards ───────────────────────────────────────────────────────────

    // TRACE: requireStaff/Manager/Admin — null-safe guards. Null user → ForbiddenException.
    //        Dispatcher guarantees non-null before calling controllers, but null-check
    //        is defence-in-depth for any future direct controller instantiation.
    public static function requireStaff( ?array $user ): void {
        if ( ! $user ) throw new \S2NRI\Exceptions\ForbiddenException( 'Authentication required.' );
        if ( in_array( $user['s2nri_role'], [ 'super_admin', 'manager', 'agent', 'finance' ], true ) ) return;
        if ( in_array( 'administrator', $user['wp_roles'] ?? [], true ) ) return;
        throw new \S2NRI\Exceptions\ForbiddenException( 'Staff access required.' );
    }

    public static function requireManager( ?array $user ): void {
        if ( ! $user ) throw new \S2NRI\Exceptions\ForbiddenException( 'Authentication required.' );
        if ( in_array( $user['s2nri_role'], [ 'super_admin', 'manager' ], true ) ) return;
        if ( in_array( 'administrator', $user['wp_roles'] ?? [], true ) ) return;
        throw new \S2NRI\Exceptions\ForbiddenException( 'Manager access required.' );
    }

    public static function requireAdmin( ?array $user ): void {
        if ( ! $user ) throw new \S2NRI\Exceptions\ForbiddenException( 'Authentication required.' );
        if ( $user['s2nri_role'] === 'super_admin' ) return;
        if ( in_array( 'administrator', $user['wp_roles'] ?? [], true ) ) return;
        throw new \S2NRI\Exceptions\ForbiddenException( 'Admin access required.' );
    }
}
