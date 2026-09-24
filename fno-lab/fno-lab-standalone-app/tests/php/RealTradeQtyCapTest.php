<?php
/**
 * Real, deliberately isolated helper - run as its own, separate PHP
 * process (PHP constants cannot be redefined within one process) with
 * FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED forced true ONLY here, never
 * in the real, main test suite - the same isolation technique
 * RealMoneyTradingStaleVersionTest.php already uses, needed for the
 * same reason: fno_place_real_trade_fn()'s qty/strike validation runs
 * AFTER the armed check, which itself short-circuits on the real
 * master switch, so it is genuinely unreachable with the switch off.
 *
 * Covers a real gap FOUND during a fresh audit pass of the dormant
 * real-order dispatch path (see FNO_REAL_ORDER_MAX_QTY in fno-lab.php):
 * fno_place_real_trade_fn() previously had no upper bound on qty at
 * all (only qty<=0 was rejected), and accepted a negative strike
 * (the old `!$strike` check is falsy only for 0, not for a negative
 * float). Both are real, exploitable gaps on the one code path in this
 * app that places a genuine order with real money if the global switch
 * is ever deliberately flipped by a developer.
 *
 * Run with: php tests/php/RealTradeQtyCapTest.php
 */
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function get_current_user_id() { return 1; }
function wp_salt($scheme = 'auth') { return 'test-fixed-salt-do-not-use-in-production-' . $scheme; }
function is_user_logged_in() { return true; }
function fno_rate_limit($endpoint) { return true; }
function check_ajax_referer($a, $k, $d = true) { return true; }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED')) define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', true);

class TestWPDieExceptionQtyCap extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionQtyCap(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionQtyCap(['success' => false, 'data' => $data]); }
function is_wp_error($v) { return false; }
function wp_remote_post($url, $args) { $GLOBALS['fno_qtycap_broker_called'] = true; return ['body' => '{}']; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? '{}'; }
// Stubbed true, deliberately - real NSE-market-hours gating on the
// real broker dispatcher is already covered by
// BrokerOrderMarketHoursTest.php; this file's own subject is the
// qty-cap/negative-strike validation that runs BEFORE that dispatcher
// is ever reached, so market hours is neutralized here rather than
// re-tested.
function fno_is_real_market_hours_php() { return true; }
function wp_json_encode($v) { return json_encode($v); }

class FakeWpdbQtyCap {
    public $prefix = 'wp_';
    public $inserted = [];
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function prepare($q, ...$a) { return $q; }
    public function get_row($q, $o = null) { return $this->rows[0] ?? null; }
    public function insert($t, $r) { $this->inserted[] = $r; return true; }
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
preg_match("/define\('FNO_JOURNAL_SCHEMA_VERSION', '([^']+)'\)/", $pluginSource, $m);
define('FNO_JOURNAL_SCHEMA_VERSION', $m[1]);
preg_match("/define\('FNO_REAL_ORDER_MAX_QTY', (\d+)\)/", $pluginSource, $mCap);
if (!$mCap) { echo "  FAIL  FNO_REAL_ORDER_MAX_QTY constant not found in fno-lab.php\n"; exit(1); }
define('FNO_REAL_ORDER_MAX_QTY', (int) $mCap[1]);

foreach (['fno_verify_app_nonce', 'fno_encrypt_secret', 'fno_decrypt_secret', 'fno_valid_symbols', 'fno_validate_symbol',
          'fno_get_broker_catalog', 'fno_broker_place_order',
          'fno_broker_place_order_zerodha', 'fno_is_real_money_armed', 'fno_place_real_trade_fn'] as $fn) {
    $start = strpos($pluginSource, "function $fn(");
    if ($start === false) { echo "  FAIL  could not locate function $fn() in fno-lab.php\n"; exit(1); }
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}

$passed = 0; $failed = 0;
function assertTrueQC($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_place_real_trade_fn - qty cap / negative-strike gap (isolated, master switch true) ===\n";

$armedAccount = ['id' => 1, 'user_id' => 1, 'is_armed' => 1, 'armed_plugin_version' => FNO_JOURNAL_SCHEMA_VERSION,
    'broker' => 'zerodha', 'api_key_encrypted' => fno_encrypt_secret('k'), 'access_token_encrypted' => fno_encrypt_secret('t')];

// A qty far beyond the real fat-finger cap is rejected before ever reaching the broker dispatcher.
global $wpdb;
$wpdb = new FakeWpdbQtyCap([$armedAccount]);
$GLOBALS['fno_qtycap_broker_called'] = false;
$_POST = ['account_id' => '1', 'symbol' => 'NIFTY', 'strike' => '23200', 'option_type' => 'CE', 'qty' => (string) (FNO_REAL_ORDER_MAX_QTY + 1)];
$response = null;
try { fno_place_real_trade_fn(); } catch (TestWPDieExceptionQtyCap $e) { $response = $e->responseData; }
assertTrueQC($response['success'] === false, 'a real trade qty above FNO_REAL_ORDER_MAX_QTY is honestly rejected');
assertTrueQC(strpos($response['data']['message'], 'safety cap') !== false, 'the real rejection reason names the fat-finger safety cap specifically');
assertTrueQC($GLOBALS['fno_qtycap_broker_called'] === false, 'the real broker dispatcher (wp_remote_post) is genuinely never reached for an over-cap qty');
assertTrueQC(count($wpdb->inserted) === 0, 'no real journal row is written for a request rejected before dispatch');

// A qty exactly at the cap is allowed PAST the qty-cap check itself (boundary check) -
// deliberately not asserting wp_remote_post was reached, since
// fno_broker_place_order_zerodha() also gates on real NSE market
// hours (a real, separate, already-tested safeguard), which may
// genuinely be closed at whatever real wall-clock time this test runs.
$wpdb = new FakeWpdbQtyCap([$armedAccount]);
$GLOBALS['fno_qtycap_broker_called'] = false;
$_POST = ['account_id' => '1', 'symbol' => 'NIFTY', 'strike' => '23200', 'option_type' => 'CE', 'qty' => (string) FNO_REAL_ORDER_MAX_QTY];
$response = null;
try { fno_place_real_trade_fn(); } catch (TestWPDieExceptionQtyCap $e) { $response = $e->responseData; }
assertTrueQC(strpos($response['data']['message'] ?? '', 'safety cap') === false, 'a real trade qty exactly at the cap is NOT rejected by the qty-cap check itself (not off-by-one over-rejected)');

// A negative strike is honestly rejected (previously accepted: the old `!$strike` check is falsy only for 0.0, not a negative float).
$wpdb = new FakeWpdbQtyCap([$armedAccount]);
$GLOBALS['fno_qtycap_broker_called'] = false;
$_POST = ['account_id' => '1', 'symbol' => 'NIFTY', 'strike' => '-23200', 'option_type' => 'CE', 'qty' => '50'];
$response = null;
try { fno_place_real_trade_fn(); } catch (TestWPDieExceptionQtyCap $e) { $response = $e->responseData; }
assertTrueQC($response['success'] === false, 'a real trade request with a negative strike is honestly rejected');
assertTrueQC($GLOBALS['fno_qtycap_broker_called'] === false, 'the real broker dispatcher is genuinely never reached for a negative strike');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
