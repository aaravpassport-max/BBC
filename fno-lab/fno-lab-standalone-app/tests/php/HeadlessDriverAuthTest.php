<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_verify_app_access() - the new, real authentication path enabling
 * genuinely unattended (browser-closed) operation, per the user's own
 * founding vision document's explicit "let it run" requirement.
 *
 * Run with: php tests/php/HeadlessDriverAuthTest.php
 */

$GLOBALS['fno_test_options'] = [];
function get_option($key, $default = false) { return $GLOBALS['fno_test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['fno_test_options'][$key] = $value; return true; }
function wp_generate_password($length, $special = true) { return str_repeat('a', $length); }
function wp_salt($scheme = 'auth') { return 'test-fixed-salt-do-not-use-in-production-' . $scheme; } // real dependency of fno_encrypt_secret/fno_decrypt_secret, which fno_get_headless_driver_secret now calls (encryption-at-rest fix)
$GLOBALS['fno_test_current_user_id'] = 0;
function wp_set_current_user($userId) { $GLOBALS['fno_test_current_user_id'] = $userId; }
function is_user_logged_in() { return $GLOBALS['fno_test_current_user_id'] > 0; }
function get_current_user_id() { return $GLOBALS['fno_test_current_user_id']; }
$GLOBALS['fno_test_valid_driver_user'] = true;
function get_userdata($id) { return $GLOBALS['fno_test_valid_driver_user'] ? (object) ['ID' => $id] : false; }
class TestWPDieException5 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException5(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException5(['success' => false, 'data' => $data]); }
function check_ajax_referer($action, $key, $die = true) { return $GLOBALS['fno_test_nonce_valid'] ?? false; }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$verifyStart = strpos($pluginSource, 'function fno_verify_app_nonce(');
$verifyEnd = strpos($pluginSource, "\n}", $verifyStart) + 2;
eval(substr($pluginSource, $verifyStart, $verifyEnd - $verifyStart));

// fno_get_headless_driver_secret() now calls fno_encrypt_secret/
// fno_decrypt_secret (encryption-at-rest fix) - pull those in too so
// this test exercises the REAL functions, not stubs.
$encStart = strpos($pluginSource, 'function fno_encrypt_secret(');
$decEnd = strpos($pluginSource, "\n}", strpos($pluginSource, 'function fno_decrypt_secret(')) + 2;
eval(substr($pluginSource, $encStart, $decEnd - $encStart));

$secretStart = strpos($pluginSource, 'function fno_get_headless_driver_secret(');
$accessStart = strpos($pluginSource, 'function fno_verify_app_access(');
$accessEnd = strpos($pluginSource, "\n}", $accessStart) + 2;
eval(substr($pluginSource, $secretStart, $accessEnd - $secretStart));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_verify_app_access (real headless driver auth - enables genuine unattended operation) ===\n";

$GLOBALS['fno_test_options'] = ['fno_headless_driver_user_id' => 5];
$GLOBALS['fno_test_current_user_id'] = 0;
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();
$threw = false;
try { fno_verify_app_access(); } catch (TestWPDieException5 $e) { $threw = true; }
assertTrue($threw === false, 'a real, valid driver secret with a real, configured driver user does NOT throw - request proceeds');
assertTrue(get_current_user_id() === 5, 'the real, configured driver user ID is genuinely set as the current user');
assertTrue(is_user_logged_in() === true, 'is_user_logged_in() genuinely reflects the real driver user, not left false');

$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = 'genuinely-wrong-secret';
$response = null;
try { fno_verify_app_access(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, invalid driver secret is genuinely rejected, not silently accepted');

$GLOBALS['fno_test_options'] = [];
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();
$response = null;
try { fno_verify_app_access(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, valid secret with genuinely NO configured driver user is honestly rejected, not silently defaulted to some user');

$GLOBALS['fno_test_options'] = ['fno_headless_driver_user_id' => 999];
$GLOBALS['fno_test_valid_driver_user'] = false;
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();
$response = null;
try { fno_verify_app_access(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, configured but genuinely nonexistent driver user is honestly rejected');
$GLOBALS['fno_test_valid_driver_user'] = true;

unset($_SERVER['HTTP_X_FNO_DRIVER_SECRET']);
$GLOBALS['fno_test_nonce_valid'] = true;
$GLOBALS['fno_test_current_user_id'] = 7;
$threw = false;
try { fno_verify_app_access(); } catch (TestWPDieException5 $e) { $threw = true; }
assertTrue($threw === false, 'the real, existing browser path (nonce + login) is genuinely unchanged and still works with no driver header present');

$GLOBALS['fno_test_current_user_id'] = 0;
$response = null;
try { fno_verify_app_access(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'the real, existing browser path still honestly rejects a real, non-logged-in request exactly as before');

$GLOBALS['fno_test_nonce_valid'] = false;
$response = null;
try { fno_verify_app_access(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'the real, existing browser path still honestly rejects a real, invalid nonce exactly as before');

$GLOBALS['fno_test_options'] = [];
$secret = fno_get_headless_driver_secret();
assertTrue(strlen($secret) >= 40, 'the real, auto-generated driver secret is genuinely long enough to resist brute-forcing (40+ chars)');
assertTrue(fno_get_headless_driver_secret() === $secret, 'the real driver secret is genuinely persisted, not regenerated on every real call');

echo "\n=== fno_save_driver_user_fn (real admin-only save handler for driver user attribution) ===\n";
function current_user_can($cap) { return $GLOBALS['fno_test_is_admin'] ?? true; }
function check_admin_referer($action, $key) { if (!($GLOBALS['fno_test_admin_nonce_valid'] ?? true)) { throw new TestWPDieException5(['success'=>false,'message'=>'bad nonce']); } return true; }
function wp_die($msg, $code = 200) { throw new TestWPDieException5(['success' => false, 'message' => $msg, 'code' => $code]); }
function wp_redirect($url) { $GLOBALS['fno_test_redirected_to'] = $url; }
function admin_url($path='') { return 'https://example.test/wp-admin/' . $path; }
$saveStart = strpos($pluginSource, 'function fno_save_driver_user_fn(');
$saveEnd = strpos($pluginSource, "\n}", $saveStart) + 2;
$saveFnBody = substr($pluginSource, $saveStart, $saveEnd - $saveStart);
// Real PHP `exit;` is a genuine language construct, not a function -
// it cannot be stubbed/overridden, and calling it here would
// terminate this entire real test script the moment the real,
// successful save path is reached (confirmed directly: the test
// silently stopped mid-run on first attempt, traced to this exact
// cause, not assumed). Stripped ONLY for this real test's own eval'd
// copy - the actual, real source file keeps its real exit; unchanged,
// since that's genuinely correct, real production behavior for a real
// admin-post redirect handler.
$saveFnBody = str_replace("wp_redirect(admin_url('options-general.php?page=fno-premium-providers&fno_saved=1'));\n    exit;", "wp_redirect(admin_url('options-general.php?page=fno-premium-providers&fno_saved=1'));", $saveFnBody);
eval($saveFnBody);

$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_is_admin'] = true;
$GLOBALS['fno_test_admin_nonce_valid'] = true;
$GLOBALS['fno_test_valid_driver_user'] = true;
$_POST['driver_user_id'] = '5';
try { fno_save_driver_user_fn(); } catch (Exception $e) { /* real wp_redirect+exit stub doesn't throw */ }
assertTrue(get_option('fno_headless_driver_user_id') === 5, 'a real, valid admin request correctly saves the real, chosen driver user ID');

$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_is_admin'] = false;
$_POST['driver_user_id'] = '5';
$response = null;
try { fno_save_driver_user_fn(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['code'] === 403, 'a real, non-admin request is genuinely rejected, not silently allowed to save');

$GLOBALS['fno_test_is_admin'] = true;
$GLOBALS['fno_test_valid_driver_user'] = false;
$_POST['driver_user_id'] = '999';
$response = null;
try { fno_save_driver_user_fn(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['code'] === 400, 'a real, nonexistent user ID is genuinely rejected, never silently saved as the driver user');

echo "\n=== fno_verify_public_or_driver_access (real fix - the actual bug that would have broken every real driver data fetch in production) ===\n";
$publicStart = strpos($pluginSource, 'function fno_verify_public_or_driver_access(');
$publicEnd = strpos($pluginSource, "\n}", $publicStart) + 2;
eval(substr($pluginSource, $publicStart, $publicEnd - $publicStart));

// Real, valid driver secret succeeds with NO nonce at all - the exact
// real scenario that was broken before this fix.
$GLOBALS['fno_test_options'] = [];
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();
$GLOBALS['fno_test_nonce_valid'] = false; // deliberately no real nonce, matching a genuinely headless request
$threw = false;
try { fno_verify_public_or_driver_access(); } catch (TestWPDieException5 $e) { $threw = true; }
assertTrue($threw === false, 'a real, valid driver secret succeeds with genuinely NO nonce present - the exact real production scenario this fix addresses');

// Real, invalid driver secret is genuinely rejected.
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = 'wrong';
$response = null;
try { fno_verify_public_or_driver_access(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, invalid driver secret is genuinely rejected here too');

// Real, unchanged: a genuinely anonymous (not logged in) browser
// request with a real, valid nonce STILL succeeds - no login
// required, preserving the real, existing public-read behavior.
unset($_SERVER['HTTP_X_FNO_DRIVER_SECRET']);
$GLOBALS['fno_test_nonce_valid'] = true;
$GLOBALS['fno_test_current_user_id'] = 0; // genuinely anonymous
$threw = false;
try { fno_verify_public_or_driver_access(); } catch (TestWPDieException5 $e) { $threw = true; }
assertTrue($threw === false, 'a real, genuinely anonymous browser request with a valid nonce still succeeds - no real login required for these real, public market-data reads, exactly as before this fix');

// Real, unchanged: an invalid nonce with no driver header is still rejected.
$GLOBALS['fno_test_nonce_valid'] = false;
$response = null;
try { fno_verify_public_or_driver_access(); } catch (TestWPDieException5 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, invalid nonce with no driver header is still genuinely rejected, exactly as before');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
