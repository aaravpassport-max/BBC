<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the real
 * journal WRITE-boundary fixes made during the journal write/read-path
 * audit (fno_journal_add_fn, fno-lab.php):
 *
 *   1. A numeric field that IS sent but is genuinely non-numeric
 *      garbage (e.g. the literal string "NaN", which a NaN JS pnl
 *      value serializes to over x-www-form-urlencoded) must be
 *      honestly REJECTED, never silently cast to a fabricated 0 that
 *      is indistinguishable from a real breakeven trade to every
 *      downstream analytics function.
 *   2. A field that is simply ABSENT keeps its existing, intentional
 *      default (0 for pnl, null for optional fields) - this is NOT
 *      corruption and must be completely unaffected by fix #1.
 *   3. A real idempotency key - mirroring the already-proven
 *      wp_fno_open_positions pattern - must make a genuine retry of
 *      the same logical journal write return the SAME id, never
 *      create a second, duplicate row that would double-count that
 *      trade's pnl in every downstream analytics function.
 *
 * Run with: php tests/php/JournalIntegrityTest.php
 */

class FakeWpdbForJournal {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    private $rows = [];
    private $nextId = 1;
    public function insert($table, $data) {
        // Real, honest simulation of the real DB's UNIQUE KEY
        // user_journal_idempotency (user_id, idempotency_key) - NULL/
        // empty never collides (matching real MySQL/MariaDB
        // NULL-uniqueness semantics), same as FakeWpdbForOpenPositions.
        if (!empty($data['idempotency_key'])) {
            foreach ($this->rows as $r) {
                if (($r['user_id'] ?? null) === $data['user_id'] && ($r['idempotency_key'] ?? null) === $data['idempotency_key']) {
                    $this->last_error = "Duplicate entry '{$data['user_id']}-{$data['idempotency_key']}' for key 'user_journal_idempotency'";
                    return false;
                }
            }
        }
        $data['id'] = $this->nextId;
        $this->rows[$this->nextId] = $data;
        $this->insert_id = $this->nextId;
        $this->nextId++;
        return true;
    }
    public function get_row($query, $output = null) {
        preg_match('/user_id = (\d+)/', $query, $uidMatch);
        $uid = isset($uidMatch[1]) ? (int) $uidMatch[1] : null;
        if (preg_match("/idempotency_key = '([^']*)'/", $query, $keyMatch)) {
            $key = $keyMatch[1];
            foreach ($this->rows as $r) {
                if ($r['user_id'] === $uid && ($r['idempotency_key'] ?? null) === $key) return $r;
            }
            return null;
        }
        return null;
    }
    public function get_results($query, $output = null) { return []; }
    public function prepare($query, ...$args) {
        foreach ($args as $a) { $query = preg_replace('/%[ds]/', is_string($a) ? "'$a'" : $a, $query, 1); }
        return $query;
    }
    public function getRow($id) { return $this->rows[$id] ?? null; }
    public function rowCount() { return count($this->rows); }
}
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
$GLOBALS['fno_test_is_logged_in'] = true;
$GLOBALS['fno_test_user_id'] = 1;
$GLOBALS['wpdb'] = new FakeWpdbForJournal();

function is_user_logged_in() { return $GLOBALS['fno_test_is_logged_in']; }
function get_current_user_id() { return $GLOBALS['fno_test_user_id']; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function get_option($k, $d = false) { return $d; }
function get_userdata($id) { return null; }
function wp_set_current_user($id) { }
function wp_json_encode($v) { return json_encode($v); }
function check_ajax_referer($action, $key, $die = true) { return $GLOBALS['fno_test_nonce_valid'] ?? true; }
class TestWPDieExceptionJournal extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionJournal(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionJournal(['success' => false, 'data' => $data]); }
// Real fix (rate-limiter coverage audit): fno_journal_add_fn now calls
// the real fno_rate_limit() (previously genuinely unprotected) - stub
// it as a real no-op here (this file exercises journal write-boundary
// integrity, not transient-backed rate limiting) so this pre-existing
// test keeps passing under the new call.
function fno_rate_limit($endpoint, $limit = 30) { }

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
$fnStart = strpos($pluginSource, "function fno_journal_add_fn(");
$fnEnd = strpos($pluginSource, "\n}", $fnStart) + 2;
eval(substr($pluginSource, $fnStart, $fnEnd - $fnStart));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function callFn($fn, $params) {
    $_POST = $params; $_GET = $params;
    try { $fn(); return null; }
    catch (TestWPDieExceptionJournal $e) { return $e->responseData; }
}

echo "=== Real journal write-boundary integrity (journal write/read-path audit) ===\n";

// Scenario A: a real, non-numeric pnl (the literal string a NaN JS
// value serializes to over x-www-form-urlencoded) must be honestly
// rejected, never silently cast to a fabricated 0.
$rBadPnl = callFn('fno_journal_add_fn', ['pnl' => 'NaN', 'symbol' => 'NIFTY', 'source' => 'auto_target']);
assertTrue($rBadPnl['success'] === false, 'a genuinely non-numeric pnl ("NaN") is honestly rejected, not silently stored as 0');
assertTrue($GLOBALS['wpdb']->rowCount() === 0, 'the rejected garbage-pnl request creates NO journal row at all');

// Scenario B: same for a plain garbage string.
$rGarbage = callFn('fno_journal_add_fn', ['pnl' => 'not_a_number', 'symbol' => 'NIFTY']);
assertTrue($rGarbage['success'] === false, 'a genuinely non-numeric pnl ("not_a_number") is honestly rejected');

// Scenario C: a genuinely valid negative decimal pnl must still work exactly as before.
$rGoodPnl = callFn('fno_journal_add_fn', ['pnl' => '-450.50', 'symbol' => 'NIFTY', 'source' => 'auto_sl']);
assertTrue($rGoodPnl['success'] === true, 'a real, genuinely valid numeric pnl still succeeds normally');
$goodRow = $GLOBALS['wpdb']->getRow($rGoodPnl['data']['id']);
assertTrue((float) $goodRow['pnl'] === -450.50, 'the real, valid pnl value is stored correctly, unrounded, unmangled');

// Scenario D: pnl simply ABSENT (older client, or a manual entry that never sends one) must keep its existing, intentional 0 default - never treated as "garbage" or rejected.
$rNoPnl = callFn('fno_journal_add_fn', ['symbol' => 'NIFTY', 'source' => 'manual']);
assertTrue($rNoPnl['success'] === true, 'a genuinely ABSENT pnl field is NOT treated as garbage - it keeps its real, intentional default and succeeds');
$noPnlRow = $GLOBALS['wpdb']->getRow($rNoPnl['data']['id']);
assertTrue((float) $noPnlRow['pnl'] === 0.0, 'an absent pnl field correctly, intentionally defaults to 0.0, the pre-existing, unaffected behavior');

// Scenario E: a non-numeric entry_price is rejected too (not just pnl) - the fix is field-wide.
$rBadEntry = callFn('fno_journal_add_fn', ['pnl' => '100', 'entry_price' => 'undefined', 'symbol' => 'NIFTY']);
assertTrue($rBadEntry['success'] === false, 'a genuinely non-numeric entry_price is also honestly rejected, matching pnl');

echo "\n=== Real idempotency-key protection (duplicate-write / retry audit) ===\n";

// Scenario F: a real, first journal write with an idempotency key succeeds normally.
$rIdem1 = callFn('fno_journal_add_fn', ['pnl' => '250', 'symbol' => 'NIFTY', 'source' => 'auto_target', 'idempotencyKey' => 'journal-test-key-xyz']);
assertTrue($rIdem1['success'] === true, 'a real, first journal write WITH an idempotency key succeeds normally');
$idemFirstId = $rIdem1['data']['id'];
assertTrue(empty($rIdem1['data']['idempotentReplay']), 'a genuine, first-time journal write is never marked as an idempotent replay');

// Scenario G: a real, genuine RETRY (same key, same user, e.g. the autonomous-driver's enqueueRetry re-attempting an ack-lost write) must return the SAME id, never create a second row.
$rIdem2 = callFn('fno_journal_add_fn', ['pnl' => '250', 'symbol' => 'NIFTY', 'source' => 'auto_target', 'idempotencyKey' => 'journal-test-key-xyz']);
assertTrue($rIdem2['success'] === true, 'a real, genuine retry with the SAME idempotency key still succeeds, never a confusing error');
assertTrue($rIdem2['data']['id'] === $idemFirstId, 'a real, genuine retry returns the EXACT SAME journal id - no duplicate row, no double-counted pnl');
assertTrue($rIdem2['data']['idempotentReplay'] === true, 'a real, genuine retry is honestly, explicitly flagged as an idempotent replay');

$rowCountAfterRetry = 0;
for ($i = 1; $i <= 20; $i++) { $r = $GLOBALS['wpdb']->getRow($i); if ($r && ($r['idempotency_key'] ?? null) === 'journal-test-key-xyz') $rowCountAfterRetry++; }
assertTrue($rowCountAfterRetry === 1, 'exactly ONE real journal row exists for this idempotency key after the retry - the pnl for this one real trade is not double-counted');

// Scenario H: a DIFFERENT idempotency key for the same user (a genuinely different trade close) must create a genuinely separate, new row.
$rIdem3 = callFn('fno_journal_add_fn', ['pnl' => '-80', 'symbol' => 'NIFTY', 'source' => 'auto_sl', 'idempotencyKey' => 'journal-test-key-DIFFERENT']);
assertTrue($rIdem3['success'] === true && $rIdem3['data']['id'] !== $idemFirstId, 'a genuinely DIFFERENT idempotency key correctly creates a real, separate, new journal row - the key does not over-block legitimate distinct trades');

// Scenario I: no idempotency key at all (the existing browser UI's exact, current behavior) must be completely unaffected - inserts normally every time, never blocked, never deduped.
$rNoKey1 = callFn('fno_journal_add_fn', ['pnl' => '10', 'symbol' => 'BANKNIFTY']);
$rNoKey2 = callFn('fno_journal_add_fn', ['pnl' => '10', 'symbol' => 'BANKNIFTY']);
assertTrue($rNoKey1['success'] === true && $rNoKey2['success'] === true && $rNoKey1['data']['id'] !== $rNoKey2['data']['id'],
  'two real journal writes with NO idempotency key at all (the existing browser UI, unchanged) genuinely create two separate rows every time - this fix is purely additive');

// Scenario J: regression test for the real, historical
// autonomous-driver bug (pre-fix, autonomous-driver.js sent `entry`/
// `exit` instead of `entry_price`/`exit_price`) - simulates exactly
// that buggy POST body (a real, valid pnl present, but no
// entry_price/exit_price keys at all, since fno_journal_add_fn never
// reads `entry`/`exit`). Verifies, with real evidence, the exact
// blast radius documented in autonomous-driver/README.md: the row is
// still accepted (not rejected), pnl is stored correctly and
// unaffected, and entry_price/exit_price land as NULL rather than
// being silently fabricated as some other value.
$rBuggyDriverShape = callFn('fno_journal_add_fn', [
    'pnl' => '325.75', 'symbol' => 'NIFTY', 'source' => 'auto_target',
    'entry' => '150', 'exit' => '175', // the real, historical bug: wrong field names, never read by this function
]);
assertTrue($rBuggyDriverShape['success'] === true, 'a pre-fix-shaped driver request (entry/exit instead of entry_price/exit_price) is still accepted, not rejected - matches the real DB schema allowing NULL entry_price/exit_price');
$buggyDriverRow = $GLOBALS['wpdb']->getRow((int) $rBuggyDriverShape['data']['id']);
assertTrue((float) $buggyDriverRow['pnl'] === 325.75, 'pnl is stored correctly and completely unaffected by the entry/exit field-name bug - the driver computes pnl itself and sends it independently of entry_price/exit_price');
assertTrue($buggyDriverRow['entry_price'] === null, 'entry_price is genuinely NULL for a pre-fix-shaped request (real, verified blast radius: only this display/audit column is empty)');
assertTrue($buggyDriverRow['exit_price'] === null, 'exit_price is genuinely NULL for a pre-fix-shaped request (real, verified blast radius: only this display/audit column is empty)');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
