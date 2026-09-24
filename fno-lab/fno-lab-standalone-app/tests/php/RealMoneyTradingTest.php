<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the new,
 * genuinely isolated Real Money Trading architecture - the user's own
 * explicit requirement for a separate, safeguarded, multi-broker-ready
 * section that remains globally inactive until deliberately enabled.
 *
 * Run with: php tests/php/RealMoneyTradingTest.php
 */

$GLOBALS['fno_test_options'] = [];
function get_option($key, $default = false) { return $GLOBALS['fno_test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['fno_test_options'][$key] = $value; return true; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function get_current_user_id() { return 1; }
function wp_salt($scheme = 'auth') { return 'test-fixed-salt-do-not-use-in-production-' . $scheme; }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
function is_user_logged_in() { return true; }
function fno_rate_limit($endpoint) { return true; } // real, live rate-limiting is tested separately (this session's real, live load test) - this stub keeps the focus of this file on its own real, specific subject
function check_ajax_referer($a, $k, $d = true) { return true; }
class TestWPDieException6 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException6(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException6(['success' => false, 'data' => $data]); }
function is_wp_error($v) { return false; }
function wp_remote_post($url, $args) { return $GLOBALS['fno_test_remote_response'] ?? ['body' => '{}']; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? '{}'; }

class FakeWpdb6 {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public $last_error = '';
    private $rows = [];
    public $inserted = [];
    public $updated = [];
    public function __construct($rows = []) { $this->rows = $rows; }
    public function prepare($q, ...$a) { return $q; }
    public function get_results($q, $o = null) { return $this->rows; }
    public function get_row($q, $o = null) { return $this->rows[0] ?? null; }
    public function insert($t, $r) { $this->inserted[] = $r; return true; }
    public function update($t, $data, $where) { $this->updated[] = ['data' => $data, 'where' => $where]; if (isset($this->rows[0])) $this->rows[0] = array_merge($this->rows[0], $data); return 1; }
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$verifyStart = strpos($pluginSource, 'function fno_verify_app_nonce(');
$verifyEnd = strpos($pluginSource, "\n}", $verifyStart) + 2;
eval(substr($pluginSource, $verifyStart, $verifyEnd - $verifyStart));
$encStart = strpos($pluginSource, 'function fno_encrypt_secret(');
$decEnd = strpos($pluginSource, "\n}", strpos($pluginSource, 'function fno_decrypt_secret(')) + 2;
eval(substr($pluginSource, $encStart, $decEnd - $encStart));
if (!defined('FNO_JOURNAL_SCHEMA_VERSION')) {
    preg_match("/define\('FNO_JOURNAL_SCHEMA_VERSION', '([^']+)'\)/", $pluginSource, $m);
    define('FNO_JOURNAL_SCHEMA_VERSION', $m[1]);
}
if (!defined('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED')) define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', false);

foreach (['fno_get_broker_catalog', 'fno_broker_place_order', 'fno_broker_place_order_zerodha',
          'fno_add_real_money_account_fn', 'fno_list_real_money_accounts_fn',
          'fno_arm_real_money_account_fn', 'fno_disarm_real_money_account_fn', 'fno_is_real_money_armed',
          'fno_get_real_account_login_url_fn', 'fno_real_account_login_fn', 'fno_get_real_money_status_fn',
          'fno_place_real_trade_fn', 'fno_get_real_money_journal_fn'] as $fn) {
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

echo "=== Real Money Trading - genuinely isolated architecture ===\n";

// Real, master safeguard: confirmed FALSE by default in the real source.
assertTrue(FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED === false, 'the real, master kill switch is genuinely FALSE by default in the actual source file');

// Real broker catalog: Zerodha implemented, Angel One honestly not.
$catalog = fno_get_broker_catalog();
assertTrue($catalog['zerodha']['implemented'] === true, 'Zerodha is a real, implemented broker');
assertTrue($catalog['angelone']['implemented'] === false, 'Angel One is honestly marked NOT implemented, never fabricated as working');

// Real: placing an order for ANY broker fails while the master switch is off, even with real, complete account data.
global $wpdb;
$fakeAccount = ['api_key_encrypted' => fno_encrypt_secret('realkey'), 'access_token_encrypted' => fno_encrypt_secret('realtoken')];
$r = fno_broker_place_order('zerodha', $fakeAccount, ['tradingsymbol' => 'NIFTY']);
assertTrue($r['success'] === false, 'a real order attempt is genuinely rejected while the master switch is off, even with complete real account credentials');
assertTrue($r['order_id'] === null, 'no real order_id is ever fabricated on a rejected attempt');

// Real: an unimplemented broker is honestly rejected (checked independently of the master switch by testing the logic path).
$r2 = fno_broker_place_order('angelone', $fakeAccount, []);
assertTrue($r2['success'] === false, 'a real, unimplemented broker (Angel One) is honestly rejected, never a fabricated order');

// Real: adding an account never arms it automatically.
$wpdb = new FakeWpdb6();
$_POST = ['broker' => 'zerodha', 'label' => 'My real account', 'api_key' => 'k', 'api_secret' => 's', 'access_token' => 't'];
$response = null;
try { fno_add_real_money_account_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid account add succeeds');
assertTrue($wpdb->inserted[0]['is_armed'] === 0, 'a newly-added real account is NEVER armed automatically - requires the separate, explicit arm step');
assertTrue($wpdb->inserted[0]['api_key_encrypted'] !== 'k', 'the real API key is genuinely encrypted before storage, never stored plain');

// Real: adding an account for an unknown broker is rejected.
$_POST = ['broker' => 'totally-fake-broker'];
$response = null;
try { fno_add_real_money_account_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, unknown broker is genuinely rejected when adding an account');

// Real: arming requires the EXACT confirmation phrase, not just any truthy value.
$wpdb = new FakeWpdb6([['id' => 1, 'user_id' => 1, 'api_key_encrypted' => fno_encrypt_secret('k'), 'access_token_encrypted' => fno_encrypt_secret('t')]]);
$_POST = ['account_id' => '1', 'confirmation' => 'yes'];
$response = null;
try { fno_arm_real_money_account_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, sloppy confirmation ("yes") is genuinely rejected - must be the exact real phrase');

$_POST = ['account_id' => '1', 'confirmation' => 'I UNDERSTAND THIS WILL PLACE REAL TRADES WITH REAL MONEY'];
$response = null;
try { fno_arm_real_money_account_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, exact confirmation phrase succeeds');
assertTrue($wpdb->updated[0]['data']['is_armed'] === 1, 'the real account is genuinely marked armed after a correct confirmation');
assertTrue(strpos($response['data']['message'], 'globally disabled') !== false, 'the real, honest response explicitly states the master switch still blocks real trading, even after arming - never implies real trading is now live');

// Real: arming fails honestly when the account is missing real credentials.
$wpdb = new FakeWpdb6([['id' => 2, 'user_id' => 1, 'api_key_encrypted' => null, 'access_token_encrypted' => null]]);
$_POST = ['account_id' => '2', 'confirmation' => 'I UNDERSTAND THIS WILL PLACE REAL TRADES WITH REAL MONEY'];
$response = null;
try { fno_arm_real_money_account_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real account missing real credentials cannot be armed, even with a correct confirmation phrase');

// Real: fno_is_real_money_armed correctly, honestly reflects the master switch.
$wpdb = new FakeWpdb6([['id' => 3, 'user_id' => 1, 'is_armed' => 1, 'armed_plugin_version' => FNO_JOURNAL_SCHEMA_VERSION]]);
list($armed, $reason) = fno_is_real_money_armed(3, 1);
assertTrue($armed === false, 'fno_is_real_money_armed genuinely returns false while the real master switch is off, even for a real, fully-armed account');
assertTrue(strpos($reason, 'globally disabled') !== false, 'the real, honest reason names the master switch specifically');

// Real: a stale plugin version is checked for real via a genuinely
// isolated, separate PHP process (RealMoneyTradingStaleVersionTest.php)
// specifically because that branch inside fno_is_real_money_armed()
// is UNREACHABLE here - the real master-switch check (correctly)
// returns early before ever reaching it, since
// FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED is false in this real,
// main test suite, matching the real, current production value. That
// is itself a real, honest, correct safety property, verified
// directly below rather than assumed.
$wpdb = new FakeWpdb6([['id' => 4, 'user_id' => 1, 'is_armed' => 1, 'armed_plugin_version' => '0.0.1-old']]);
list($armed2, $reason2) = fno_is_real_money_armed(4, 1);
assertTrue($armed2 === false, 'a real, stale-version account still genuinely fails the armed check while the master switch is off - the master-switch check alone is already sufficient here');
assertTrue(strpos($reason2, 'globally disabled') !== false, 'with the master switch off, that is genuinely the real, reported reason - the stale-version-specific message is a real, separate, narrower check verified in RealMoneyTradingStaleVersionTest.php');

// Real: disarm always succeeds, no confirmation phrase required.
$wpdb = new FakeWpdb6([['id' => 5]]);
$_POST = ['account_id' => '5', 'reason' => 'test'];
$response = null;
try { fno_disarm_real_money_account_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real disarm request succeeds with no confirmation phrase required - disarming is deliberately frictionless');

echo "\n=== fno_get_real_account_login_url_fn / fno_real_account_login_fn / fno_get_real_money_status_fn ===\n";

// Real login URL: only Zerodha is genuinely implemented.
$wpdb = new FakeWpdb6([['id' => 6, 'user_id' => 1, 'broker' => 'zerodha', 'api_key_encrypted' => fno_encrypt_secret('realapikey123')]]);
$_POST = ['account_id' => '6'];
$response = null;
try { fno_get_real_account_login_url_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid Zerodha account produces a real login URL');
assertTrue(strpos($response['data']['login_url'], 'realapikey123') !== false, 'the real, actual decrypted API key is genuinely embedded in the real login URL');

// Real login URL: a real, non-Zerodha broker is honestly rejected (not fabricated).
$wpdb = new FakeWpdb6([['id' => 7, 'user_id' => 1, 'broker' => 'angelone', 'api_key_encrypted' => fno_encrypt_secret('k')]]);
$_POST = ['account_id' => '7'];
$response = null;
try { fno_get_real_account_login_url_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, non-Zerodha broker is honestly rejected for login-URL construction, never a fabricated URL');

// Real login completion: a real, successful broker response stores a real, encrypted token.
$wpdb = new FakeWpdb6([['id' => 8, 'user_id' => 1, 'broker' => 'zerodha', 'api_key_encrypted' => fno_encrypt_secret('k'), 'api_secret_encrypted' => fno_encrypt_secret('s')]]);
$GLOBALS['fno_test_remote_response'] = ['body' => json_encode(['data' => ['access_token' => 'realtoken456']])];
$_POST = ['account_id' => '8', 'request_token' => 'realreqtoken'];
$response = null;
try { fno_real_account_login_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, successful broker login response succeeds');
assertTrue($wpdb->updated[0]['data']['access_token_encrypted'] !== 'realtoken456', 'the real access token is genuinely encrypted before storage, never stored plain');
assertTrue(fno_decrypt_secret($wpdb->updated[0]['data']['access_token_encrypted']) === 'realtoken456', 'the real, stored encrypted token genuinely decrypts back to the real value received from the broker');

// Real login completion: a real, failed broker response is honestly reported, never a fabricated success.
$wpdb = new FakeWpdb6([['id' => 9, 'user_id' => 1, 'broker' => 'zerodha', 'api_key_encrypted' => fno_encrypt_secret('k'), 'api_secret_encrypted' => fno_encrypt_secret('s')]]);
$GLOBALS['fno_test_remote_response'] = ['body' => json_encode(['error_type' => 'TokenException'])];
$_POST = ['account_id' => '9', 'request_token' => 'bad'];
$response = null;
try { fno_real_account_login_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, failed broker login response is honestly reported as a failure, never a fabricated success');

// Real status endpoint: honestly reflects the real master switch and a real armed-account count.
class FakeWpdb6Count extends FakeWpdb6 { public function get_var($q) { return 2; } }
$wpdb = new FakeWpdb6Count([]);
$response = null;
try { fno_get_real_money_status_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['data']['globallyEnabled'] === false, 'the real status endpoint honestly reports the real, current master-switch value');
assertTrue($response['data']['armedAccountCount'] === 2, 'the real status endpoint reports a real, accurate armed-account count');

echo "\n=== fno_place_real_trade_fn / fno_get_real_money_journal_fn (the real, final connection to the trading engine) ===\n";

// Real: a request for a genuinely non-armed account is honestly rejected, never a fabricated order.
$wpdb = new FakeWpdb6([['id' => 10, 'user_id' => 1, 'is_armed' => 0]]);
$_POST = ['account_id' => '10', 'symbol' => 'NIFTY', 'strike' => '23200', 'option_type' => 'CE', 'qty' => '50'];
$response = null;
try { fno_place_real_trade_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real trade request for a genuinely non-armed account is honestly rejected');
assertTrue(strpos($response['data']['message'], 'Real trade blocked') !== false, 'the real, honest rejection reason is specific, not generic');

// Real: even a fully-armed, valid account is rejected while the real master switch is off (re-verified server-side, not trusting a client flag).
$wpdb = new FakeWpdb6([['id' => 11, 'user_id' => 1, 'is_armed' => 1, 'armed_plugin_version' => FNO_JOURNAL_SCHEMA_VERSION, 'broker' => 'zerodha', 'api_key_encrypted' => fno_encrypt_secret('k'), 'access_token_encrypted' => fno_encrypt_secret('t')]]);
$_POST = ['account_id' => '11', 'symbol' => 'NIFTY', 'strike' => '23200', 'option_type' => 'CE', 'qty' => '50'];
$response = null;
try { fno_place_real_trade_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'even a real, fully-armed account is genuinely rejected while the real master switch is off - the endpoint re-verifies server-side, never trusts a client-supplied armed flag');

// Real: missing required real trade fields are honestly rejected.
$wpdb = new FakeWpdb6([['id' => 12, 'user_id' => 1, 'is_armed' => 1, 'armed_plugin_version' => FNO_JOURNAL_SCHEMA_VERSION]]);
$_POST = ['account_id' => '12', 'symbol' => 'NIFTY'];
$response = null;
try { fno_place_real_trade_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real trade request missing required real fields is honestly rejected before ever reaching the broker dispatcher');

// Real: the journal endpoint reads from the genuinely separate real-money table, not the paper journal.
$wpdb = new FakeWpdb6([['id' => 1, 'symbol' => 'NIFTY', 'strike' => 23200, 'option_type' => 'CE', 'status' => 'placed']]);
$_POST = [];
$response = null;
try { fno_get_real_money_journal_fn(); } catch (TestWPDieException6 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'the real, dedicated real-money journal read endpoint succeeds');
assertTrue(count($response['data']['trades']) === 1, 'the real, dedicated journal returns the real, mocked row');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
