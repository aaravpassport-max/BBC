<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP regression test for
 * multi-broker-account correctness - a user with 2+ configured real
 * broker accounts (fno_real_money_accounts rows) must never have one
 * account's action (arm/disarm/login/reconcile) affect, read, or
 * write another account's row, even though both belong to the SAME
 * user_id.
 *
 * Unlike BrokerPositionsReconciliationTest.php's FakeWpdb (which
 * matches only by table name and ignores the real bound arguments),
 * this fake genuinely APPLIES the SQL WHERE filters by doing real
 * sprintf-style substitution in prepare() and then real per-row
 * filtering in get_row/get_results/update - so a query that is
 * missing a WHERE clause bound (e.g. account_id, or user_id) is
 * actually caught here, the same way a real MySQL table with 2+ rows
 * for the same user would catch it.
 *
 * Run with: php tests/php/MultiAccountScopingTest.php
 */

function is_wp_error($thing) { return false; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_remote_post($url, $args = []) { return ['body' => '{}']; }
$GLOBALS['fno_test_wp_remote_get_response'] = ['body' => '{}'];
function wp_remote_get($url, $args = []) { return $GLOBALS['fno_test_wp_remote_get_response']; }
function fno_decrypt_secret($encoded) { return str_replace('enc_', '', (string) $encoded); }
function fno_encrypt_secret($plain) { return 'enc_' . $plain; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function is_user_logged_in() { return true; }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
function check_ajax_referer($a, $k, $d = true) { return true; }
if (!defined('FNO_JOURNAL_SCHEMA_VERSION')) define('FNO_JOURNAL_SCHEMA_VERSION', '2.9.0');
if (!defined('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED')) define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', false);
class TestWPDieExceptionMAS extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionMAS(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionMAS(['success' => false, 'data' => $data]); }

$GLOBALS['fno_test_current_user_id'] = 1;
function get_current_user_id() { return $GLOBALS['fno_test_current_user_id']; }

/**
 * A genuinely filtering fake wpdb: prepare() does real sprintf-style
 * substitution (like the real $wpdb->prepare), and get_row/get_results
 * /update apply the resulting WHERE clause for real against in-memory
 * "tables" (associative arrays of rows), so a missing/incorrect bound
 * matches the exact production defect this test is meant to catch.
 */
class FakeWpdbMultiAccount {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $inserted = [];
    public $accounts = [];  // id => row
    public $journal = [];   // list of rows (has 'user_id','account_id')

    public function prepare($query, ...$args) {
        // Real wpdb::prepare substitutes %d/%s placeholders in order.
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return is_numeric($v) ? (string) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }

    public function get_row($query, $output = null) {
        if (strpos($query, 'fno_real_money_accounts') !== false) {
            foreach ($this->accounts as $row) {
                if ($this->rowMatches($query, $row)) return $row;
            }
            return null;
        }
        return null;
    }

    public function get_results($query, $output = null) {
        if (strpos($query, 'fno_real_money_journal') !== false) {
            return array_values(array_filter($this->journal, function ($row) use ($query) {
                return $this->rowMatches($query, $row);
            }));
        }
        return [];
    }

    public function get_var($query) {
        if (strpos($query, 'fno_real_money_accounts') !== false && strpos($query, 'COUNT(*)') !== false) {
            $matching = array_filter($this->accounts, function ($row) use ($query) { return $this->rowMatches($query, $row); });
            $armed = array_filter($matching, function ($row) { return (bool) $row['is_armed']; });
            return count($armed);
        }
        return null;
    }

    public function update($table, $data, $where) {
        if (strpos($table, 'fno_real_money_accounts') !== false) {
            $updated = 0;
            foreach ($this->accounts as $id => $row) {
                $matches = true;
                foreach ($where as $k => $v) { if ((string) $row[$k] !== (string) $v) { $matches = false; break; } }
                if ($matches) { $this->accounts[$id] = array_merge($row, $data); $updated++; }
            }
            return $updated;
        }
        return false;
    }

    public function insert($table, $row) {
        $this->inserted[] = ['table' => $table, 'row' => $row];
        if (strpos($table, 'fno_real_money_journal') !== false) { $this->journal[] = $row; }
        return true;
    }

    // Extracts "id = N", "user_id = N", "account_id = N" bounds from
    // the (already-substituted) query text and checks the row for real.
    private function rowMatches($query, $row) {
        foreach (['id', 'user_id', 'account_id'] as $field) {
            if (preg_match('/\b' . $field . '\s*=\s*\'?(\d+)\'?/', $query, $m)) {
                if (!isset($row[$field]) || (string) $row[$field] !== $m[1]) return false;
            }
        }
        return true;
    }
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach ([
    'fno_verify_app_nonce', 'fno_get_broker_catalog', 'fno_broker_get_positions',
    'fno_broker_get_positions_zerodha', 'fno_compare_broker_positions',
    'fno_reconcile_real_money_positions_fn', 'fno_arm_real_money_account_fn',
    'fno_disarm_real_money_account_fn', 'fno_is_real_money_armed',
    'fno_list_real_money_accounts_fn', 'fno_real_account_login_fn',
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

echo "=== Multi-broker-account scoping: reconciliation never cross-compares accounts ===\n";

// Same user (id=1) has TWO real broker accounts: account 10 (zerodha)
// and account 20 (zerodha). Each has its own 'placed' journal rows
// for a DIFFERENT symbol, so cross-account conflation is detectable.
$wpdb = new FakeWpdbMultiAccount();
$wpdb->accounts = [
    10 => ['id' => 10, 'user_id' => 1, 'broker' => 'zerodha', 'label' => 'Account A', 'api_key_encrypted' => 'enc_keyA', 'access_token_encrypted' => 'enc_tokA', 'is_armed' => 0, 'armed_plugin_version' => null],
    20 => ['id' => 20, 'user_id' => 1, 'broker' => 'zerodha', 'label' => 'Account B', 'api_key_encrypted' => 'enc_keyB', 'access_token_encrypted' => 'enc_tokB', 'is_armed' => 0, 'armed_plugin_version' => null],
];
$wpdb->journal = [
    ['id' => 1, 'user_id' => 1, 'account_id' => 10, 'symbol' => 'NIFTY', 'strike' => '25200', 'option_type' => 'CE', 'qty' => 50, 'status' => 'placed'],
    ['id' => 2, 'user_id' => 1, 'account_id' => 20, 'symbol' => 'BANKNIFTY', 'strike' => '51000', 'option_type' => 'PE', 'qty' => 25, 'status' => 'placed'],
];

// Account A's broker reports ONLY the NIFTY position (its own).
$GLOBALS['fno_test_wp_remote_get_response'] = ['body' => json_encode([
    'status' => 'success',
    'data' => ['net' => [['tradingsymbol' => 'NIFTY25200CE', 'exchange' => 'NFO', 'product' => 'MIS', 'quantity' => 50, 'average_price' => 120.0, 'last_price' => 130.0, 'pnl' => 500.0]], 'day' => []],
])];
$_POST = ['account_id' => '10'];
$response = null;
try { fno_reconcile_real_money_positions_fn(); } catch (TestWPDieExceptionMAS $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'reconciling account A succeeds');
assertTrue($response['data']['matchedCount'] === 1, 'account A matches its OWN NIFTY row against its own broker feed');
assertTrue(count($response['data']['phantomOpen']) === 0, 'account A\'s BANKNIFTY sibling row (belonging to account B) is NOT pulled in and flagged phantom-open - proves the journal SELECT is scoped by account_id, not just user_id');
assertTrue(count($response['data']['brokerOnly']) === 0, 'no broker-only discrepancy for account A');

// Now reconcile account B - its broker reports ONLY the BANKNIFTY position.
$GLOBALS['fno_test_wp_remote_get_response'] = ['body' => json_encode([
    'status' => 'success',
    'data' => ['net' => [['tradingsymbol' => 'BANKNIFTY51000PE', 'exchange' => 'NFO', 'product' => 'MIS', 'quantity' => 25, 'average_price' => 200.0, 'last_price' => 190.0, 'pnl' => -250.0]], 'day' => []],
])];
$_POST = ['account_id' => '20'];
$response = null;
try { fno_reconcile_real_money_positions_fn(); } catch (TestWPDieExceptionMAS $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'reconciling account B succeeds');
assertTrue($response['data']['matchedCount'] === 1, 'account B matches its OWN BANKNIFTY row against its own broker feed');
assertTrue(count($response['data']['phantomOpen']) === 0, 'account B\'s NIFTY sibling row (belonging to account A) is NOT pulled in and flagged phantom-open');

echo "\n=== Multi-broker-account scoping: arming one account never arms/affects a sibling account ===\n";

$wpdb2 = new FakeWpdbMultiAccount();
$wpdb2->accounts = [
    30 => ['id' => 30, 'user_id' => 1, 'broker' => 'zerodha', 'label' => 'Account A', 'api_key_encrypted' => 'enc_keyA', 'access_token_encrypted' => 'enc_tokA', 'is_armed' => 0, 'armed_plugin_version' => null],
    40 => ['id' => 40, 'user_id' => 1, 'broker' => 'zerodha', 'label' => 'Account B', 'api_key_encrypted' => 'enc_keyB', 'access_token_encrypted' => 'enc_tokB', 'is_armed' => 0, 'armed_plugin_version' => null],
];
$wpdb = $wpdb2;
$_POST = ['account_id' => '30', 'confirmation' => 'I UNDERSTAND THIS WILL PLACE REAL TRADES WITH REAL MONEY'];
$response = null;
try { fno_arm_real_money_account_fn(); } catch (TestWPDieExceptionMAS $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'arming account A succeeds');
assertTrue((bool) $wpdb2->accounts[30]['is_armed'] === true, 'account A (id=30) is genuinely armed');
assertTrue((bool) $wpdb2->accounts[40]['is_armed'] === false, 'sibling account B (id=40) remains UNARMED - no shared/global armed-state bug across a user\'s multiple accounts');

// Disarm A, arm B - verify the reverse direction too.
$_POST = ['account_id' => '30', 'reason' => 'test'];
try { fno_disarm_real_money_account_fn(); } catch (TestWPDieExceptionMAS $e) {}
$_POST = ['account_id' => '40', 'confirmation' => 'I UNDERSTAND THIS WILL PLACE REAL TRADES WITH REAL MONEY'];
try { fno_arm_real_money_account_fn(); } catch (TestWPDieExceptionMAS $e) {}
assertTrue((bool) $wpdb2->accounts[30]['is_armed'] === false, 'account A is genuinely disarmed');
assertTrue((bool) $wpdb2->accounts[40]['is_armed'] === true, 'account B is genuinely armed, independently of account A\'s prior arm/disarm cycle');

echo "\n=== Multi-broker-account scoping: a user cannot arm/disarm/relogin ANOTHER user's account via account_id alone ===\n";

$wpdb3 = new FakeWpdbMultiAccount();
$wpdb3->accounts = [
    50 => ['id' => 50, 'user_id' => 2, 'broker' => 'zerodha', 'label' => "Other user's account", 'api_key_encrypted' => 'enc_keyC', 'access_token_encrypted' => 'enc_tokC', 'is_armed' => 0, 'armed_plugin_version' => null],
];
$wpdb = $wpdb3;
$GLOBALS['fno_test_current_user_id'] = 1; // attacker is user 1, target account belongs to user 2
$_POST = ['account_id' => '50', 'confirmation' => 'I UNDERSTAND THIS WILL PLACE REAL TRADES WITH REAL MONEY'];
$response = null;
try { fno_arm_real_money_account_fn(); } catch (TestWPDieExceptionMAS $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, "arming another user's account_id is honestly rejected (SELECT is scoped by user_id)");
assertTrue((bool) $wpdb3->accounts[50]['is_armed'] === false, "the foreign account's is_armed is genuinely untouched");

$_POST = ['account_id' => '50', 'request_token' => 'tok123'];
$response = null;
try { fno_real_account_login_fn(); } catch (TestWPDieExceptionMAS $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, "completing login for another user's account_id is honestly rejected");
assertTrue($wpdb3->accounts[50]['access_token_encrypted'] === 'enc_tokC', "the foreign account's access token is genuinely untouched by the rejected login attempt");

$GLOBALS['fno_test_current_user_id'] = 1;

echo "\n=== Multi-broker-account scoping: fno_is_real_money_armed() targets exactly the requested account ===\n";

$wpdb4 = new FakeWpdbMultiAccount();
$wpdb4->accounts = [
    60 => ['id' => 60, 'user_id' => 1, 'broker' => 'zerodha', 'is_armed' => 1, 'armed_plugin_version' => FNO_JOURNAL_SCHEMA_VERSION, 'api_key_encrypted' => 'enc_k', 'access_token_encrypted' => 'enc_t'],
    70 => ['id' => 70, 'user_id' => 1, 'broker' => 'zerodha', 'is_armed' => 1, 'armed_plugin_version' => FNO_JOURNAL_SCHEMA_VERSION, 'api_key_encrypted' => 'enc_k', 'access_token_encrypted' => 'enc_t'],
];
$wpdb = $wpdb4;
list($armed60, $reason60) = fno_is_real_money_armed(60, 1);
assertTrue($armed60 === false, 'account 60 correctly reports not-armed (FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED is false - the master switch, not a scoping bug)');
assertTrue((bool) $wpdb4->accounts[70]['is_armed'] === true, "checking account 60's armed status never touches sibling account 70's row");

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
