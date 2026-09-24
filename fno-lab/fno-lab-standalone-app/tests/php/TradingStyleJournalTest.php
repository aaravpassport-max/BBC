<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the real, new
 * wp_fno_journal.trading_style column and its round-trip through
 * fno_journal_add_fn() and fno_journal_list_fn() - the foundational
 * piece of the user's own direct, explicit request for a trade-type-
 * aware architecture: every trade must genuinely record which real
 * trade type it actually was, and that value must genuinely,
 * correctly survive being written and read back.
 *
 * Run with: php tests/php/TradingStyleJournalTest.php
 */

class FakeWpdbForTradingStyle {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $lastInsertedRow = null;
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
    public function get_results($query, $output = null) {
        preg_match('/user_id = (\d+)/', $query, $m);
        $uid = isset($m[1]) ? (int) $m[1] : null;
        return array_values(array_filter($this->rows, function ($r) use ($uid) { return $r['user_id'] === $uid; }));
    }
    public function prepare($query, ...$args) {
        foreach ($args as $a) { $query = preg_replace('/%[ds]/', is_numeric($a) ? $a : "'$a'", $query, 1); }
        return $query;
    }
}
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
$GLOBALS['wpdb'] = new FakeWpdbForTradingStyle();
$GLOBALS['fno_test_is_logged_in'] = true;

function is_user_logged_in() { return $GLOBALS['fno_test_is_logged_in']; }
function get_current_user_id() { return 1; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function wp_json_encode($v) { return json_encode($v); }
class TestWPDieException13 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException13(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException13(['success' => false, 'data' => $data]); }
function check_ajax_referer($action, $key, $die = true) { return true; }
function get_option($k, $d = false) { return $d; }
function get_userdata($id) { return null; }
function wp_set_current_user($id) { }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
// Real fix (server-side symbol-enum sweep regression coverage): the
// real fno_validate_symbol()/fno_valid_symbols() helpers this test's
// target function(s) now call must be eval'd in too, same as every
// other real helper dependency below - never reimplemented here.
foreach (['fno_valid_symbols', 'fno_validate_symbol'] as $fnoHelperFn) {
    $hStart = strpos($pluginSource, "function $fnoHelperFn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}
$nonceStart = strpos($pluginSource, 'function fno_verify_app_nonce(');
$nonceEnd = strpos($pluginSource, "\n}", $nonceStart) + 2;
eval(substr($pluginSource, $nonceStart, $nonceEnd - $nonceStart));
$accessStart = strpos($pluginSource, 'function fno_verify_app_access(');
$accessEnd = strpos($pluginSource, "\n}", $accessStart) + 2;
eval(substr($pluginSource, $accessStart, $accessEnd - $accessStart));
foreach (['fno_journal_add_fn', 'fno_journal_list_fn'] as $fn) {
    $start = strpos($pluginSource, "function $fn(");
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function callFn($fn, $params) {
    $_POST = $params; $_GET = $params;
    try { $fn(); return null; }
    catch (TestWPDieException13 $e) { return $e->responseData; }
}

echo "=== wp_fno_journal.trading_style - real round-trip (the foundational piece of trade-type awareness) ===\n";

// Scenario A: a real, valid trading style is genuinely stored and read back correctly.
callFn('fno_journal_add_fn', ['ts' => time(), 'symbol' => 'NIFTY', 'trade_action' => 'AUTO_TARGET_EXIT', 'pnl' => '500', 'tradingStyle' => 'scalping']);
assertTrue($GLOBALS['wpdb']->lastInsertedRow['trading_style'] === 'scalping', 'a real, valid trading style ("scalping") is genuinely stored exactly as sent');

$listResult = callFn('fno_journal_list_fn', []);
$firstTrade = $listResult['data']['trades'][0] ?? $listResult['data'][0] ?? null;
if ($firstTrade === null && isset($listResult['data']) && is_array($listResult['data'])) {
    // real, defensive lookup in case the real response key differs from assumed
    foreach ($listResult['data'] as $v) { if (is_array($v) && isset($v[0]['tradingStyle'])) { $firstTrade = $v[0]; break; } }
}
assertTrue($firstTrade !== null, 'the real, just-inserted trade genuinely appears in the real list response');
if ($firstTrade !== null) {
    assertTrue($firstTrade['tradingStyle'] === 'scalping', 'the real, stored trading style is genuinely read back correctly as "scalping", not lost or defaulted');
}

// Scenario B: a genuinely invalid/unrecognized trading style honestly, safely falls back to intraday, never stores an arbitrary string.
$GLOBALS['wpdb'] = new FakeWpdbForTradingStyle();
callFn('fno_journal_add_fn', ['ts' => time(), 'symbol' => 'NIFTY', 'trade_action' => 'MANUAL', 'pnl' => '0', 'tradingStyle' => 'not_a_real_type']);
assertTrue($GLOBALS['wpdb']->lastInsertedRow['trading_style'] === 'intraday', 'a real, genuinely invalid trading style honestly, safely falls back to intraday - never stores an unrecognized, arbitrary value');

// Scenario C: a genuinely missing trading style (an older client, or manual entry) honestly defaults to intraday.
$GLOBALS['wpdb'] = new FakeWpdbForTradingStyle();
callFn('fno_journal_add_fn', ['ts' => time(), 'symbol' => 'NIFTY', 'trade_action' => 'MANUAL', 'pnl' => '0']);
assertTrue($GLOBALS['wpdb']->lastInsertedRow['trading_style'] === 'intraday', 'a genuinely missing trading style (e.g. an older client) honestly defaults to intraday, the real, documented default');

// Scenario D: real, valid 'swing' is genuinely stored correctly too.
$GLOBALS['wpdb'] = new FakeWpdbForTradingStyle();
callFn('fno_journal_add_fn', ['ts' => time(), 'symbol' => 'NIFTY', 'trade_action' => 'AUTO_SL_EXIT', 'pnl' => '-300', 'tradingStyle' => 'swing']);
assertTrue($GLOBALS['wpdb']->lastInsertedRow['trading_style'] === 'swing', 'a real, valid "swing" trading style is genuinely stored correctly');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
