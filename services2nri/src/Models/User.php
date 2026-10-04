<?php
namespace S2NRI\Models;

defined( 'ABSPATH' ) || exit;

/**
 * User — WP user extension helpers.
 *
 * TRACE: buildUser() called by Auth middleware and Bootstrap.
 *        Joins wp_users + s2nri_customers + s2nri_staff in one query.
 *        Returns normalised user array used throughout all controllers.
 *        Preconditions: $wpdb available, tables exist.
 *        Postconditions: Returns array with wp_id, s2nri_role, customer/staff flags.
 *        Edge cases: WP admin with no profile row → synthesises super_admin row.
 */
class User {

    public static function buildUser( \WP_User $u ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $is_wp_admin = in_array( 'administrator', (array) $u->roles, true );

        // Single query: get both customer profile and staff row
        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1", $u->ID
        ), ARRAY_A );

        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_staff WHERE wp_user_id = %d AND is_active = 1 LIMIT 1", $u->ID
        ), ARRAY_A );

        // Determine role
        if ( $is_wp_admin ) {
            $s2nri_role = 'super_admin';
        } elseif ( $staff ) {
            $s2nri_role = $staff['s2nri_role'];
        } else {
            $s2nri_role = 'customer';
        }

        return [
            'wp_id'       => $u->ID,
            'name'        => $u->display_name ?: $u->user_login,
            'email'       => $u->user_email,
            'wp_roles'    => (array) $u->roles,
            's2nri_role'  => $s2nri_role,
            'is_staff'    => (bool) $staff || $is_wp_admin,
            'is_customer' => (bool) $customer,
            'customer_id' => $customer ? (int) $customer['id'] : null,
            'phone'       => $customer['phone'] ?? '',
            'whatsapp'    => $customer['whatsapp'] ?? '',
            'country'     => $customer['country'] ?? '',
            'is_disabled' => get_user_meta( $u->ID, 's2nri_disabled', true ) === '1',
        ];
    }

    public static function ensureCustomerProfile( int $wp_user_id, array $extra = [] ): bool {
        global $wpdb;
        $t = $wpdb->prefix . 's2nri_customers';

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t} WHERE wp_user_id = %d LIMIT 1", $wp_user_id
        ) );

        if ( ! $exists ) {
            // CHECKED (was previously unchecked, now returns bool instead
            // of void so callers can react): this is the root of a subtle
            // chain — if this insert silently fails, the customer row
            // genuinely doesn't exist, and a caller's SUBSEQUENT
            // $wpdb->update(...WHERE wp_user_id=...) would then match
            // ZERO rows and return the integer 0 (a successful query that
            // simply found nothing), NOT false. A caller checking
            // "=== false" (the pattern used correctly elsewhere in this
            // codebase for genuine query errors) would NOT catch this
            // case, since 0 !== false under PHP's strict comparison — the
            // caller would see no error and report success while having
            // saved nothing.
            $result = $wpdb->insert( $t, array_merge( [
                'wp_user_id' => $wp_user_id,
                'is_active'  => 1,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ], $extra ) );
            if ( $result === false ) {
                error_log( '[S2NRI] ensureCustomerProfile() insert failed for wp_user_id ' . $wp_user_id . ': ' . $wpdb->last_error );
                return false;
            }
        }
        return true;
    }

    public static function getS2NRIRole( \WP_User $u ): string {
        if ( in_array( 'administrator', (array) $u->roles, true ) ) return 'super_admin';
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT s2nri_role FROM {$wpdb->prefix}s2nri_staff WHERE wp_user_id = %d AND is_active = 1 LIMIT 1",
            $u->ID
        ) );
        return $row ?: 'customer';
    }
}
