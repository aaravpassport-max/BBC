<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for uninstall.php -
 * the new uninstall handler added by the 2026-08-30 "uninstall/
 * deactivation cleanup" audit pass (this plugin previously had no
 * uninstall.php and no register_uninstall_hook() at all, leaving every
 * option it ever wrote - including AES-256-CBC-encrypted broker/API
 * credentials - permanently orphaned on plugin deletion).
 *
 * Covers three real things:
 *  1. The WP_UNINSTALL_PLUGIN guard: running the file directly (the
 *     way a malicious/careless direct request would) exits immediately
 *     and touches nothing - proven via a real subprocess, not a stub.
 *  2. Functional: with WP_UNINSTALL_PLUGIN defined and get_option/
 *     delete_option/$wpdb stubbed, every plugin-owned option this
 *     codebase actually writes (per a live grep of fno-lab.php and
 *     fno-data-layer.php) is deleted, and delete_site_option is also
 *     called for each (multisite safety).
 *  3. Safety invariant: a static source scan proves uninstall.php
 *     contains no DROP TABLE and no DELETE ... FROM referencing any of
 *     this plugin's real trade-data tables (fno_journal,
 *     fno_open_positions, fno_real_money_journal,
 *     fno_real_money_accounts, fno_manual_positions,
 *     fno_failure_events, fno_participant_hypotheses,
 *     fno_rejected_opportunities, fno_factor_values,
 *     fno_microstructure, fno_raw_ticks) - the documented, deliberate
 *     "never silently destroy trade history" design decision, checked
 *     mechanically so a future edit can't regress it unnoticed.
 *
 * Run with: php tests/php/UninstallCleanupTest.php
 */

$failures = 0;
$total = 0;
function check($label, $cond) {
    global $failures, $total;
    $total++;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label\n"; $failures++; }
}

$uninstallPath = __DIR__ . '/../../uninstall.php';
$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$dataLayerSource = file_get_contents(__DIR__ . '/../../fno-data-layer.php');
$uninstallSource = file_get_contents($uninstallPath);

// --- (1) Guard: direct execution (no WP_UNINSTALL_PLUGIN) must exit
// cleanly and touch nothing. Run in a real subprocess so `exit` is
// observed for real, not stubbed away.
$guardOutput = [];
$guardExitCode = 0;
exec('php -r ' . escapeshellarg('include ' . escapeshellarg($uninstallPath) . '; echo "REACHED_END";') . ' 2>&1', $guardOutput, $guardExitCode);
$guardOutputStr = implode("\n", $guardOutput);
check('direct execution without WP_UNINSTALL_PLUGIN exits before reaching the end of the file', strpos($guardOutputStr, 'REACHED_END') === false);
check('direct execution without WP_UNINSTALL_PLUGIN produces no fatal error/warning noise', strpos($guardOutputStr, 'Fatal error') === false && strpos($guardOutputStr, 'Warning') === false);

// --- (2) Functional: every option this plugin actually writes gets
// deleted. Derive the expected list from a live grep of the real
// source, so this test breaks (loudly) if a future option is added to
// the plugin but forgotten in uninstall.php - rather than silently
// passing forever.
preg_match_all("/(?:get_option|update_option|delete_option|add_option)\('(fno_[a-z_]+)'/", $pluginSource . "\n" . $dataLayerSource, $m);
$expectedOptions = array_values(array_unique($m[1]));
sort($expectedOptions);
check('at least one real fno_ option name was discovered by the live source grep (sanity check on the test itself)', count($expectedOptions) >= 10);

define('WP_UNINSTALL_PLUGIN', __DIR__ . '/../../fno-lab-standalone-app/fno-lab.php');
$GLOBALS['fno_test_deleted_options'] = [];
$GLOBALS['fno_test_deleted_site_options'] = [];
function delete_option($key) { $GLOBALS['fno_test_deleted_options'][] = $key; return true; }
function delete_site_option($key) { $GLOBALS['fno_test_deleted_site_options'][] = $key; return true; }
function is_multisite() { return false; }
class FnoTestWpdbStub {
    public $options = 'wp_options';
    public $sitemeta = 'wp_sitemeta';
    public $queries = [];
    public function query($sql) { $this->queries[] = $sql; return 0; }
}
$wpdb = new FnoTestWpdbStub();

include $uninstallPath;

foreach ($expectedOptions as $opt) {
    // fno_dsm_paid_api_log and a couple of internal read-only lookups
    // are still real, plugin-owned options - every one of them must be
    // covered.
    check("uninstall.php deletes option '$opt'", in_array($opt, $GLOBALS['fno_test_deleted_options'], true));
    check("uninstall.php deletes site-option '$opt' (multisite safety)", in_array($opt, $GLOBALS['fno_test_deleted_site_options'], true));
}
check('uninstall.php issues at least one DELETE against transients via $wpdb->query()', count($wpdb->queries) >= 1);
foreach ($wpdb->queries as $q) {
    check('every $wpdb query in uninstall.php only ever targets transient rows (LIKE ..._transient_fno_...), never a real data table', stripos($q, 'fno_transient') !== false || preg_match('/LIKE\s+\'\\\\_(site_)?transient/i', $q));
}

// --- (3) Safety invariant: no destructive statement against any real
// trade-data table anywhere in uninstall.php.
$protectedTables = [
    'fno_journal', 'fno_open_positions', 'fno_real_money_journal',
    'fno_real_money_accounts', 'fno_manual_positions', 'fno_failure_events',
    'fno_participant_hypotheses', 'fno_rejected_opportunities',
    'fno_factor_values', 'fno_microstructure', 'fno_raw_ticks',
];
check('uninstall.php contains no DROP TABLE statement at all', stripos($uninstallSource, 'DROP TABLE') === false);
foreach ($protectedTables as $table) {
    // Deliberately allow the table name to appear in a comment (it does,
    // in the documented design-decision block) but never inside an
    // actual DELETE/DROP/TRUNCATE SQL statement.
    $destructivePattern = '/(DELETE\s+FROM|DROP\s+TABLE|TRUNCATE)[^;]*' . preg_quote($table, '/') . '/i';
    check("uninstall.php never issues a destructive statement against real trade-data table '$table'", !preg_match($destructivePattern, $uninstallSource));
}

echo "\n$total checks, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
