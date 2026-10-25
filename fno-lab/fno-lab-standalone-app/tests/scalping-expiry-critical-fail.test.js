// Regression: scalping profit profile must not hard-NO_TRADE on expiry-day theta (v16.37.3)
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
Object.assign(global, ge);

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
global.localStorage = {
  store: {},
  getItem(k) { return this.store[k] != null ? this.store[k] : null; },
  setItem(k, v) { this.store[k] = v; },
  removeItem(k) { delete this.store[k]; },
};
global.window = { FNO_FACTORS_CATALOG: [] };
global.document = { addEventListener() {}, dispatchEvent() {} };
eval(coreSource.slice(0, end));

function applySettings(scalpingOn) {
  localStorage.setItem('fno_trading_controls_v1', JSON.stringify({
    scalpingProfitProfileEnabled: scalpingOn,
    tradingTypes: scalpingOn ? { scalping: true, intraday: false, swing: false } : { scalping: false, intraday: true, swing: false },
  }));
}

function buildCtx(daysExp, scalpingOn) {
  applySettings(scalpingOn);
  const spot = 23200;
  const strike = 23200;
  const iv = 14;
  const lotSize = 50;
  const candles = [];
  let c = 23000;
  for (let i = 0; i < 60; i++) {
    c += 5;
    candles.push({ c });
  }
  candles[candles.length - 1].c = spot;
  const closes = candles.map(x => x.c);
  const optPrice = Math.round(calculateDecay(spot, strike, daysExp, iv, 100, lotSize, 'CE').snapshot.now.price * 100) / 100;
  const decay = calculateDecay(spot, strike, daysExp, iv, optPrice, lotSize, 'CE');
  decay.days = daysExp;
  const pct = computeValueDecayCriticalPct({ decay, optPrice });
  return {
    ctx: {
      spot,
      pcr: 1.2,
      totalPE: 480000,
      totalCE: 500000,
      vix: 15,
      time: '10:30',
      day: 'Thursday',
      isExpiry: daysExp <= 1,
      banList: [],
      banListSource: 'live',
      todayPnL: 0,
      tradesToday: 0,
      consecLoss: 0,
      fullJournal: [],
      accountAvailableCapital: 100000,
      daily: {},
      decay,
      optPrice,
      lotSize,
      ocRow: {
        CE: { lastPrice: optPrice, impliedVolatility: iv, openInterest: 500000 },
        PE: { lastPrice: 84, impliedVolatility: 15, openInterest: 480000 },
        strikePrice: strike,
      },
      ocRows: [{ strikePrice: strike, CE: { openInterest: 500000, impliedVolatility: 14 }, PE: { openInterest: 480000, impliedVolatility: 15 } }],
      candles,
      ema21: ema(closes, 21)[closes.length - 1] - 50,
      vwap: vwapCalc(candles)[candles.length - 1] - 30,
      operatorIntel: {
        bias: 'BULLISH',
        score: 5,
        confidence: 'HIGH',
        signals: [
          { factor: 'Bull', contrib: 4, reason: 'x' },
          { factor: 'Bull2', contrib: 4, reason: 'y' },
        ],
      },
      symbol: 'NIFTY',
      futuresPrice: spot + 20,
      correlationSym: 'BANKNIFTY',
      correlationCloses: [],
    },
    pct,
  };
}

let passed = 0;
let failed = 0;
function check(cond, msg) {
  if (cond) {
    passed++;
    console.log('  PASS  ' + msg);
  } else {
    failed++;
    console.log('  FAIL  ' + msg);
  }
}

console.log('\n=== scalping expiry / value decay critical fail (v16.37.3) ===\n');

{
  const { ctx, pct } = buildCtx(1, true);
  check(pct > 5, 'sanity: DTE=1 ATM theta% > 5');
  check(!shouldPushValueDecayCriticalFail(ctx, pct), 'scalping: Value Decay is not a critFail');
  check(!shouldPushExpiryCriticalFail(ctx), 'scalping: Expiry is not a critFail at DTE=1');
  const brain = evaluateBrain(ctx);
  check(!brain.criticalFails.some(f => f.factor === 'Expiry'), 'evaluateBrain: no Expiry critFail');
  check(!brain.criticalFails.some(f => f.factor === 'Value Decay'), 'evaluateBrain: no Value Decay critFail');
  check(brain.decision !== 'NO_TRADE' || brain.criticalFails.length === 0, 'evaluateBrain: not NO_TRADE from decay/expiry critFails');
}

{
  const { ctx, pct } = buildCtx(1, false);
  check(shouldPushExpiryCriticalFail(ctx), 'non-scalping: Expiry critFail at DTE=1');
  check(shouldPushValueDecayCriticalFail(ctx, pct), 'non-scalping: Value Decay critFail when theta>5%');
  const brain = evaluateBrain(ctx);
  check(brain.decision === 'NO_TRADE', 'non-scalping: NO_TRADE on expiry day with high theta');
}

console.log(`\nTOTAL: ${passed} passed, ${failed} failed\n`);
process.exit(failed ? 1 : 0);
