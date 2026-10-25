'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const modesSrc = fs.readFileSync(path.join(__dirname, '../assets/trading-modes-engine.js'), 'utf8');
const fmsSrc = fs.readFileSync(path.join(__dirname, '../assets/first-momentum-scalper-engine.js'), 'utf8');

const boot = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); } };',
  'var document = { getElementById(){ return null; } };',
  'function ema(arr,n){ const out=[]; for(let i=0;i<arr.length;i++) out.push(arr[i]); return out; }',
  'function computeBreakoutReversalCondition(candles, w){ return { condition: "breakout_up", rangeHigh: 25010, rangeLow: 24990, reason: "test breakout" }; }',
  'function checkSpreadLevel(leg){ if(!leg||typeof leg.bidprice!=="number") return { spreadPct: null }; return { spreadPct: (leg.askPrice-leg.bidprice)/leg.bidprice*100 }; }',
  'function checkSufficientTimeRemaining(){ return { sufficient: true, minutesRemaining: 60, reason: "ok" }; }',
  'var fnoSettings = { _s: { scalpingProfitProfileEnabled: true, tradingTypes: { scalping: true }, fmsInitialTargetPoints: 10, fmsExtendedTargetPoints: 15, fmsMomentumHalfLifeMinutes: 3, fmsMaxHoldingMinutes: 8, fmsThesisMinutes: 2, fmsMinTierBScore: 6, fmsStrongTierBScore: 8, fmsMinPremiumPctForTarget: 6, fmsMicroRangeWindow: 12, fmsStopPremiumPoints: 12, scalpingTradingMode: "first_momentum_scalper" }, get(){ return this._s; }, set(p){ Object.assign(this._s, p); } };',
  'function getEffectiveDecisionThresholds(){ return { buyThreshold: 5, sellThreshold: -9 }; }',
  'function getModeSpreadHardBlockPct(){ return 14; }',
  'function isMicrostructureDaemonOnline(){ return false; }',
  modesSrc,
  fmsSrc,
  'return { fnoSettings, FNO_SCALPING_TRADING_MODES, applyScalpingTradingModePreset, computeFirstMomentumScalper, applyFirstMomentumScalperInfluence, isFirstMomentumScalperModeActive, resolveFirstMomentumScalperBracket, evaluateFirstMomentumScalperExit, getFirstMomentumScalperConfig };',
].join('\n');

const api = new Function(boot)();

assert.strictEqual(Object.keys(api.FNO_SCALPING_TRADING_MODES).length, 8);
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.first_momentum_scalper.firstMomentumScalper, true);
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.first_momentum_scalper.initialTargetPoints, 10);

api.fnoSettings.set({ scalpingTradingMode: 'first_momentum_scalper', scalpingProfitProfileEnabled: true, tradingTypes: { scalping: true } });
assert.strictEqual(api.isFirstMomentumScalperModeActive(), true);

const candles = [];
let p = 25000;
for (let i = 0; i < 30; i++) {
  p += i > 25 ? 6 : (Math.random() > 0.5 ? 1 : -1);
  candles.push({ t: i, c: p, o: p, h: p + 2, l: p - 2 });
}
const ctx = {
  candles,
  spot: p,
  vwap: p - 3,
  vix: 14,
  futuresPrice: p + 2,
  optPrice: 120,
  ocRow: { CE: { lastPrice: 120, bidprice: 119.5, askPrice: 120.5, bidQty: 200, askQty: 200 }, PE: { lastPrice: 100, bidprice: 99.5, askPrice: 100.5, bidQty: 200, askQty: 200 } },
  liquidityInputs: { breakoutCondition: { condition: 'breakout_up' } },
};
const brain = { decision: 'WAIT', directionalScore: 4, directionalScoreAvailableOnly: 6.5, criticalFails: [], confidence: 'Medium' };
const fms = api.computeFirstMomentumScalper(ctx, brain, {});
assert.strictEqual(fms.active, true);
if (fms.entryAllowed) {
  api.applyFirstMomentumScalperInfluence(brain, fms);
  assert.ok(brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY');
} else {
  assert.ok(fms.blockReason);
}

const bracket = api.resolveFirstMomentumScalperBracket(120, ctx, { firstMomentumScalper: fms, decision: 'BUY_READY' }, 'scalping', 'CE');
if (fms.entryAllowed && bracket) {
  assert.ok(bracket.target > 120);
  assert.ok(bracket.sl < 120);
  assert.strictEqual(bracket.source, 'first_momentum_scalper');
}

const open = {
  firstMomentumScalperTrade: true,
  entryPrice: 120,
  optionType: 'CE',
  openedAt: Date.now() - 4 * 60000,
  fmsInitialTargetRs: 10,
  fmsExtendedTargetRs: 15,
  fmsTriggerSpot: 25000,
  mfe: 131,
};
const exit = api.evaluateFirstMomentumScalperExit(open, ctx, { decision: 'SELL_READY' }, 125);
assert.ok(exit && exit.exit);

console.log('All first-momentum-scalper tests passed.');
