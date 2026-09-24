<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the real, new
 * per-OPTION-STRIKE microstructure ingest/read endpoints
 * (fno_ingest_microstructure_instrument_fn / fno_get_microstructure_instruments_fn)
 * - built at the user's own direct, explicit request to close the last
 * remaining Zerodha-maximization backlog item (extending the companion
 * daemon's microstructure computation to real option strikes, not just
 * the underlying index).
 *
 * Deliberately a NEW, separate table/endpoint pair, not a widened
 * contract on the existing underlying-only fno_ingest_microstructure_fn/
 * fno_get_microstructure_fn (see fno_create_journal_table()'s own TRACE
 * for wp_fno_microstructure_instruments for why) - this test proves the
 * new pair works correctly and independently, on its own real table.
 *
 * Run with: php tests/php/MicrostructureInstrumentsTest.php
 */

class FakeWpdbMicroInstr {
    public $prefix = 'wp_';
    public $last_error = '';
    public $replaced = [];
    public $rows = []; // instrument_key => real, stored row (simulates the real table's persisted state across get_results calls)
    public function replace($table, $data) {
        $this->replaced[] = $data;
        $this->rows[$data['instrument_key']] = $data;
        return true;
    }
    public function prepare($query, ...$args) { return [$query, $args]; } // real args captured, not interpolated - get_results below reads $args directly rather than re-parsing SQL
    public function get_results($prepared, $output = ARRAY_A) {
        $symbol = $prepared[1][0] ?? null;
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['symbol'] === $symbol) $out[] = $row;
        }
        return $out;
    }
}

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
function get_current_user_id() { return 1; }
function is_user_logged_in() { return true; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function fno_cap_text($v) { return $v; }
function fno_get_daemon_secret() { return 'real-test-daemon-secret'; }
function fno_verify_public_or_driver_access() { return true; }
$GLOBALS['fno_test_now_ts'] = strtotime('2026-09-02 10:00:00');
function current_time($type) { return $type === 'timestamp' ? $GLOBALS['fno_test_now_ts'] : date('Y-m-d H:i:s', $GLOBALS['fno_test_now_ts']); }
class TestWPDieExceptionMicroInstr extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionMicroInstr(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionMicroInstr(['success' => false, 'data' => $data]); }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach (['fno_valid_symbols', 'fno_validate_symbol'] as $fnoHelperFn) {
    $hStart = strpos($pluginSource, "function $fnoHelperFn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}
foreach (['fno_ingest_microstructure_instrument_fn', 'fno_get_microstructure_instruments_fn'] as $fn) {
    $start = strpos($pluginSource, "function $fn(");
    if ($start === false) { echo "FATAL: $fn not found in fno-lab.php\n"; exit(1); }
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function callIngest($params, $secret = 'real-test-daemon-secret') {
    $_POST = $params;
    $_SERVER['HTTP_X_FNO_DAEMON_SECRET'] = $secret;
    try { fno_ingest_microstructure_instrument_fn(); return null; }
    catch (TestWPDieExceptionMicroInstr $e) { return $e->responseData; }
}
function callRead($symbol) {
    $_GET = ['symbol' => $symbol];
    try { fno_get_microstructure_instruments_fn(); return null; }
    catch (TestWPDieExceptionMicroInstr $e) { return $e->responseData; }
}

echo "=== fno_ingest_microstructure_instrument_fn (real per-strike write) ===\n";

global $wpdb;
$wpdb = new FakeWpdbMicroInstr();

$rBadSecret = callIngest(['instrumentKey' => 'NIFTY24AUG23200CE', 'symbol' => 'NIFTY'], 'wrong-secret');
assertTrue($rBadSecret['success'] === false && count($wpdb->replaced) === 0, 'a genuinely wrong daemon secret is rejected, no row written');

$rNoKey = callIngest(['symbol' => 'NIFTY']);
assertTrue($rNoKey['success'] === false, 'a genuinely missing instrumentKey is rejected');

$rTooLong = callIngest(['instrumentKey' => str_repeat('X', 41), 'symbol' => 'NIFTY']);
assertTrue($rTooLong['success'] === false, 'an instrumentKey longer than the real VARCHAR(40) column width is rejected loudly, never silently truncated');

$rBadSymbol = callIngest(['instrumentKey' => 'NIFTY24AUG23200CE', 'symbol' => 'NOTASYMBOL']);
assertTrue($rBadSymbol['success'] === false, 'a genuinely invalid symbol is rejected (same enum validation as every other endpoint)');

$rGarbagePoc = callIngest(['instrumentKey' => 'NIFTY24AUG23200CE', 'symbol' => 'NIFTY', 'poc' => 'garbage']);
assertTrue($rGarbagePoc['success'] === false && count($wpdb->replaced) === 0, 'a genuinely non-numeric poc is rejected, never silently stored as a fabricated 0.0');

$rGood = callIngest([
    'instrumentKey' => 'NIFTY24AUG23200CE', 'symbol' => 'NIFTY',
    'cumulativeDelta' => '1250', 'poc' => '23200.5', 'flowImbalancePct' => '54.2',
    'ticksPerMinute' => '38', 'icebergDetected' => '0', 'domSpoofDetected' => '1',
    'footprintTopLevels' => json_encode([['price' => 23200, 'vol' => 500]]),
]);
assertTrue($rGood['success'] === true, 'a real, well-formed strike snapshot is accepted');
assertTrue(count($wpdb->replaced) === 1, 'exactly one real row upserted');
assertTrue($wpdb->replaced[0]['instrument_key'] === 'NIFTY24AUG23200CE', 'the real instrument_key is stored correctly');
assertTrue($wpdb->replaced[0]['symbol'] === 'NIFTY', 'the real symbol is stored correctly');
assertTrue($wpdb->replaced[0]['poc'] === 23200.5, 'the real poc is stored correctly');
assertTrue($wpdb->replaced[0]['cumulative_delta'] === 1250, 'the real cumulative_delta is stored correctly');

// A second, DIFFERENT real strike for the same underlying - proves this
// is genuinely per-instrument, not overwriting the same row (the exact
// limitation this whole feature exists to fix).
$rSecondStrike = callIngest(['instrumentKey' => 'NIFTY24AUG23200PE', 'symbol' => 'NIFTY', 'poc' => '23150.0']);
assertTrue($rSecondStrike['success'] === true, 'a second, real, different strike for the same underlying is accepted independently');
assertTrue(count($wpdb->rows) === 2, 'both real strikes now have their own, independent, real stored row - not overwriting each other');

echo "\n=== fno_get_microstructure_instruments_fn (real per-strike read, with staleness discipline) ===\n";

$rRead = callRead('NIFTY');
assertTrue($rRead['success'] === true && count($rRead['data']) === 2, 'both real, fresh NIFTY strikes are returned');
$ceRow = null; foreach ($rRead['data'] as $row) { if ($row['instrumentKey'] === 'NIFTY24AUG23200CE') $ceRow = $row; }
assertTrue($ceRow !== null && $ceRow['poc'] === 23200.5, 'the real CE strike\'s real poc is correctly returned');
assertTrue($ceRow['cumulativeDelta'] === 1250, 'the real CE strike\'s real cumulativeDelta is correctly returned');
assertTrue($ceRow['flowImbalancePct'] === 54.2, 'the real CE strike\'s real flowImbalancePct is correctly returned');
assertTrue(is_array($ceRow['footprintTopLevels']) && $ceRow['footprintTopLevels'][0]['price'] === 23200, 'the real, JSON-decoded footprintTopLevels is correctly returned, not left as a raw string');

$rReadOtherSymbol = callRead('BANKNIFTY');
assertTrue($rReadOtherSymbol['success'] === true && count($rReadOtherSymbol['data']) === 0, 'a real, different underlying with no tracked strikes correctly returns an empty array, not the NIFTY rows');

// Real staleness discipline: advance the fake clock 45s past one
// strike's last update - it must be honestly omitted, never served as
// live, exactly matching the existing underlying-only endpoint's own
// 30-second discipline.
$GLOBALS['fno_test_now_ts'] += 45;
$rReadStale = callRead('NIFTY');
assertTrue(count($rReadStale['data']) === 0, 'both real strikes have now genuinely gone stale (45s > the real 30s threshold) - honestly omitted, not served as live');

// One strike refreshed (still live), one genuinely stale - proves this
// is a real PER-ROW staleness check, not an all-or-nothing gate.
callIngest(['instrumentKey' => 'NIFTY24AUG23200CE', 'symbol' => 'NIFTY', 'poc' => '23210.0']); // real, fresh re-upsert at the current (advanced) fake time
$rReadMixed = callRead('NIFTY');
assertTrue(count($rReadMixed['data']) === 1 && $rReadMixed['data'][0]['instrumentKey'] === 'NIFTY24AUG23200CE', 'only the genuinely fresh strike is returned - the other, genuinely stale strike is correctly, independently omitted (real per-row staleness, not a blanket gate)');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
