<?php
defined('ABSPATH') || exit;

/*
 * IA_Cost_Tracker
 *
 * Records every external API call with unit counts and rupee cost.
 * Called from IA_Claude after each API response, and from the
 * front-end reporter for Deepgram/ElevenLabs (client-side bytes
 * reported back via REST).
 *
 * Pricing (as of June 2025, converted to INR at ₹84/USD):
 *   Claude Sonnet 4.6  — input  $3/M tokens  = ₹0.025 per token
 *                      — output $15/M tokens  = ₹0.126 per token
 *   Deepgram Nova-2    — $0.0043/min = ₹0.361/min = ₹6.01 paise/sec
 *   ElevenLabs Turbo   — $0.50/1k chars = ₹42/1k chars = 4.2 paise/char
 *
 * All costs stored in paise (₹1 = 100 paise) as integers.
 * Admin can override rates via WP options (ia_cost_rate_*).
 */
class IA_Cost_Tracker {

    /* ── Rate accessors (paise per unit, admin-overridable) ─────── */

    /** Cost per Claude input token in paise (default: 0.025p) */
    public static function rate_claude_input(): float {
        $v = get_option('ia_cost_rate_claude_input');
        return ($v !== false && is_numeric($v)) ? (float)$v : 0.025;
    }

    /** Cost per Claude output token in paise (default: 0.126p) */
    public static function rate_claude_output(): float {
        $v = get_option('ia_cost_rate_claude_output');
        return ($v !== false && is_numeric($v)) ? (float)$v : 0.126;
    }

    /** Cost per Deepgram second in paise (default: 6.01p/sec) */
    public static function rate_deepgram(): float {
        $v = get_option('ia_cost_rate_deepgram_sec');
        return ($v !== false && is_numeric($v)) ? (float)$v : 6.01;
    }

    /** Cost per ElevenLabs character in paise (default: 0.042p/char) */
    public static function rate_elevenlabs(): float {
        $v = get_option('ia_cost_rate_elevenlabs_char');
        return ($v !== false && is_numeric($v)) ? (float)$v : 0.042;
    }

    /* ── Record methods ─────────────────────────────────────────── */

    /**
     * Record a Claude API call.
     * Called from IA_Claude::call() after every successful response.
     */
    public static function record_claude(
        int    $uid,
        ?int   $interview_id,
        string $operation,          // 'generate_plan' | 'interview_turn' | 'generate_report' | etc.
        int    $input_tokens,
        int    $output_tokens
    ): void {
        $total_tokens = $input_tokens + $output_tokens;
        $cost_paise   = (int)round(
            $input_tokens  * self::rate_claude_input() +
            $output_tokens * self::rate_claude_output()
        );
        self::insert('claude', $operation, $total_tokens, 'tokens', $cost_paise, $uid, $interview_id);
    }

    /**
     * Record Deepgram usage.
     * Called from REST endpoint /interviews/{id}/report-stt-cost
     * with seconds of audio processed (reported by client after session).
     */
    public static function record_deepgram(int $uid, ?int $interview_id, float $seconds): void {
        $cost_paise = (int)round($seconds * self::rate_deepgram());
        self::insert('deepgram', 'stt', (int)ceil($seconds), 'seconds', $cost_paise, $uid, $interview_id);
    }

    /**
     * Record ElevenLabs usage.
     * Called from REST endpoint /interviews/{id}/report-tts-cost
     * with character count of text sent to TTS (reported by client).
     */
    public static function record_elevenlabs(int $uid, ?int $interview_id, int $chars): void {
        $cost_paise = (int)round($chars * self::rate_elevenlabs());
        self::insert('elevenlabs', 'tts', $chars, 'characters', $cost_paise, $uid, $interview_id);
    }

    private static function insert(
        string $service, string $op, int $units, string $unit_type,
        int $cost_paise, int $uid, ?int $interview_id
    ): void {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ia_api_costs", [
            'user_id'      => $uid,
            'interview_id' => $interview_id ?: null,
            'service'      => $service,
            'operation'    => $op,
            'units'        => $units,
            'unit_type'    => $unit_type,
            'cost_paise'   => $cost_paise,
        ]);
    }

    /* ── Aggregation helpers (used by admin dashboard) ─────────── */

    /** Total cost in paise for a date range, optionally by service */
    public static function total(
        string $from, string $to, ?string $service = null, ?int $uid = null
    ): int {
        global $wpdb; $p = $wpdb->prefix;
        $where = "WHERE recorded_at BETWEEN %s AND %s";
        $args  = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($service) { $where .= " AND service=%s"; $args[] = $service; }
        if ($uid)     { $where .= " AND user_id=%d";  $args[] = $uid; }
        return (int)$wpdb->get_var(
            $wpdb->prepare("SELECT COALESCE(SUM(cost_paise),0) FROM {$p}ia_api_costs $where", ...$args)
        );
    }

    /** Daily cost breakdown for a month — returns array of {date, total_paise} */
    public static function daily_breakdown(string $month_year): array {
        global $wpdb; $p = $wpdb->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(recorded_at) as date,
                    SUM(cost_paise) as total_paise,
                    SUM(CASE WHEN service='claude'     THEN cost_paise ELSE 0 END) as claude_paise,
                    SUM(CASE WHEN service='deepgram'   THEN cost_paise ELSE 0 END) as deepgram_paise,
                    SUM(CASE WHEN service='elevenlabs' THEN cost_paise ELSE 0 END) as elevenlabs_paise,
                    COUNT(DISTINCT interview_id) as interviews
             FROM {$p}ia_api_costs
             WHERE DATE_FORMAT(recorded_at, '%%Y-%%m') = %s
             GROUP BY DATE(recorded_at)
             ORDER BY date ASC",
            $month_year
        ));
    }

    /** Per-service totals for a period */
    public static function service_breakdown(string $from, string $to): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT service,
                    SUM(units) as total_units,
                    SUM(cost_paise) as total_paise,
                    COUNT(*) as call_count
             FROM {$p}ia_api_costs
             WHERE recorded_at BETWEEN %s AND %s
             GROUP BY service
             ORDER BY total_paise DESC",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ), ARRAY_A);
        return $rows ?: [];
    }

    /** Top N most expensive users in a period */
    public static function top_users(string $from, string $to, int $limit = 15): array {
        global $wpdb; $p = $wpdb->prefix;
        /*
         * ROOT-CAUSE FIX: `{$p}users` used `$wpdb->prefix`, which is the
         * per-site table prefix. On a single-site install that happens to
         * equal 'wp_' and this coincidentally worked, but on WordPress
         * Multisite `$wpdb->prefix` is blog-specific (e.g. 'wp_2_') while
         * the users table is always global and unprefixed per-blog —
         * `{$p}users` would resolve to a table that doesn't exist
         * ('wp_2_users'), and this query would fail on any site but the
         * main one. `$wpdb->users` is WordPress's own correctly-resolved
         * reference to the real (global) users table in every configuration.
         */
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.user_id,
                    u.display_name,
                    u.user_email,
                    SUM(c.cost_paise) as total_paise,
                    COUNT(DISTINCT c.interview_id) as interviews,
                    s.plan
             FROM {$p}ia_api_costs c
             JOIN {$wpdb->users} u ON u.ID = c.user_id
             LEFT JOIN {$p}ia_subscriptions s ON s.user_id=c.user_id AND s.status='active'
             WHERE c.recorded_at BETWEEN %s AND %s
             GROUP BY c.user_id
             ORDER BY total_paise DESC
             LIMIT %d",
            $from . ' 00:00:00', $to . ' 23:59:59', $limit
        ), ARRAY_A) ?: [];
    }

    /** Average cost per interview in paise */
    public static function cost_per_interview(string $from, string $to): int {
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(cost_paise) as total, COUNT(DISTINCT interview_id) as cnt
             FROM {$p}ia_api_costs
             WHERE recorded_at BETWEEN %s AND %s AND interview_id IS NOT NULL",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ));
        if (!$row || !$row->cnt) return 0;
        return (int)round($row->total / $row->cnt);
    }

    /** Format paise as ₹ string */
    public static function fmt(int $paise): string {
        if ($paise < 100)   return $paise . ' p';
        $rs = $paise / 100;
        if ($rs < 1000)     return '₹' . number_format($rs, 2);
        if ($rs < 100000)   return '₹' . number_format($rs / 1000, 1) . 'K';
        return '₹' . number_format($rs / 100000, 2) . 'L';
    }
}
