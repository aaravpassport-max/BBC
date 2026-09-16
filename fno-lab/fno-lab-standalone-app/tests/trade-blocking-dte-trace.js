// One-off trace for trade-blocking audit — DTE / Value Decay / Expiry critFails
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

function buildExpiryCtx(daysExp, bullishBoost) {
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
  const vdFail = shouldPushValueDecayCriticalFail({ decay, optPrice }, pct);
  const ctx = {
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
    ema21: ema(closes, 21)[closes.length - 1] - (bullishBoost ? 50 : 0),
    vwap: vwapCalc(candles)[candles.length - 1] - (bullishBoost ? 30 : 0),
    operatorIntel: bullishBoost
      ? { bias: 'BULLISH', score: 5, confidence: 'HIGH', signals: [{ factor: 'Bull', contrib: 4, reason: 'x' }, { factor: 'Bull2', contrib: 4, reason: 'y' }] }
      : { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] },
    symbol: 'NIFTY',
    futuresPrice: spot + 20,
    correlationSym: 'BANKNIFTY',
    correlationCloses: [],
  };
  const brain = evaluateBrain(ctx);
  return {
    daysExp,
    optPrice,
    thetaPct: pct == null ? null : +pct.toFixed(2),
    vdFail,
    expiryCrit: daysExp <= 1,
    profile: isScalpingProfitProfileActive(),
    buyTh: brain.buyThreshold,
    dir: brain.directionalScoreAvailableOnly,
    rawConf: brain.rawConfidence,
    conf: brain.confidence,
    decision: brain.decision,
    crit: brain.criticalFails.map(f => f.factor),
    reason: brain.reason,
  };
}

console.log('Default settings (empty localStorage → scalping profile defaults):');
[15, 5, 3, 2, 1].forEach(d => console.log(JSON.stringify(buildExpiryCtx(d, true))));

localStorage.setItem('fno_trading_controls_v1', JSON.stringify({
  scalpingProfitProfileEnabled: false,
  tradingTypes: { intraday: true, scalping: false, swing: false },
}));
console.log('\nIntraday only (no scalping decay waiver), DTE=3 bullish:');
console.log(JSON.stringify(buildExpiryCtx(3, true)));
