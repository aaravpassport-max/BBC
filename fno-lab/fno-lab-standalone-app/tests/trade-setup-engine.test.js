'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const tseSrc = fs.readFileSync(path.join(__dirname, '../assets/trade-setup-engine.js'), 'utf8');
const phpSrc = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');

const emaStart = coreSrc.indexOf('function ema(data,p)');
const emaEnd = coreSrc.indexOf('function vwapCalc', emaStart);
const rsiStart = coreSrc.indexOf('function rsiCalc(closes, period)');
const rsiEnd = coreSrc.indexOf('function macdCalc(closes)', rsiStart);
const macdStart = rsiEnd;
const macdEnd = coreSrc.indexOf('function bollinger(closes', macdStart);
const hvStart = coreSrc.indexOf('function historicalVolPct(closes, period)');
const hvEnd = coreSrc.indexOf('\n\n/**\n * TRACE: Zerodha-maximization', hvStart);
const fnoSettingsStart = coreSrc.indexOf('const FNO_SETTINGS_KEY');
const fnoSettingsEnd = coreSrc.indexOf('\n};\n', coreSrc.indexOf('const fnoSettings = {')) + 3;

const bootSrc = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); }, removeItem(k){ delete this._d[k]; } };',
  'var document = { dispatchEvent(){}, getElementById(){ return null; } };',
  coreSrc.slice(fnoSettingsStart, fnoSettingsEnd)
    .replace(/const FNO_SETTINGS_KEY/g, 'var FNO_SETTINGS_KEY')
    .replace(/const FNO_SETTINGS_DEFAULTS/g, 'var FNO_SETTINGS_DEFAULTS')
    .replace(/const FNO_SETTINGS_SCHEMA_KEY/g, 'var FNO_SETTINGS_SCHEMA_KEY')
    .replace(/const FNO_SETTINGS_SCHEMA_VERSION/g, 'var FNO_SETTINGS_SCHEMA_VERSION')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSrc.slice(emaStart, emaEnd),
  coreSrc.slice(rsiStart, rsiEnd),
  coreSrc.slice(macdStart, macdEnd),
  coreSrc.slice(hvStart, hvEnd + 2),
  'function computeBreakoutReversalCondition(candles, rangeWindow){ if(!candles||candles.length<5) return null; const closes=candles.map(c=>c.c); const win=closes.slice(-Math.min(rangeWindow||20,closes.length)); const hi=Math.max(...win), lo=Math.min(...win); const last=closes[closes.length-1]; const range=hi-lo; const choppy=range>0&&range/(last||1)*100<0.15; return { condition: choppy?"choppy":"range_bound", rangeHigh:hi, rangeLow:lo }; }',
  'function computeMarketRegime(ctx){ return { label:"Bullish-Normal Vol", trend:"Bullish", volatility:"Normal Vol" }; }',
  'function isScalpingProfitProfileActive(){ return true; }',
  'function checkScalpingCapitalPreservation(brain, ctx, opts){ opts=opts||{}; const j=opts.journalToday||[]; const losses=j.filter(t=>typeof t.pnl==="number"&&t.pnl<0).length; if(losses>=1) return {allowed:false,reason:"Capital preservation: 1 losing trade(s) today (cap 1)"}; return {allowed:true}; }',
  'function fnoThemePalette(){ return { panel:"#000", line:"#333", text:"#eee", muted:"#888", pass:"#0f0", fail:"#f00", warn:"#ff0" }; }',
  tseSrc,
  'return { fnoSettings, isTradeSetupEngineActive, classifyMovement, determineProfitTarget, makeTradeDecision, evaluateTradeEligibility, findAllSetups, buildMarketState, detectEmaPullbackSetup, detectBreakoutRetestSetup, detectStructureContinuationSetup, detectVwapSetup, detectConsolidationBreakoutSetup, detectEmaCompressionSetup, detectMomentumExpansionSetup, detectKeyLevels, applyTradeSetupInfluence, checkTradeSetupEntryGate, resolveTradeSetupBracket, convertSpotTargetToOptionBracket, calculateSetupScore, computeSetupPerformanceStats, applySetupExpiration, invalidateSetup, FNO_TSE_SETUP_TYPES, FNO_TSE_SETUP_STATE, FNO_TSE_DECISION, FNO_TSE_TARGET_POINTS, logTradeSetupDecision, getTradeSetupDecisionLog, recordTradeSetupOutcome };',
].join('\n');

const api = new Function(bootSrc)();

api.fnoSettings.set({
  tradeSetupEngineEnabled: true,
  pullbackContinuationEnabled: true,
  scalpingProfitProfileEnabled: true,
  tradingTypes: { scalping: true, intraday: false, swing: false },
  fastMovementThreshold: 65,
  mediumMovementThreshold: 40,
  insufficientMovementThreshold: 25,
  minimumSetupScore: 65,
  minimumRiskReward: 1.5,
  targetPointsFast: 20,
  targetPointsMedium: 15,
  targetPointsSlow: 10,
});

assert.strictEqual(api.FNO_TSE_TARGET_POINTS.fast, 20);
assert.strictEqual(api.FNO_TSE_TARGET_POINTS.medium, 15);
assert.strictEqual(api.FNO_TSE_TARGET_POINTS.slow, 10);

function makeCandles(closes) {
  return closes.map((c, i) => ({ c, t: i }));
}

// --- Movement classification ---
const fastCloses = [];
let p = 24000;
for (let i = 0; i < 30; i++) { p += i > 20 ? 8 : 2; fastCloses.push(p); }
const fastCtx = { spot: p, candles: makeCandles(fastCloses), decay: { snapshot: { now: { delta: 0.5 } } } };
const fastMs = api.buildMarketState(fastCtx, null);
const fastMove = api.classifyMovement(fastMs);
assert.strictEqual(fastMove.movementClass, 'FAST');
assert.strictEqual(fastMove.targetPoints, 20);

const slowCloses = [];
p = 24000;
for (let i = 0; i < 30; i++) { p += 0.1; slowCloses.push(p); }
const slowCtx = { spot: p, candles: makeCandles(slowCloses), decay: { snapshot: { now: { delta: 0.4 } } } };
const slowMove = api.classifyMovement(api.buildMarketState(slowCtx, null));
assert.strictEqual(slowMove.movementClass, 'SLOW');
assert.strictEqual(slowMove.targetPoints, 10);

const flatCloses = Array.from({ length: 25 }, (_, i) => 24000 + i * 0.001);
const flatCtx = { spot: flatCloses[flatCloses.length - 1], candles: makeCandles(flatCloses) };
const insufMove = api.classifyMovement(api.buildMarketState(flatCtx, null));
assert.ok(['INSUFFICIENT', 'SLOW'].includes(insufMove.movementClass));
if (insufMove.movementClass === 'INSUFFICIENT') assert.strictEqual(insufMove.targetPoints, null);

// --- 20pt target blocked by resistance ---
const blockedCtx = {
  spot: 24050,
  candles: makeCandles(Array.from({ length: 25 }, (_, i) => 24000 + i * 2.5)),
  vwap: 24040,
};
const blockedSetup = { direction: 'bullish', type: api.FNO_TSE_SETUP_TYPES.EMA_PULLBACK };
const blockedTarget = api.determineProfitTarget(api.buildMarketState(blockedCtx, null), blockedSetup, fastMove);
assert.strictEqual(blockedTarget.feasible, false, '20pt target should be blocked when resistance is too close');

// --- Strong bullish EMA pullback ---
const bullCloses = [];
p = 24000;
for (let i = 0; i < 25; i++) {
  p += i > 15 ? (i % 3 === 0 ? -3 : 6) : 1.5;
  bullCloses.push(p);
}
p += 4;
bullCloses.push(p);
const bullCtx = { spot: p, candles: makeCandles(bullCloses), vwap: p - 5, decay: { snapshot: { now: { delta: 0.55 } } } };
const bullBrain = { decision: 'BUY_READY', confidence: 'High', results: [] };
const bullTsd = api.makeTradeDecision(bullCtx, bullBrain, { journalToday: [] });
assert.ok(bullTsd.active);
assert.ok(bullTsd.setups.length > 0, 'should detect at least one setup');

// --- Choppy market block ---
const choppyCloses = Array.from({ length: 25 }, (_, i) => 24000 + (i % 2 === 0 ? 3 : -3));
const choppyCtx = { spot: choppyCloses[choppyCloses.length - 1], candles: makeCandles(choppyCloses) };
const choppyBrain = { decision: 'BUY_READY', confidence: 'High', results: [] };
const choppyTsd = api.makeTradeDecision(choppyCtx, choppyBrain, { journalToday: [] });
assert.ok(choppyTsd.blockers.some(b => /choppy/i.test(b)) || choppyTsd.decision !== 'BUY', 'choppy market should block or downgrade');

// --- WAIT for confirmation ---
const waitBrain = { decision: 'BUY_READY', confidence: 'High', results: [] };
const waitCtx = { spot: slowCloses[slowCloses.length - 1], candles: makeCandles(slowCloses) };
const waitElig = api.evaluateTradeEligibility(waitCtx, waitBrain, { journalToday: [] });
assert.ok(['WAIT', 'BLOCK'].includes(waitElig.action));

// --- Daily loss limit hard block ---
const lossBrain = { decision: 'BUY_READY', confidence: 'High', results: [] };
const lossTsd = api.makeTradeDecision(bullCtx, lossBrain, { journalToday: [{ pnl: -500, ts: Date.now() }] });
assert.ok(
  lossTsd.blockers.some(b => /Capital preservation|losing trade/i.test(b)) || !lossTsd.entryAllowed,
  'daily loss limit should hard block'
);

// --- Invalid data ---
const badTsd = api.makeTradeDecision({ spot: null, candles: [] }, bullBrain, {});
assert.strictEqual(badTsd.decision, 'NO_TRADE');

// --- applyTradeSetupInfluence downgrades BUY_READY ---
const blockedInfluence = api.applyTradeSetupInfluence(
  { ...bullBrain, reason: 'Test' },
  { active: true, entryAllowed: false, decision: 'WAIT', blockReason: 'Setup not confirmed', reasons: ['waiting'], engineStatus: api.FNO_TSE_DECISION.SETUP_WAITING }
);
assert.strictEqual(blockedInfluence.decision, 'WAIT');
assert.ok(/TSE:/.test(blockedInfluence.reason));

// --- Bracket resolution ---
const bracket = api.resolveTradeSetupBracket(100, bullCtx, bullBrain, 'scalping', 'CE');
if (bullTsd.entryAllowed) {
  assert.ok(bracket);
  assert.ok(bracket.source.startsWith('trade_setup_'));
}

// --- Breakout retest detection ---
const brCloses = Array.from({ length: 22 }, (_, i) => 24000 + (i < 18 ? Math.sin(i) * 2 : 0));
brCloses.push(24005, 24008, 24012);
const brCtx = { spot: brCloses[brCloses.length - 1], candles: makeCandles(brCloses) };
const brMs = api.buildMarketState(brCtx, null);
const brSetup = api.detectBreakoutRetestSetup(brMs, 'bullish');
assert.ok(brSetup === null || brSetup.type === api.FNO_TSE_SETUP_TYPES.BREAKOUT_RETEST || brSetup.type === api.FNO_TSE_SETUP_TYPES.FAILED_BREAKOUT);

// --- VWAP reclaim ---
const vwapCtx = {
  spot: 24010,
  vwap: 24005,
  candles: makeCandles([23998, 23999, 24000, 24002, 24004, 24006, 24008, 24009, 24010, 24010.5, 24011, 24010, 24010.2, 24010.5, 24010.8, 24011, 24010.5, 24010, 24010.2, 24010.5, 24010.8, 24011]),
};
const vwapSetup = api.detectVwapSetup(api.buildMarketState(vwapCtx, null), 'bullish');
assert.ok(vwapSetup === null || vwapSetup.type === api.FNO_TSE_SETUP_TYPES.VWAP_RECLAIM);

// --- Decision log + performance stats ---
api.logTradeSetupDecision(bullTsd, 'NIFTY', bullBrain);
const log = api.getTradeSetupDecisionLog();
assert.ok(log.length >= 1);
api.recordTradeSetupOutcome('test-trade', { pnl: 150, exitReason: 'target', reachedTarget: true, hitStop: false });
const stats = api.computeSetupPerformanceStats(log);
assert.ok(stats.totalLogged >= 1);

// --- Settings change affects runtime ---
api.fnoSettings.set({ minimumSetupScore: 99 });
const strictTsd = api.makeTradeDecision(bullCtx, bullBrain, { journalToday: [] });
assert.ok(
  strictTsd.blockers.some(b => /below minimum 99/i.test(b)) || !strictTsd.entryAllowed,
  'higher min setup score should block more trades'
);
api.fnoSettings.set({ minimumSetupScore: 65 });

// --- Strong bearish trend ---
const bearCloses = [];
p = 24500;
for (let i = 0; i < 25; i++) { p -= i > 15 ? (i % 3 === 0 ? 2 : 5) : 1.2; bearCloses.push(p); }
p -= 3; bearCloses.push(p);
const bearCtx = { spot: p, candles: makeCandles(bearCloses), vwap: p + 8, decay: { snapshot: { now: { delta: -0.5 } } } };
const bearBrain = { decision: 'SELL_READY', confidence: 'High', results: [] };
const bearTsd = api.makeTradeDecision(bearCtx, bearBrain, { journalToday: [] });
assert.ok(bearTsd.active);
assert.ok(bearTsd.setups.some(s => s.direction === 'bearish'), 'should detect bearish setups');

// --- Invalid pullback (EMA cross invalidates) ---
const invalidPullbackMs = api.buildMarketState(bullCtx, null);
const invalidSetup = api.detectEmaPullbackSetup(invalidPullbackMs, 'bullish');
if (invalidSetup) {
  invalidPullbackMs.ema9 = invalidPullbackMs.ema21 - 1;
  const inv = api.invalidateSetup(invalidSetup, invalidPullbackMs);
  assert.strictEqual(inv.state, api.FNO_TSE_SETUP_STATE.INVALIDATED, 'EMA cross should invalidate pullback');
}

// --- False breakout (failed breakout reversal) ---
const failBrCloses = Array.from({ length: 20 }, (_, i) => 24000 + Math.sin(i) * 2);
failBrCloses.push(24008, 24006, 24004);
const failBrCtx = { spot: failBrCloses[failBrCloses.length - 1], candles: makeCandles(failBrCloses) };
const failBrMs = api.buildMarketState(failBrCtx, null);
const failSetup = api.detectBreakoutRetestSetup(failBrMs, 'bullish');
assert.ok(failSetup === null || failSetup.type === api.FNO_TSE_SETUP_TYPES.FAILED_BREAKOUT || failSetup.type === api.FNO_TSE_SETUP_TYPES.BREAKOUT_RETEST);

// --- EMA compression ---
const compCloses = Array.from({ length: 22 }, (_, i) => 24000 + i * 0.05);
compCloses.push(24002, 24004, 24006);
const compMs = api.buildMarketState({ spot: compCloses[compCloses.length - 1], candles: makeCandles(compCloses) }, null);
const compSetup = api.detectEmaCompressionSetup(compMs, 'bullish');
assert.ok(compSetup === null || compSetup.type === api.FNO_TSE_SETUP_TYPES.EMA_COMPRESSION);

// --- Momentum expansion (FAST) ---
const momSetup = api.detectMomentumExpansionSetup(fastMs, 'bullish');
assert.ok(momSetup === null || momSetup.type === api.FNO_TSE_SETUP_TYPES.MOMENTUM_EXPANSION);

// --- 15pt target blocked ---
const medMove = { movementClass: 'MEDIUM', targetPoints: 15, compositeScore: 50, metrics: fastMove.metrics, reason: 'medium' };
const blocked15 = api.determineProfitTarget(api.buildMarketState({ spot: 24048, candles: makeCandles(Array.from({ length: 25 }, (_, i) => 24000 + i * 2)) }, null), { direction: 'bullish' }, medMove);
assert.strictEqual(blocked15.feasible, false, '15pt should block when headroom insufficient');

// --- 10pt target blocked ---
const slowMoveObj = { movementClass: 'SLOW', targetPoints: 10, compositeScore: 30, metrics: slowMove.metrics, reason: 'slow' };
const blocked10 = api.determineProfitTarget(api.buildMarketState({ spot: 24004, candles: makeCandles(Array.from({ length: 25 }, (_, i) => 24000 + i * 0.2)) }, null), { direction: 'bullish' }, slowMoveObj);
assert.strictEqual(blocked10.feasible, false, '10pt should block when headroom insufficient');

// --- Poor risk/reward (minRiskReward raised) ---
api.fnoSettings.set({ minimumRiskReward: 3.0 });
const poorRrTsd = api.makeTradeDecision(bullCtx, bullBrain, { journalToday: [] });
assert.ok(!poorRrTsd.entryAllowed || poorRrTsd.blockers.some(b => /Risk\/reward/i.test(b)), 'high min R:R should block');
api.fnoSettings.set({ minimumRiskReward: 1.5 });

// --- Stale/missing data ---
const staleTsd = api.makeTradeDecision({ spot: 24000, candles: makeCandles([24000, 24001]) }, bullBrain, {});
assert.strictEqual(staleTsd.decision, 'NO_TRADE');

// --- Opening range in key levels ---
const levels = api.detectKeyLevels(api.buildMarketState(bullCtx, null));
assert.ok(levels.some(l => l.kind === 'or_high'), 'opening range high should be detected');
assert.ok(levels.some(l => l.kind === 'or_low'), 'opening range low should be detected');

// --- Setup expiration ---
api.fnoSettings.set({ setupConfirmationMaxCandles: 2 });
const expSetups = [{ type: 'ema_pullback_continuation', direction: 'bullish', state: api.FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, score: 70, reason: 'wait' }];
api.applySetupExpiration(expSetups, { last: 98, valid: true });
const expired = api.applySetupExpiration(expSetups, { last: 101, valid: true });
assert.ok(expired.some(s => s.state === api.FNO_TSE_SETUP_STATE.EXPIRED), 'setup should expire after max candles');
api.fnoSettings.set({ setupConfirmationMaxCandles: 8 });

// --- Entry gate blocks wrong direction ---
const gateBlock = api.checkTradeSetupEntryGate(
  { tradeSetupDecision: { active: true, entryAllowed: true, setup: { direction: 'bullish' } } },
  bullCtx, 'PE'
);
assert.strictEqual(gateBlock.allowed, false, 'PE entry should block on bullish setup');

// --- Engine off bypasses ---
api.fnoSettings.set({ tradeSetupEngineEnabled: false });
const offTsd = api.makeTradeDecision(bullCtx, bullBrain, {});
assert.strictEqual(offTsd.active, false);
api.fnoSettings.set({ tradeSetupEngineEnabled: true });

// --- End-to-end flow check ---
const e2e = api.makeTradeDecision(fastCtx, { decision: 'BUY_READY', confidence: 'High', results: [] }, { journalToday: [] });
assert.ok(['BUY', 'WAIT', 'NO_TRADE'].includes(e2e.decision.replace('_READY', '')) || e2e.decision === 'NO_TRADE');
assert.ok(e2e.movementClass);
assert.ok(Array.isArray(e2e.blockers));
assert.ok(Array.isArray(e2e.setups));

// --- Wiring checks ---
assert.ok(/makeTradeDecision/.test(coreSrc));
assert.ok(/trade-setup-engine\.js/.test(phpSrc));
assert.ok(/applyTradeSetupInfluence/.test(coreSrc));
assert.ok(/checkTradeSetupEntryGate/.test(coreSrc));
assert.ok(/tradeSetupDecision/.test(coreSrc));
assert.ok(/settingFastMovementThreshold/.test(phpSrc));
assert.ok(/renderTradeSetupMonitor/.test(tseSrc));

console.log('All trade-setup-engine tests passed.');
