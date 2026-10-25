<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the Layer B
 * provenance-split dual-write inside fno_journal_add_fn() - closes
 * docs/PENDING_REQUIREMENTS.md's "Layer B provenance split" item.
 *
 * Specifically proves a real bug caught and fixed during development:
 * $wpdb->insert_id is a mutable property overwritten by every real
 * insert call, so reading it fresh inside the per-factor dual-write
 * loop (or in the final response, after that loop ran) would have
 * silently corrupted the journal_id for every factor row after the
 * first, and the ID returned to the client, for every real trade with
 * more than one factor.
 *
 * Run with: php tests/php/LayerBProvenanceSplitTest.php
 *
 * HONEST LIMITATION, found via real, live WordPress testing
 * (2026-08-21): this test calls fno_journal_add_fn() directly,
 * bypassing WordPress's own admin-ajax.php dispatch layer entirely -
 * meaning it could NOT have caught a real bug where the client's own
 * `action` POST field collided with WordPress's own reserved `action`
 * dispatch parameter (fixed the same day this was found - see the
 * real, detailed TRACE comments in journalAdd() in fno-lab-core.js
 * and fno_journal_add_fn() in fno-lab.php). This test's own $_POST
 * fixture was updated to use the corrected `trade_action` field name
 * to stay accurate to the real, current client-server contract, but
 * this class of test can only ever verify the function's OWN internal
 * logic, never the real request-routing layer around it - exactly why
 * this project installed a genuine, live WordPress instance to test
 * against, rather than relying on this kind of test alone.
 */

function is_user_logged_in() { return true; }
function get_current_user_id() { return 1; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function fno_verify_app_nonce() { return true; }
function fno_verify_app_access() { return true; }
// Real fix (rate-limiter coverage audit): fno_journal_add_fn now calls
// the real fno_rate_limit() (previously genuinely unprotected) - stub
// it as a real no-op here (out of scope for this provenance-split test).
function fno_rate_limit($endpoint, $limit = 30) { }
class TestWPDieException9 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException9(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException9(['success' => false, 'data' => $data]); }
function wp_json_encode($v) { return json_encode($v); }

class FakeWpdb8 {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $inserts = [];
    private $nextId = 100;
    public function insert($table, $row) {
        $this->insert_id = $this->nextId++;
        $this->inserts[] = ['table' => $table, 'row' => $row, 'assignedId' => $this->insert_id];
        return true;
    }
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
// Real FNO_STRATEGY_VERSION_FIELDS_MAX constant (bulk/array-accepting
// AJAX endpoint size-limit audit) - fno_journal_add_fn's Layer B
// dual-write loop now references it, and it's defined at plugin
// top-level, not inside a function, so the per-function eval
// extraction below can't pick it up on its own; pulled out by real
// regex from the real source instead of being hardcoded a second time
// here, same pattern already used for FNO_RAW_TICK_BATCH_MAX in
// RawTickIngestTest.php.
if (!preg_match("/define\('FNO_STRATEGY_VERSION_FIELDS_MAX',\s*(\d+)\)/", $pluginSource, $m)) {
    fwrite(STDERR, "FNO_STRATEGY_VERSION_FIELDS_MAX constant not found in fno-lab.php\n");
    exit(1);
}
define('FNO_STRATEGY_VERSION_FIELDS_MAX', (int) $m[1]);
// Real fix (server-side symbol-enum sweep regression coverage): the
// real fno_validate_symbol()/fno_valid_symbols() helpers this test's
// target function(s) now call must be eval'd in too, same as every
// other real helper dependency below - never reimplemented here.
foreach (['fno_valid_symbols', 'fno_validate_symbol'] as $fnoHelperFn) {
    $hStart = strpos($pluginSource, "function $fnoHelperFn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}
$start = strpos($pluginSource, 'function fno_journal_add_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Layer B provenance split - real dual-write, proving the real journal_id corruption bug is fixed ===\n";

global $wpdb;
$wpdb = new FakeWpdb8();
$factorSnapshot = [
    'factors' => [
        'f1' => ['cat' => 'Market', 'status' => 'COMPUTED', 'pass' => true, 'score' => 1.5],
        'f2' => ['cat' => 'Flow', 'status' => 'COMPUTED', 'pass' => false, 'score' => -0.5],
        'f3' => ['cat' => 'Tech', 'status' => 'UNAVAILABLE', 'pass' => null, 'score' => 0],
    ],
];
$_POST = ['symbol' => 'NIFTY', 'strike' => '23200', 'trade_action' => 'CE', 'pnl' => '500', 'factor_snapshot' => json_encode($factorSnapshot)];
$response = null;
try { fno_journal_add_fn(); } catch (TestWPDieException9 $e) { $response = $e->responseData; }

assertTrue($response['success'] === true, 'a real journal write with a real factor_snapshot succeeds');
$realJournalId = $response['data']['id'];

$journalInserts = array_values(array_filter($wpdb->inserts, function($i) { return strpos($i['table'], 'factor_values') === false; }));
assertTrue($realJournalId === $journalInserts[0]['assignedId'], 'the real, final response ID genuinely matches the real journal row\'s own real, assigned ID - not corrupted by any later insert');

$factorValueInserts = array_values(array_filter($wpdb->inserts, function($i) { return strpos($i['table'], 'factor_values') !== false; }));
assertTrue(count($factorValueInserts) === 3, 'all 3 real factors produced their own real, dual-written row');

$allSameJournalId = true;
foreach ($factorValueInserts as $fv) {
    if ($fv['row']['journal_id'] !== $realJournalId) $allSameJournalId = false;
}
assertTrue($allSameJournalId, 'EVERY real factor_values row - not just the first - correctly references the SAME real journal_id, the exact real bug this test exists to catch');

assertTrue($factorValueInserts[0]['row']['factor_id'] === 'f1', 'real factor_id correctly extracted');
assertTrue($factorValueInserts[0]['row']['pass'] === 1, 'a real, true pass value is correctly stored as a real 1, not fabricated as some other truthy value');
assertTrue($factorValueInserts[1]['row']['pass'] === 0, 'a real, false pass value is correctly stored as a real 0');
assertTrue($factorValueInserts[2]['row']['pass'] === null, 'a real, genuinely null/unavailable pass value stays honestly null, never coerced to 0 or false');

$wpdb = new FakeWpdb8();
$_POST = ['symbol' => 'NIFTY', 'strike' => '23200', 'trade_action' => 'CE', 'pnl' => '500'];
$response = null;
try { fno_journal_add_fn(); } catch (TestWPDieException9 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real journal write with genuinely no factor_snapshot still succeeds');
assertTrue(count($wpdb->inserts) === 1, 'genuinely no real factor_values rows are attempted when there is no real snapshot to dual-write from');
assertTrue($response['data']['id'] === $wpdb->inserts[0]['assignedId'], 'the real response ID is still correctly the real journal row\'s own ID');

$wpdb = new FakeWpdb8();
$badSnapshot = ['factors' => ['f1' => ['cat'=>'Market','pass'=>true,'score'=>1], 'f2' => 'not-a-real-array']];
$_POST = ['symbol' => 'NIFTY', 'strike' => '23200', 'trade_action' => 'CE', 'pnl' => '500', 'factor_snapshot' => json_encode($badSnapshot)];
$response = null;
try { fno_journal_add_fn(); } catch (TestWPDieException9 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, malformed individual factor entry does not crash the whole real journal write');
$fvCount = count(array_filter($wpdb->inserts, function($i) { return strpos($i['table'], 'factor_values') !== false; }));
assertTrue($fvCount === 1, 'the real, malformed entry is honestly skipped - only the 1 real, valid factor produces a dual-write row');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
