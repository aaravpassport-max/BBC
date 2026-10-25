<?php
defined('ABSPATH') || exit;

/**
 * Lets a WordPress admin experience the customer-facing app themselves —
 * "be admin and customer at the same time" — without a second browser,
 * incognito mode, or a separate real account.
 *
 * Two modes, both reached from buttons on the InterviewAce settings page:
 *
 *  - Try it as yourself (Admin/Unlimited): logs the admin's OWN WordPress
 *    account into the app. IA_Plan_Enforcer::get_plan() already treats any
 *    user with the manage_options capability as the special 'admin' plan
 *    (see includes/class-plan-enforcer.php) — unlimited weekly interviews,
 *    9999-minute session cap, 99999 remaining monthly minutes. So this is
 *    the fastest way to click through the whole product — interview flow,
 *    reports, PDF download, library/review — with nothing artificially
 *    blocking you.
 *
 *  - Try it as a Free-tier customer: logs into a separate, dedicated,
 *    ordinary (non-admin) test account instead, so you see exactly what a
 *    real Free-plan customer sees — the weekly interview limit, the
 *    15-minute session cap, upgrade prompts — none of which the admin
 *    account above will ever hit. A "reset" button wipes that account's
 *    interview history and usage so onboarding and the free-tier limits
 *    can be re-tested from a clean slate as many times as needed.
 *
 * Neither mode touches your actual WordPress login. Both only set this
 * plugin's own app-level session cookie (`ia_rt`) — exactly the cookie a
 * real customer gets back from POST /auth/login. Logging out inside the
 * app (or clearing cookies) ends the test session with zero effect on
 * your WordPress admin account.
 */
class IA_Admin_Impersonate {

    public static function init() {
        add_action('admin_post_ia_try_as_admin',         [__CLASS__, 'try_as_admin']);
        add_action('admin_post_ia_try_as_test_customer',  [__CLASS__, 'try_as_test_customer']);
        add_action('admin_post_ia_reset_test_customer',   [__CLASS__, 'reset_test_customer']);
    }

    /** Logs the current WP admin into the app as themselves (their own WP user ID -> automatically the unlimited 'admin' plan tier). */
    public static function try_as_admin() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', 'Forbidden', ['response' => 403]);
        check_admin_referer('ia_try_as_admin');

        $wp_user = wp_get_current_user();
        self::ensure_profile((int) $wp_user->ID, $wp_user->display_name ?: 'Admin');

        $rt = IA_JWT::store_refresh((int) $wp_user->ID);
        IA_JWT::set_cookie($rt);
        wp_safe_redirect(home_url('/app/dashboard'));
        exit;
    }

    /** Logs the current WP admin into a dedicated, ordinary (non-admin) test-customer account, creating it on first use. */
    public static function try_as_test_customer() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', 'Forbidden', ['response' => 403]);
        check_admin_referer('ia_try_as_test_customer');

        $uid = self::get_or_create_test_customer();
        $rt  = IA_JWT::store_refresh($uid);
        IA_JWT::set_cookie($rt);
        wp_safe_redirect(home_url('/app/dashboard'));
        exit;
    }

    /** Wipes the test customer's interview history, usage, badges and saved answers so free-tier limits and onboarding can be retested from a clean slate. */
    public static function reset_test_customer() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', 'Forbidden', ['response' => 403]);
        check_admin_referer('ia_reset_test_customer');

        $uid = (int) get_option('ia_test_customer_user_id');
        if ($uid && get_userdata($uid)) {
            global $wpdb;
            $p = $wpdb->prefix;

            $interview_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}ia_interviews WHERE user_id=%d", $uid));
            if ($interview_ids) {
                $in = implode(',', array_map('intval', $interview_ids));
                $wpdb->query("DELETE FROM {$p}ia_turns WHERE interview_id IN ($in)");
                $wpdb->query("DELETE FROM {$p}ia_reports WHERE interview_id IN ($in)");
            }
            $wpdb->delete("{$p}ia_interviews", ['user_id' => $uid]);
            $wpdb->delete("{$p}ia_usage", ['user_id' => $uid]);
            $wpdb->delete("{$p}ia_subscriptions", ['user_id' => $uid]);
            $wpdb->delete("{$p}ia_saved_answers", ['user_id' => $uid]);
            $wpdb->delete("{$p}ia_review_queue", ['user_id' => $uid]);
            $wpdb->delete("{$p}ia_badges", ['user_id' => $uid]);
            $wpdb->delete("{$p}ia_xp_events", ['user_id' => $uid]);
            $wpdb->update("{$p}ia_profiles", [
                'onboarding_complete' => 0,
                'target_role'         => null,
                'current_role'        => null,
                'industry'            => null,
                'resume_url'          => null,
                'resume_text'         => null,
                'resume_parsed_json'  => null,
                'jd_text'             => null,
                'jd_parsed_json'      => null,
            ], ['user_id' => $uid]);
            IA_JWT::revoke_all($uid);
        }
        wp_safe_redirect(add_query_arg(['page' => 'interviewace', 'ia_reset' => '1'], admin_url('admin.php')));
        exit;
    }

    private static function get_or_create_test_customer(): int {
        $uid = (int) get_option('ia_test_customer_user_id');
        if ($uid && get_userdata($uid)) return $uid;

        $host     = parse_url(home_url(), PHP_URL_HOST) ?: 'example.com';
        $email    = 'ia-test-customer@' . $host;
        $existing = get_user_by('email', $email);

        if ($existing) {
            $uid = $existing->ID;
        } else {
            $uid = wp_create_user('ia-test-customer', wp_generate_password(32), $email);
            if (is_wp_error($uid)) {
                wp_die('Could not create the test customer account: ' . esc_html($uid->get_error_message()));
            }
            $user = new WP_User($uid);
            $user->set_role('subscriber');
            wp_update_user(['ID' => $uid, 'display_name' => 'Test Customer']);
        }
        update_option('ia_test_customer_user_id', $uid);
        self::ensure_profile($uid, 'Test Customer');
        return $uid;
    }

    /** Makes sure the given user has an ia_profiles row, and is marked email-verified so the app never blocks a test session on an OTP prompt. */
    private static function ensure_profile(int $uid, string $name) {
        global $wpdb;
        $table  = "{$wpdb->prefix}ia_profiles";
        $exists = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE user_id=%d", $uid));
        if (!$exists) {
            $wpdb->insert($table, [
                'user_id'          => $uid,
                'name'             => $name,
                'experience_level' => 'fresher',
                'email_verified'   => 1,
            ]);
        } elseif (!(int) $wpdb->get_var($wpdb->prepare("SELECT email_verified FROM $table WHERE user_id=%d", $uid))) {
            $wpdb->update($table, ['email_verified' => 1], ['user_id' => $uid]);
        }
    }
}
