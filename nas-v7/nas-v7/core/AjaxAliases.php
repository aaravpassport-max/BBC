<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX Aliases
 * Maps shorthand action names used by JS (nas-admin.js, nas-booking.js, etc.)
 * to the correct handlers registered in modules/dashboards.
 *
 * Why this file exists: modules prefix their actions (nas_admin_*, nas_mod_*,
 * nas_staff_*) but the JS was written expecting plain names like nas_get_settings.
 * Rather than editing every module, we bridge the gap here.
 */
class AjaxAliases {

    public static function register(): void {

        $map = [
            // ── Admin panel ────────────────────────────────────────────────
            'nas_get_settings'          => [ '\NAS\Dashboards\AdminDashboard', 'get_settings' ],
            'nas_save_settings'         => [ '\NAS\Dashboards\AdminDashboard', 'save_settings' ],
            'nas_get_all_clients'       => [ '\NAS\Dashboards\AdminDashboard', 'get_all_clients' ],
            // NOTE: nas_get_newspapers is intentionally NOT aliased here.
            // BookingModule registers both wp_ajax_ and wp_ajax_nopriv_ for nas_get_newspapers
            // so it handles all callers (guests and logged-in users) correctly.
            // Admin-specific newspaper management uses nas_admin_get_newspapers (AdminModule).
            'nas_save_newspaper'        => [ '\NAS\Dashboards\AdminDashboard', 'save_newspaper' ],
            'nas_delete_newspaper'      => [ '\NAS\Dashboards\AdminDashboard', 'delete_newspaper' ],
            'nas_export_csv'            => [ '\NAS\Dashboards\AdminDashboard', 'export_csv' ],
            'nas_toggle_feature_flag'   => [ '\NAS\Dashboards\AdminDashboard', 'toggle_feature' ],
            'nas_get_feature_flags'     => [ '\NAS\Dashboards\AdminDashboard', 'get_feature_flags' ],

            // ── Staff panel ────────────────────────────────────────────────
            'nas_get_assigned_bookings' => [ '\NAS\Dashboards\StaffDashboard', 'get_assigned_bookings' ],
            'nas_get_today_tasks'       => [ '\NAS\Dashboards\StaffDashboard', 'get_today_tasks' ],
            'nas_mark_task_done'        => [ '\NAS\Dashboards\StaffDashboard', 'mark_task_done' ],
            'nas_update_booking_status' => [ '\NAS\Dashboards\StaffDashboard', 'update_booking_status' ],

            // ── Moderation panel ───────────────────────────────────────────
            'nas_get_pending_bookings'  => [ '\NAS\Dashboards\ModerationDashboard', 'get_pending' ],
            'nas_approve_booking'       => [ '\NAS\Dashboards\ModerationDashboard', 'approve_booking' ],
            'nas_reject_booking'        => [ '\NAS\Dashboards\ModerationDashboard', 'reject_booking' ],
            'nas_flag_booking'          => [ '\NAS\Dashboards\ModerationDashboard', 'flag_booking' ],
            'nas_get_sample_ads_list'   => [ '\NAS\Dashboards\ModerationDashboard', 'get_sample_ads' ],
            'nas_save_quick_reply'      => [ '\NAS\Dashboards\ModerationDashboard', 'save_quick_reply' ],
            'nas_mod_delete_sample_ad'  => [ '\NAS\Dashboards\ModerationDashboard', 'delete_sample_ad' ],
            'nas_delete_quick_reply'    => [ '\NAS\Dashboards\ModerationDashboard', 'delete_quick_reply' ],
            'nas_bulk_generate_city_seo'=> [ '\NAS\Dashboards\ModerationDashboard', 'bulk_generate_city_seo' ],

            // ── Chat (unread count alias) ───────────────────────────────────
            'nas_get_unread_count'      => [ '\NAS\Modules\Chat\ChatModule', 'unread_count_alias' ],

            // ── Booking bulk ───────────────────────────────────────────────
            'nas_bulk_booking_action'   => [ '\NAS\Dashboards\AdminDashboard', 'bulk_booking_action' ],
        ];

        foreach ( $map as $action => $callback ) {
            add_action( 'wp_ajax_' . $action, $callback );
        }

        // Public (nopriv) aliases for booking wizard
        $public = [
            'nas_get_categories',
            'nas_get_cities',
            'nas_get_editions',
            'nas_get_sample_ads',
            'nas_get_templates',
        ];
        foreach ( $public as $action ) {
            add_action( 'wp_ajax_nopriv_' . $action, self::get_booking_handler( $action ) );
        }
    }

    // TRACE: get_booking_handler() — Trigger: wp_ajax_get_booking_handler AJAX action.
    //        Steps: fetches and returns data.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    private static function get_booking_handler( string $action ): array {
        // These are already registered by BookingModule — nopriv alias just re-points
        $map = [
            'nas_get_categories' => 'get_categories',
            'nas_get_cities'     => 'get_cities',
            'nas_get_editions'   => 'get_editions',
            'nas_get_sample_ads' => 'get_sample_ads',
            'nas_get_templates'  => 'get_templates',
        ];
        return [ '\NAS\Modules\Booking\BookingModule', $map[ $action ] ?? 'get_categories' ];
    }
}
