<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for this session's
 * server-side symbol/optionType/status/confidence enum-validation
 * sweep of fno-lab.php.
 *
 * TRACE: the prior pass fixed client-side XSS escaping for the
 * symbol field everywhere, but left the server-side gap open: every
 * AJAX handler that accepts a real symbol field validated it with
 * sanitize_text_field() alone - ANY string, not just this app's real,
 * actual three valid symbols (NIFTY/BANKNIFTY/FINNIFTY, the exact set
 * in this app's own real <select id="sym">,
 * assets/standalone-app.php:129) would have been accepted by a direct,
 * off-UI AJAX call. This session added fno_valid_symbols()/
 * fno_validate_symbol() (fno-lab.php) as one real, shared enum gate,
 * and applied it at every real call site found in the sweep, along
 * with two adjacent enum gaps found on the way: fno_add_knowledge_entry_fn's
 * status field (Observing|Testing|Confirmed|Rejected - already
 * documented in that field's own trailing comment) and
 * fno_log_hypothesis_fn's confidence field (low|medium|high - the
 * real, only three values computeParticipantPayoffHypothesis()
 * (fno-lab-core.js) ever produces).
 *
 * This file proves, per real call site, three things: (1) a real,
 * valid value passes through unchanged (no regression to the existing
 * valid-flow behavior); (2) a garbage/invalid value is rejected or
 * safely defaulted, matching whichever pattern that specific endpoint
 * already used for its other required/optional fields; (3) the
 * shared fno_validate_symbol() gate itself behaves correctly in
 * isolation (case-insensitivity, non-string input, invalid input).
 *
 * Run with: php tests/php/SymbolOptionTypeStatusEnumValidationTest.php
 */

class FakeWpdbForEnumSweep {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $lastInsertedRow = null;
    public $lastReplacedRow = null;
    private $rows = [];
    private $nextId = 1;
    public function insert($table, $data) {
        $data['id'] = $this->nextId;
        $this->rows[$this->nextId] = $data;
        $this->lastInsertedRow = $data;
        $this->insert_id = $this->nextId;
        $this->nextId++;
        return true;
    }
    public function replace($table, $data) {
        $this->lastReplacedRow = $data;
        return true;
    }
    public function get_results($query, $output = null) { return []; }
    public function get_row($query, $output = null) { return null; }
    public function prepare($query, ...$args) {
        foreach ($args as $a) { $query = preg_replace('/%[dsf]/', is_numeric($a) ? $a : "'$a'", $query, 1); }
        return $query;
    }
}
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
$GLOBALS['wpdb'] = new FakeWpdbForEnumSweep();
$GLOBALS['fno_test_is_logged_in'] = true;
$GLOBALS['fno_test_options'] = [];

function is_user_logged_in() { return $GLOBALS['fno_test_is_logged_in']; }
function get_current_user_id() { return 7; }
function get_userdata($id) { return null; }
function wp_set_current_user($id) { }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function wp_json_encode($v) { return json_encode($v); }
function get_option($k, $d = false) { return $GLOBALS['fno_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['fno_test_options'][$k] = $v; return true; }
function check_ajax_referer($action, $key, $die = true) { return true; }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function fno_get_headless_driver_secret() { return 'unused-in-this-test'; }
function fno_get_daemon_secret() { return 'real-test-daemon-secret'; }
function current_time($type, $gmt = 0) { return $type === 'timestamp' ? time() : date('Y-m-d H:i:s'); }
function fno_cap_text($str, $maxLen = 4000) {
    $str = (string) $str;
    if ($maxLen <= 0) return '';
    if (mb_strlen($str) <= $maxLen) return $str;
    return mb_substr($str, 0, $maxLen);
}
class TestWPDieExceptionEnumSweep extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionEnumSweep(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionEnumSweep(['success' => false, 'data' => $data]); }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach ([
    'fno_verify_app_nonce', 'fno_verify_app_access', 'fno_valid_symbols', 'fno_validate_symbol',
    'fno_open_position_fn', 'fno_journal_add_fn', 'fno_add_knowledge_entry_fn',
    'fno_log_hypothesis_fn', 'fno_evaluate_hypotheses_fn', 'fno_ingest_microstructure_fn',
] as $fn) {
    $start = strpos($pluginSource, "function $fn(");
    if ($start === false) { fwrite(STDERR, "Could not find function $fn in fno-lab.php\n"); exit(1); }
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function callFn($fn, $params, $server = []) {
    $_POST = $params; $_GET = $params;
    $prevServer = $_SERVER;
    foreach ($server as $k => $v) { $_SERVER[$k] = $v; }
    try {
        $fn();
        $result = null;
    } catch (TestWPDieExceptionEnumSweep $e) {
        $result = $e->responseData;
    } finally {
        $_SERVER = $prevServer;
    }
    return $result;
}

// ====================================================================
echo "=== fno_valid_symbols() / fno_validate_symbol() - the shared enum gate itself ===\n";
assertTrue(fno_valid_symbols() === ['NIFTY', 'BANKNIFTY', 'FINNIFTY'], 'valid symbol set is exactly the real <select id="sym"> set (NIFTY/BANKNIFTY/FINNIFTY)');
assertTrue(fno_validate_symbol('NIFTY') === 'NIFTY', 'exact-case valid symbol accepted');
assertTrue(fno_validate_symbol('banknifty') === 'BANKNIFTY', 'lowercase valid symbol accepted and uppercased');
assertTrue(fno_validate_symbol('FinNifty') === 'FINNIFTY', 'mixed-case valid symbol accepted and uppercased');
assertTrue(fno_validate_symbol('RELIANCE') === null, 'a real, unsupported NSE stock symbol is rejected (not one of this app\'s 3 real traded symbols)');
assertTrue(fno_validate_symbol('<script>alert(1)</script>') === null, 'garbage/XSS-shaped input rejected, not silently accepted as a symbol');
assertTrue(fno_validate_symbol('') === null, 'empty string rejected (not itself a valid symbol)');
assertTrue(fno_validate_symbol(null) === null, 'null input rejected without a PHP warning/fatal');
assertTrue(fno_validate_symbol(['array', 'injection']) === null, 'non-scalar input rejected without a PHP warning/fatal');

// ====================================================================
echo "\n=== fno_open_position_fn - real position write, REJECT pattern (matches optionType CE/PE convention) ===\n";
$validOpen = callFn('fno_open_position_fn', [
    'symbol' => 'NIFTY', 'strike' => 24000, 'optionType' => 'CE', 'qty' => 50,
    'entryPrice' => 120, 'sl' => 100, 'target' => 160,
]);
assertTrue($validOpen['success'] === true, 'valid symbol (NIFTY) - real position opened, existing valid flow unchanged');
assertTrue($GLOBALS['wpdb']->lastInsertedRow['symbol'] === 'NIFTY', 'valid symbol persisted exactly as submitted (uppercased)');

$garbageOpen = callFn('fno_open_position_fn', [
    'symbol' => 'HACKCOIN', 'strike' => 24000, 'optionType' => 'CE', 'qty' => 50,
    'entryPrice' => 120, 'sl' => 100, 'target' => 160,
]);
assertTrue($garbageOpen['success'] === false, 'invalid/garbage symbol - real position open request genuinely rejected, not silently opened');

// ====================================================================
echo "\n=== fno_journal_add_fn - real journal write, DEFAULT-TO-NIFTY pattern (matches this endpoint's own fallback-on-absence default) ===\n";
$validJournal = callFn('fno_journal_add_fn', ['symbol' => 'BANKNIFTY', 'pnl' => 500]);
assertTrue($validJournal['success'] === true, 'valid symbol (BANKNIFTY) - real journal row inserted, existing valid flow unchanged');
assertTrue($GLOBALS['wpdb']->lastInsertedRow['symbol'] === 'BANKNIFTY', 'valid symbol persisted exactly as submitted');

$garbageJournal = callFn('fno_journal_add_fn', ['symbol' => "'; DROP TABLE wp_fno_journal; --", 'pnl' => 500]);
assertTrue($garbageJournal['success'] === true, 'invalid/garbage symbol on an optional-with-default field - request still succeeds (matches this endpoint\'s own non-strict fallback-on-absence design), not silently rejected');
assertTrue($GLOBALS['wpdb']->lastInsertedRow['symbol'] === 'NIFTY', 'invalid/garbage symbol safely defaulted to NIFTY, never persisted verbatim');

$absentJournal = callFn('fno_journal_add_fn', ['pnl' => 500]);
assertTrue($GLOBALS['wpdb']->lastInsertedRow['symbol'] === 'NIFTY', 'absent symbol still defaults to NIFTY exactly as before this fix (no regression to the pre-existing default-on-absence behavior)');

// ====================================================================
echo "\n=== fno_add_knowledge_entry_fn - status enum (Observing|Testing|Confirmed|Rejected), DEFAULT pattern ===\n";
$validStatus = callFn('fno_add_knowledge_entry_fn', ['factor' => 'IV Rank', 'observation' => 'Real observation text', 'status' => 'Confirmed']);
assertTrue($validStatus['success'] === true, 'valid status (Confirmed) - knowledge entry added, existing valid flow unchanged');
assertTrue($validStatus['data']['entries'][0]['status'] === 'Confirmed', 'valid status persisted exactly as submitted');

$garbageStatus = callFn('fno_add_knowledge_entry_fn', ['factor' => 'IV Rank 2', 'observation' => 'Another real observation', 'status' => 'HACKED']);
assertTrue($garbageStatus['success'] === true, 'invalid/garbage status - request still succeeds (matches this endpoint\'s own fallback-on-absence design)');
assertTrue($garbageStatus['data']['entries'][1]['status'] === 'Observing', 'invalid/garbage status safely defaulted to Observing, never persisted verbatim');

// ====================================================================
echo "\n=== fno_log_hypothesis_fn - symbol DEFAULT + confidence enum (low|medium|high) DEFAULT ===\n";
$validHyp = callFn('fno_log_hypothesis_fn', [
    'direction' => 'bullish', 'symbol' => 'FINNIFTY', 'confidence' => 'high',
    'spotAtGeneration' => 23500, 'hypothesis' => 'Real test hypothesis', 'falsifiablePrediction' => 'Real falsifiable prediction',
]);
assertTrue($validHyp['success'] === true, 'valid symbol+confidence - hypothesis logged, existing valid flow unchanged');
assertTrue($GLOBALS['wpdb']->lastInsertedRow['symbol'] === 'FINNIFTY', 'valid symbol persisted exactly as submitted');
assertTrue($GLOBALS['wpdb']->lastInsertedRow['confidence'] === 'high', 'valid confidence persisted exactly as submitted');

$garbageHyp = callFn('fno_log_hypothesis_fn', [
    'direction' => 'bearish', 'symbol' => 'NOTASYMBOL', 'confidence' => 'ultra-mega-confident',
    'spotAtGeneration' => 23500, 'hypothesis' => 'Real test hypothesis 2', 'falsifiablePrediction' => 'Real falsifiable prediction 2',
]);
assertTrue($garbageHyp['success'] === true, 'invalid symbol+confidence on default-pattern fields - request still succeeds');
assertTrue($GLOBALS['wpdb']->lastInsertedRow['symbol'] === 'NIFTY', 'invalid symbol safely defaulted to NIFTY');
assertTrue($GLOBALS['wpdb']->lastInsertedRow['confidence'] === 'low', 'invalid confidence safely defaulted to low');

// ====================================================================
echo "\n=== fno_evaluate_hypotheses_fn - required symbol, REJECT pattern ===\n";
$garbageEval = callFn('fno_evaluate_hypotheses_fn', ['currentSpot' => 23600, 'symbol' => 'GARBAGE']);
assertTrue($garbageEval['success'] === false, 'invalid/garbage required symbol - request genuinely rejected, never silently evaluates against the wrong/undefined symbol');
$validEval = callFn('fno_evaluate_hypotheses_fn', ['currentSpot' => 23600, 'symbol' => 'niFty']);
assertTrue($validEval['success'] === true, 'valid (mixed-case) symbol - request succeeds, existing valid flow unchanged');

// ====================================================================
echo "\n=== fno_ingest_microstructure_fn - daemon-secret endpoint, required symbol, REJECT pattern ===\n";
$validIngest = callFn('fno_ingest_microstructure_fn', ['symbol' => 'BANKNIFTY', 'poc' => 51000], ['HTTP_X_FNO_DAEMON_SECRET' => 'real-test-daemon-secret']);
assertTrue($validIngest['success'] === true, 'valid symbol with correct daemon secret - real microstructure row ingested, existing valid flow unchanged');
$garbageIngest = callFn('fno_ingest_microstructure_fn', ['symbol' => 'GOLDFUTURES', 'poc' => 51000], ['HTTP_X_FNO_DAEMON_SECRET' => 'real-test-daemon-secret']);
assertTrue($garbageIngest['success'] === false, 'invalid/garbage symbol, even with a valid daemon secret - genuinely rejected (secret authenticates WHO, not that the payload symbol is real)');

// ====================================================================
echo "\n\nTOTAL: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
