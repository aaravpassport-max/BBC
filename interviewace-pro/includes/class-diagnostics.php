<?php
defined('ABSPATH') || exit;

/**
 * IA_Diagnostics — a self-service "why isn't this working" page.
 *
 * This exists because the plugin has, in the past, failed in ways whose
 * true cause (a hosting-level DDL block on ia_profiles, a stale
 * ia_db_version option masking a failed migration, a table-prefix
 * mismatch, a write that returns success but doesn't persist) was only
 * discoverable by reading raw error text out of screenshots and asking
 * the site owner to dig through phpMyAdmin by hand. Every check below
 * runs live, against the real database, on page load — no guessing.
 *
 * Menu: InterviewAce → Diagnostics (admin.php?page=interviewace-diagnostics)
 */
class IA_Diagnostics {

    /** Every table this plugin needs, keyed by suffix (without prefix). */
    const REQUIRED_TABLES = [
        'ia_profiles','ia_interviews','ia_turns','ia_reports','ia_usage',
        'ia_subscriptions','ia_saved_answers','ia_review_queue','ia_badges',
        'ia_xp_events','ia_otp_codes','ia_refresh_tokens','ia_payments',
        'ia_api_costs','ia_score_aggregates','ia_signup_attempts',
    ];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu'], 20);
        add_action('wp_ajax_ia_run_diagnostics', [__CLASS__, 'ajax_run']);
    }

    public static function add_menu() {
        add_submenu_page(
            'interviewace',
            'Diagnostics',
            '🩺 Diagnostics',
            'manage_options',
            'interviewace-diagnostics',
            [__CLASS__, 'render_page']
        );
    }

    /* ════════════════════════════════════════════════════
       THE CHECKS — each returns:
       ['label'=>string, 'status'=>'pass'|'fail'|'warn', 'detail'=>string, 'fix'=>string]
    ════════════════════════════════════════════════════ */

    public static function run_all(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $out = [];

        /* 1. PHP / WP / MySQL minimum versions */
        $out[] = self::check_versions();

        /* 2. wpdb prefix sanity */
        $out[] = [
            'label'  => 'Table prefix',
            'status' => $p ? 'pass' : 'fail',
            'detail' => 'WordPress is using table prefix "' . esc_html($p) . '". All InterviewAce tables are expected under this prefix.',
            'fix'    => '',
        ];

        /* 3. Every required table — does it exist, right now, via a live query */
        $missing = [];
        $existing = [];
        foreach (self::REQUIRED_TABLES as $suffix) {
            $table = $p . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found === $table) {
                $existing[] = $table;
            } else {
                $missing[] = $table;
            }
        }
        if (empty($missing)) {
            $out[] = [
                'label'  => 'Database tables (' . count(self::REQUIRED_TABLES) . ' required)',
                'status' => 'pass',
                'detail' => 'All ' . count(self::REQUIRED_TABLES) . ' required tables exist: ' . esc_html(implode(', ', $existing)) . '.',
                'fix'    => '',
            ];
        } else {
            $out[] = [
                'label'  => 'Database tables (' . count(self::REQUIRED_TABLES) . ' required)',
                'status' => 'fail',
                'detail' => count($missing) . ' table(s) are MISSING: ' . esc_html(implode(', ', $missing)) . '. ' . count($existing) . ' exist correctly.',
                'fix'    => 'Copy the CREATE TABLE statements for exactly these missing tables (see the SQL box below, auto-generated for just what\'s missing) and run them in phpMyAdmin → your database → SQL tab → Go.',
            ];
        }

        /* 4. ia_db_version option vs the code's expected IA_DB_VER */
        $stored_ver = get_option('ia_db_version', false);
        $code_ver   = defined('IA_DB_VER') ? IA_DB_VER : '(undefined)';
        if ($stored_ver === false) {
            $out[] = [
                'label'  => 'Database migration version',
                'status' => 'warn',
                'detail' => 'No ia_db_version option is stored yet — the plugin has never recorded a successful migration on this site.',
                'fix'    => 'Deactivate and reactivate the InterviewAce plugin once from Plugins → Installed Plugins to trigger table creation again.',
            ];
        } elseif ($stored_ver !== $code_ver) {
            $out[] = [
                'label'  => 'Database migration version',
                'status' => 'warn',
                'detail' => 'Stored version is "' . esc_html($stored_ver) . '" but this plugin code expects "' . esc_html($code_ver) . '". A migration may not have completed yet, or is pending.',
                'fix'    => 'Deactivate and reactivate the plugin to re-run migrations, then reload this page.',
            ];
        } else {
            $out[] = [
                'label'  => 'Database migration version',
                'status' => 'pass',
                'detail' => 'Stored version "' . esc_html($stored_ver) . '" matches the plugin code\'s expected version.',
                'fix'    => '',
            ];
        }

        /* 5. Any recorded migration error from verify_and_repair_core_tables() */
        $mig_err = get_option('ia_db_migration_error', []);
        if (!empty($mig_err) && is_array($mig_err)) {
            $lines = [];
            foreach ($mig_err as $table => $err) $lines[] = esc_html($table) . ': ' . esc_html($err);
            $out[] = [
                'label'  => 'Last recorded migration error',
                'status' => 'fail',
                'detail' => 'The plugin itself tried and failed to create these tables. Raw database error(s): <br>' . implode('<br>', $lines),
                'fix'    => 'This is almost always a hosting-level policy blocking the website from running CREATE TABLE, even though the table doesn\'t exist. Run the SQL manually via phpMyAdmin (bypasses this block), or forward this exact error text to your hosting provider\'s support team.',
            ];
        } else {
            $out[] = [
                'label'  => 'Last recorded migration error',
                'status' => 'pass',
                'detail' => 'No migration errors are currently recorded.',
                'fix'    => '',
            ];
        }

        /* 6. Live write test — actually insert+read+delete a throwaway row in wp_options
              (always exists) to prove the DB user can write at all, separately from
              whether it can run DDL (CREATE/ALTER TABLE) — these are different privileges
              on some hosts. */
        $test_key = 'ia_diag_write_test_' . time();
        $write_ok = add_option($test_key, 'ok', '', 'no');
        $read_ok  = $write_ok ? (get_option($test_key) === 'ok') : false;
        if ($write_ok) delete_option($test_key);
        $out[] = [
            'label'  => 'Database write permission (DML)',
            'status' => ($write_ok && $read_ok) ? 'pass' : 'fail',
            'detail' => ($write_ok && $read_ok)
                ? 'The database user can successfully write and read data (INSERT/SELECT).'
                : 'Could not write a test row to the database at all. Last DB error: ' . esc_html($wpdb->last_error ?: '(none returned)'),
            'fix'    => ($write_ok && $read_ok) ? '' : 'Contact your hosting provider — the database user WordPress is configured with cannot write data. Share this exact message with them.',
        ];

        /* 7. If ia_profiles exists, can we actually write a real profile row and read it back? */
        if (in_array($p . 'ia_profiles', $existing, true)) {
            $out[] = self::check_profiles_roundtrip($wpdb, $p);
        }

        /* 8. Permalink structure — rewrite rules for the app shell require non-Plain */
        $structure = get_option('permalink_structure');
        $out[] = [
            'label'  => 'Permalink structure',
            'status' => $structure ? 'pass' : 'fail',
            'detail' => $structure
                ? 'Permalinks are set to "' . esc_html($structure) . '" — the app\'s routes can work.'
                : 'Permalinks are set to "Plain" — the InterviewAce app routes (/app/...) will not load correctly.',
            'fix'    => $structure ? '' : 'Go to Settings → Permalinks and choose any option other than "Plain" (e.g. "Post name"), then Save Changes.',
        ];

        /* 9. REST API reachability — hit our own health route over HTTP, the same way the browser would */
        $out[] = self::check_rest_reachable();

        /* 10. Required API keys present (functional, not just DB-health, but relevant to "is it working") */
        $keys = [
            'ia_claude_key'    => 'Claude AI (required for interviews)',
            'ia_deepgram_key'  => 'Deepgram speech-to-text (required for voice)',
        ];
        $missing_keys = [];
        foreach ($keys as $opt => $label) if (!get_option($opt)) $missing_keys[] = $label;
        $out[] = [
            'label'  => 'Required API keys',
            'status' => empty($missing_keys) ? 'pass' : 'warn',
            'detail' => empty($missing_keys)
                ? 'Claude and Deepgram keys are both set.'
                : 'Missing: ' . esc_html(implode(', ', $missing_keys)) . '.',
            'fix'    => empty($missing_keys) ? '' : 'Go to InterviewAce → API Keys and paste the missing key(s).',
        ];

        return ['checks' => $out, 'missing_tables' => $missing];
    }

    private static function check_versions(): array {
        global $wpdb;
        $php_ok   = defined('IA_MIN_PHP')  ? version_compare(PHP_VERSION, IA_MIN_PHP, '>=')  : true;
        $wp_ok    = defined('IA_MIN_WP')   ? version_compare(get_bloginfo('version'), IA_MIN_WP, '>=') : true;
        $mysql_v  = $wpdb->db_version();
        $mysql_ok = defined('IA_MIN_MYSQL') && $mysql_v ? version_compare($mysql_v, IA_MIN_MYSQL, '>=') : true;
        $ok = $php_ok && $wp_ok && $mysql_ok;
        return [
            'label'  => 'Server requirements',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => sprintf(
                'PHP %s (need %s+) — %s. WordPress %s (need %s+) — %s. MySQL %s (need %s+) — %s.',
                esc_html(PHP_VERSION), esc_html(IA_MIN_PHP ?? '?'), $php_ok ? 'OK' : 'TOO OLD',
                esc_html(get_bloginfo('version')), esc_html(IA_MIN_WP ?? '?'), $wp_ok ? 'OK' : 'TOO OLD',
                esc_html($mysql_v ?: 'unknown'), esc_html(IA_MIN_MYSQL ?? '?'), $mysql_ok ? 'OK' : 'TOO OLD'
            ),
            'fix' => $ok ? '' : 'Ask your hosting provider to upgrade the outdated component(s) listed above.',
        ];
    }

    private static function check_profiles_roundtrip($wpdb, string $p): array {
        $table = $p . 'ia_profiles';
        // Use a fake, obviously-not-real user_id far outside any real WP user range so we never touch real data.
        $test_uid = 999999999;
        $wpdb->delete($table, ['user_id' => $test_uid]); // clean any leftover from a previous failed run
        $insert = $wpdb->insert($table, [
            'user_id'          => $test_uid,
            'name'             => 'Diagnostics Test',
            'experience_level' => 'fresher',
            'language_pref'    => 'english',
        ]);
        $err = $wpdb->last_error;
        $row = ($insert !== false) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d", $test_uid)) : null;
        $matches = $row && $row->experience_level === 'fresher' && $row->language_pref === 'english';
        // clean up regardless of outcome
        $wpdb->delete($table, ['user_id' => $test_uid]);

        if ($insert !== false && $matches) {
            return [
                'label'  => 'Profile save round-trip (real INSERT/SELECT test)',
                'status' => 'pass',
                'detail' => 'Successfully inserted a real test profile row into ' . esc_html($table) . ' and read the exact same values back. This is the same operation onboarding performs — it works.',
                'fix'    => '',
            ];
        }
        return [
            'label'  => 'Profile save round-trip (real INSERT/SELECT test)',
            'status' => 'fail',
            'detail' => 'Tried to insert a real row into ' . esc_html($table) . ', the same way onboarding does, and it failed or the values did not match. Database error: ' . esc_html($err ?: '(no error text returned)'),
            'fix'    => 'If the error mentions "experience_level" or "language_pref", the column type is out of date — deactivate and reactivate the plugin to trigger the column-widening migration. Otherwise, forward this exact error text to your hosting provider.',
        ];
    }

    private static function check_rest_reachable(): array {
        $url = rest_url('interviewace/v1/health');
        $has_health_route = false;
        // Only probe if a health route is actually registered, to avoid a false "fail" on 404 for unrelated reasons.
        $rest_server = rest_get_server();
        $routes = $rest_server ? $rest_server->get_routes() : [];
        foreach (array_keys($routes) as $route) {
            if (strpos($route, '/interviewace/v1/') === 0) { $has_health_route = true; break; }
        }
        if (!$has_health_route) {
            return [
                'label'  => 'REST API routes',
                'status' => 'warn',
                'detail' => 'No InterviewAce REST routes are currently registered on this request. This can happen if a fatal PHP error occurred earlier during plugin load.',
                'fix'    => 'Check Site Health → Info → Server, or your host\'s PHP error log, for a fatal error mentioning "interviewace".',
            ];
        }
        $resp = wp_remote_get($url, ['timeout' => 8, 'sslverify' => true]);
        if (is_wp_error($resp)) {
            return [
                'label'  => 'REST API reachability',
                'status' => 'fail',
                'detail' => 'InterviewAce REST routes are registered, but a live HTTP request to ' . esc_html($url) . ' failed: ' . esc_html($resp->get_error_message()),
                'fix'    => 'This usually means a security plugin, firewall, or .htaccess rule is blocking REST API requests. Check for security plugins that restrict /wp-json/.',
            ];
        }
        $code = wp_remote_retrieve_response_code($resp);
        return [
            'label'  => 'REST API reachability',
            'status' => ($code >= 200 && $code < 500) ? 'pass' : 'warn',
            'detail' => 'InterviewAce REST routes are registered and respond to live requests (HTTP ' . esc_html($code) . ' from ' . esc_html($url) . ').',
            'fix'    => '',
        ];
    }

    /** Build CREATE TABLE statements for only the currently-missing tables, so the
     *  admin can copy/paste the minimum needed instead of the full 16-table file. */
    public static function sql_for_missing(array $missing): string {
        if (empty($missing)) return '';
        global $wpdb;
        require_once IA_DIR . 'includes/class-activator.php';
        if (!method_exists('IA_Activator', 'get_table_sql_map')) return '';
        $map = IA_Activator::get_table_sql_map($wpdb->prefix, $wpdb->get_charset_collate());
        $out = [];
        foreach ($missing as $table) {
            $suffix = preg_replace('/^' . preg_quote($wpdb->prefix, '/') . '/', '', $table);
            if (isset($map[$suffix])) $out[] = $map[$suffix];
        }
        return implode("\n\n", $out);
    }

    /* ════════════════════════════════════════════════════
       RENDER
    ════════════════════════════════════════════════════ */

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        $result = self::run_all();
        $checks = $result['checks'];
        $missing = $result['missing_tables'];
        $sql = self::sql_for_missing($missing);

        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $c) $counts[$c['status']]++;
        ?>
        <div class="wrap">
        <style>
            .ia-diag{max-width:920px}
            .ia-diag h1{display:flex;align-items:center;gap:10px}
            .ia-diag-summary{display:flex;gap:10px;margin:14px 0 22px;}
            .ia-diag-pill{padding:8px 16px;border-radius:9px;font-weight:700;font-size:13px}
            .ia-diag-row{background:#fff;border:1px solid #e2e8f0;border-left:5px solid #ccc;border-radius:8px;padding:14px 18px;margin-bottom:10px}
            .ia-diag-row.pass{border-left-color:#16a34a}
            .ia-diag-row.warn{border-left-color:#d97706}
            .ia-diag-row.fail{border-left-color:#dc2626}
            .ia-diag-row h3{margin:0 0 6px;font-size:14px;display:flex;align-items:center;gap:8px}
            .ia-diag-row p{margin:4px 0;font-size:13px;color:#334155;line-height:1.6}
            .ia-diag-fix{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;margin-top:8px;font-size:12.5px;color:#78350f}
            .ia-diag-badge{font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px}
            .ia-diag-badge.pass{background:#d1fae5;color:#065f46}
            .ia-diag-badge.warn{background:#fef3c7;color:#92400e}
            .ia-diag-badge.fail{background:#fee2e2;color:#991b1b}
            .ia-diag-sql{width:100%;height:220px;font-family:monospace;font-size:12px;margin-top:10px}
        </style>
        <div class="ia-diag">
            <h1>🩺 InterviewAce Diagnostics</h1>
            <p style="color:#64748b;">Every check below runs live against this site right now — nothing here is cached or guessed. Reload this page any time after making a change to re-check.</p>

            <div class="ia-diag-summary">
                <div class="ia-diag-pill" style="background:#d1fae5;color:#065f46;"><?php echo (int)$counts['pass']; ?> Passing</div>
                <div class="ia-diag-pill" style="background:#fef3c7;color:#92400e;"><?php echo (int)$counts['warn']; ?> Warnings</div>
                <div class="ia-diag-pill" style="background:#fee2e2;color:#991b1b;"><?php echo (int)$counts['fail']; ?> Failing</div>
            </div>

            <?php foreach ($checks as $c): ?>
                <div class="ia-diag-row <?php echo esc_attr($c['status']); ?>">
                    <h3>
                        <?php echo $c['status'] === 'pass' ? '✅' : ($c['status'] === 'warn' ? '⚠️' : '❌'); ?>
                        <?php echo esc_html($c['label']); ?>
                        <span class="ia-diag-badge <?php echo esc_attr($c['status']); ?>"><?php echo strtoupper($c['status']); ?></span>
                    </h3>
                    <p><?php echo wp_kses_post($c['detail']); ?></p>
                    <?php if ($c['fix']): ?>
                        <div class="ia-diag-fix"><strong>How to fix:</strong> <?php echo wp_kses_post($c['fix']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($sql): ?>
                <div class="ia-diag-row fail">
                    <h3>📋 Copy-paste SQL for exactly the missing table(s)</h3>
                    <p>This is generated specifically for what's missing on <em>this</em> site right now — paste it into phpMyAdmin → your database → SQL tab → Go.</p>
                    <textarea class="ia-diag-sql" readonly onclick="this.select()"><?php echo esc_textarea($sql); ?></textarea>
                </div>
            <?php endif; ?>

            <p style="margin-top:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=interviewace-diagnostics')); ?>" class="button button-primary">🔄 Re-run diagnostics</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=interviewace')); ?>" class="button" style="margin-left:8px;">← Back to InterviewAce</a>
            </p>
        </div>
        </div>
        <?php
    }

    /** Optional AJAX entry point (not required for the page itself, kept for
     *  future use e.g. a "re-check" button that doesn't reload the whole page). */
    public static function ajax_run() {
        if (!current_user_can('manage_options') || !check_ajax_referer('ia_diag_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        wp_send_json_success(self::run_all());
    }
}
