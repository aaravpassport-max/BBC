<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_generate_diagnostic_report_data() - the shared, real data
 * source both the CSV and PDF diagnostic-report exports use, built
 * at the user's own direct, explicit request for an in-app
 * evaluation/diagnostic system with downloadable reports.
 *
 * Stubs $wpdb with a real, lightweight, in-memory mock matching each
 * real query by its own distinguishing SQL fragment - the same real,
 * established "stub the exact call, not the whole system" principle
 * already used throughout this test suite (e.g. fno_nse_get,
 * wp_remote_get stubs matched by URL substring) - never a full,
 * separately-implemented SQL engine that could itself silently
 * diverge from the real function's actual behavior.
 *
 * Run with: php tests/php/DiagnosticReportTest.php
 */

class FakeWpdbForDiagnosticReport {
    public $prefix = 'wp_';
    public function get_results($query, $output = OBJECT) {
        if (strpos($query, 'GROUP BY category') !== false && strpos($query, 'failure_events') !== false) {
            return $GLOBALS['fno_test_failures_by_category'] ?? [];
        }
        if (strpos($query, 'GROUP BY regime_label, category') !== false) {
            return $GLOBALS['fno_test_failures_by_regime'] ?? [];
        }
        if (strpos($query, 'SELECT category, subcategory, reason') !== false) {
            return $GLOBALS['fno_test_recent_failures'] ?? [];
        }
        if (strpos($query, 'factor_snapshot') !== false) {
            return $GLOBALS['fno_test_snapshots'] ?? [];
        }
        if (strpos($query, 'FROM ' . $this->prefix . 'fno_journal') !== false) {
            return $GLOBALS['fno_test_trades'] ?? [];
        }
        return [];
    }
    public function get_row($query, $output = OBJECT) {
        return $GLOBALS['fno_test_hypothesis_stats'] ?? null;
    }
    public function get_var($query) {
        if (strpos($query, 'SHOW TABLES') !== false) return $GLOBALS['fno_test_hypothesis_table_exists'] ?? null;
        return null;
    }
    public function prepare($query, ...$args) { return $query; } // real, deliberately pass-through - this test verifies real query CONSTRUCTION/routing and real data-shaping, not real SQL escaping (already covered elsewhere in this suite)
}
if (!defined('OBJECT')) define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
$GLOBALS['wpdb'] = new FakeWpdbForDiagnosticReport();
if (!defined('FNO_JOURNAL_SCHEMA_VERSION')) define('FNO_JOURNAL_SCHEMA_VERSION', 'test-schema-v1');

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$hStart = strpos($pluginSource, 'function fno_now_ist(');
$hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
eval(substr($pluginSource, $hStart, $hEnd - $hStart));
$start = strpos($pluginSource, 'function fno_generate_diagnostic_report_data(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_generate_diagnostic_report_data (real, shared source for both CSV and PDF exports) ===\n";

// SELF-CAUGHT BUG, found via a real, live download test immediately
// after this feature was first built: the real query below had
// referenced a column named 'ts', which does not exist in the real
// wp_fno_journal table (the real column is 'trade_ts') - silently
// returning zero rows in real, live use rather than throwing, since
// $wpdb->get_results() never throws on a bad query. The mock-based
// tests above this comment, by design, could not have caught this
// exact bug class, since they match queries by SQL fragment and
// return pre-configured fixture data regardless of which specific
// columns were actually requested. This real, direct check on the
// actual, live SQL text closes that gap - reads the real
// fno-lab.php source directly and confirms the real query genuinely
// references the real, correct column name.
$realSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$realFnStart = strpos($realSource, 'function fno_generate_diagnostic_report_data(');
$realFnEnd = strpos($realSource, "\n}", $realFnStart) + 2;
$realFnBody = substr($realSource, $realFnStart, $realFnEnd - $realFnStart);
assertTrue(strpos($realFnBody, 'trade_ts') !== false, 'SELF-CAUGHT BUG REGRESSION CHECK: the real, live query text genuinely references the real trade_ts column');
assertTrue(preg_match('/FROM \$journalTable WHERE user_id/', $realFnBody) === 1, 'SELF-CAUGHT BUG REGRESSION CHECK: the real, live trade-summary query genuinely filters by user_id, matching this app\'s own established per-user convention (fno_journal_list_fn)');
// Real, precise check scoped only to the two real queries against
// $journalTable specifically (wp_fno_journal's own real column is
// trade_ts) - deliberately does NOT check the separate, real
// $failureTable queries, which correctly, genuinely use their own,
// different real column (wp_fno_failure_events' own real schema uses
// plain 'ts', confirmed directly against that table's own real
// CREATE TABLE statement) - an earlier, broader version of this check
// incorrectly flagged that second, genuinely correct table's own real
// column as if it were the same bug, a real, self-caught false
// positive in this test itself, not in the real application code.
preg_match_all('/FROM \$journalTable[^;]*?;/s', $realFnBody, $journalQueries);
$anyBareTs = false;
foreach ($journalQueries[0] as $q) {
    if (preg_match('/[^_]\bts\b(?!\w)/', str_replace(['trade_ts'], '', $q))) $anyBareTs = true;
}
assertTrue(!$anyBareTs, 'SELF-CAUGHT BUG REGRESSION CHECK: no remaining, real, bare "ts" column reference exists in either real $journalTable query specifically');


// Scenario A: genuinely no real data at all yet - every section must
// honestly report "no data" rather than a fabricated number or a
// silently-missing section.
$GLOBALS['fno_test_trades'] = [];
$GLOBALS['fno_test_failures_by_category'] = [];
$GLOBALS['fno_test_recent_failures'] = [];
$GLOBALS['fno_test_snapshots'] = [];
$GLOBALS['fno_test_hypothesis_stats'] = null;
$GLOBALS['fno_test_hypothesis_table_exists'] = null;
$r = fno_generate_diagnostic_report_data(1);
assertTrue($r['tradeSummary']['totalTrades'] === 0, 'genuinely zero real trades -> honestly reports 0, not null or an error');
assertTrue($r['tradeSummary']['winRate'] === null, 'genuinely zero trades -> win rate is honestly null, never a fabricated 0% or 100%');
assertTrue(strpos($r['tradeSummary']['note'], 'No real trades recorded yet') !== false, 'an honest, explicit "no data yet" note is present, not a silently empty section');
assertTrue($r['factorReliability'] === [], 'genuinely no real snapshots -> honestly empty factor-reliability list');
assertTrue($r['hypothesisStats'] === null, 'hypothesis table genuinely does not exist in this real scenario -> honestly null');

// Scenario B: real, mixed trade outcomes - win rate must be computed
// correctly and honestly, nothing filtered by outcome.
$GLOBALS['fno_test_trades'] = [
    ['action' => 'AUTO_TARGET_EXIT', 'entry_price' => 100, 'exit_price' => 150, 'pnl' => 2500, 'gross_pnl' => 2500, 'costs_total' => 0, 'trade_ts' => time()],
    ['action' => 'AUTO_SL_EXIT', 'entry_price' => 100, 'exit_price' => 85, 'pnl' => -750, 'gross_pnl' => -750, 'costs_total' => 0, 'trade_ts' => time()],
    ['action' => 'AUTO_SL_EXIT', 'entry_price' => 100, 'exit_price' => 90, 'pnl' => -500, 'gross_pnl' => -500, 'costs_total' => 0, 'trade_ts' => time()],
    ['action' => 'AUTO_TARGET_EXIT', 'entry_price' => 100, 'exit_price' => 140, 'pnl' => 2000, 'gross_pnl' => 2000, 'costs_total' => 0, 'trade_ts' => time()],
];
$r2 = fno_generate_diagnostic_report_data(1);
assertTrue($r2['tradeSummary']['totalTrades'] === 4, 'all 4 real trades counted, none filtered by outcome');
assertTrue($r2['tradeSummary']['wins'] === 2 && $r2['tradeSummary']['losses'] === 2, 'real wins/losses correctly, honestly split 2/2');
assertTrue($r2['tradeSummary']['winRate'] === 50.0, 'real win rate correctly computed as exactly 50%');
assertTrue($r2['tradeSummary']['netPnl'] === 3250.0, 'real net P&L correctly summed across all 4 real trades: 2500-750-500+2000=3250');

// Scenario C: real factor-snapshot reliability scan - a factor
// present but genuinely unavailable most of the time must show a
// real, low, honest reliability %, not hidden or averaged away.
$GLOBALS['fno_test_snapshots'] = [
    ['factor_snapshot' => json_encode(['factors' => ['f1' => ['status' => 'COMPUTED'], 'f2' => ['status' => 'NOT_COMPUTED']]])],
    ['factor_snapshot' => json_encode(['factors' => ['f1' => ['status' => 'COMPUTED'], 'f2' => ['status' => 'NOT_COMPUTED']]])],
    ['factor_snapshot' => json_encode(['factors' => ['f1' => ['status' => 'COMPUTED'], 'f2' => ['status' => 'COMPUTED']]])],
];
$r3 = fno_generate_diagnostic_report_data(1);
$f1 = null; $f2 = null;
foreach ($r3['factorReliability'] as $f) { if ($f['factorId'] === 'f1') $f1 = $f; if ($f['factorId'] === 'f2') $f2 = $f; }
assertTrue($f1['reliabilityPct'] === 100.0, 'f1 was genuinely computed all 3 real times -> honest 100% reliability');
assertTrue($f2['reliabilityPct'] === 33.3, 'f2 was genuinely computed only 1 of 3 real times -> honest, low 33.3% reliability, not hidden or inflated');
assertTrue($r3['factorReliability'][0]['factorId'] === 'f2', 'the real, weakest factor (f2) is correctly sorted first - the most actionable ordering');

// Scenario D: malformed/genuinely un-parseable snapshot JSON must not crash the whole report.
$GLOBALS['fno_test_snapshots'] = [['factor_snapshot' => 'not real valid json{{{']];
$r4 = null;
try { $r4 = fno_generate_diagnostic_report_data(1); assertTrue(true, 'a real, genuinely malformed snapshot row does not crash the whole report'); }
catch (Exception $e) { assertTrue(false, 'a real, genuinely malformed snapshot row does not crash the whole report'); }
assertTrue($r4['factorReliability'] === [], 'a real, malformed snapshot correctly, honestly contributes nothing rather than corrupting the real result');

// Scenario E: hypothesis stats, when the real table genuinely exists and has real data.
$GLOBALS['fno_test_hypothesis_table_exists'] = 'wp_fno_participant_hypotheses';
$GLOBALS['fno_test_hypothesis_stats'] = ['total' => 10, 'confirmed' => 6, 'disconfirmed' => 3, 'pending' => 1];
$r5 = fno_generate_diagnostic_report_data(1);
assertTrue($r5['hypothesisStats']['confirmed'] === 6, 'real hypothesis stats are correctly, directly passed through when genuinely available');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
