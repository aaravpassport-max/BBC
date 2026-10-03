<?php
defined('ABSPATH') || exit;

class IA_Plan_Enforcer {

    /* ── Default quota values (overridden by admin settings) ── */
    const DEFAULTS = [
        'free_session_minutes'    => 15,
        'free_weekly_interviews'  => 2,
        'free_monthly_minutes'    => 60,
        'pro_session_minutes'     => 60,
        'pro_monthly_minutes'     => 600,
        'premium_session_minutes' => 90,
        'premium_monthly_minutes' => 1800,
        'b2b_session_minutes'     => 60,
        'b2b_monthly_minutes'     => 600,
    ];

    /** Read a quota value: admin option → default */
    public static function quota(string $key): int {
        $v = get_option('ia_quota_' . $key);
        if ($v !== false && is_numeric($v) && (int)$v > 0) return (int)$v;
        return self::DEFAULTS[$key] ?? 0;
    }

    /** Get plan for a user */
    public static function get_plan(int $uid): string {
        if (user_can($uid, 'manage_options')) return 'admin';
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT plan FROM {$wpdb->prefix}ia_subscriptions
             WHERE user_id=%d AND status='active' AND current_period_end>NOW()
             ORDER BY created_at DESC LIMIT 1", $uid
        ));
        return $r ? $r->plan : 'free';
    }

    /** Can user create a new interview this week? */
    public static function can_create(int $uid) {
        $plan = self::get_plan($uid);
        if (in_array($plan, ['admin','pro','premium','b2b'])) return true;
        /* Free: limited interviews per week */
        global $wpdb;
        $week_start = gmdate('Y-m-d', strtotime('monday this week'));
        $cnt = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ia_interviews
             WHERE user_id=%d AND status IN('completed','active','created')
             AND created_at>=%s", $uid, $week_start . ' 00:00:00'
        ));
        $limit = self::quota('free_weekly_interviews');
        if ($cnt >= $limit) return new WP_Error('ia_limit',
            "Free plan: {$limit} interviews per week. Upgrade to Pro for unlimited.",
            /* ROOT-CAUSE FIX: was home_url('/billing') — the React app is
               mounted with basename="/app" (see frontend/src/App.tsx), so a
               link without that prefix falls outside every route the SPA
               router knows and renders blank. Matches the equivalent
               time_limit error a few lines below in turn(), which already
               used the correct '/app/billing'. */
            ['status'=>403,'code'=>'weekly_limit','upgrade_url'=>home_url('/app/billing')]
        );
        return true;
    }

    /** Max minutes per session for this user */
    public static function max_minutes(int $uid): int {
        $plan = self::get_plan($uid);
        switch ($plan) {
            case 'admin':   return 9999;
            case 'premium': return self::quota('premium_session_minutes');
            case 'pro':     return self::quota('pro_session_minutes');
            case 'b2b':     return self::quota('b2b_session_minutes');
            default:        return self::quota('free_session_minutes');
        }
    }

    /** Remaining minutes this month (cumulative across sessions) */
    public static function remaining_minutes(int $uid): int {
        $plan = self::get_plan($uid);
        if ($plan === 'admin') return 99999;
        $allowance_key = $plan . '_monthly_minutes';
        $max   = self::quota($allowance_key) ?: self::quota('free_monthly_minutes');
        global $wpdb;
        $month = gmdate('Y-m');
        $used  = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT minutes_used FROM {$wpdb->prefix}ia_usage
             WHERE user_id=%d AND month_year=%s", $uid, $month
        ));
        return max(0, $max - $used);
    }

    /** Increment usage counters */
    public static function inc_usage(int $uid, int $minutes = 0, bool $new_interview = true) {
        global $wpdb;
        $month = gmdate('Y-m');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}ia_usage
             (user_id, month_year, interviews_count, minutes_used)
             VALUES(%d, %s, %d, %d)
             ON DUPLICATE KEY UPDATE
             interviews_count = interviews_count + %d,
             minutes_used     = minutes_used + %d",
            $uid, $month,
            $new_interview ? 1 : 0, $minutes,
            $new_interview ? 1 : 0, $minutes
        ));
    }

    /** Signup rate limiting: 1 per device/day, 3 per IP total */
    public static function check_signup_allowed(string $ip, string $ua) {
        global $wpdb; $t = "{$wpdb->prefix}ia_signup_attempts";
        $fp = hash('sha256', $ip . '|' . $ua . '|' . gmdate('Y-m-d'));
        $fp_today = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE fingerprint_hash=%s AND created_at>=CURDATE()", $fp
        ));
        if ($fp_today >= 1) return new WP_Error('ia_rate',
            'One account per device per day. Please try again tomorrow.', ['status'=>429]);
        $ip_total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE ip_address=%s", $ip
        ));
        if ($ip_total >= 3) return new WP_Error('ia_rate',
            'Account limit reached from this network.', ['status'=>429]);
        return true;
    }

    public static function record_signup(string $ip, string $ua) {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ia_signup_attempts", [
            'ip_address'       => $ip,
            'fingerprint_hash' => hash('sha256', $ip . '|' . $ua . '|' . gmdate('Y-m-d')),
        ]);
    }
}
