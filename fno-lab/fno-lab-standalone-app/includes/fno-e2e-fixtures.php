<?php
/**
 * Deterministic market-data fixtures for local WordPress E2E (no NSE/Kite).
 * Active only when wp-config.php defines FNO_E2E_FIXTURES as true.
 */
if (!defined('ABSPATH')) {
    exit;
}

function fno_e2e_fixtures_active() {
    return defined('FNO_E2E_FIXTURES') && FNO_E2E_FIXTURES;
}

function fno_e2e_fixture_spot() {
    return 24000.0;
}

function fno_e2e_fixture_chart() {
    if (!fno_e2e_fixtures_active()) {
        return false;
    }
    $spot = fno_e2e_fixture_spot();
    $now = (int) round(microtime(true) * 1000);
    $grapthData = [];
    for ($i = 59; $i >= 0; $i--) {
        $grapthData[] = [$now - ($i * 60000), $spot + sin($i / 8) * 35];
    }
    $ohlcvData = array_map(function ($row) {
        $c = $row[1];
        return ['o' => $c, 'h' => $c + 5, 'l' => $c - 5, 'v' => 100000];
    }, $grapthData);
    wp_send_json_success([
        'grapthData' => $grapthData,
        'ohlcvData' => $ohlcvData,
        'sourceStatus' => 'e2e_fixture',
        'isFallback' => false,
        'message' => 'E2E fixture chart (local test only)',
    ]);
    return true;
}

function fno_e2e_fixture_option_chain() {
    if (!fno_e2e_fixtures_active()) {
        return false;
    }
    $spot = fno_e2e_fixture_spot();
    $expiry = '2026-09-25';
    $rows = [];
    for ($strike = 23600; $strike <= 24400; $strike += 100) {
        $dist = abs($strike - $spot);
        $prem = max(12, 180 - $dist * 0.35);
        $iv = 16 + ($dist / 1000);
        $rows[] = [
            'strikePrice' => (float) $strike,
            'expiryDate' => $expiry,
            'CE' => [
                'lastPrice' => round($prem, 2),
                'impliedVolatility' => round($iv, 2),
                'openInterest' => 80000 + $strike,
                'changeinOpenInterest' => 1200,
                'totalTradedVolume' => 45000,
                'bidprice' => round($prem * 0.99, 2),
                'askPrice' => round($prem * 1.01, 2),
            ],
            'PE' => [
                'lastPrice' => round($prem * 0.92, 2),
                'impliedVolatility' => round($iv + 0.5, 2),
                'openInterest' => 70000 + $strike,
                'changeinOpenInterest' => -800,
                'totalTradedVolume' => 38000,
                'bidprice' => round($prem * 0.9, 2),
                'askPrice' => round($prem * 0.94, 2),
            ],
        ];
    }
    wp_send_json_success([
        'records' => [
            'underlyingValue' => $spot,
            'data' => $rows,
            'expiryDates' => [$expiry],
            'sourceStatus' => 'e2e_fixture',
            'fetchedAt' => time() * 1000,
            'message' => 'E2E fixture option chain (local test only)',
        ],
    ]);
    return true;
}

function fno_e2e_fixture_market_status() {
    if (!fno_e2e_fixtures_active()) {
        return false;
    }
    $nowIst = function_exists('fno_now_ist') ? fno_now_ist() : new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    wp_send_json_success([
        'time' => $nowIst->format('H:i'),
        'day' => $nowIst->format('l'),
        'isExpiry' => false,
        'isEventDay' => null,
        'vix' => 14.2,
        'vixChangePct' => 0.5,
        'vixPrevClose' => 14.1,
        'vixSource' => 'e2e_fixture',
        'vixQualityFlags' => [],
        'vixFetchedAt' => time() * 1000,
        'fiiLongShort' => null,
        'fiiSource' => 'unavailable',
        'banList' => [],
        'banListSource' => 'e2e_fixture',
    ]);
    return true;
}

function fno_e2e_fixture_futures() {
    if (!fno_e2e_fixtures_active()) {
        return false;
    }
    $spot = fno_e2e_fixture_spot();
    wp_send_json_success([
        'futuresPrice' => $spot + 25,
        'expiryDate' => '2026-09-25',
        'sourceStatus' => 'e2e_fixture',
        'allFutures' => [],
        'fetchedAt' => time() * 1000,
    ]);
    return true;
}
