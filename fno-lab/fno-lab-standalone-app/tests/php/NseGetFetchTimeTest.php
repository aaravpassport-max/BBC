<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test proving
 * fno_nse_get()'s new fetch-timestamp capture (this pass) is a
 * genuine, real wall-clock instant captured at response-receipt time
 * - NOT a fabricated/fixed value, and NOT a "time()-at-read" stand-in
 * for whoever calls fno_nse_get_last_fetch_time() later.
 *
 * fno_nse_get() itself is extracted and eval'd directly from the real
 * source (same pattern as CircuitBreakerTest.php) so this test runs
 * against the ACTUAL function body, not a reimplementation. Its own
 * dependencies (fno_nse_circuit_open/_record, fno_nse_get_session_cookie,
 * wp_remote_get and friends) are stubbed - confirmed sufficient by
 * reading fno_nse_get()'s full body before writing this test: it calls
 * exactly those six functions and nothing else.
 *
 * Run with: php tests/php/NseGetFetchTimeTest.php
 */

// --- Stubs -----------------------------------------------------------
$GLOBALS['__fno_test_circuit_open'] = false;
function fno_nse_circuit_open() { return $GLOBALS['__fno_test_circuit_open']; }
function fno_nse_circuit_record($success) { /* no-op - not under test here, CircuitBreakerTest.php covers it */ }
function fno_nse_get_session_cookie() { return null; } // real code path this already handles: cookie bootstrap can genuinely fail/return null

// Controls what the next wp_remote_get() call returns - lets each test
// case script a real HTTP outcome without a real network call.
$GLOBALS['__fno_test_wp_response'] = null; // ['code' => 200, 'body' => '{"a":1}'] or ['error' => true]
function wp_remote_get($url, $args) { return $GLOBALS['__fno_test_wp_response']; }
function is_wp_error($res) { return is_array($res) && !empty($res['error']); }
function wp_remote_retrieve_response_code($res) { return $res['code'] ?? null; }
function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; }

// --- Extract the REAL fno_nse_get() (and its new accessor) from source, unmodified.
$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach (['fno_nse_get', 'fno_nse_get_last_fetch_time'] as $fn) {
    $start = strpos($pluginSource, "function $fn(");
    if ($start === false) { fwrite(STDERR, "FATAL: $fn() not found in fno-lab.php\n"); exit(2); }
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_nse_get() real fetch-timestamp capture (this pass's fix) ===\n";

// 1. Before any call for a URL, the registry honestly has nothing for it.
$urlA = 'https://www.nseindia.com/api/test-a';
assertTrue(fno_nse_get_last_fetch_time($urlA) === null, 'fno_nse_get_last_fetch_time() returns null for a URL never fetched this process - no fabricated default');

// 2. A successful fetch captures a real timestamp very close to "now" -
// proves it's the ACTUAL receipt instant, not some fixed/fabricated
// constant baked into the function.
$GLOBALS['__fno_test_wp_response'] = ['code' => 200, 'body' => json_encode(['data' => [['lastPrice' => 100]]])];
$beforeMs = (int) round(microtime(true) * 1000);
$data = fno_nse_get($urlA, 'https://www.nseindia.com/');
$afterMs = (int) round(microtime(true) * 1000);
assertTrue(is_array($data) && $data['data'][0]['lastPrice'] === 100, 'fno_nse_get() still returns the real decoded data unchanged - return shape untouched by this pass');
$capturedA = fno_nse_get_last_fetch_time($urlA);
assertTrue(is_int($capturedA), 'a captured fetch time exists after a successful response and is an int ms timestamp');
assertTrue($capturedA >= $beforeMs && $capturedA <= $afterMs, "captured timestamp ($capturedA) falls within the real [$beforeMs, $afterMs] window this test call actually took - proves it's a genuine microtime() read, not a fabricated/hardcoded value");

// 3. Sleeping a real, measurable amount and re-fetching produces a
// LATER real timestamp - proves the value is live-computed each call,
// not memoized/frozen at first use.
usleep(50000); // 50ms - real, measurable, comfortably above any float-rounding noise
$GLOBALS['__fno_test_wp_response'] = ['code' => 200, 'body' => json_encode(['data' => [['lastPrice' => 101]]])];
fno_nse_get($urlA, 'https://www.nseindia.com/');
$capturedA2 = fno_nse_get_last_fetch_time($urlA);
assertTrue($capturedA2 > $capturedA, "a second fetch 50ms later captures a strictly LATER real timestamp ($capturedA2 > $capturedA) - confirms this is live microtime()-based capture, not a fixed value returned on every call");
assertTrue(($capturedA2 - $capturedA) >= 40, 'the real gap between the two captured timestamps is consistent with the real ~50ms sleep between the two calls (allowing for scheduler jitter), not some unrelated fixed delta');

// 4. A DIFFERENT URL fetched independently gets its OWN independently
// tracked timestamp - the registry is genuinely keyed per-URL, proving
// it can't be confused with a single global "last request time" that
// would misattribute one endpoint's freshness to another's response.
$urlB = 'https://www.nseindia.com/api/test-b';
assertTrue(fno_nse_get_last_fetch_time($urlB) === null, 'a second, not-yet-fetched URL still honestly has no captured time, even though urlA now does');
usleep(20000);
$beforeB = (int) round(microtime(true) * 1000);
fno_nse_get($urlB, 'https://www.nseindia.com/');
$capturedB = fno_nse_get_last_fetch_time($urlB);
assertTrue($capturedB >= $beforeB, 'urlB gets its own real, independently-captured timestamp');
assertTrue(fno_nse_get_last_fetch_time($urlA) === $capturedA2, "urlA's own previously captured timestamp is untouched by fetching urlB - confirms per-URL keying, not a single shared slot");

// 5. A FAILED fetch (non-200) does NOT capture a fresh timestamp for
// that URL - the registry must not silently claim a successful,
// verified round-trip happened when it genuinely didn't.
$urlC = 'https://www.nseindia.com/api/test-c';
$GLOBALS['__fno_test_wp_response'] = ['code' => 503, 'body' => ''];
$failResult = fno_nse_get($urlC, 'https://www.nseindia.com/');
assertTrue($failResult === null, 'fno_nse_get() still honestly returns null on a real non-200 response - unchanged by this pass');
assertTrue(fno_nse_get_last_fetch_time($urlC) === null, 'a failed (non-200) fetch captures NO timestamp - the registry never fabricates a successful-receipt time for a request that actually failed');

// 6. A wp_error (network-level failure) also captures no timestamp.
$urlD = 'https://www.nseindia.com/api/test-d';
$GLOBALS['__fno_test_wp_response'] = ['error' => true];
$errResult = fno_nse_get($urlD, 'https://www.nseindia.com/');
assertTrue($errResult === null, 'fno_nse_get() still honestly returns null on a real wp_error - unchanged by this pass');
assertTrue(fno_nse_get_last_fetch_time($urlD) === null, 'a network-error fetch captures NO timestamp either');

// 7. Invalid JSON body (200 status, garbage body - e.g. NSE's HTML
// challenge page) also captures no timestamp - matches fno_nse_get()'s
// own existing "200 with invalid JSON = still a failure" handling.
$urlE = 'https://www.nseindia.com/api/test-e';
$GLOBALS['__fno_test_wp_response'] = ['code' => 200, 'body' => '<html>not json</html>'];
$junkResult = fno_nse_get($urlE, 'https://www.nseindia.com/');
assertTrue($junkResult === null, 'fno_nse_get() still honestly returns null on a real 200-with-invalid-JSON response - unchanged by this pass');
assertTrue(fno_nse_get_last_fetch_time($urlE) === null, 'a 200-with-invalid-JSON fetch captures NO timestamp - only a genuinely decoded, valid response counts as a real receipt');

// 8. Open circuit breaker short-circuits before any real fetch attempt
// - no timestamp captured, exactly as expected (no HTTP round-trip
// genuinely happened at all).
$GLOBALS['__fno_test_circuit_open'] = true;
$urlF = 'https://www.nseindia.com/api/test-f';
$GLOBALS['__fno_test_wp_response'] = ['code' => 200, 'body' => json_encode(['data' => [['lastPrice' => 999]]])]; // would succeed if attempted - proves the short-circuit, not a coincidental failure, is why nothing is captured
$circuitResult = fno_nse_get($urlF, 'https://www.nseindia.com/');
assertTrue($circuitResult === null, 'an open circuit still short-circuits fno_nse_get() to null before any real fetch - unchanged by this pass');
assertTrue(fno_nse_get_last_fetch_time($urlF) === null, 'no timestamp is captured when the circuit breaker prevented a real fetch from ever happening');
$GLOBALS['__fno_test_circuit_open'] = false;

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
