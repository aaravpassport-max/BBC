<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the auth change
 * that closes the driver's last genuine FM-coverage gap (see
 * docs/PENDING_REQUIREMENTS.md §3c / autonomous-driver/README.md):
 * fno_get_strategy_versions_fn() and fno_get_hypothesis_stats_fn() were
 * previously reachable only by a real, logged-in browser session
 * (fno_verify_app_nonce()/is_user_logged_in()) - the headless driver had
 * no path to either at all. This proves, against the REAL, unmodified
 * function bodies (never a reimplementation), that:
 *
 *   (a) the real, existing browser-session path is genuinely unchanged
 *       for both endpoints;
 *   (b) a real, valid driver secret now genuinely works for both
 *       (fno_get_strategy_versions_fn via fno_verify_public_or_driver_access -
 *       site-wide, no per-user identity needed; fno_get_hypothesis_stats_fn
 *       via fno_verify_app_access() - per-user data, so the real driver
 *       user is genuinely set as the current user, not left at 0);
 *   (c) an invalid or missing driver secret (with no real nonce either)
 *       is still genuinely rejected for both.
 *
 * Run with: php tests/php/StrategyVersionsAndHypothesisStatsAuthTest.php
 */

class FakeWpdbSVHS {
    public $prefix = 'wp_';
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query, $output = null) { return []; }
    public function get_var($query) { return 0; }
}

$GLOBALS['fno_test_options'] = [];
function get_option($key, $default = false) { return $GLOBALS['fno_test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['fno_test_options'][$key] = $value; return true; }
function wp_generate_password($length, $special = true) { return str_repeat('a', $length); }
function wp_salt($scheme = 'auth') { return 'test-fixed-salt-do-not-use-in-production-' . $scheme; } // real dependency of fno_encrypt_secret/fno_decrypt_secret, which fno_get_headless_driver_secret now calls (encryption-at-rest fix)
function get_userdata($id) { return $GLOBALS['fno_test_valid_driver_user'] ? (object) ['ID' => $id] : false; }
$GLOBALS['fno_test_current_user_id'] = 0;
function wp_set_current_user($userId) { $GLOBALS['fno_test_current_user_id'] = $userId; }
function is_user_logged_in() { return $GLOBALS['fno_test_current_user_id'] > 0; }
function get_current_user_id() { return $GLOBALS['fno_test_current_user_id']; }
function check_ajax_referer($action, $key, $die = true) { return $GLOBALS['fno_test_nonce_valid'] ?? false; }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

class TestWPDieExceptionSVHS extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionSVHS(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionSVHS(['success' => false, 'data' => $data]); }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
function evalFn($src, $name) {
    $start = strpos($src, "function $name(");
    if ($start === false) { fwrite(STDERR, "FATAL: could not locate function $name() in fno-lab.php\n"); exit(1); }
    $end = strpos($src, "\n}", $start) + 2;
    eval(substr($src, $start, $end - $start));
}
foreach (['fno_encrypt_secret', 'fno_decrypt_secret', 'fno_verify_app_nonce', 'fno_get_headless_driver_secret', 'fno_verify_public_or_driver_access',
          'fno_verify_app_access', 'fno_get_strategy_versions_fn', 'fno_get_hypothesis_stats_fn'] as $fn) {
    evalFn($pluginSource, $fn);
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

function resetSVHS() {
    $GLOBALS['fno_test_options'] = [];
    $GLOBALS['fno_test_current_user_id'] = 0;
    $GLOBALS['fno_test_valid_driver_user'] = true;
    $GLOBALS['fno_test_nonce_valid'] = false;
    unset($_SERVER['HTTP_X_FNO_DRIVER_SECRET']);
    global $wpdb;
    $wpdb = new FakeWpdbSVHS();
}

echo "=== fno_get_strategy_versions_fn auth (real, site-wide read - fno_verify_public_or_driver_access) ===\n";

// (a) real, existing browser-session path unchanged: a real, valid
// nonce with genuinely no login at all still succeeds (this was always
// true - these are public reads, no is_user_logged_in() check exists).
resetSVHS();
$GLOBALS['fno_test_nonce_valid'] = true;
$response = null;
try { fno_get_strategy_versions_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, anonymous browser request with a valid nonce still succeeds unchanged - no login required, exactly as before this session\'s auth swap');

// (b) real, valid driver secret now works, with no nonce at all.
resetSVHS();
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();
$response = null;
try { fno_get_strategy_versions_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid driver secret now genuinely succeeds with NO nonce present - the exact real gap this session closes');
assertTrue(isset($response['data']['versions']) && is_array($response['data']['versions']), 'the real response genuinely includes the versions array in the exact shape loadStrategyVersions() already expects');

// (c) invalid/missing auth still rejected.
resetSVHS();
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = 'genuinely-wrong-secret';
$response = null;
try { fno_get_strategy_versions_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, invalid driver secret is genuinely rejected, not silently accepted');

resetSVHS();
$GLOBALS['fno_test_nonce_valid'] = false;
$response = null;
try { fno_get_strategy_versions_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'genuinely no driver secret and an invalid/missing nonce is still rejected');

echo "\n=== fno_get_hypothesis_stats_fn auth (real, PER-USER read - fno_verify_app_access, needs the real driver user identity) ===\n";

// (a) real, existing browser-session path unchanged: nonce valid +
// logged in succeeds; nonce valid but NOT logged in is still rejected,
// exactly as the original fno_verify_app_nonce()+is_user_logged_in()
// check required before this session's change.
resetSVHS();
$GLOBALS['fno_test_nonce_valid'] = true;
$GLOBALS['fno_test_current_user_id'] = 7;
$response = null;
try { fno_get_hypothesis_stats_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, logged-in browser user with a valid nonce still succeeds unchanged');
assertTrue(get_current_user_id() === 7, 'the real, already-logged-in browser user id is genuinely untouched (not overwritten by any driver-user logic)');

resetSVHS();
$GLOBALS['fno_test_nonce_valid'] = true;
$GLOBALS['fno_test_current_user_id'] = 0;
$response = null;
try { fno_get_hypothesis_stats_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, valid nonce with genuinely NO login is still rejected exactly as before - "Login required" behavior unchanged');

// (b) real, valid driver secret now works, with no nonce - AND
// genuinely sets the real, configured driver user as current, so the
// per-user WHERE user_id=... queries inside are scoped correctly
// (this is the real reason fno_verify_app_access() was required here
// instead of the simpler fno_verify_public_or_driver_access() used for
// the site-wide strategy-versions endpoint above).
resetSVHS();
$GLOBALS['fno_test_options'] = ['fno_headless_driver_user_id' => 42];
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();
$response = null;
try { fno_get_hypothesis_stats_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid driver secret with a real, configured driver user now genuinely succeeds - the exact real gap this session closes');
assertTrue(get_current_user_id() === 42, 'the real, configured driver user id is genuinely set as current_user, so the per-user hypothesis-stats query is correctly scoped to the real driver user, not user_id=0');
assertTrue(array_key_exists('byDirection', $response['data']), 'the real response genuinely includes byDirection, the exact shape loadHypothesisStats() already expects for window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE');

// (c) a valid secret but NO configured driver user is honestly
// rejected, never silently falling back to some default/anonymous scope.
resetSVHS();
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();
$response = null;
try { fno_get_hypothesis_stats_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, valid secret with genuinely no configured driver user is honestly rejected, not silently defaulted to user_id=0');

// (c) invalid driver secret still rejected.
resetSVHS();
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = 'genuinely-wrong-secret';
$response = null;
try { fno_get_hypothesis_stats_fn(); } catch (TestWPDieExceptionSVHS $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, invalid driver secret is genuinely rejected here too');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
