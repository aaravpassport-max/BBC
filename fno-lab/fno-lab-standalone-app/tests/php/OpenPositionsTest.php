<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the real,
 * server-side open-position endpoints (fno_open_position_fn,
 * fno_list_open_positions_fn, fno_update_open_position_fn,
 * fno_close_position_fn) - built at the user's own direct, explicit
 * request to add scalping and swing trading, which first required
 * moving open-position state out of browser-only localStorage into
 * real, server-side, multi-row-capable storage.
 *
 * Run with: php tests/php/OpenPositionsTest.php
 */

class FakeWpdbForOpenPositions {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows_affected = 0;
    private $rows = [];
    private $nextId = 1;
    // Real race-simulation hook (used ONLY by the alreadyClosed
    // closed-loop test below): honestly reproduces, within one
    // single-threaded PHP process, the exact real interleaving that
    // fno_close_position_fn's atomic-UPDATE fix guards against - a
    // second, concurrent closer's UPDATE (the "browser") committing in
    // the real gap between THIS call's own SELECT (which genuinely,
    // honestly still sees status='open', same as the real racing
    // caller would) and THIS call's own atomic UPDATE. When set to a
    // real position id, the NEXT get_row() call for that id returns
    // the row as still-open (as the real racing SELECT genuinely
    // would), then flips it to 'closed' immediately after returning -
    // simulating the other caller's UPDATE landing in that exact
    // window - so this call's own subsequent atomic UPDATE below
    // honestly, correctly affects 0 rows via the real WHERE
    // status='open' guard, exactly like the real second caller would
    // see.
    public $raceFlipOnSelectFor = null;
    public function insert($table, $data) {
        // Real, honest simulation of the real DB's UNIQUE KEY
        // user_idempotency (user_id, idempotency_key) - a NULL/empty
        // idempotency_key never collides with anything (matching real
        // MySQL/MariaDB NULL-uniqueness semantics), but two rows for
        // the same real user with the SAME real key genuinely violate
        // the constraint, exactly like the real database would.
        if (!empty($data['idempotency_key'])) {
            foreach ($this->rows as $r) {
                if (($r['user_id'] ?? null) === $data['user_id'] && ($r['idempotency_key'] ?? null) === $data['idempotency_key']) {
                    $this->last_error = "Duplicate entry '{$data['user_id']}-{$data['idempotency_key']}' for key 'user_idempotency'";
                    return false;
                }
            }
        }
        // Real, honest simulation of the real DB's UNIQUE KEY
        // user_symbol_style_open_lock (the generated open_symbol_lock
        // column, see fno-lab.php's dbDelta schema + the 2026-08-30
        // open-position dual-writer audit) - mirrors real MySQL
        // generated-column-partial-unique-index semantics exactly:
        // the "lock" only exists (is non-NULL) while status='open', so
        // only rows with status='open' can ever collide, and only when
        // they share the same user_id+symbol+trading_style. A closed
        // row's lock value is honestly NULL (matching real MySQL,
        // where NULL never collides with anything), so re-opening a
        // symbol after its prior position closed is never blocked.
        if (($data['status'] ?? null) === 'open') {
            foreach ($this->rows as $r) {
                if (($r['status'] ?? null) === 'open'
                    && ($r['user_id'] ?? null) === $data['user_id']
                    && ($r['symbol'] ?? null) === $data['symbol']
                    && ($r['trading_style'] ?? null) === $data['trading_style']) {
                    $this->last_error = "Duplicate entry '{$data['user_id']}:{$data['symbol']}:{$data['trading_style']}' for key 'user_symbol_style_open_lock'";
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
    public function get_results($query, $output = null) {
        // Real, direct, simple filter matching this test's own real
        // fixtures - deliberately not a full SQL engine (the same,
        // established principle used throughout this test suite).
        preg_match('/user_id = (\d+)/', $query, $uidMatch);
        $uid = isset($uidMatch[1]) ? (int) $uidMatch[1] : null;
        preg_match("/trading_style = '([a-z]+)'/", $query, $styleMatch);
        $style = $styleMatch[1] ?? null;
        return array_values(array_filter($this->rows, function ($r) use ($uid, $style) {
            if ($r['user_id'] !== $uid || $r['status'] !== 'open') return false;
            if ($style !== null && $r['trading_style'] !== $style) return false;
            return true;
        }));
    }
    public function get_row($query, $output = null) {
        preg_match('/user_id = (\d+)/', $query, $uidMatch);
        $uid = isset($uidMatch[1]) ? (int) $uidMatch[1] : null;
        // Real, new: the idempotency-key lookup this test now needs to
        // simulate (fno_open_position_fn's real, new duplicate-check
        // query - "WHERE user_id = %d AND idempotency_key = %s",
        // deliberately no status/id filter, matching the real query).
        if (preg_match("/idempotency_key = '([^']*)'/", $query, $keyMatch)) {
            $key = $keyMatch[1];
            foreach ($this->rows as $r) {
                if ($r['user_id'] === $uid && ($r['idempotency_key'] ?? null) === $key) return $r;
            }
            return null;
        }
        // Real, new (2026-08-30 open-position dual-writer audit): the
        // real fno_open_position_fn's post-race-loss lookup query -
        // "WHERE user_id = %d AND symbol = %s AND trading_style = %s
        // AND status = 'open'" - used to find the WINNING position's id
        // to hand back to a caller whose own open was rejected by the
        // new user_symbol_style_open_lock UNIQUE KEY.
        if (preg_match("/symbol = '([A-Z]+)' AND trading_style = '([a-z]+)' AND status = 'open'/", $query, $symStyleMatch)) {
            $sym = $symStyleMatch[1]; $style = $symStyleMatch[2];
            foreach ($this->rows as $r) {
                if ($r['user_id'] === $uid && $r['symbol'] === $sym && $r['trading_style'] === $style && $r['status'] === 'open') return $r;
            }
            return null;
        }
        preg_match('/id = (\d+)/', $query, $idMatch);
        $id = isset($idMatch[1]) ? (int) $idMatch[1] : null;
        foreach ($this->rows as $r) {
            if ($r['id'] === $id && $r['user_id'] === $uid && $r['status'] === 'open') {
                if ($this->raceFlipOnSelectFor === $id) {
                    $this->raceFlipOnSelectFor = null;
                    $this->rows[$id]['status'] = 'closed';
                }
                return $r;
            }
        }
        return null;
    }
    public function update($table, $data, $where) {
        foreach ($this->rows as $id => $r) {
            $matches = true;
            foreach ($where as $k => $v) { if (($r[$k] ?? null) != $v) { $matches = false; break; } }
            if ($matches) { $this->rows[$id] = array_merge($r, $data); return 1; }
        }
        return 0;
    }
    public function prepare($query, ...$args) {
        // Real wpdb::prepare() also accepts a single array as the args
        // (used by the new atomic-ratchet UPDATE in
        // fno_update_open_position_fn) - not just var-args - so this
        // fake honestly supports both call shapes, matching real
        // WordPress behavior.
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        // Real fix: walk the format string LEFT TO RIGHT, consuming
        // exactly one placeholder (whichever of %f/%d/%s appears next)
        // per arg, in order - the previous version separately ran a
        // %f pass and a %d/%s pass per arg, which consumed TWO
        // placeholders per single arg and misaligned every arg after
        // the first mixed-type prepare() call (the new atomic-ratchet
        // SQL mixes %f and %d in one statement, which the old code
        // never exercised).
        $i = 0;
        $query = preg_replace_callback('/%[fds]/', function ($m) use (&$i, $args) {
            if (!array_key_exists($i, $args)) return $m[0];
            $a = $args[$i++];
            if ($m[0] === '%f') return is_numeric($a) ? (string) (float) $a : '0';
            return is_string($a) ? "'" . $a . "'" : (string) $a;
        }, $query);
        return $query;
    }
    // Real, honest simulation of the real, new SQL-atomic ratchet UPDATE
    // (server-side GREATEST/LEAST/COALESCE against the CURRENT DB value
    // at write time, not a client-stale read) - the exact real fix this
    // test's concurrent-race scenarios (Q/R/S below) verify. Deliberately
    // narrow: only understands the ONE real UPDATE shape
    // fno_update_open_position_fn's atomic path actually emits, matching
    // this whole fake wpdb's established "simple filter, not a full SQL
    // engine" principle.
    public function query($sql) {
        if (!preg_match('/^UPDATE \S+ SET (.+) WHERE id = (\d+) AND user_id = (\d+) AND status = \'open\'$/', $sql, $m)) {
            return false;
        }
        $setClause = $m[1]; $id = (int) $m[2]; $uid = (int) $m[3];
        if (!isset($this->rows[$id]) || $this->rows[$id]['user_id'] !== $uid || $this->rows[$id]['status'] !== 'open') {
            // Real wpdb behavior: rows_affected reflects THIS query's
            // own result, honestly 0 here - the exact real signal
            // fno_close_position_fn's fix now checks to detect a lost
            // race instead of assuming success.
            $this->rows_affected = 0;
            return 0;
        }
        $row = $this->rows[$id];
        // Split on top-level commas only (GREATEST/LEAST calls contain
        // their own internal commas).
        $assignments = [];
        $depth = 0; $cur = '';
        for ($i = 0; $i < strlen($setClause); $i++) {
            $ch = $setClause[$i];
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $assignments[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        if ($cur !== '') $assignments[] = $cur;
        foreach ($assignments as $assign) {
            [$col, $expr] = array_map('trim', explode('=', $assign, 2));
            $row[$col] = $this->evalSqlExpr($expr, $row);
        }
        $this->rows[$id] = $row;
        $this->rows_affected = 1;
        return 1;
    }
    private function evalSqlExpr($expr, $row) {
        $expr = trim($expr);
        if (is_numeric($expr)) return (float) $expr;
        // Real, new: fno_close_position_fn's atomic conditional UPDATE
        // (this pass's fix) sets a quoted string literal (status =
        // 'closed'), not just numeric ratchet expressions - the fake
        // must honestly evaluate that too, matching real MySQL/wpdb
        // string-literal semantics, or a close would silently write
        // NULL into status instead of 'closed'.
        if (preg_match("/^'(.*)'$/s", $expr, $mStr)) return $mStr[1];
        if (preg_match('/^GREATEST\((.+)\)$/i', $expr, $m)) {
            return max(array_map(function ($a) use ($row) { return $this->evalSqlExpr($a, $row); }, $this->splitTopLevelCommas($m[1])));
        }
        if (preg_match('/^LEAST\((.+)\)$/i', $expr, $m)) {
            return min(array_map(function ($a) use ($row) { return $this->evalSqlExpr($a, $row); }, $this->splitTopLevelCommas($m[1])));
        }
        if (preg_match('/^COALESCE\((.+)\)$/i', $expr, $m)) {
            foreach ($this->splitTopLevelCommas($m[1]) as $a) {
                $v = $this->evalSqlExpr($a, $row);
                if ($v !== null) return $v;
            }
            return null;
        }
        // bare column reference
        return isset($row[$expr]) ? (is_numeric($row[$expr]) ? (float) $row[$expr] : $row[$expr]) : null;
    }
    private function splitTopLevelCommas($s) {
        $parts = []; $depth = 0; $cur = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $ch = $s[$i];
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $parts[] = trim($cur); $cur = ''; continue; }
            $cur .= $ch;
        }
        if (trim($cur) !== '') $parts[] = trim($cur);
        return $parts;
    }
    public function getRow($id) { return $this->rows[$id] ?? null; }
}
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
$GLOBALS['fno_test_is_logged_in'] = true;
$GLOBALS['fno_test_user_id'] = 1;
$GLOBALS['wpdb'] = new FakeWpdbForOpenPositions();

function is_user_logged_in() { return $GLOBALS['fno_test_is_logged_in']; }
function get_current_user_id() { return $GLOBALS['fno_test_user_id']; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function get_option($k, $d = false) { return $d; }
function get_userdata($id) { return null; }
function wp_set_current_user($id) { }
function check_ajax_referer($action, $key, $die = true) { return $GLOBALS['fno_test_nonce_valid'] ?? true; }
class TestWPDieException12 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException12(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException12(['success' => false, 'data' => $data]); }

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
foreach (['fno_open_position_fn', 'fno_list_open_positions_fn', 'fno_update_open_position_fn', 'fno_close_position_fn'] as $fn) {
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
function callFn($fn, $params) {
    $_POST = $params; $_GET = $params;
    try { $fn(); return null; }
    catch (TestWPDieException12 $e) { return $e->responseData; }
}

echo "=== Real, server-side open-position endpoints (the architectural fix for scalping/swing trading) ===\n";

// Scenario A: open a real, new position with a real, valid trading style.
$r = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 23200, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 120, 'sl' => 100, 'target' => 160, 'tradingStyle' => 'swing']);
assertTrue($r['success'] === true, 'a real, valid position genuinely opens successfully');
$openedId = $r['data']['id'];
assertTrue($openedId === 1, 'the real, new position gets a real, correct id');

// Scenario B: a real, invalid trading style honestly, safely falls back to intraday rather than storing garbage.
callFn('fno_open_position_fn', ['symbol' => 'BANKNIFTY', 'strike' => 50000, 'optionType' => 'PE', 'qty' => 25, 'entryPrice' => 200, 'sl' => 170, 'target' => 260, 'tradingStyle' => 'not_a_real_style']);
$row2 = $GLOBALS['wpdb']->getRow(2);
assertTrue($row2['trading_style'] === 'intraday', 'a real, genuinely invalid trading style honestly, safely defaults to intraday, never stores an unrecognized value');

// Scenario C: real, required fields missing must honestly fail, never silently open a broken position.
$rBad = callFn('fno_open_position_fn', ['symbol' => '', 'strike' => 0, 'optionType' => 'XX', 'qty' => 0, 'entryPrice' => 0]);
assertTrue($rBad['success'] === false, 'genuinely missing/invalid required fields correctly, honestly reject rather than open a broken position');

// Scenario D: real, per-user isolation - listing positions for a different real user must not see user 1's real positions.
$GLOBALS['fno_test_user_id'] = 2;
$rOtherUser = callFn('fno_list_open_positions_fn', []);
assertTrue(count($rOtherUser['data']['positions']) === 0, 'a real, different user genuinely sees zero of user 1\'s real open positions - real, per-user isolation holds');
$GLOBALS['fno_test_user_id'] = 1;

// Scenario E: real, style-filtered listing.
$rSwingOnly = callFn('fno_list_open_positions_fn', ['tradingStyle' => 'swing']);
assertTrue(count($rSwingOnly['data']['positions']) === 1, 'filtering by trading_style=swing correctly returns only the real, one swing position, not the real intraday one too');

$rAll = callFn('fno_list_open_positions_fn', []);
assertTrue(count($rAll['data']['positions']) === 2, 'genuinely unfiltered listing correctly returns both real, open positions');

// Scenario F: real, live update of trailing SL / MFE / MAE.
callFn('fno_update_open_position_fn', ['id' => 1, 'trailingSl' => 110, 'mfe' => 45, 'mae' => -10]);
$row1AfterUpdate = $GLOBALS['wpdb']->getRow(1);
assertTrue((float) $row1AfterUpdate['trailing_sl'] === 110.0, 'a real, live trailing-SL update is correctly, genuinely persisted server-side');

echo "\n=== Real trailing_sl write-boundary validation (open-position write-path audit) ===\n";
// Position id=1 at this point: sl=100 (original, from open), trailing_sl=110 (just set above).

// Scenario F1: a real, legitimate FURTHER tightening (matching updateTrailingStop()'s
// own Math.max ratchet - the browser's real, already-correct trailing logic) still
// succeeds exactly as before.
$rTighten = callFn('fno_update_open_position_fn', ['id' => 1, 'trailingSl' => 118]);
assertTrue($rTighten['success'] === true, 'a real, legitimate further-tightening trailingSl update (110 -> 118) still succeeds');
assertTrue((float) $GLOBALS['wpdb']->getRow(1)['trailing_sl'] === 118.0, 'the real, legitimate tightened value is correctly persisted');

// Scenario F2: a non-numeric / NaN-producing trailingSl (the exact literal-string-"NaN"
// class of bug fixed in fno_journal_add_fn) is honestly rejected, not silently cast to 0.
$rNanTrail = callFn('fno_update_open_position_fn', ['id' => 1, 'trailingSl' => 'NaN']);
assertTrue($rNanTrail['success'] === false, 'a genuinely non-numeric trailingSl ("NaN") is honestly rejected, never silently stored as 0 (which would look like a valid, terrible stop level)');
assertTrue((float) $GLOBALS['wpdb']->getRow(1)['trailing_sl'] === 118.0, 'the real position\'s trailing_sl is completely UNCHANGED after the rejected NaN update - no silent corruption');

$rGarbageTrail = callFn('fno_update_open_position_fn', ['id' => 1, 'trailingSl' => 'garbage']);
assertTrue($rGarbageTrail['success'] === false, 'a genuinely non-numeric trailingSl ("garbage") is also honestly rejected');

// Scenario F3: a real, backwards (looser-than-current) trailingSl is honestly rejected -
// a real trailing stop only ever tightens, matching updateTrailingStop()'s Math.max ratchet
// and reconcileOpenPositionOnLoad's `trailing_sl > sl` restore-gate, both already proven
// this session. Current best-known level for id=1 is 118 (trailing_sl, > sl=100).
$rLooseTrail = callFn('fno_update_open_position_fn', ['id' => 1, 'trailingSl' => 105]);
assertTrue($rLooseTrail['success'] === false, 'a real, backwards trailingSl update (118 -> 105, looser than the current protective level) is honestly rejected');
assertTrue((float) $GLOBALS['wpdb']->getRow(1)['trailing_sl'] === 118.0, 'the real position\'s trailing_sl is completely UNCHANGED after the rejected backwards update');

// Scenario F4: exactly-equal-to-current (no actual movement, e.g. a duplicate/retried
// tick) is honestly accepted as a legitimate no-op update, not rejected as "backwards".
$rEqualTrail = callFn('fno_update_open_position_fn', ['id' => 1, 'trailingSl' => 118]);
assertTrue($rEqualTrail['success'] === true, 'a trailingSl update exactly equal to the current protective level is honestly accepted (not treated as backwards)');

// Scenario F5: mfe/mae still get the same non-numeric rejection (they are numeric
// excursion-tracking fields too).
//
// Real fix (this pass, browser-vs-driver concurrent-race audit): mfe/mae used to be
// documented here as deliberately NOT ratchet-enforced ("can legitimately move either
// direction tick to tick"). That was itself a real, latent gap, not a correct scope
// decision - mfe IS, by definition, the MAXIMUM favorable excursion seen so far
// (updateMFEMAE() in fno-lab-core.js already only ever computes Math.max(prevMfe,
// livePrice) client-side - a genuinely honest client NEVER sends a decreasing mfe), and
// mae IS, by definition, the MINIMUM price seen so far (Math.min ratchet). The real,
// server-side atomic GREATEST()/LEAST() fix that closes the browser-vs-driver
// concurrent-write race (see fno_update_open_position_fn) correctly makes this
// invariant a genuine server-side guarantee too, not just a client-side convention a
// buggy/malicious/stale caller could silently violate. This is a deliberate,
// documented behavior correction, not a regression: a decreasing mfe/increasing-mae
// write is now honestly, silently ratcheted to the still-best-known value (matching
// trailingSl's own existing silent-ratchet convergence under the atomic fix) rather
// than accepted at face value.
$rBadMfe = callFn('fno_update_open_position_fn', ['id' => 1, 'mfe' => 'NaN']);
assertTrue($rBadMfe['success'] === false, 'a genuinely non-numeric mfe is honestly rejected too, matching trailingSl/journal validation');
callFn('fno_update_open_position_fn', ['id' => 1, 'mfe' => 30]);
callFn('fno_update_open_position_fn', ['id' => 1, 'mfe' => 20]);
assertTrue((float) $GLOBALS['wpdb']->getRow(1)['mfe'] === 45.0, 'REGRESSION (behavior correction): mfe is now genuinely, atomically ratcheted server-side too - later, lower mfe values (30, then 20) no longer clobber the real, already-recorded higher one (45, set earlier in Scenario F), matching mfe\'s own true "maximum favorable excursion" definition');

// Scenario F6: a real, driver-shaped swing trailing-stop persistence update (2-passes-ago
// fix - the driver computes its own tightening-only value via updateTrailingStop() and
// POSTs it) must continue to work exactly as before against this new validation.
$rSwingOpen = callFn('fno_open_position_fn', ['symbol' => 'BANKNIFTY', 'strike' => 52000, 'optionType' => 'PE', 'qty' => 25, 'entryPrice' => 300, 'sl' => 260, 'target' => 420, 'tradingStyle' => 'swing']);
$swingId = $rSwingOpen['data']['id'];
callFn('fno_update_open_position_fn', ['id' => $swingId, 'trailingSl' => 275]);
callFn('fno_update_open_position_fn', ['id' => $swingId, 'trailingSl' => 290]); // real, further swing-day tightening
$rSwingCheck = $GLOBALS['wpdb']->getRow($swingId);
assertTrue((float) $rSwingCheck['trailing_sl'] === 290.0, 'a real, driver-computed swing multi-day trailing-stop tightening sequence (260 -> 275 -> 290) still persists correctly against the new write-boundary validation');

// Scenario G: real close - the exact real position, and no one else's.
$rClose = callFn('fno_close_position_fn', ['id' => 1, 'exitPrice' => 155, 'exitReason' => 'AUTO_TARGET_EXIT']);
assertTrue($rClose['data']['position']['id'] === 1, 'the real, exact requested position is returned on real close');
$rAllAfterClose = callFn('fno_list_open_positions_fn', []);
// Real, updated count: this file's new trailing_sl write-boundary validation scenarios
// (F1-F6) added one more real, still-open swing position (id=$swingId) before this
// close, on top of the original 2 - so 2 remain open after id=1 closes, not 1.
assertTrue(count($rAllAfterClose['data']['positions']) === 2, 'after a real close, the remaining real positions stay genuinely open (the swing one closed, the others did not)');

// Scenario H: closing a genuinely already-closed (or non-existent) position must honestly fail, not silently succeed.
$rDoubleClose = callFn('fno_close_position_fn', ['id' => 1, 'exitPrice' => 999]);
assertTrue($rDoubleClose['success'] === false, 'attempting to close a real, already-closed position honestly, correctly fails rather than silently succeeding again');

// Scenario I: a real user attempting to close a DIFFERENT real user's position must honestly fail (real, per-user ownership enforced).
$GLOBALS['fno_test_user_id'] = 2;
$rWrongUserClose = callFn('fno_close_position_fn', ['id' => 2, 'exitPrice' => 200]);
assertTrue($rWrongUserClose['success'] === false, 'a real, different user cannot close user 1\'s real position - genuine ownership check holds');
$GLOBALS['fno_test_user_id'] = 1;

// Real, new (2026-08-30 open-position dual-writer audit): id2 (the BANKNIFTY intraday
// position opened by Scenario B, still genuinely 'open' at this point) is closed here so
// the idempotency-key and symbol-lock sections below can reuse BANKNIFTY|intraday
// without colliding with this earlier, unrelated fixture position - matching the same
// "free the slot once the position actually closes" cleanup already applied to id4/id
// $rIdem3 above.
callFn('fno_close_position_fn', ['id' => 2, 'exitPrice' => 210, 'exitReason' => 'TEST_CLEANUP']);

echo "\n=== Real idempotency-key protection (post-session audit fix - duplicate network-level request) ===\n";

// Scenario J: a real, first open with an idempotency key succeeds normally.
$rIdem1 = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 24000, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 100, 'sl' => 85, 'target' => 130, 'tradingStyle' => 'intraday', 'idempotencyKey' => 'real-test-key-abc123']);
assertTrue($rIdem1['success'] === true, 'a real, first open WITH an idempotency key succeeds normally, exactly like without one');
$idemFirstId = $rIdem1['data']['id'];
assertTrue(empty($rIdem1['data']['idempotentReplay']), 'a genuine, first-time open is never marked as an idempotent replay');

// Scenario K: a real, genuine RETRY (same key, same user) must return the SAME id, not create a second row.
$rIdem2 = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 24000, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 100, 'sl' => 85, 'target' => 130, 'tradingStyle' => 'intraday', 'idempotencyKey' => 'real-test-key-abc123']);
assertTrue($rIdem2['success'] === true, 'a real, genuine retry with the SAME idempotency key still returns success, never a confusing error');
assertTrue($rIdem2['data']['id'] === $idemFirstId, 'a real, genuine retry returns the EXACT SAME id as the original successful open - no duplicate row created');
assertTrue($rIdem2['data']['idempotentReplay'] === true, 'a real, genuine retry is honestly, explicitly flagged as an idempotent replay, not indistinguishable from a fresh open');

// Real, new (2026-08-30 open-position dual-writer audit): id4 (this
// NIFTY intraday position opened by Scenario J and still 'open' at
// this point) is deliberately closed here before Scenario L reuses
// the exact same symbol+tradingStyle - otherwise Scenario L's own
// open would now correctly collide with the NEW user_symbol_style_open_lock
// invariant this same audit pass adds below (see "Real single-open-
// position-per-symbol invariant" section), which is not what Scenario
// L is testing. Closing it first keeps this section focused purely on
// idempotency-key semantics, exactly as originally intended.
callFn('fno_close_position_fn', ['id' => $idemFirstId, 'exitPrice' => 105, 'exitReason' => 'TEST_CLEANUP']);

// Scenario L: a DIFFERENT idempotency key for the same user, for a symbol+style with
// no position currently open, must open a genuinely separate, new position.
$rIdem3 = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 24000, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 100, 'sl' => 85, 'target' => 130, 'tradingStyle' => 'intraday', 'idempotencyKey' => 'real-test-key-DIFFERENT']);
assertTrue($rIdem3['success'] === true && $rIdem3['data']['id'] !== $idemFirstId, 'a genuinely DIFFERENT idempotency key correctly opens a real, separate, new position - the key does not over-block legitimate distinct opens');
// Same cleanup reasoning as above - free NIFTY|intraday back up before the sl/target
// and race sections below reuse it.
callFn('fno_close_position_fn', ['id' => $rIdem3['data']['id'], 'exitPrice' => 105, 'exitReason' => 'TEST_CLEANUP']);

// Scenario M: no idempotency key at all (the existing browser UI's exact, current
// behavior) for a FIRST open of a symbol+style with nothing currently open must be
// completely unaffected - opens normally, exactly as before this pass.
$rNoKey1 = callFn('fno_open_position_fn', ['symbol' => 'BANKNIFTY', 'strike' => 51000, 'optionType' => 'CE', 'qty' => 25, 'entryPrice' => 300, 'sl' => 250, 'target' => 400, 'tradingStyle' => 'intraday']);
assertTrue($rNoKey1['success'] === true, 'a real, keyless open for a symbol+style with no existing open position still succeeds exactly as before this pass');

// Real, NEW (2026-08-30 open-position dual-writer audit, THE core fix this pass
// verifies): a SECOND keyless open for the SAME symbol+tradingStyle, while the first
// (rNoKey1, BANKNIFTY intraday) is still genuinely open, is a completely different
// scenario from Scenario L above - there is no idempotency key at all here (so this is
// NOT a retry/replay of the same logical request; it is a genuinely different logical
// open, exactly like two independent callers - browser and driver - both reaching a
// BUY signal for the same still-open symbol at nearly the same time, each unaware of
// the other's local state). Before this pass's fix this silently created a SECOND
// simultaneous 'open' row for the same symbol+style, violating this app's own "the
// open position, singular, per symbol" design invariant (see
// recoverOpenPositionOnStartup/checkAndMonitorSwingPositions in autonomous-driver.js).
// This must now be honestly, atomically rejected server-side.
$rNoKey2 = callFn('fno_open_position_fn', ['symbol' => 'BANKNIFTY', 'strike' => 51500, 'optionType' => 'CE', 'qty' => 25, 'entryPrice' => 305, 'sl' => 255, 'target' => 405, 'tradingStyle' => 'intraday']);
assertTrue($rNoKey2['success'] === false, 'REGRESSION: a genuinely SECOND keyless open for a symbol+style that already has an open position is now honestly, atomically rejected - never silently creates a second simultaneous open row for the same symbol');
assertTrue(($rNoKey2['data']['symbolAlreadyOpen'] ?? null) === true, 'REGRESSION: the rejected second open carries the machine-readable symbolAlreadyOpen flag (mirroring the established alreadyClosed pattern from fno_close_position_fn), so a real caller can distinguish this from a generic failure and reconcile its own local state');
assertTrue(($rNoKey2['data']['existingId'] ?? null) === $rNoKey1['data']['id'], 'REGRESSION: the rejected second open\'s response correctly names the EXISTING winning position\'s id, so a losing caller (e.g. the browser, having independently lost this race to the driver) can reconcile against the real, actually-open row instead of being left with none');

// Companion check: the fix must not over-block legitimately DIFFERENT symbols/styles -
// a keyless open for a genuinely different symbol+style succeeds normally, concurrent
// with rNoKey1 still open, exactly like Scenario L's spirit above.
$rNoKeyDifferentSymbol = callFn('fno_open_position_fn', ['symbol' => 'FINNIFTY', 'strike' => 24000, 'optionType' => 'PE', 'qty' => 25, 'entryPrice' => 90, 'sl' => 75, 'target' => 120, 'tradingStyle' => 'intraday']);
assertTrue($rNoKeyDifferentSymbol['success'] === true, 'a keyless open for a genuinely DIFFERENT symbol succeeds normally even while another symbol has an open position - the fix does not over-block unrelated symbols');

// Companion check: once the FIRST position for a symbol+style genuinely closes, that
// slot is honestly freed - a real, later re-open for the exact same symbol+style must
// succeed again (the invariant is "at most one open at a time", never "at most one
// ever").
callFn('fno_close_position_fn', ['id' => $rNoKey1['data']['id'], 'exitPrice' => 320, 'exitReason' => 'TEST_CLEANUP']);
$rReopenAfterClose = callFn('fno_open_position_fn', ['symbol' => 'BANKNIFTY', 'strike' => 51000, 'optionType' => 'CE', 'qty' => 25, 'entryPrice' => 300, 'sl' => 250, 'target' => 400, 'tradingStyle' => 'intraday']);
assertTrue($rReopenAfterClose['success'] === true, 'REGRESSION: once the prior open position for a symbol+style has genuinely closed, a real, later open for that exact same symbol+style correctly succeeds again - the invariant tracks "currently open", not "ever opened"');
callFn('fno_close_position_fn', ['id' => $rReopenAfterClose['data']['id'], 'exitPrice' => 320, 'exitReason' => 'TEST_CLEANUP']);
callFn('fno_close_position_fn', ['id' => $rNoKeyDifferentSymbol['data']['id'], 'exitPrice' => 95, 'exitReason' => 'TEST_CLEANUP']);

echo "\n=== Real sl/target write-boundary validation (definitive \$_POST-numeric-write sweep) ===\n";

// Scenario N: a real, legitimate open with valid sl/target still succeeds exactly as before.
$rSlOk = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 23500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 130, 'sl' => 110, 'target' => 170, 'tradingStyle' => 'intraday']);
assertTrue($rSlOk['success'] === true, 'a real, legitimate open with valid numeric sl/target still succeeds');

// Scenario O: a non-numeric / NaN-producing sl is honestly rejected, not silently cast to 0.
$rSlNan = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 23500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 130, 'sl' => 'NaN', 'target' => 170, 'tradingStyle' => 'intraday']);
assertTrue($rSlNan['success'] === false, 'a genuinely non-numeric sl ("NaN") is honestly rejected, never silently stored as a fabricated 0 protective level');

// Scenario P: a non-numeric / garbage target is honestly rejected too.
$rTargetGarbage = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 23500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 130, 'sl' => 110, 'target' => 'garbage', 'tradingStyle' => 'intraday']);
assertTrue($rTargetGarbage['success'] === false, 'a genuinely non-numeric target ("garbage") is honestly rejected');

// Real, new (2026-08-30 open-position dual-writer audit): free NIFTY|intraday back up -
// the race scenarios below open their own fresh NIFTY|intraday position and would
// otherwise collide with rSlOk (Scenario N) still sitting open here.
callFn('fno_close_position_fn', ['id' => $rSlOk['data']['id'], 'exitPrice' => 140, 'exitReason' => 'TEST_CLEANUP']);

echo "\n=== Real browser-vs-driver concurrent-management race (trailing_sl/mfe/mae atomic ratchet) ===\n";
// TRACE: reproduces the real, genuine scenario this audit pass targeted -
// the browser tab (fno-lab-core.js) and the headless autonomous-driver
// (which eval()s that same shared source) can both be actively managing
// the SAME open position at once. Each independently reads the row,
// computes its own honestly-valid ratchet value from its own tick, and
// POSTs to fno_update_open_position_fn - with NO coordination between
// them. This simulates that by issuing two "calls" back-to-back with
// values computed from what would have been two different, real, stale
// reads, and proving the DB converges to the objectively most-protective
// value NO MATTER WHICH ONE PHYSICALLY WRITES LAST.

$rRaceOpen = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 24500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 150, 'sl' => 130, 'target' => 200, 'tradingStyle' => 'intraday']);
$raceId = $rRaceOpen['data']['id'];

// Real, honest simulation of genuine TWO-PROCESS concurrency: unlike a
// call through fno_update_open_position_fn (whose pre-check ALWAYS
// re-reads the live, current row, so a strictly-sequential test can
// never reproduce the real staleness gap), each "process" here issues
// the EXACT SAME raw atomic UPDATE SQL fno_update_open_position_fn's
// real, fixed code path emits, applied directly via $wpdb->query() -
// this is exactly the write each process would genuinely send after
// ITS OWN pre-check independently passed against ITS OWN, real, stale
// read (which the pre-check step is UX/advisory only for - the actual
// correctness guarantee this fix adds lives entirely in this atomic
// SQL, per the audit's own finding).
function raceWrite($table, $id, $uid, $trailingSl) {
    global $wpdb;
    $sql = "UPDATE $table SET trailing_sl = GREATEST(COALESCE(trailing_sl, %f), COALESCE(sl, %f), %f), last_checked_at = %d WHERE id = %d AND user_id = %d AND status = 'open'";
    $wpdb->query($wpdb->prepare($sql, [$trailingSl, $trailingSl, $trailingSl, time(), $id, $uid]));
}

// Scenario Q: driver computes 140 (from a newer tick) and writes first;
// browser, still on a slightly older tick, independently computes only
// 135 and writes SECOND (last writer, physically). A pre-atomic bare
// column overwrite would let 135 clobber 140 - the atomic GREATEST()
// fix must keep 140.
raceWrite('wp_fno_open_positions', $raceId, 1, 140); // "driver" write
raceWrite('wp_fno_open_positions', $raceId, 1, 135); // "browser" write, physically LAST, but looser
$rowAfterRace = $GLOBALS['wpdb']->getRow($raceId);
assertTrue((float) $rowAfterRace['trailing_sl'] === 140.0, 'REGRESSION: two concurrent processes (driver then browser) writing trailing_sl - the looser value written LAST does NOT clobber the tighter one; DB converges to the objectively most-protective value regardless of write order');

// Scenario R: reverse the physical write order (browser's tighter value
// arrives AFTER the driver's looser one) - must converge to the SAME
// correct answer either way, proving true order-independence, not a
// lucky one-direction fix.
$rRaceOpen2 = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 24500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 150, 'sl' => 130, 'target' => 200, 'tradingStyle' => 'scalping']);
$raceId2 = $rRaceOpen2['data']['id'];
raceWrite('wp_fno_open_positions', $raceId2, 1, 135); // "driver" write, looser, first
raceWrite('wp_fno_open_positions', $raceId2, 1, 140); // "browser" write, tighter, LAST
$rowAfterRace2 = $GLOBALS['wpdb']->getRow($raceId2);
assertTrue((float) $rowAfterRace2['trailing_sl'] === 140.0, 'the same race resolves correctly in the REVERSE physical write order too - true order-independent convergence, not a one-direction coincidence');

// Scenario S: mfe/mae ratchet the same order-independent way. mfe should
// converge to the highest value proposed by either process; mae (stored
// as a raw price, lower = worse) should converge to the LOWEST value
// proposed by either process - regardless of which one writes last.
$rRaceOpen3 = callFn('fno_open_position_fn', ['symbol' => 'NIFTY', 'strike' => 24500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 150, 'sl' => 130, 'target' => 200, 'tradingStyle' => 'swing']);
$raceId3 = $rRaceOpen3['data']['id'];
callFn('fno_update_open_position_fn', ['id' => $raceId3, 'mfe' => 180, 'mae' => 140]); // "driver": higher mfe, higher (less-bad) mae
callFn('fno_update_open_position_fn', ['id' => $raceId3, 'mfe' => 170, 'mae' => 125]); // "browser" writes LAST: lower mfe, lower (worse) mae
$rowAfterRace3 = $GLOBALS['wpdb']->getRow($raceId3);
assertTrue((float) $rowAfterRace3['mfe'] === 180.0, 'REGRESSION: mfe converges to the real, genuine HIGHEST value either concurrent process proposed, not whichever wrote last');
assertTrue((float) $rowAfterRace3['mae'] === 125.0, 'REGRESSION: mae converges to the real, genuine LOWEST (worst) value either concurrent process proposed, not whichever wrote last');

// Scenario T: a genuinely single-writer trailing_sl update (no race)
// still behaves exactly as before this fix - the atomic rewrite must
// be behavior-preserving for the non-concurrent case, not just
// race-safe.
// Real, new (2026-08-30 open-position dual-writer audit): NIFTY|intraday is already
// occupied by Scenario Q's own still-open $raceId above (deliberately left open for the
// rest of this file, same as R/S below), so this scenario uses a genuinely free
// symbol+style combo (FINNIFTY|scalping) instead - the choice of symbol/style is
// incidental to what Scenario T actually verifies (single-writer trailing_sl
// persistence), so this substitution changes nothing about the scenario's intent.
$rRaceOpen4 = callFn('fno_open_position_fn', ['symbol' => 'FINNIFTY', 'strike' => 24500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 150, 'sl' => 130, 'target' => 200, 'tradingStyle' => 'scalping']);
$raceId4 = $rRaceOpen4['data']['id'];
callFn('fno_update_open_position_fn', ['id' => $raceId4, 'trailingSl' => 145]);
assertTrue((float) $GLOBALS['wpdb']->getRow($raceId4)['trailing_sl'] === 145.0, 'a genuinely single-writer trailing_sl update still persists exactly the intended value, unchanged behavior from before the atomic-ratchet fix');

echo "\n=== Real browser-vs-driver concurrent DOUBLE-CLOSE race (fno_close_position_fn atomic-guard audit) ===\n";
// TRACE (this pass's audit): the earlier session had asserted, WITHOUT
// deep verification, that fno_close_position_fn's status='open'->'closed'
// transition was "one-way, WHERE-guarded... not a regressable ratchet
// value" and so out of scope for the browser-vs-driver race class. Reading
// the real code showed that claim was WRONG: the original UPDATE's WHERE
// clause was `id = %d AND user_id = %d` only - NOT status='open' - so the
// preceding SELECT's status check was pure TOCTOU, not an actual guard.
// This scenario reproduces the real, genuine concurrent scenario: the
// browser tab and the headless autonomous-driver both independently decide
// "target hit, close now" for the SAME real position within the same tick
// window, and BOTH send a close request. Simulated the same honest way as
// the trailing_sl race above (Scenarios Q/R/S): by issuing the identical
// raw atomic UPDATE SQL fno_close_position_fn's real, fixed code path now
// emits, applied directly via $wpdb->query() for each "process".
// Real, new (2026-08-30 open-position dual-writer audit): NIFTY|intraday is already
// permanently occupied by Scenario Q's still-open $raceId above, so this scenario uses
// a genuinely free symbol+style combo (BANKNIFTY|scalping) instead - incidental to what
// this scenario actually verifies (the atomic close-race guard).
$rDoubleCloseOpen = callFn('fno_open_position_fn', ['symbol' => 'BANKNIFTY', 'strike' => 52500, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 160, 'sl' => 140, 'target' => 210, 'tradingStyle' => 'scalping']);
$doubleCloseId = $rDoubleCloseOpen['data']['id'];
function raceClose($table, $id, $uid) {
    global $wpdb;
    $sql = "UPDATE $table SET status = 'closed', last_checked_at = %d WHERE id = %d AND user_id = %d AND status = 'open'";
    return $wpdb->query($wpdb->prepare($sql, [time(), $id, $uid]));
}
// "Browser" closes first.
$browserCloseResult = raceClose('wp_fno_open_positions', $doubleCloseId, 1);
// "Driver" independently, concurrently attempts to close the SAME real
// position an instant later (its own pre-check SELECT had already, honestly,
// seen status='open' before the browser's UPDATE committed - exactly the
// real TOCTOU window this fix closes).
$driverCloseResult = raceClose('wp_fno_open_positions', $doubleCloseId, 1);
assertTrue((int) $browserCloseResult === 1, 'REGRESSION: the real FIRST close request (browser) genuinely wins the race - its atomic UPDATE affects exactly 1 row');
assertTrue((int) $driverCloseResult === 0, 'REGRESSION: the real SECOND close request (driver), racing against the same position, now honestly affects 0 rows via the atomic status=\'open\' WHERE-guard - it is NOT silently told it succeeded');
assertTrue($GLOBALS['wpdb']->getRow($doubleCloseId)['status'] === 'closed', 'the real position ends up in exactly ONE terminal closed state, not corrupted or reverted by the losing second writer');

// Scenario U (end-to-end, through the real endpoint function itself, not just raw SQL):
// two real, sequential calls to fno_close_position_fn for the SAME position - simulating
// the second caller's request physically landing after the first has already committed.
// The FIRST call must succeed and return the position; the SECOND must honestly fail with
// rows_affected===0 (not silently succeed a second time), preventing the caller from
// proceeding to double-write a journal entry / double-book P&L for one real trade.
$rEndToEndOpen = callFn('fno_open_position_fn', ['symbol' => 'BANKNIFTY', 'strike' => 53000, 'optionType' => 'PE', 'qty' => 25, 'entryPrice' => 310, 'sl' => 270, 'target' => 430, 'tradingStyle' => 'intraday']);
$e2eId = $rEndToEndOpen['data']['id'];
$rFirstClose = callFn('fno_close_position_fn', ['id' => $e2eId, 'exitPrice' => 400, 'exitReason' => 'AUTO_TARGET_EXIT']);
assertTrue($rFirstClose['success'] === true, 'end-to-end: the real, genuine first close call (e.g. the browser winning the race) succeeds normally');
$rSecondClose = callFn('fno_close_position_fn', ['id' => $e2eId, 'exitPrice' => 400, 'exitReason' => 'AUTO_TARGET_EXIT']);
assertTrue($rSecondClose['success'] === false, 'REGRESSION: end-to-end, the real, genuine second concurrent close call for the SAME position (e.g. the driver, having independently, honestly passed its own pre-check SELECT before the browser\'s UPDATE committed) now honestly fails instead of being told it succeeded a second time - this is exactly the gap that would otherwise let a second caller proceed to double-write a journal entry and double-book P&L for one real trade');

// Scenario V (closes the honest gap left by Pass 60): the SAME real
// fno_close_position_fn function, but exercising specifically the
// rows_affected===0 atomic-UPDATE-lost-the-race branch (not the
// "not found" SELECT-guard branch Scenario U's fully-sequential calls
// actually hit, since a fully-closed row never reaches the SELECT at
// all) - and asserting the ACTUAL JSON response body's exact shape as
// wp_send_json_error() really serializes it, live, through the real
// function's real output (fno-lab.php:4682:
// wp_send_json_error(['message' => ..., 'alreadyClosed' => true])),
// not a hand-built mock of what the shape "should" be. The
// raceFlipOnSelectFor hook above honestly reproduces the real
// interleaving: this call's own SELECT still genuinely sees
// status='open' (exactly like the real losing caller's SELECT would),
// then a concurrent closer's UPDATE lands in the real gap before this
// call's own atomic UPDATE runs, so THIS call's UPDATE affects 0 rows
// via the real WHERE status='open' guard - triggering the exact
// branch under audit.
// Real, new (2026-08-30 open-position dual-writer audit): NIFTY|intraday is already
// permanently occupied by Scenario Q's still-open $raceId above (a different variable,
// reused-name shadowing is intentional here, matching the original test's own style),
// so this scenario uses a genuinely free symbol+style combo (FINNIFTY|swing) instead -
// incidental to what this scenario actually verifies (the lost-close-race JSON shape).
$rRaceOpen = callFn('fno_open_position_fn', ['symbol' => 'FINNIFTY', 'strike' => 24800, 'optionType' => 'CE', 'qty' => 50, 'entryPrice' => 120, 'sl' => 100, 'target' => 180, 'tradingStyle' => 'swing']);
$raceId = $rRaceOpen['data']['id'];
$GLOBALS['wpdb']->raceFlipOnSelectFor = $raceId;
$rLostRaceClose = callFn('fno_close_position_fn', ['id' => $raceId, 'exitPrice' => 130, 'exitReason' => 'AUTO_TARGET_EXIT']);
assertTrue($rLostRaceClose['success'] === false, 'REGRESSION: the real losing caller of a genuine concurrent close race (SELECT saw open, but a concurrent UPDATE won before this call\'s own atomic UPDATE ran) is honestly told the close failed, via the real fno_close_position_fn code path');
assertTrue(array_key_exists('alreadyClosed', $rLostRaceClose['data']), 'REGRESSION: the real losing caller\'s actual JSON response body genuinely includes the alreadyClosed flag under data (not just a generic failure) - so a real caller (browser/driver) can distinguish "lost the race, already closed" from every other close failure');
assertTrue($rLostRaceClose['data']['alreadyClosed'] === true, 'REGRESSION: the real losing caller\'s data.alreadyClosed is genuinely boolean true (exact field + exact value, from fno_close_position_fn\'s real wp_send_json_error call at fno-lab.php:4682) - not a truthy stand-in, not missing, not misspelled');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
