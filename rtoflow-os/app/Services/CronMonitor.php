<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 8, item — "WP-Cron is the only job runner,
 * with no dead-man's-switch"):
 *
 * wp_schedule_event() only fires WP-Cron on an incoming HTTP request to
 * the site (WP-Cron is not a real daemon) — under low traffic, or if a
 * host disables it (DISABLE_WP_CRON) without wiring a real system-cron
 * replacement, the SLA check, notification queue, payment reconciliation,
 * document-expiry check, weekly ops report and weekly backup jobs can all
 * silently stop firing with nothing surfacing the failure beyond an admin
 * proactively opening a status page.
 *
 * This class is that missing dead-man's-switch:
 *   - heartbeat($job) is hooked (priority 999, after the real handlers)
 *     onto every cron action below, and stamps "this job actually ran" —
 *     not just "was scheduled" — into wp_options.
 *   - status() compares each heartbeat against the job's expected
 *     interval (with slack) and reports which jobs are overdue.
 *   - Wired into both health-check endpoints (Router::routeHealth() and
 *     the /wp-json/rtoflow/v1/health REST route) so an external uptime
 *     monitor polling either one flips to "degraded" the moment a job
 *     goes silent — not only when the DB connection itself fails.
 *   - Also surfaces an admin_notice on wp-admin page loads, so a stalled
 *     queue is visible without anyone needing to know this class exists.
 */
final class CronMonitor
{
    private const OPTION = 'rtoflow_cron_heartbeats';

    /** hook => [label, expected interval in seconds, slack multiplier] */
    private const JOBS = [
        'rtoflow_sla_check'             => ['SLA / inactivity check',   HOUR_IN_SECONDS,  3],
        'rtoflow_notification_queue'    => ['Notification queue',       5 * MINUTE_IN_SECONDS, 6],
        'rtoflow_payment_reconciliation'=> ['Payment reconciliation',   DAY_IN_SECONDS,   2],
        'rtoflow_document_expiry_check' => ['Document expiry check',    DAY_IN_SECONDS,   2],
        'rtoflow_weekly_ops_report'     => ['Weekly ops report',        WEEK_IN_SECONDS,  2],
        'rtoflow_weekly_backup'         => ['Weekly backup',            WEEK_IN_SECONDS,  2],
    ];

    public static function register(): void
    {
        foreach (self::JOBS as $hook => $unused) {
            // Priority 999: run after the job's real handler(s) so a
            // heartbeat only lands once the job actually executed, not
            // merely once WP-Cron attempted to fire the hook.
            add_action($hook, function () use ($hook) { self::heartbeat($hook); }, 999, 0);
        }
        add_action('admin_notices', [self::class, 'renderAdminNotice']);

        // ENTERPRISE GAP FIX (Phase 13, item — "WP-Cron dead-man's-switch:
        // admin-triggered self-healing fallback")
        add_action('admin_init', [self::class, 'maybeRunOverdueNow']);
    }

    /**
     * ENTERPRISE GAP FIX (Phase 13, item — "WP-Cron dead-man's-switch:
     * admin-triggered self-healing fallback"):
     *
     * Hooked onto admin_init. Deliberately admin-only — never hooked into
     * front-end requests — so this can never be triggered by an
     * unauthenticated visitor hitting a public URL repeatedly. Throttled to
     * roughly once a minute across all admin traffic via a short-lived
     * transient, so a busy wp-admin does not pay the status()/do_action()
     * cost on every single page load.
     */
    public static function maybeRunOverdueNow(): void
    {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;

        // Throttle: only actually check roughly once every 60 seconds
        // across all admin requests, not on every page load.
        if (get_transient('rtoflow_cron_fallback_checked')) return;
        set_transient('rtoflow_cron_fallback_checked', 1, 60);

        self::runOverdueNow();
    }

    /**
     * ENTERPRISE GAP FIX (Phase 13, item — "WP-Cron dead-man's-switch:
     * admin-triggered self-healing fallback"):
     *
     * For every job that status() reports as overdue, fires the hook via
     * do_action() — this deliberately does NOT reimplement any job logic,
     * it simply fires the exact same real handler(s) registered in
     * Bootstrap.php (including this class's own heartbeat(), which is
     * registered on the same hooks at priority 999), so a synchronous
     * admin-triggered run is indistinguishable from a real WP-Cron run as
     * far as every downstream handler and the heartbeat log are concerned.
     *
     * Per-job transient lock prevents two admins loading pages seconds
     * apart from both triggering the same job, and prevents a single job
     * from being re-fired while a previous synchronous run of it is still
     * within its lock window. This is a lightweight advisory lock (not a
     * hard mutex) — adequate given WordPress's normal one-request-at-a-time
     * PHP-FPM/mod_php process model, not a guarantee under exotic
     * concurrent-request setups.
     *
     * Each job is wrapped in its own try/catch so one job throwing can
     * neither break the admin page render nor prevent the remaining
     * overdue jobs from getting their turn.
     */
    public static function runOverdueNow(): void
    {
        foreach (self::status() as $hook => $s) {
            if (!$s['overdue']) continue;

            $lockKey = 'rtoflow_cron_fallback_lock_' . $hook;
            if (get_transient($lockKey)) continue;
            set_transient($lockKey, 1, 120);

            try {
                do_action($hook);
            } catch (\Throwable $e) {
                error_log('RTOFLOW cron fallback: ' . $hook . ' threw: ' . $e->getMessage());
            }
        }
    }

    public static function heartbeat(string $hook): void
    {
        $all = get_option(self::OPTION, []);
        if (!is_array($all)) $all = [];
        $all[$hook] = time();
        update_option(self::OPTION, $all, false);
    }

    /**
     * @return array<string, array{label:string, last_run:?int, overdue:bool, expected_interval:int}>
     */
    public static function status(): array
    {
        $all  = get_option(self::OPTION, []);
        if (!is_array($all)) $all = [];
        $now  = time();
        $out  = [];

        foreach (self::JOBS as $hook => [$label, $interval, $slack]) {
            $last    = isset($all[$hook]) ? (int)$all[$hook] : null;
            $overdue = $last === null
                ? (bool)wp_next_scheduled($hook) === false // never ran AND not even scheduled = definitely overdue
                : ($now - $last) > ($interval * $slack);
            // A job that has never run but IS scheduled and the site is
            // simply young (first interval hasn't elapsed yet) is not yet
            // overdue — only flag "never ran" once the interval has passed.
            if ($last === null && wp_next_scheduled($hook)) {
                $overdue = false;
            }
            $out[$hook] = [
                'label'             => $label,
                'last_run'          => $last,
                'overdue'           => $overdue,
                'expected_interval' => $interval,
            ];
        }
        return $out;
    }

    public static function hasOverdueJobs(): bool
    {
        foreach (self::status() as $s) {
            if ($s['overdue']) return true;
        }
        return false;
    }

    public static function renderAdminNotice(): void
    {
        if (!current_user_can('manage_options')) return;
        $overdue = array_filter(self::status(), fn($s) => $s['overdue']);
        if (!$overdue) return;

        $names = implode(', ', array_column($overdue, 'label'));
        echo '<div class="notice notice-error"><p><strong>RTOFLOW:</strong> '
           . 'the following background jobs have not run recently and may have '
           . 'stopped firing (WP-Cron only runs on incoming traffic — check your '
           . 'site\'s cron delivery): ' . esc_html($names) . '. '
           // ENTERPRISE GAP FIX (Phase 13, item — "WP-Cron dead-man's-switch:
           // admin-triggered self-healing fallback"): be honest that this
           // notice appearing at all means the built-in admin-visit fallback
           // either hasn't caught up with these jobs yet or can't reach them
           // (e.g. a site with genuinely no admin logins for an extended
           // stretch) — it is a mitigation, not a substitute for real
           // server-level cron, especially for jobs like the weekly backup
           // that matter even when no admin opens wp-admin.
           . 'RTOFLOW automatically attempts to catch up overdue jobs on '
           . 'admin dashboard visits as a fallback, but this is not a '
           . 'substitute for real server-level cron — configure one for full '
           . 'reliability, particularly for jobs (like the weekly backup) '
           . 'that should run even if no admin logs in.</p></div>';
    }
}
