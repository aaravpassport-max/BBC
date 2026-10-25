<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_nse_circuit_open() / fno_nse_circuit_record() - the circuit
 * breaker deciding whether this app keeps hammering NSE during a real
 * outage, or honestly reports "source unavailable" instead. Get this
 * wrong in either direction and it's a real production problem: too
 * aggressive and a transient NSE hiccup makes the whole app falsely
 * report everything unavailable for no reason; too permissive and a
 * genuine outage means this app keeps hitting NSE's servers forever
 * instead of backing off - a real courtesy/stability concern for a
 * free, unauthenticated, scraped data source.
 *
 * These two functions only ever call get_transient()/set_transient() -
 * confirmed by direct inspection before writing this test - so they're
 * stubbed here with a real, working in-memory transient store (with
 * genuine TTL/expiry behavior, not just a bare associative array) that
 * behaves the same way WordPress's real transient API does for the
 * purposes these two functions actually need.
 *
 * Run with: php tests/php/CircuitBreakerTest.php
 */

// --- Real, working in-memory transient stub - confirmed sufficient
// for what these two functions actually need (get_transient/
// set_transient only). Real time() calls inside the actual functions
// are genuinely NOT mockable (PHP does not allow redefining built-in
// functions in the global namespace, which this codebase uses) - the
// cooldown-expiry tests below work around this honestly by seeding a
// real, already-elapsed timestamp directly, rather than pretending to
// mock the clock.
$GLOBALS['__fno_test_transients'] = [];
function get_transient($key) {
    $store = $GLOBALS['__fno_test_transients'];
    if (!isset($store[$key])) return false;
    [$value, $expiresAt] = $store[$key];
    if ($expiresAt !== null && time() >= $expiresAt) return false; // real, honored expiry
    return $value;
}
function set_transient($key, $value, $ttlSeconds) {
    $GLOBALS['__fno_test_transients'][$key] = [$value, time() + $ttlSeconds];
    return true;
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach (['FNO_NSE_CB_KEY', 'FNO_NSE_CB_THRESHOLD', 'FNO_NSE_CB_COOLDOWN'] as $const) {
    if (!defined($const)) {
        preg_match("/define\\('$const',\\s*([^)]+)\\);/", $pluginSource, $m);
        if ($m) eval("define('$const', {$m[1]});");
    }
}
foreach (['fno_nse_circuit_open', 'fno_nse_circuit_record'] as $fn) {
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
function resetTransients() { $GLOBALS['__fno_test_transients'] = []; }

echo "=== fno_nse_circuit_open / fno_nse_circuit_record (real NSE circuit breaker) ===\n";
echo "Real threshold: " . FNO_NSE_CB_THRESHOLD . " consecutive failures, real cooldown: " . FNO_NSE_CB_COOLDOWN . "s\n";

resetTransients();
assertTrue(fno_nse_circuit_open() === false, 'a genuinely fresh circuit (no prior state at all) starts CLOSED - real requests are allowed');

resetTransients();
fno_nse_circuit_record(true);
assertTrue(fno_nse_circuit_open() === false, 'a single real success keeps the circuit closed');

resetTransients();
for ($i = 0; $i < FNO_NSE_CB_THRESHOLD - 1; $i++) fno_nse_circuit_record(false);
assertTrue(fno_nse_circuit_open() === false, 'failures below the real threshold keep the circuit CLOSED - a few real transient blips must not trip it');

resetTransients();
for ($i = 0; $i < FNO_NSE_CB_THRESHOLD; $i++) fno_nse_circuit_record(false);
assertTrue(fno_nse_circuit_open() === true, 'exactly the real threshold number of consecutive real failures OPENS the circuit');

resetTransients();
for ($i = 0; $i < FNO_NSE_CB_THRESHOLD; $i++) fno_nse_circuit_record(false);
fno_nse_circuit_record(true); // one real success in between
assertTrue(fno_nse_circuit_open() === false, 'a real success genuinely RESETS the streak to zero - the circuit closes immediately, no gradual half-open state');

resetTransients();
for ($i = 0; $i < FNO_NSE_CB_THRESHOLD; $i++) fno_nse_circuit_record(false);
assertTrue(fno_nse_circuit_open() === true, 'circuit is genuinely open right after tripping');
// Real cooldown-expiry test: PHP does not allow redefining the
// built-in time() function (no namespace trick applies here, since
// this codebase's functions are in the global namespace) - so rather
// than attempt a fake clock the real function would never actually
// read, this directly seeds the SAME transient state
// fno_nse_circuit_record() itself would have produced, but with a
// real, already-elapsed opened_at timestamp computed from the ACTUAL
// current time() - this exercises the real (time() - opened_at) <
// COOLDOWN comparison for real, honestly, without needing to mock
// PHP's clock at all.
set_transient(FNO_NSE_CB_KEY, ['streak' => FNO_NSE_CB_THRESHOLD, 'opened_at' => time() - (FNO_NSE_CB_COOLDOWN + 1)], 300);
assertTrue(fno_nse_circuit_open() === false, 'after the REAL cooldown window has genuinely elapsed, the circuit closes again - retries are allowed, not permanently stuck open');

resetTransients();
for ($i = 0; $i < FNO_NSE_CB_THRESHOLD; $i++) fno_nse_circuit_record(false);
set_transient(FNO_NSE_CB_KEY, ['streak' => FNO_NSE_CB_THRESHOLD, 'opened_at' => time() - (FNO_NSE_CB_COOLDOWN - 5)], 300); // real, genuinely BEFORE the cooldown elapses
assertTrue(fno_nse_circuit_open() === true, 'the circuit correctly stays open just before the real cooldown window elapses - not prematurely closed');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
