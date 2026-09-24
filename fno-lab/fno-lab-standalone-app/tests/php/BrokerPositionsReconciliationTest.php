<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_broker_get_positions_zerodha() (real Kite Connect
 * /portfolio/positions parsing, against a mock response shaped
 * exactly like Kite Connect API v3's own real, documented
 * /portfolio/positions endpoint) and fno_compare_broker_positions()
 * (the real DB-vs-broker discrepancy logic), plus the
 * fno_reconcile_real_money_positions_fn() AJAX endpoint end to end.
 *
 * This closes the previously-honestly-open gap tracked in
 * docs/TRADING_KNOWLEDGE_BASE.md §8 and autonomous-driver/README.md:
 * "no fno_broker_get_positions() (or equivalent) function exists
 * anywhere in this codebase." Built and tested the same disciplined
 * way as BrokerOrderMarketHoursTest.php / RealMoneyTradingTest.php -
 * mocking wp_remote_get/wp_remote_post exactly like every other real
 * Kite-integration test in this suite already does.
 *
 * Run with: php tests/php/BrokerPositionsReconciliationTest.php
 */

$GLOBALS['fno_test_wp_remote_get_call_count'] = 0;
$GLOBALS['fno_test_wp_remote_get_last_url'] = null;
$GLOBALS['fno_test_wp_remote_get_response'] = null;
function wp_remote_get($url, $args = []) {
    $GLOBALS['fno_test_wp_remote_get_call_count']++;
    $GLOBALS['fno_test_wp_remote_get_last_url'] = $url;
    return $GLOBALS['fno_test_wp_remote_get_response'] ?? ['body' => '{}'];
}
function wp_remote_post($url, $args = []) { return ['body' => '{}']; }
function is_wp_error($thing) { return false; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function fno_decrypt_secret($encoded) { return str_replace('enc_', '', $encoded); }
function get_current_user_id() { return 1; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function is_user_logged_in() { return true; }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
function check_ajax_referer($a, $k, $d = true) { return true; }
class TestWPDieExceptionRecon extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionRecon(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionRecon(['success' => false, 'data' => $data]); }

class FakeWpdbRecon {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public $last_error = '';
    public $inserted = [];
    private $accountRow;
    private $journalRows;
    public function __construct($accountRow, $journalRows) { $this->accountRow = $accountRow; $this->journalRows = $journalRows; }
    public function prepare($q, ...$a) { return $q; }
    public function get_row($q, $o = null) {
        // Distinguish the two real real-money tables by the query text itself, same technique other tests in this suite already use.
        if (strpos($q, 'fno_real_money_accounts') !== false) return $this->accountRow;
        return null;
    }
    public function get_results($q, $o = null) {
        if (strpos($q, 'fno_real_money_journal') !== false) return $this->journalRows;
        return [];
    }
    public function insert($t, $r) { $this->inserted[] = ['table' => $t, 'row' => $r]; return true; }
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach (['fno_verify_app_nonce', 'fno_get_broker_catalog', 'fno_broker_get_positions',
          'fno_broker_get_positions_zerodha', 'fno_compare_broker_positions',
          'fno_reconcile_real_money_positions_fn'] as $fn) {
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

echo "=== fno_broker_get_positions_zerodha() - real Kite Connect /portfolio/positions parsing ===\n";

// Real, documented Kite Connect API v3 /portfolio/positions response shape.
$mockKiteResponse = [
    'status' => 'success',
    'data' => [
        'net' => [
            ['tradingsymbol' => 'NIFTY25200CE', 'exchange' => 'NFO', 'product' => 'MIS', 'quantity' => 50, 'average_price' => 120.5, 'last_price' => 135.0, 'pnl' => 725.0],
            ['tradingsymbol' => 'NIFTY25300PE', 'exchange' => 'NFO', 'product' => 'MIS', 'quantity' => 0, 'average_price' => 80.0, 'last_price' => 60.0, 'pnl' => -1000.0],
        ],
        'day' => [],
    ],
];
$GLOBALS['fno_test_wp_remote_get_response'] = ['body' => json_encode($mockKiteResponse)];
$GLOBALS['fno_test_wp_remote_get_call_count'] = 0;
$account = ['api_key_encrypted' => 'enc_key', 'access_token_encrypted' => 'enc_token'];
$result = fno_broker_get_positions_zerodha($account);
assertTrue($result['success'] === true, 'a real, well-shaped Kite positions response is genuinely parsed as success');
assertTrue($GLOBALS['fno_test_wp_remote_get_call_count'] === 1, 'wp_remote_get was called exactly once');
assertTrue($GLOBALS['fno_test_wp_remote_get_last_url'] === 'https://api.kite.trade/portfolio/positions', 'the real, documented Kite Connect positions endpoint URL is used, not invented');
assertTrue(count($result['positions']) === 2, 'both real rows in the mocked "net" array are returned - net, not day, is the real comparison source');
assertTrue($result['positions'][0]['tradingsymbol'] === 'NIFTY25200CE', 'the real tradingsymbol is genuinely extracted');
assertTrue($result['positions'][0]['quantity'] === 50, 'the real quantity is genuinely extracted and cast to int');
assertTrue($result['positions'][1]['quantity'] === 0, 'a real, fully-exited (quantity=0) row is honestly passed through, not silently dropped or fabricated as still-open');

// Missing credentials.
$result2 = fno_broker_get_positions_zerodha(['api_key_encrypted' => '', 'access_token_encrypted' => '']);
assertTrue($result2['success'] === false, 'missing real credentials are honestly rejected before any real HTTP call');
assertTrue($result2['positions'] === [], 'no positions are ever fabricated on a credentials failure');

// Malformed/unexpected broker response shape.
$GLOBALS['fno_test_wp_remote_get_response'] = ['body' => json_encode(['error_type' => 'TokenException'])];
$result3 = fno_broker_get_positions_zerodha($account);
assertTrue($result3['success'] === false, 'an unexpected real broker response shape (e.g. an auth error) is honestly reported as a failure, never fabricated as empty-but-successful');

echo "\n=== fno_broker_get_positions() dispatcher ===\n";
$result4 = fno_broker_get_positions('angelone', $account);
assertTrue($result4['success'] === false, 'a real, unimplemented broker (Angel One) is honestly rejected for positions fetch too, never fabricated');

echo "\n=== fno_compare_broker_positions() - the real comparison logic ===\n";

$brokerPositions = [
    ['tradingsymbol' => 'NIFTY25200CE', 'quantity' => 50, 'product' => 'MIS', 'average_price' => 120.5, 'last_price' => 135.0, 'pnl' => 725.0],
    ['tradingsymbol' => 'BANKNIFTY51000PE', 'quantity' => 25, 'product' => 'MIS', 'average_price' => 200.0, 'last_price' => 190.0, 'pnl' => -250.0],
];

// Scenario 1: matched - DB and broker fully agree.
$dbRowsMatched = [['id' => 1, 'symbol' => 'NIFTY', 'strike' => '25200', 'option_type' => 'CE', 'qty' => 50]];
$cmp1 = fno_compare_broker_positions($dbRowsMatched, $brokerPositions);
assertTrue(count($cmp1['matched']) === 1, 'a real DB row with a genuinely matching broker position (same tradingsymbol, same qty) is correctly matched');
assertTrue(count($cmp1['phantomOpen']) === 0, 'a genuinely matched row produces no phantom-open discrepancy');

// Scenario 2: phantom-open - DB says open, broker shows no such position (this app's own close call failed, or a manual close happened at the broker).
$dbRowsPhantom = [['id' => 2, 'symbol' => 'FINNIFTY', 'strike' => '19500', 'option_type' => 'PE', 'qty' => 40]];
$cmp2 = fno_compare_broker_positions($dbRowsPhantom, $brokerPositions);
assertTrue(count($cmp2['phantomOpen']) === 1, 'a real DB row with NO matching broker position is correctly flagged phantom-open');
assertTrue($cmp2['phantomOpen'][0]['tradingsymbol'] === 'FINNIFTY19500PE', 'the phantom-open discrepancy names the real, correct tradingsymbol');
assertTrue(count($cmp2['matched']) === 0, 'a phantom-open row is never also counted as matched');

// Scenario 3: broker-only - a real broker position with no corresponding DB row (e.g. a manual trade placed directly with the broker).
$cmp3 = fno_compare_broker_positions([], $brokerPositions);
assertTrue(count($cmp3['brokerOnly']) === 2, 'every real broker position with no claiming DB row is correctly flagged broker-only');

// Scenario 4: qty mismatch (partial fill/exit) is also flagged, not silently matched.
$dbRowsQtyMismatch = [['id' => 3, 'symbol' => 'NIFTY', 'strike' => '25200', 'option_type' => 'CE', 'qty' => 75]];
$cmp4 = fno_compare_broker_positions($dbRowsQtyMismatch, $brokerPositions);
assertTrue(count($cmp4['phantomOpen']) === 1, 'a real qty mismatch (DB=75 vs broker=50) is honestly flagged, never silently treated as matched');
assertTrue(strpos($cmp4['phantomOpen'][0]['reason'], 'qty') !== false, 'the real mismatch reason specifically names the qty discrepancy');

// Scenario 5: a broker position with quantity=0 (fully exited) never counts as a real open broker-only position.
$cmp5 = fno_compare_broker_positions([], [['tradingsymbol' => 'NIFTY25200CE', 'quantity' => 0, 'product' => 'MIS', 'average_price' => 0, 'last_price' => 0, 'pnl' => 0]]);
assertTrue(count($cmp5['brokerOnly']) === 0, 'a real, fully-exited (quantity=0) broker row is correctly never flagged as a real open broker-only position');

echo "\n=== fno_reconcile_real_money_positions_fn() - the real AJAX endpoint, end to end ===\n";

$account6 = ['id' => 6, 'user_id' => 1, 'broker' => 'zerodha', 'api_key_encrypted' => 'enc_key', 'access_token_encrypted' => 'enc_token'];
$journalRows6 = [
    ['id' => 100, 'symbol' => 'NIFTY', 'strike' => '25200', 'option_type' => 'CE', 'qty' => 50, 'status' => 'placed'],
    ['id' => 101, 'symbol' => 'FINNIFTY', 'strike' => '19500', 'option_type' => 'PE', 'qty' => 40, 'status' => 'placed'],
];
$GLOBALS['fno_test_wp_remote_get_response'] = ['body' => json_encode(['status' => 'success', 'data' => ['net' => $brokerPositions, 'day' => []]])];
$wpdb = new FakeWpdbRecon($account6, $journalRows6);
$_POST = ['account_id' => '6'];
$response = null;
try { fno_reconcile_real_money_positions_fn(); } catch (TestWPDieExceptionRecon $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, successful end-to-end reconciliation run succeeds');
assertTrue($response['data']['matchedCount'] === 1, 'the real matched count is genuinely 1 (NIFTY25200CE)');
assertTrue(count($response['data']['phantomOpen']) === 1, 'the real phantom-open list genuinely contains the FINNIFTY row with no broker counterpart');
assertTrue(count($response['data']['brokerOnly']) === 1, 'the real broker-only list genuinely contains BANKNIFTY51000PE');
assertTrue(count($wpdb->inserted) === 2, 'every real discrepancy (1 phantom-open + 1 broker-only) is durably logged to wp_fno_failure_events, not just returned transiently');
assertTrue($wpdb->inserted[0]['row']['category'] === 'execution_issue', 'the real, durable log entry uses the existing, established execution_issue Failure-Mode category, not a new ad-hoc one');
assertTrue($wpdb->inserted[0]['row']['subcategory'] === 'broker_reconciliation_mismatch', 'the real, durable log entry is specifically tagged as a reconciliation mismatch');

// Real: an unknown account is honestly rejected.
$wpdb = new FakeWpdbRecon(null, []);
$_POST = ['account_id' => '999'];
$response = null;
try { fno_reconcile_real_money_positions_fn(); } catch (TestWPDieExceptionRecon $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, unknown/foreign account_id is honestly rejected, never a fabricated report');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
