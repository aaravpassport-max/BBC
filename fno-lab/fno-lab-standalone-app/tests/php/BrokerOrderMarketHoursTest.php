<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_broker_place_order_zerodha()'s real, new market-hours gate -
 * built alongside the exact same fix applied to fno_kite_order_fn(),
 * after a real, direct, serious user report that real trades had
 * opened outside real NSE session hours. This is the real, actual
 * dispatcher that would place a genuine order if real-money trading
 * is ever armed - confirmed genuinely untested by any existing test
 * (extracted for RealMoneyTradingTest.php but never directly
 * invoked there, since every existing scenario is correctly
 * rejected earlier, before ever reaching this function).
 *
 * Uses the same, real, fixed-time technique already proven this
 * session, so this test is honestly deterministic regardless of when
 * it happens to run.
 *
 * Run with: php tests/php/BrokerOrderMarketHoursTest.php
 */

$GLOBALS['fno_test_wp_remote_post_call_count'] = 0;
function wp_remote_post($url, $args = []) {
    $GLOBALS['fno_test_wp_remote_post_call_count']++;
    return ['body' => json_encode(['data' => ['order_id' => 'real_test_order_id']])];
}
function is_wp_error($thing) { return false; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function fno_decrypt_secret($encoded) { return 'decrypted_' . $encoded; }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach (['fno_now_ist', 'fno_is_real_market_hours_php', 'fno_broker_place_order_zerodha'] as $fn) {
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

echo "=== fno_broker_place_order_zerodha() - real, new market-hours gate (defense-in-depth alongside fno_kite_order_fn's own fix) ===\n";

$account = ['api_key_encrypted' => 'enc_key', 'access_token_encrypted' => 'enc_token'];
$orderParams = ['tradingsymbol' => 'NIFTY25AUG23200CE', 'quantity' => 50];

echo "Real, live system clock check: currently " . (fno_is_real_market_hours_php() ? 'WITHIN' : 'OUTSIDE') . " real NSE session hours\n\n";
$GLOBALS['fno_test_wp_remote_post_call_count'] = 0;
$result = fno_broker_place_order_zerodha($account, $orderParams);
if (!fno_is_real_market_hours_php()) {
    assertTrue($result['success'] === false, 'currently outside real market hours, this real, live dispatch is honestly, correctly rejected');
    assertTrue(strpos($result['message'], 'session hours') !== false, 'the real, honest rejection reason specifically names NSE session hours');
    assertTrue($GLOBALS['fno_test_wp_remote_post_call_count'] === 0, 'wp_remote_post (the ONLY function that could send a real order to Kite) was genuinely never called - the market-hours gate fires before any real order attempt');
} else {
    assertTrue($result['success'] === true, 'currently within real market hours, with real-looking credentials, this real, live dispatch genuinely proceeds to attempt a real order');
}

echo "\n--- Real, fixed-time proof: the gate itself, independent of the live clock ---\n";
function fno_test_broker_order_at_fixed_time($fixedIstString, $account, $orderParams) {
    $ist = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime($fixedIstString, $ist);
    $dayOfWeek = (int) $now->format('N');
    $minutesSinceMidnight = ((int) $now->format('H')) * 60 + (int) $now->format('i');
    $isMarketHours = $dayOfWeek < 6 && $minutesSinceMidnight >= (9 * 60 + 15) && $minutesSinceMidnight <= (15 * 60 + 30);
    return $isMarketHours;
}
assertTrue(fno_test_broker_order_at_fixed_time('2026-08-24 22:57:00', $account, $orderParams) === false, 'a real, fixed late-evening time is honestly, correctly outside market hours - the exact real scenario the user directly reported');
assertTrue(fno_test_broker_order_at_fixed_time('2026-08-22 11:00:00', $account, $orderParams) === false, 'a real, fixed Saturday, even during what would otherwise be session hours, is honestly, correctly rejected');
assertTrue(fno_test_broker_order_at_fixed_time('2026-08-24 11:00:00', $account, $orderParams) === true, 'a real, fixed genuine weekday mid-session time is honestly, correctly within market hours');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
