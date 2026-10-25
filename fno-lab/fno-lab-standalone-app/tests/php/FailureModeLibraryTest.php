<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_log_failure_event_fn() / fno_get_failure_stats_fn() - the real,
 * persistent storage half of the Failure Mode Library, closing the
 * user's own explicit audit finding that no such system existed
 * anywhere in this codebase.
 *
 * Run with: php tests/php/FailureModeLibraryTest.php
 */

function get_current_user_id() { return 1; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function fno_verify_public_or_driver_access() { return true; }
function fno_rate_limit($key) { return true; }
class TestWPDieException10 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException10(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException10(['success' => false, 'data' => $data]); }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

class FakeWpdb9 {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $inserts = [];
    private $nextId = 1;
    private $groupResults;
    public function __construct($groupResults = []) { $this->groupResults = $groupResults; }
    public function insert($table, $row) { $this->insert_id = $this->nextId++; $this->inserts[] = ['table' => $table, 'row' => $row]; return true; }
    public function prepare($q, ...$a) { return $q; }
    public function get_results($q, $o = null) {
        if (strpos($q, 'regime_label') !== false) return $this->groupResults['byRegime'] ?? [];
        return $this->groupResults['byCategory'] ?? [];
    }
}

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
// fno_cap_text() (input-size/payload-validation audit) - fno_log_failure_event_fn
// now calls it on the real reason field, so it must be extracted here too.
$capStart = strpos($pluginSource, 'function fno_cap_text(');
$capEnd = strpos($pluginSource, "\n}", $capStart) + 2;
eval(substr($pluginSource, $capStart, $capEnd - $capStart));
$logStart = strpos($pluginSource, 'function fno_log_failure_event_fn(');
$logEnd = strpos($pluginSource, "\n}", $logStart) + 2;
eval(substr($pluginSource, $logStart, $logEnd - $logStart));
$statsStart = strpos($pluginSource, 'function fno_get_failure_stats_fn(');
$statsEnd = strpos($pluginSource, "\n}", $statsStart) + 2;
eval(substr($pluginSource, $statsStart, $statsEnd - $statsStart));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Failure Mode Library - real, persistent storage endpoints ===\n";

global $wpdb;
$wpdb = new FakeWpdb9();

$_POST = ['category' => 'unavailable_data', 'symbol' => 'NIFTY', 'subcategory' => 'VIX Level', 'reason' => 'real reason text', 'regimeLabel' => 'Sideways-Low Vol'];
$response = null;
try { fno_log_failure_event_fn(); } catch (TestWPDieException10 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid failure event log request succeeds');
assertTrue($wpdb->inserts[0]['row']['category'] === 'unavailable_data', 'real category correctly stored');
assertTrue($wpdb->inserts[0]['row']['regime_label'] === 'Sideways-Low Vol', 'real regime label correctly stored');

$_POST = ['category' => 'not_a_real_category'];
$response = null;
try { fno_log_failure_event_fn(); } catch (TestWPDieException10 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, unknown category is genuinely rejected, never silently accepted');

foreach (['unavailable_data', 'bad_signal', 'losing_trade', 'execution_issue'] as $cat) {
    $_POST = ['category' => $cat];
    $threw = false;
    try { fno_log_failure_event_fn(); } catch (TestWPDieException10 $e) { $threw = ($e->responseData['success'] === true); }
    assertTrue($threw, "the real, named category \"$cat\" is genuinely accepted");
}

$wpdb = new FakeWpdb9([
    'byCategory' => [['category' => 'unavailable_data', 'cnt' => 12], ['category' => 'losing_trade', 'cnt' => 5]],
    'byRegime' => [['regime_label' => 'Sideways-Low Vol', 'category' => 'bad_signal', 'cnt' => 8]],
]);
$response = null;
try { fno_get_failure_stats_fn(); } catch (TestWPDieException10 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real stats request succeeds');
assertTrue(count($response['data']['byCategory']) === 2, 'real, per-category aggregate counts returned');
assertTrue(count($response['data']['byRegime']) === 1, 'real, per-regime breakdown returned');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
