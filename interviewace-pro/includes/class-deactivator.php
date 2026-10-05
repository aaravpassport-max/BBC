<?php
defined('ABSPATH') || exit;

class IA_Deactivator {
    public static function deactivate(){
        IA_Cron_Manager::clear_all();
        flush_rewrite_rules();
    }
}

class IA_Cron_Manager {
    /**
     * ROOT-CAUSE FIX #1: 'monthly' is not a built-in WP-Cron schedule (core
     * only ships hourly/twicedaily/daily). wp_schedule_event(...,'monthly',...)
     * in class-activator.php was silently failing — the event was never
     * actually scheduled, so ia_monthly_usage_reset never ran in production
     * even though the handler for it was correctly written. Registering the
     * interval here (hooked in interviewace.php before activation/scheduling
     * runs) makes the schedule name real.
     */
    public static function register_schedules(array $schedules): array {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => __('Once Monthly','interviewace')];
        }
        /* 'weekly' is also not in WP core's default schedule list (only
           hourly/twicedaily/daily are guaranteed) — register defensively so
           ia_weekly_aggregate and ia_weekly_digest don't silently no-op on
           a stock WordPress install with no other plugin providing it. */
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => __('Once Weekly','interviewace')];
        }
        return $schedules;
    }

    public static function register_hooks(){
        /*
         * ROOT-CAUSE FIX #2: reset_daily_usage() (expired refresh-token +
         * OTP cleanup) was registered as a hook handler but the activator
         * never scheduled 'ia_daily_usage_reset' anywhere — dead code. It
         * only ran once, on plugin deactivation (class-deactivator.php),
         * meaning expired tokens/OTPs accumulated indefinitely in normal
         * operation. Now actually scheduled daily on activation (see
         * class-activator.php) and self-heals if missing on any request.
         */
        add_action('ia_daily_usage_reset',       [__CLASS__, 'reset_daily_usage']);
        add_action('ia_subscription_sync',       [__CLASS__, 'sync_subscriptions']);
        add_action('ia_cleanup_abandoned',       [__CLASS__, 'cleanup_abandoned']);
        add_action('ia_cleanup_signup_attempts', [__CLASS__, 'cleanup_signup_attempts']);
        add_action('ia_monthly_usage_reset',     [__CLASS__, 'monthly_usage_reset']);
        add_action('ia_weekly_aggregate',        [__CLASS__, 'weekly_aggregate']);
        add_action('ia_weekly_digest',           [__CLASS__, 'weekly_digest']);
        add_action('ia_generate_report',         ['IA_Report_Generator', 'generate']);

        /* Self-healing: if any expected recurring event is missing (fresh
           install, a prior failed activation, a schedule that silently
           failed like #1 above), re-schedule it on the next request rather
           than requiring a manual deactivate/reactivate cycle. */
        add_action('init', [__CLASS__, 'ensure_scheduled']);
    }

    public static function ensure_scheduled(){
        $jobs = [
            'ia_daily_usage_reset'       => 'daily',
            'ia_subscription_sync'       => 'hourly',
            'ia_cleanup_abandoned'       => 'daily',
            'ia_cleanup_signup_attempts' => 'daily',
            'ia_monthly_usage_reset'     => 'monthly',
            'ia_weekly_aggregate'        => 'weekly',
            'ia_weekly_digest'           => 'weekly',
        ];
        foreach ($jobs as $hook => $recurrence) {
            if (!wp_next_scheduled($hook)) {
                $ok = wp_schedule_event(time() + 60, $recurrence, $hook);
                if ($ok === false) {
                    error_log("[InterviewAce] Failed to schedule cron hook '$hook' with recurrence '$recurrence' — check that the schedule is registered via cron_schedules.");
                }
            }
        }
    }

    public static function clear_all(){
        foreach ([
            'ia_daily_usage_reset','ia_subscription_sync','ia_cleanup_abandoned',
            'ia_cleanup_signup_attempts','ia_monthly_usage_reset',
            'ia_weekly_aggregate','ia_weekly_digest',
        ] as $hook) {
            $ts = wp_next_scheduled($hook);
            if ($ts) wp_unschedule_event($ts, $hook);
        }
        wp_clear_scheduled_hook('ia_generate_report');
    }

    public static function reset_daily_usage(){
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}ia_refresh_tokens WHERE expires_at < NOW() OR revoked = 1");
        $wpdb->query("DELETE FROM {$wpdb->prefix}ia_otp_codes WHERE expires_at < NOW()");
    }

    public static function sync_subscriptions(){
        global $wpdb;
        if (empty(ia_key('IA_RAZORPAY_KEY_ID'))) return;
        $subs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ia_subscriptions
             WHERE status IN ('active','past_due') AND razorpay_sub_id IS NOT NULL
             AND updated_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        );
        foreach ($subs as $sub) {
            $res = wp_remote_get("https://api.razorpay.com/v1/subscriptions/{$sub->razorpay_sub_id}", [
                'headers' => ['Authorization' => 'Basic ' . base64_encode(ia_key('IA_RAZORPAY_KEY_ID').':'.ia_key('IA_RAZORPAY_KEY_SECRET'))],
                'timeout' => 15,
            ]);
            if (is_wp_error($res)) continue;
            $data = json_decode(wp_remote_retrieve_body($res), true);
            $map  = ['active'=>'active','cancelled'=>'cancelled','completed'=>'completed','halted'=>'past_due'];
            $ns   = $map[$data['status'] ?? ''] ?? null;
            if (!$ns) continue;
            $upd  = ['status' => $ns];
            if (!empty($data['current_end']))   $upd['current_period_end']   = gmdate('Y-m-d H:i:s', $data['current_end']);
            if (!empty($data['current_start'])) $upd['current_period_start'] = gmdate('Y-m-d H:i:s', $data['current_start']);
            $wpdb->update("{$wpdb->prefix}ia_subscriptions", $upd, ['id' => $sub->id]);
        }
    }

    public static function cleanup_signup_attempts(){
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}ia_signup_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

    public static function monthly_usage_reset(){
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}ia_usage WHERE month_year < DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 3 MONTH), '%Y-%m')");
    }

    public static function cleanup_abandoned(){
        global $wpdb;
        $wpdb->query(
            "UPDATE {$wpdb->prefix}ia_interviews
             SET status='abandoned', ended_at=NOW()
             WHERE status='active' AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }

    public static function weekly_aggregate(){
        global $wpdb; $p = $wpdb->prefix;
        $week  = gmdate('Y-m-d', strtotime('monday this week'));
        $types = $wpdb->get_col(
            "SELECT DISTINCT i.type FROM {$p}ia_interviews i
             JOIN {$p}ia_reports r ON r.interview_id=i.id
             WHERE r.status='done' AND i.ended_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        foreach ($types as $type) {
            $scores = $wpdb->get_col($wpdb->prepare(
                "SELECT r.overall_score FROM {$p}ia_reports r
                 JOIN {$p}ia_interviews i ON i.id=r.interview_id
                 WHERE i.type=%s AND r.status='done'
                 AND i.ended_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY r.overall_score ASC", $type
            ));
            $n = count($scores);
            if ($n < 10) continue;
            sort($scores);
            $p25 = (int)$scores[(int)floor(0.25 * ($n-1))];
            $p50 = (int)$scores[(int)floor(0.50 * ($n-1))];
            $p75 = (int)$scores[(int)floor(0.75 * ($n-1))];
            $avg = (int)round(array_sum($scores) / $n);
            $wpdb->replace("{$p}ia_score_aggregates", [
                'interview_type' => $type,
                'week_start'     => $week,
                'score_p25'      => $p25,
                'score_p50'      => $p50,
                'score_p75'      => $p75,
                'score_avg'      => $avg,
                'sample_size'    => $n,
            ]);
        }
    }

    public static function weekly_digest(){
        global $wpdb; $p = $wpdb->prefix;
        $users = $wpdb->get_results(
            "SELECT DISTINCT i.user_id FROM {$p}ia_interviews i
             WHERE i.status='completed' AND i.ended_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        foreach ($users as $row) {
            $uid  = (int)$row->user_id;
            $user = get_userdata($uid);
            if (!$user || !$user->user_email) continue;
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as cnt, AVG(r.overall_score) as avg_score, MAX(r.overall_score) as best
                 FROM {$p}ia_interviews i
                 LEFT JOIN {$p}ia_reports r ON r.interview_id=i.id
                 WHERE i.user_id=%d AND i.status='completed'
                 AND i.ended_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND r.status='done'", $uid
            ));
            $streak   = IA_API_Gamification::get_streak($uid);
            $total_xp = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(xp_delta),0) FROM {$p}ia_xp_events WHERE user_id=%d", $uid
            ));
            $name  = $user->display_name ?: 'there';
            $cnt   = (int)($stats->cnt ?? 0);
            $avg   = $stats->avg_score ? round($stats->avg_score) : 'N/A';
            $best  = $stats->best ?? 'N/A';
            $link  = home_url('/');
            $subj  = "Your week: $cnt interview" . ($cnt !== 1 ? 's' : '') . ", avg score $avg — InterviewAce";
            $body  = "Hi $name,\n\n"
                   . "Here's your InterviewAce weekly digest:\n\n"
                   . "This week: $cnt interview" . ($cnt !== 1 ? 's' : '') . "\n"
                   . "Average score: $avg / 100\n"
                   . "Best score: $best / 100\n"
                   . "Current streak: $streak day" . ($streak !== 1 ? 's' : '') . "\n"
                   . "Total XP: $total_xp\n\n"
                   . "Keep going — consistency is everything.\n\n"
                   . "Practice this week: $link\n\n"
                   . "— The InterviewAce Team";
            wp_mail($user->user_email, $subj, $body);
        }
    }
}
