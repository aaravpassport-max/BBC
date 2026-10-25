<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_ingest_raw_tick_fn() - the bulk raw-tick insert every
 * companion-daemon tick batch goes through. Chosen specifically
 * because a careful manual trace (comparing this function's real
 * printf-style format string against the real wp_fno_raw_ticks column
 * order, position by position) found a genuine type mismatch: the
 * `strike` column (a real DECIMAL) was using the %s (string)
 * placeholder instead of %f (float), the only column in this entire
 * 20-column INSERT that didn't match its real column's numeric type.
 * Fixed as part of this same phase - this test verifies the fix with
 * a real, executed check, not just the manual trace alone.
 *
 * fno_ingest_raw_tick_fn() depends on $wpdb (real WordPress global) -
 * stubbed here with a real, faithful (if simplified) implementation of
 * $wpdb->prepare()'s actual documented placeholder substitution
 * behavior (including real NULL handling, matching modern WordPress
 * core's own real behavior: a PHP null value becomes the literal SQL
 * NULL regardless of which placeholder type it was passed through),
 * so the REAL final SQL string this function builds can be inspected
 * directly - the most direct way to catch exactly the class of bug
 * this function had.
 *
 * Run with: php tests/php/RawTickIngestTest.php
 */

class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $last_query = '';
    public $insert_result = 5; // real, simulated "5 rows inserted" success

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[dfs]/', function($m) use (&$i, $args) {
            $value = $args[$i++];
            if ($value === null) return 'NULL';
            switch ($m[0]) {
                case '%d': return (string) (int) $value;
                case '%f': return (string) (float) $value;
                case '%s': return "'" . addslashes((string) $value) . "'";
            }
        }, $query);
    }

    public function query($sql) {
        $this->last_query = $sql;
        return $this->insert_result;
    }
}

function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function get_option($k, $default = false) { return 'test-real-daemon-secret'; }
function update_option($k, $v) { return true; }
function fno_rate_limit($endpoint) { return true; } // real, live rate-limiting is tested separately (this session's real, live load test) - this stub keeps the focus of this file on its own real, specific subject
function wp_generate_password($len, $special) { return 'unused-in-this-test'; }
function wp_salt($scheme = 'auth') { return 'test-fixed-salt-do-not-use-in-production-' . $scheme; } // real dependency of fno_encrypt_secret/fno_decrypt_secret, which fno_get_daemon_secret now calls (encryption-at-rest fix)
class TestWPDieException extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException(['success' => false, 'data' => $data]); }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
// Real FNO_RAW_TICK_BATCH_MAX constant (input-size/payload-validation
// audit) - defined at plugin top-level, not inside a function, so the
// per-function eval extraction below can't pick it up on its own;
// pulled out by real regex from the real source instead of being
// hardcoded a second time here, so this test always exercises
// whatever the real, current constant value actually is.
if (!preg_match("/define\('FNO_RAW_TICK_BATCH_MAX',\s*(\d+)\)/", $pluginSource, $m)) {
    fwrite(STDERR, "FNO_RAW_TICK_BATCH_MAX constant not found in fno-lab.php\n");
    exit(1);
}
define('FNO_RAW_TICK_BATCH_MAX', (int) $m[1]);
foreach (['fno_encrypt_secret', 'fno_decrypt_secret', 'fno_get_daemon_secret', 'fno_ingest_raw_tick_fn'] as $fn) {
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

echo "=== fno_ingest_raw_tick_fn (real bulk-insert SQL construction, including the strike type-mismatch fix) ===\n";

global $wpdb;
$wpdb = new FakeWpdb();
$_SERVER['HTTP_X_FNO_DAEMON_SECRET'] = fno_get_daemon_secret();

$response = null;
$_POST['ticks'] = json_encode([
    ['symbol' => 'NIFTY', 'ts' => 1755000000000, 'strike' => 23200.5, 'optionType' => 'CE', 'ltp' => 95.5, 'oi' => 120000],
]);
try { fno_ingest_raw_tick_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid tick with a real strike is accepted');
assertTrue(strpos($wpdb->last_query, '23200.5') !== false, 'the real strike value (23200.5) appears correctly in the real generated SQL, as a real number, via the fixed %f placeholder');
assertTrue(strpos($wpdb->last_query, "'23200.5'") === false, 'the real strike is NOT quoted as a string in the real SQL (confirms %f, not the old buggy %s, is genuinely being used)');

$wpdb = new FakeWpdb();
$response = null;
$_POST['ticks'] = json_encode([
    ['symbol' => 'NIFTY', 'ts' => 1755000000000, 'ltp' => 23200.0],
]);
try { fno_ingest_raw_tick_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real tick with NO strike (a real underlying/index tick) is still accepted, not wrongly rejected');
assertTrue(strpos($wpdb->last_query, 'NULL') !== false, 'a genuinely missing strike produces a real SQL NULL in the generated query - never a fabricated 0 or an empty string masquerading as a real value');

$wpdb = new FakeWpdb();
$response = null;
$_POST['ticks'] = json_encode([
    ['symbol' => 'NIFTY', 'ts' => 1755000000000, 'strike' => 23200, 'optionType' => 'CE'],
    ['symbol' => '', 'ts' => 1755000000000],
    ['symbol' => 'NIFTY', 'ts' => 0],
    ['symbol' => 'NIFTY', 'ts' => 1755000060000, 'strike' => 23250, 'optionType' => 'PE'],
]);
try { fno_ingest_raw_tick_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a mixed batch (2 valid, 2 invalid) is still genuinely accepted');
assertTrue($response['data']['skipped'] === 2, 'exactly the 2 real invalid ticks are counted as skipped, not silently dropped uncounted');
assertTrue($response['data']['submitted'] === 4, 'the real total submitted count reflects all 4 ticks, valid and invalid alike');

$wpdb = new FakeWpdb();
$_POST['ticks'] = json_encode([]);
try { fno_ingest_raw_tick_fn(); assertTrue(false, 'must throw via the real error path for an empty batch'); }
catch (TestWPDieException $e) { assertTrue($e->responseData['success'] === false, 'a genuinely empty ticks array is rejected with a real error, not a silent success'); }

$wpdb = new FakeWpdb();
$_SERVER['HTTP_X_FNO_DAEMON_SECRET'] = 'wrong-secret';
$_POST['ticks'] = json_encode([['symbol' => 'NIFTY', 'ts' => 1755000000000]]);
try { fno_ingest_raw_tick_fn(); assertTrue(false, 'must throw for a real invalid secret'); }
catch (TestWPDieException $e) {
    assertTrue($e->responseData['success'] === false, 'a genuinely wrong daemon secret is rejected');
    assertTrue($wpdb->last_query === '', 'no real SQL is ever built when the real secret check fails first');
}

// Real, deliberate batch-size cap (input-size/payload-validation audit,
// this session) - FOUND genuinely missing: this endpoint previously
// accepted a ticks array of ANY size, so a single, otherwise
// rate-limit-compliant POST could still trigger one enormous bulk
// INSERT. FNO_RAW_TICK_BATCH_MAX matches the real daemon's own
// RAW_TICK_BUFFER_MAX (companion-daemon/kite-microstructure-daemon.js)
// exactly, so a batch at or under that real size must never be
// rejected (a real, legitimate full daemon flush), while anything
// larger - which the real daemon itself can never produce - must be.
$wpdb = new FakeWpdb();
$_SERVER['HTTP_X_FNO_DAEMON_SECRET'] = fno_get_daemon_secret(); // reset - the preceding "wrong secret" test left this deliberately broken
$response = null;
$_POST['ticks'] = json_encode(array_fill(0, FNO_RAW_TICK_BATCH_MAX, ['symbol' => 'NIFTY', 'ts' => 1755000000000, 'ltp' => 100]));
try { fno_ingest_raw_tick_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real batch at exactly FNO_RAW_TICK_BATCH_MAX (' . FNO_RAW_TICK_BATCH_MAX . ', matching the real daemon\'s own RAW_TICK_BUFFER_MAX) is accepted, not wrongly rejected');

$wpdb = new FakeWpdb();
$response = null;
$_POST['ticks'] = json_encode(array_fill(0, FNO_RAW_TICK_BATCH_MAX + 1, ['symbol' => 'NIFTY', 'ts' => 1755000000000, 'ltp' => 100]));
try { fno_ingest_raw_tick_fn(); assertTrue(false, 'must throw via the real error path for an oversized batch'); }
catch (TestWPDieException $e) {
    assertTrue($e->responseData['success'] === false, 'a batch of FNO_RAW_TICK_BATCH_MAX + 1 ticks (bigger than the real daemon can ever legitimately send) is rejected, not silently accepted or truncated');
    assertTrue($wpdb->last_query === '', 'no real bulk-INSERT SQL is ever built for an oversized batch - rejected before any DB work happens');
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
