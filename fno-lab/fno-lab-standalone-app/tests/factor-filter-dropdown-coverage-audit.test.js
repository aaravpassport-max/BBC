// REAL, STANDALONE audit for the factors-list category filter dropdown
// (#filterF in assets/standalone-app.php) against the actual, live set
// of `cat` values evaluateBrain() produces in brain.results.
//
// FOUND during the Phase 1 full factor inventory audit: #filterF's
// <option> list (assets/standalone-app.php) only ever listed 6 of the
// 14 real categories evaluateBrain() actually populates (Regulatory,
// Decay, Greeks Deep, Microstructure, Fundamental, Psychology) - Market,
// Flow, Tech, Vol, Costs, Risk, Personal, and Operator Intel had no
// <option> at all, so a user could never filter the factors list down
// to just one of those 8 categories (the default "All" option still
// showed them, so this was a filter-usability gap, not a factor being
// silently hidden from the list altogether - still a real, user-facing
// inconsistency worth a real regression test). FIXED by adding the 8
// missing <option> entries alongside the existing 6.
//
// This test calls the REAL evaluateBrain() (same eval-slice + fixture
// pattern as tests/end-to-end-decision-engine-audit.test.js) to get the
// real, live set of `cat` values, and REAL-reads the actual PHP file's
// <select id="filterF"> markup to assert every live category has a
// matching <option>. Not a hardcoded guess in either direction.
//
// Run with: node tests/factor-filter-dropdown-coverage-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
const { fnoNormCdf, fnoNormPdf, bsGreeks, bsGreeksAtDays, buildGreeksSnapshot, FNO_RISK_FREE_RATE, TRADING_HOURS_PER_DAY, solveImpliedVolatility } = ge;

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) { console.error('FATAL: render() boundary marker not found'); process.exit(1); }
eval(coreSource.slice(0, end));

console.log('\n=== FACTOR FILTER DROPDOWN COVERAGE AUDIT ===\n');

// ---------------------------------------------------------------------
// Build a realistic, fully-populated ctx so every guarded compute*Factors
// block in evaluateBrain() actually runs (mirrors buildBaseCtx() in
// tests/end-to-end-decision-engine-audit.test.js).
// ---------------------------------------------------------------------
const spot = 23200;
const candles = [];
let c = 23000;
for (let i = 0; i < 60; i++) { c += (Math.sin(i / 5) * 10 + 2); candles.push({ c: Math.round(c * 100) / 100, t: 1700000000 + i * 300 }); }
candles[candles.length - 1].c = spot;
const closes = candles.map(x => x.c);
const strike = 23200;
const iv = 14;
const lotSize = 50;
const daysExp = 15;
const optPrice = Math.round(calculateDecay(spot, strike, daysExp, iv, 100, lotSize, 'CE').snapshot.now.price * 100) / 100;
const decay = calculateDecay(spot, strike, daysExp, iv, optPrice, lotSize, 'CE');
const ocRow = { CE: { lastPrice: optPrice, impliedVolatility: iv, openInterest: 500000 }, PE: { lastPrice: 84, impliedVolatility: iv + 1, openInterest: 480000 }, strikePrice: strike };
const ocRows = [
  { strikePrice: strike - 500, CE: { openInterest: 400000, impliedVolatility: 15 }, PE: { openInterest: 420000, impliedVolatility: 19 } },
  { strikePrice: strike, CE: { openInterest: 500000, impliedVolatility: 14 }, PE: { openInterest: 480000, impliedVolatility: 15 } },
  { strikePrice: strike + 500, CE: { openInterest: 380000, impliedVolatility: 13 }, PE: { openInterest: 360000, impliedVolatility: 14 } },
];
const fullJournal = [];
for (let i = 0; i < 30; i++) {
  fullJournal.push({ id: i, pnl: (i % 3 === 0 ? -200 : 300), entryTime: 1700000000 + i * 86400, exitTime: 1700003600 + i * 86400, reasonWritten: i % 2 === 0, symbol: 'NIFTY', tradeType: 'intraday' });
}
const microstructure = { icebergDetected: 0, cumulativeDelta: 1200, poc: 23180, flowImbalancePct: 55, footprintTopLevels: [1, 2, 3], ticksPerMinute: 40, domSpoofDetected: 0, lastUpdateMs: Date.now() };

const ctx = {
  spot, pcr: 1.0, totalPE: 480000, totalCE: 500000,
  vix: 15, time: '10:30', day: 'Monday', isExpiry: false,
  banList: [], banListSource: 'live',
  fiiLongShort: null,
  todayPnL: 0, tradesToday: 0, consecLoss: 0,
  fullJournal, journalToday: [],
  accountAvailableCapital: 100000, accountCurrentDrawdownPct: 0, accountCurrentBalance: 100000,
  daily: { internet: true, broker: true, deviceCharged: true, mindset: true, sleep: true, physicalHealth: true, emotionalState: true, timeFree: true, distractions: true, newsChecked: true, econCalendar: true, journalUpdated: true, lastTradeReason: true, plan: true, notificationsOff: true, brokerPhoneSaved: true, backupDevice: true, familyKnows: true, waterFood: true },
  decay: { ...decay, days: daysExp },
  operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [{ factor: 'Real Smart-Money OI Buildup', contrib: 1, reason: 'x' }] },
  status: {}, optPrice, lotSize, ocRow, candles,
  ocRows, expiryDates: ['21-Aug-2026', '28-Aug-2026'],
  correlationSym: 'BANKNIFTY', correlationCloses: [],
  futuresPrice: spot + 20,
  targetPrice: null, slPrice: null,
  ema21: ema(closes, 21)[closes.length - 1],
  vwap: vwapCalc(candles)[candles.length - 1],
  newsSentiment: null, newsSentimentTier: 'unavailable',
  marketDepth: null, marketDepthTier: 'unavailable',
  participantOI: null, asmGsmResult: null, microstructure,
  symbol: 'NIFTY',
  allFutures: [{ expiryDate: '21-Aug-2026', price: spot + 20 }, { expiryDate: '28-Aug-2026', price: spot + 35 }],
};

const brain = evaluateBrain(ctx);
const liveCats = [...new Set(brain.results.map(r => r.cat))].sort();
console.log('  Live categories from real evaluateBrain() output:', liveCats.join(', '));
check(brain.results.length > 100, 'sanity: evaluateBrain() with a fully-populated ctx produced a substantial results array (' + brain.results.length + ' rows)');

// ---------------------------------------------------------------------
// Real-read the actual PHP markup and extract the #filterF <option> set.
// ---------------------------------------------------------------------
const phpSource = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');
const selectMatch = phpSource.match(/<select id="filterF"[^>]*>([\s\S]*?)<\/select>/);
check(!!selectMatch, 'the #filterF <select> element exists in assets/standalone-app.php');

const optionTexts = selectMatch ? [...selectMatch[1].matchAll(/<option(?:\s+value="[^"]*")?>([^<]*)<\/option>/g)].map(m => m[1]) : [];
const dropdownCats = new Set(optionTexts.filter(t => t !== 'All'));
console.log('  #filterF <option> categories:', [...dropdownCats].sort().join(', '));

const missing = liveCats.filter(cat => !dropdownCats.has(cat));
check(missing.length === 0, 'every live category evaluateBrain() actually produces has a matching #filterF <option> (missing before fix: Market, Flow, Tech, Vol, Costs, Risk, Personal, Operator Intel)');
if (missing.length) console.log('  MISSING:', missing.join(', '));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
console.log(`\n=== SUMMARY: ${passed} passed, ${failed} failed ===\n`);
process.exit(failed > 0 ? 1 : 0);
