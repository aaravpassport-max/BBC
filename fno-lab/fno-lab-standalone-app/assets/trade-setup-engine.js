/**
 * TradeSetupEngine — real short-term setup detection, confirmation, target selection,
 * and trade eligibility. All UI/settings must route through makeTradeDecision().
 */
const FNO_TSE_LOG_KEY = 'fno_trade_setup_decision_log_v1';
const FNO_TSE_LOG_MAX = 3000;
const FNO_TSE_PERF_KEY = 'fno_trade_setup_perf_v1';
const FNO_TSE_STATE_KEY = 'fno_trade_setup_state_v1';
const FNO_TSE_TARGET_POINTS = { slow: 10, medium: 15, fast: 20 };

const FNO_TSE_SETUP_TYPES = {
  EMA_PULLBACK: 'ema_pullback_continuation',
  BREAKOUT_RETEST: 'breakout_retest',
  STRUCTURE_CONTINUATION: 'structure_continuation',
  VWAP_RECLAIM: 'vwap_reclaim_rejection',
  CONSOLIDATION_BREAKOUT: 'consolidation_breakout',
  EMA_COMPRESSION: 'ema_compression_expansion',
  FAILED_BREAKOUT: 'failed_breakout_reversal',
  MOMENTUM_EXPANSION: 'momentum_expansion',
};

const FNO_TSE_SETUP_STATE = {
  DETECTED: 'DETECTED',
  WAITING_FOR_CONFIRMATION: 'WAITING_FOR_CONFIRMATION',
  CONFIRMED: 'CONFIRMED',
  INVALIDATED: 'INVALIDATED',
  EXPIRED: 'EXPIRED',
};

const FNO_TSE_DECISION = {
  SETUP_DETECTED: 'SETUP_DETECTED',
  SETUP_WAITING: 'SETUP_WAITING',
  NO_SETUP: 'NO_SETUP',
  TRADE_ALLOWED: 'TRADE_ALLOWED',
  TRADE_BLOCKED: 'TRADE_BLOCKED',
};

function fnoTseEscapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function getTradeSetupSettings() {
  const s = typeof fnoSettings !== 'undefined' ? fnoSettings.get() : {};
  return {
    enabled: s.tradeSetupEngineEnabled !== false && s.pullbackContinuationEnabled !== false,
    fastThreshold: typeof s.fastMovementThreshold === 'number' ? s.fastMovementThreshold : 65,
    mediumThreshold: typeof s.mediumMovementThreshold === 'number' ? s.mediumMovementThreshold : 40,
    insufficientThreshold: typeof s.insufficientMovementThreshold === 'number' ? s.insufficientMovementThreshold : 25,
    minSetupScore: typeof s.minimumSetupScore === 'number' ? s.minimumSetupScore : 65,
    minRiskReward: typeof s.minimumRiskReward === 'number' ? s.minimumRiskReward : 1.5,
    confirmMaxCandles: typeof s.setupConfirmationMaxCandles === 'number' ? s.setupConfirmationMaxCandles : 8,
    targetFast: typeof s.targetPointsFast === 'number' ? s.targetPointsFast : 20,
    targetMedium: typeof s.targetPointsMedium === 'number' ? s.targetPointsMedium : 15,
    targetSlow: typeof s.targetPointsSlow === 'number' ? s.targetPointsSlow : 10,
    minEmaSlopePts: typeof s.minEmaSlopePoints === 'number' ? s.minEmaSlopePoints : 0.15,
    minImpulsePts: typeof s.minImpulsePoints === 'number' ? s.minImpulsePoints : 8,
  };
}

function isTradeSetupEngineActive() {
  if (typeof fnoSettings === 'undefined') return false;
  const s = fnoSettings.get();
  if (s.tradeSetupEngineEnabled === false || s.pullbackContinuationEnabled === false) return false;
  return !!(s.scalpingProfitProfileEnabled && s.tradingTypes && s.tradingTypes.scalping);
}

function isPullbackContinuationActive() { return isTradeSetupEngineActive(); }

function buildMarketState(ctx, brain) {
  const candles = (ctx && ctx.candles) || [];
  const closes = candles.map(c => c.c).filter(Number.isFinite);
  const last = closes.length - 1;
  const spot = Number.isFinite(ctx && ctx.spot) ? ctx.spot : (last >= 0 ? closes[last] : null);
  const ms = {
    valid: closes.length >= 21 && Number.isFinite(spot),
    spot, closes, candles, last, ctx, brain,
    e9: [], e21: [], rsi: [], macd: null,
    ema9: null, ema21: null, ema9Slope: null, ema21Slope: null, emaSpreadPct: null,
    atr: null, sessionHigh: null, sessionLow: null, sessionOpen: null,
    vwap: Number.isFinite(ctx && ctx.vwap) ? ctx.vwap : null,
    regime: null, choppy: false,
  };
  if (!ms.valid) return ms;
  ms.e9 = ema(closes, 9);
  ms.e21 = ema(closes, 21);
  ms.ema9 = ms.e9[last];
  ms.ema21 = ms.e21[last];
  if (last >= 3) {
    ms.ema9Slope = ms.e9[last] - ms.e9[last - 3];
    ms.ema21Slope = ms.e21[last] - ms.e21[last - 3];
  }
  if (ms.ema21 > 0) ms.emaSpreadPct = ((ms.ema9 - ms.ema21) / ms.ema21) * 100;
  if (typeof rsiCalc === 'function') ms.rsi = rsiCalc(closes, 14);
  if (typeof macdCalc === 'function') ms.macd = macdCalc(closes);
  ms.sessionHigh = Math.max(...closes);
  ms.sessionLow = Math.min(...closes);
  ms.sessionOpen = closes[0];
  const ranges = [];
  for (let i = Math.max(1, last - 13); i <= last; i++) ranges.push(Math.abs(closes[i] - closes[i - 1]));
  ms.atr = ranges.length ? ranges.reduce((a, b) => a + b, 0) / ranges.length : null;
  if (typeof computeMarketRegime === 'function') ms.regime = computeMarketRegime(ctx);
  if (typeof computeBreakoutReversalCondition === 'function') {
    const br = computeBreakoutReversalCondition(candles, 20);
    ms.choppy = br && (br.condition === 'choppy' || br.condition === 'range_bound');
    ms.rangeHigh = br && br.rangeHigh;
    ms.rangeLow = br && br.rangeLow;
  }
  return ms;
}

function detectKeyLevels(ms) {
  const levels = [];
  if (!ms.valid) return levels;
  const add = (label, price, kind) => {
    if (!Number.isFinite(price)) return;
    levels.push({
      label, price, kind,
      distPts: price - ms.spot,
      distAbs: Math.abs(price - ms.spot),
      side: price >= ms.spot ? 'above' : 'below',
    });
  };
  add('Session high', ms.sessionHigh, 'swing_high');
  add('Session low', ms.sessionLow, 'swing_low');
  add('Session open', ms.sessionOpen, 'open');
  if (ms.rangeHigh != null) add('Range high', ms.rangeHigh, 'range_high');
  if (ms.rangeLow != null) add('Range low', ms.rangeLow, 'range_low');
  if (ms.vwap != null) add('VWAP proxy', ms.vwap, 'vwap');
  const win = ms.closes.slice(Math.max(0, ms.last - 19), ms.last + 1);
  add('Swing high (20)', Math.max(...win), 'swing_high');
  add('Swing low (20)', Math.min(...win), 'swing_low');
  return levels.sort((a, b) => a.distAbs - b.distAbs);
}

function headroomPoints(ms, direction) {
  const lv = detectKeyLevels(ms);
  if (direction === 'bullish') {
    const above = lv.filter(l => l.side === 'above' && l.kind !== 'open');
    return above.length ? above[0].distPts : (ms.sessionHigh - ms.spot);
  }
  const below = lv.filter(l => l.side === 'below' && l.kind !== 'open');
  return below.length ? Math.abs(below[0].distPts) : (ms.spot - ms.sessionLow);
}

function resistanceDistance(ms, direction) {
  return headroomPoints(ms, direction);
}

function computeMovementMetrics(ms) {
  const cfg = getTradeSetupSettings();
  const out = {
    spot: ms.spot, velocityPointsPerMin: null, velocityScore: 0, momentumScore: 0,
    trendStrengthScore: 0, candleStructureScore: 0, volatilityScore: 0, compositeScore: 0,
    expectedPoints5Min: null, emaSeparationScore: 0, reasons: [],
  };
  if (!ms.valid) return out;
  const lookback = Math.min(5, ms.last);
  const start = ms.closes[ms.last - lookback];
  out.velocityPointsPerMin = Math.abs(ms.spot - start) / lookback;
  out.expectedPoints5Min = out.velocityPointsPerMin * 5;
  if (out.velocityPointsPerMin >= 3.5) out.velocityScore = 100;
  else if (out.velocityPointsPerMin >= 2.0) out.velocityScore = 80;
  else if (out.velocityPointsPerMin >= 1.0) out.velocityScore = 55;
  else if (out.velocityPointsPerMin >= 0.4) out.velocityScore = 30;
  else out.velocityScore = 10;
  const rsiNow = ms.rsi && ms.rsi[ms.last];
  if (Number.isFinite(rsiNow)) {
    out.momentumScore = Math.min(100, Math.round(Math.abs(rsiNow - 50) * 2));
  }
  if (ms.macd && Number.isFinite(ms.macd.histogram[ms.last])) {
    out.momentumScore = Math.min(100, out.momentumScore + Math.min(35, Math.abs(ms.macd.histogram[ms.last]) * 70));
  }
  if (Number.isFinite(ms.emaSpreadPct)) {
    out.trendStrengthScore = Math.min(100, Math.round(Math.abs(ms.emaSpreadPct) * 400));
    out.emaSeparationScore = out.trendStrengthScore;
  }
  const bodies = [];
  for (let i = Math.max(1, ms.last - 4); i <= ms.last; i++) bodies.push(Math.abs(ms.closes[i] - ms.closes[i - 1]));
  const sessionRange = ms.sessionHigh - ms.sessionLow;
  const avgBody = bodies.length ? bodies.reduce((a, b) => a + b, 0) / bodies.length : 0;
  if (sessionRange > 0) out.candleStructureScore = Math.min(100, Math.round((avgBody / sessionRange) * 300));
  if (typeof historicalVolPct === 'function') {
    const hv = historicalVolPct(ms.closes, 20);
    if (Number.isFinite(hv)) out.volatilityScore = hv >= 18 ? 90 : hv >= 14 ? 70 : hv >= 10 ? 45 : 25;
  } else if (sessionRange > 0) {
    out.volatilityScore = ((sessionRange / ms.spot) * 100) >= 0.8 ? 75 : 45;
  }
  out.compositeScore = Math.round(
    out.velocityScore * 0.30 + out.momentumScore * 0.25 + out.trendStrengthScore * 0.15
    + out.candleStructureScore * 0.15 + out.volatilityScore * 0.15
  );
  return out;
}

function classifyMovement(marketState) {
  const cfg = getTradeSetupSettings();
  const metrics = computeMovementMetrics(marketState);
  let movementClass = 'INSUFFICIENT';
  let targetPoints = null;
  if (metrics.compositeScore >= cfg.fastThreshold) {
    movementClass = 'FAST';
    targetPoints = cfg.targetFast;
  } else if (metrics.compositeScore >= cfg.mediumThreshold) {
    movementClass = 'MEDIUM';
    targetPoints = cfg.targetMedium;
  } else if (metrics.compositeScore >= cfg.insufficientThreshold) {
    movementClass = 'SLOW';
    targetPoints = cfg.targetSlow;
  }
  return {
    movementClass, targetPoints, compositeScore: metrics.compositeScore, metrics,
    reason: movementClass === 'INSUFFICIENT'
      ? `Movement score ${metrics.compositeScore} below minimum ${cfg.insufficientThreshold} — NO TRADE`
      : `${movementClass} movement (score ${metrics.compositeScore}) → ${targetPoints}-point target candidate`,
  };
}

function determineProfitTarget(marketState, setup, movement) {
  movement = movement || classifyMovement(marketState);
  if (movement.movementClass === 'INSUFFICIENT' || !movement.targetPoints) {
    return {
      target_points: null, movement_class: 'INSUFFICIENT', target_confidence: 0,
      reason: movement.reason, feasible: false,
    };
  }
  const dir = setup && setup.direction;
  const headroom = dir ? resistanceDistance(marketState, dir) : null;
  let confidence = Math.min(0.95, movement.compositeScore / 100);
  if (headroom != null && headroom < movement.targetPoints) {
    return {
      target_points: null, movement_class: movement.movementClass, target_confidence: 0,
      reason: `${movement.movementClass} selected ${movement.targetPoints}pt but only ${headroom.toFixed(1)}pt room — REJECT`,
      feasible: false, headroomPoints: headroom,
    };
  }
  if (headroom != null && headroom < movement.targetPoints * 1.1) confidence *= 0.75;
  return {
    target_points: movement.targetPoints,
    movement_class: movement.movementClass,
    target_confidence: +confidence.toFixed(2),
    reason: `${movement.movementClass} momentum + ${headroom != null ? headroom.toFixed(0) : '?'}pt room → ${movement.targetPoints}pt target`,
    feasible: true,
    headroomPoints: headroom,
    expectedPoints5Min: movement.metrics.expectedPoints5Min,
  };
}

function mkSetup(type, direction, state, score, reason, extra) {
  return Object.assign({
    type, direction, state, score, reason, setupQuality: score,
  }, extra || {});
}

function detectEmaPullbackSetup(ms, direction) {
  const cfg = getTradeSetupSettings();
  if (!ms.valid) return null;
  const bull = direction === 'bullish';
  const last = ms.last;
  const slope9 = ms.ema9Slope;
  const slope21 = ms.ema21Slope;
  const trendOk = bull
    ? (ms.ema9 > ms.ema21 && ms.spot > ms.ema21 && slope9 > cfg.minEmaSlopePts && slope21 >= 0)
    : (ms.ema9 < ms.ema21 && ms.spot < ms.ema21 && slope9 < -cfg.minEmaSlopePts && slope21 <= 0);
  if (!trendOk) return null;

  const impulseLb = Math.min(8, last);
  const impulsePts = bull ? (ms.spot - ms.closes[last - impulseLb]) : (ms.closes[last - impulseLb] - ms.spot);
  if (impulsePts < cfg.minImpulsePts) {
    return mkSetup(FNO_TSE_SETUP_TYPES.EMA_PULLBACK, direction, FNO_TSE_SETUP_STATE.DETECTED, 45,
      `Trend OK, impulse building (${impulsePts.toFixed(1)}pt)`, { impulsePoints: impulsePts });
  }

  const window = ms.closes.slice(last - 5, last + 1);
  const extreme = bull ? Math.max(...window) : Math.min(...window);
  const retracePts = bull ? (extreme - ms.spot) : (ms.spot - extreme);
  const nearEma = Math.abs(ms.spot - ms.ema9) <= Math.max(3, Math.abs(ms.spot - ms.ema21) * 0.35)
    || Math.abs(ms.spot - ms.ema21) <= Math.max(4, ms.atr || 3);
  const lastMove = ms.closes[last] - ms.closes[last - 1];
  const rsiNow = ms.rsi && ms.rsi[last];
  const sellMomentumFade = bull ? (Number.isFinite(rsiNow) ? rsiNow > 40 : lastMove >= 0) : (Number.isFinite(rsiNow) ? rsiNow < 60 : lastMove <= 0);
  const confirm = bull ? lastMove > 0.5 : lastMove < -0.5;

  if (retracePts >= 2 && nearEma && confirm && sellMomentumFade) {
    return mkSetup(FNO_TSE_SETUP_TYPES.EMA_PULLBACK, direction, FNO_TSE_SETUP_STATE.CONFIRMED, 88,
      `Trend→impulse→pullback→EMA zone→confirmation (${retracePts.toFixed(1)}pt retrace)`,
      { impulsePoints: impulsePts, retracePoints: retracePts });
  }
  if (retracePts >= 2 && nearEma) {
    return mkSetup(FNO_TSE_SETUP_TYPES.EMA_PULLBACK, direction, FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, 72,
      `Pullback to EMA zone — awaiting continuation candle`, { impulsePoints: impulsePts, retracePoints: retracePts });
  }
  if (impulsePts >= cfg.minImpulsePts) {
    return mkSetup(FNO_TSE_SETUP_TYPES.EMA_PULLBACK, direction, FNO_TSE_SETUP_STATE.DETECTED, 58,
      `Impulse ${impulsePts.toFixed(1)}pt — waiting for pullback`, { impulsePoints: impulsePts });
  }
  return null;
}

function detectBreakoutRetestSetup(ms, direction) {
  if (!ms.valid || ms.rangeHigh == null || ms.rangeLow == null) return null;
  const bull = direction === 'bullish';
  const level = bull ? ms.rangeHigh : ms.rangeLow;
  const last = ms.last;
  const prev = ms.closes[last - 1];
  const broke = bull ? (prev <= level && ms.spot > level) : (prev >= level && ms.spot < level);
  const heldOutside = bull ? ms.spot > level : ms.spot < level;
  const retesting = bull ? (ms.spot <= level + (ms.atr || 5) && ms.spot >= level - (ms.atr || 3)) : (ms.spot >= level - (ms.atr || 5) && ms.spot <= level + (ms.atr || 3));
  const failed = bull ? ms.spot < level : ms.spot > level;
  const lastMove = ms.closes[last] - ms.closes[last - 1];

  if (failed && (bull ? prev > level : prev < level)) {
    return mkSetup(FNO_TSE_SETUP_TYPES.FAILED_BREAKOUT, direction === 'bullish' ? 'bearish' : 'bullish',
      FNO_TSE_SETUP_STATE.CONFIRMED, 70, 'Breakout failed — price back inside level', { level });
  }
  if (heldOutside && retesting && (bull ? lastMove > 0.3 : lastMove < -0.3)) {
    return mkSetup(FNO_TSE_SETUP_TYPES.BREAKOUT_RETEST, direction, FNO_TSE_SETUP_STATE.CONFIRMED, 80,
      `Breakout retest held at ${level.toFixed(0)} with momentum`, { level });
  }
  if (broke || heldOutside) {
    return mkSetup(FNO_TSE_SETUP_TYPES.BREAKOUT_RETEST, direction, FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, 65,
      'Breakout seen — waiting for retest hold (no chase)', { level });
  }
  return null;
}

function detectStructureContinuationSetup(ms, direction) {
  if (!ms.valid || ms.last < 12) return null;
  const seg = ms.closes.slice(ms.last - 11, ms.last + 1);
  const bull = direction === 'bullish';
  const thirds = [seg.slice(0, 4), seg.slice(4, 8), seg.slice(8)];
  const highs = thirds.map(t => Math.max(...t));
  const lows = thirds.map(t => Math.min(...t));
  let structureOk = false;
  if (bull) structureOk = highs[1] > highs[0] && highs[2] > highs[1] && lows[1] > lows[0] && lows[2] > lows[1];
  else structureOk = highs[1] < highs[0] && highs[2] < highs[1] && lows[1] < lows[0] && lows[2] < lows[1];
  if (!structureOk) return null;
  const pullBack = bull ? (highs[2] - ms.spot) : (ms.spot - lows[2]);
  const lastMove = ms.closes[ms.last] - ms.closes[ms.last - 1];
  const breaking = bull ? (ms.spot >= highs[2] - 1 && lastMove > 0.5) : (ms.spot <= lows[2] + 1 && lastMove < -0.5);
  if (breaking) {
    return mkSetup(FNO_TSE_SETUP_TYPES.STRUCTURE_CONTINUATION, direction, FNO_TSE_SETUP_STATE.CONFIRMED, 82,
      `${bull ? 'HH/HL' : 'LL/LH'} structure — continuation break`, { pullBack });
  }
  if (pullBack >= 3) {
    return mkSetup(FNO_TSE_SETUP_TYPES.STRUCTURE_CONTINUATION, direction, FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, 68,
      'Structure intact — pullback completing', { pullBack });
  }
  return mkSetup(FNO_TSE_SETUP_TYPES.STRUCTURE_CONTINUATION, direction, FNO_TSE_SETUP_STATE.DETECTED, 55,
    'Structure forming', {});
}

function detectVwapSetup(ms, direction) {
  if (!ms.valid || !Number.isFinite(ms.vwap)) return null;
  const bull = direction === 'bullish';
  const last = ms.last;
  const prev = ms.closes[last - 1];
  const wasBelow = prev < ms.vwap;
  const wasAbove = prev > ms.vwap;
  const lastMove = ms.closes[last] - ms.closes[last - 1];
  if (bull && wasBelow && ms.spot > ms.vwap && Math.abs(ms.spot - ms.vwap) <= (ms.atr || 4) && lastMove > 0.4) {
    return mkSetup(FNO_TSE_SETUP_TYPES.VWAP_RECLAIM, 'bullish', FNO_TSE_SETUP_STATE.CONFIRMED, 75,
      'Reclaimed VWAP with hold + bullish momentum', {});
  }
  if (!bull && wasAbove && ms.spot < ms.vwap && Math.abs(ms.spot - ms.vwap) <= (ms.atr || 4) && lastMove < -0.4) {
    return mkSetup(FNO_TSE_SETUP_TYPES.VWAP_RECLAIM, 'bearish', FNO_TSE_SETUP_STATE.CONFIRMED, 75,
      'Rejected VWAP with bearish momentum', {});
  }
  if (bull && ms.spot < ms.vwap && ms.spot > ms.vwap - (ms.atr || 6)) {
    return mkSetup(FNO_TSE_SETUP_TYPES.VWAP_RECLAIM, 'bullish', FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, 60,
      'Below VWAP — waiting reclaim confirmation', {});
  }
  if (!bull && ms.spot > ms.vwap && ms.spot < ms.vwap + (ms.atr || 6)) {
    return mkSetup(FNO_TSE_SETUP_TYPES.VWAP_RECLAIM, 'bearish', FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, 60,
      'Above VWAP — waiting rejection confirmation', {});
  }
  return null;
}

function detectConsolidationBreakoutSetup(ms, direction) {
  if (!ms.valid) return null;
  const win = ms.closes.slice(Math.max(0, ms.last - 14), ms.last + 1);
  const range = Math.max(...win) - Math.min(...win);
  const compressed = ms.atr && range < ms.atr * 4;
  const emaCompressed = Number.isFinite(ms.emaSpreadPct) && Math.abs(ms.emaSpreadPct) < 0.05;
  if (!compressed && !emaCompressed) return null;
  const bull = direction === 'bullish';
  const hi = Math.max(...win);
  const lo = Math.min(...win);
  const lastMove = ms.closes[ms.last] - ms.closes[ms.last - 1];
  const broke = bull ? ms.spot > hi && lastMove > 0.5 : ms.spot < lo && lastMove < -0.5;
  if (broke) {
    return mkSetup(FNO_TSE_SETUP_TYPES.CONSOLIDATION_BREAKOUT, direction, FNO_TSE_SETUP_STATE.CONFIRMED, 78,
      'Consolidation break with momentum expansion', { rangeWidth: range });
  }
  if (compressed) {
    return mkSetup(FNO_TSE_SETUP_TYPES.CONSOLIDATION_BREAKOUT, direction, FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, 55,
      'Inside consolidation — no trade until break', { rangeWidth: range });
  }
  return null;
}

function detectEmaCompressionSetup(ms, direction) {
  if (!ms.valid || !Number.isFinite(ms.emaSpreadPct)) return null;
  const compressed = Math.abs(ms.emaSpreadPct) < 0.04;
  const expanding = Math.abs(ms.emaSpreadPct) > 0.08;
  const bull = direction === 'bullish';
  const aligned = bull ? ms.ema9 > ms.ema21 : ms.ema9 < ms.ema21;
  const lastMove = ms.closes[ms.last] - ms.closes[ms.last - 1];
  if (expanding && aligned && (bull ? lastMove > 0.5 : lastMove < -0.5)) {
    return mkSetup(FNO_TSE_SETUP_TYPES.EMA_COMPRESSION, direction, FNO_TSE_SETUP_STATE.CONFIRMED, 76,
      'EMA compression released with directional expansion', { emaSpreadPct: ms.emaSpreadPct });
  }
  if (compressed) {
    return mkSetup(FNO_TSE_SETUP_TYPES.EMA_COMPRESSION, direction, FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION, 50,
      'EMA compression — awaiting expansion', { emaSpreadPct: ms.emaSpreadPct });
  }
  return null;
}

function detectMomentumExpansionSetup(ms, direction) {
  const movement = classifyMovement(ms);
  if (movement.movementClass === 'INSUFFICIENT') return null;
  const bull = direction === 'bullish';
  const aligned = bull ? ms.ema9 > ms.ema21 : ms.ema9 < ms.ema21;
  if (!aligned) return null;
  const state = movement.movementClass === 'FAST' ? FNO_TSE_SETUP_STATE.CONFIRMED : FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION;
  return mkSetup(FNO_TSE_SETUP_TYPES.MOMENTUM_EXPANSION, direction, state, movement.compositeScore,
    `Momentum expansion ${movement.movementClass}`, { movementClass: movement.movementClass });
}

function findAllSetups(ms) {
  const setups = [];
  ['bullish', 'bearish'].forEach(direction => {
    [
      detectEmaPullbackSetup,
      detectBreakoutRetestSetup,
      detectStructureContinuationSetup,
      detectVwapSetup,
      detectConsolidationBreakoutSetup,
      detectEmaCompressionSetup,
      detectMomentumExpansionSetup,
    ].forEach(fn => {
      const s = fn(ms, direction);
      if (s) setups.push(s);
    });
  });
  return setups.sort((a, b) => b.score - a.score);
}

function invalidateSetup(setup, ms) {
  if (!setup || !ms.valid) return setup;
  const bull = setup.direction === 'bullish';
  if (setup.type === FNO_TSE_SETUP_TYPES.EMA_PULLBACK) {
    if (bull && (ms.ema9 <= ms.ema21 || ms.spot < ms.sessionLow + (ms.atr || 5))) {
      setup.state = FNO_TSE_SETUP_STATE.INVALIDATED;
      setup.reason = 'Bullish structure or EMA relationship invalidated';
    }
    if (!bull && (ms.ema9 >= ms.ema21 || ms.spot > ms.sessionHigh - (ms.atr || 5))) {
      setup.state = FNO_TSE_SETUP_STATE.INVALIDATED;
      setup.reason = 'Bearish structure or EMA relationship invalidated';
    }
  }
  return setup;
}

function calculateSetupScore(setup, ms, targetInfo, riskReward) {
  const movement = classifyMovement(ms);
  const trend = Math.min(20, Math.round((movement.metrics.trendStrengthScore / 100) * 20));
  const momentum = Math.min(20, Math.round((movement.metrics.momentumScore / 100) * 20));
  const volatility = Math.min(20, Math.round((movement.metrics.volatilityScore / 100) * 20));
  const structure = setup.type === FNO_TSE_SETUP_TYPES.STRUCTURE_CONTINUATION ? 18 : setup.type === FNO_TSE_SETUP_TYPES.EMA_PULLBACK ? 17 : 14;
  const setupQuality = Math.min(20, Math.round((setup.score || 50) / 100 * 20));
  const targetFeas = targetInfo && targetInfo.feasible ? (targetInfo.target_confidence >= 0.8 ? 10 : 7) : 0;
  const rr = riskReward >= 2 ? 10 : riskReward >= 1.5 ? 8 : riskReward >= 1 ? 5 : 0;
  const location = headroomPoints(ms, setup.direction) >= (targetInfo.target_points || 10) ? 10 : 4;
  const raw = trend + momentum + volatility + structure + setupQuality + targetFeas + rr + location;
  return {
    trend, momentum, volatility, structure, setupQuality, targetFeasibility: targetFeas, riskReward: rr, location,
    total: raw, normalized: Math.min(100, Math.round((raw / 130) * 100)),
  };
}

function evaluateHardBlocks(ms, setup, targetInfo, brain, ctx, opts) {
  opts = opts || {};
  const blockers = [];
  if (!ms.valid) blockers.push('Missing or stale candle data');
  if (ms.choppy) blockers.push('Market classified as choppy/range-bound');
  if (!setup) blockers.push('No valid setup');
  else if (setup.state === FNO_TSE_SETUP_STATE.INVALIDATED) blockers.push('Setup invalidated: ' + setup.reason);
  else if (setup.state !== FNO_TSE_SETUP_STATE.CONFIRMED) blockers.push('Setup not confirmed (' + setup.state + ')');
  if (!targetInfo || !targetInfo.feasible || !targetInfo.target_points) blockers.push(targetInfo ? targetInfo.reason : 'No feasible target');
  const movement = classifyMovement(ms);
  if (movement.movementClass === 'INSUFFICIENT') blockers.push(movement.reason);
  if (typeof checkScalpingCapitalPreservation === 'function' && brain && (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY')) {
    const journalToday = (opts.journalToday) || (ctx && ctx.journalToday) || [];
    const cp = checkScalpingCapitalPreservation(brain, ctx || ms.ctx || {}, { journalToday });
    if (!cp.allowed) blockers.push(cp.reason);
  }
  return blockers;
}

function evaluateTradeEligibility(ctx, brain, opts) {
  opts = opts || {};
  if (!isTradeSetupEngineActive()) {
    return { action: 'ALLOW', reason: 'Trade Setup Engine disabled', bypass: true };
  }
  const ms = buildMarketState(ctx, brain);
  if (!ms.valid) return { action: 'BLOCK', reason: 'Invalid market data', blockers: ['Insufficient candles'] };

  const brainDir = brain && brain.decision === 'BUY_READY' ? 'bullish' : brain && brain.decision === 'SELL_READY' ? 'bearish' : null;
  const setups = findAllSetups(ms).map(s => invalidateSetup(s, ms));
  const confirmed = setups.filter(s => s.state === FNO_TSE_SETUP_STATE.CONFIRMED);
  const waiting = setups.filter(s => s.state === FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION);

  let best = null;
  if (brainDir) {
    best = confirmed.find(s => s.direction === brainDir) || waiting.find(s => s.direction === brainDir) || setups.find(s => s.direction === brainDir);
  } else {
    best = confirmed[0] || waiting[0] || setups[0];
  }

  if (!best) {
    return { action: 'BLOCK', reason: 'NO VALID SETUP — no trade', engineDecision: FNO_TSE_DECISION.NO_SETUP, setups, movement: classifyMovement(ms) };
  }
  if (best.state === FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION) {
    return { action: 'WAIT', reason: best.reason, setup: best, engineDecision: FNO_TSE_DECISION.SETUP_WAITING, setups, movement: classifyMovement(ms) };
  }
  if (best.state !== FNO_TSE_SETUP_STATE.CONFIRMED) {
    return { action: 'WAIT', reason: best.reason, setup: best, engineDecision: FNO_TSE_DECISION.SETUP_DETECTED, setups, movement: classifyMovement(ms) };
  }

  const targetInfo = determineProfitTarget(ms, best, classifyMovement(ms));
  const stopPts = targetInfo && targetInfo.target_points ? targetInfo.target_points * 0.5 : null;
  const rr = stopPts && targetInfo.target_points ? +(targetInfo.target_points / stopPts).toFixed(2) : 0;
  const scores = calculateSetupScore(best, ms, targetInfo, rr);
  const cfg = getTradeSetupSettings();
  const blockers = evaluateHardBlocks(ms, best, targetInfo, brain, ctx, opts);
  if (scores.normalized < cfg.minSetupScore) blockers.push(`Setup score ${scores.normalized} below minimum ${cfg.minSetupScore}`);
  if (rr < cfg.minRiskReward) blockers.push(`Risk/reward ${rr} below minimum ${cfg.minRiskReward}`);

  if (blockers.length) {
    return {
      action: 'BLOCK', reason: blockers[0], blockers, setup: best, targetInfo, scores,
      engineDecision: FNO_TSE_DECISION.TRADE_BLOCKED, setups, movement: classifyMovement(ms),
    };
  }
  return {
    action: 'ALLOW', reason: `A-grade ${best.type} with ${targetInfo.target_points}pt target`,
    setup: best, targetInfo, scores, engineDecision: FNO_TSE_DECISION.TRADE_ALLOWED,
    setups, movement: classifyMovement(ms),
  };
}

function makeTradeDecision(ctx, brain, opts) {
  opts = opts || {};
  const eligibility = evaluateTradeEligibility(ctx, brain, opts);
  const ms = buildMarketState(ctx, brain);
  const movement = eligibility.movement || classifyMovement(ms);
  const cfg = getTradeSetupSettings();

  let decision = 'NO_TRADE';
  let engineStatus = eligibility.engineDecision || FNO_TSE_DECISION.NO_SETUP;

  if (!isTradeSetupEngineActive()) {
    return {
      active: false, decision: brain ? brain.decision : 'NO_TRADE', engineStatus: 'BYPASS',
      eligibility, movement, setups: [], blockers: [], reasons: ['Engine off'],
    };
  }

  if (eligibility.bypass) {
    decision = brain && (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY') ? brain.decision : 'WAIT';
    engineStatus = 'BYPASS';
  } else if (eligibility.action === 'ALLOW') {
    decision = eligibility.setup.direction === 'bullish' ? 'BUY' : 'SELL';
    engineStatus = FNO_TSE_DECISION.TRADE_ALLOWED;
  } else if (eligibility.action === 'WAIT') {
    decision = 'WAIT';
    engineStatus = eligibility.engineDecision || FNO_TSE_DECISION.SETUP_WAITING;
  } else {
    decision = 'NO_TRADE';
    engineStatus = FNO_TSE_DECISION.TRADE_BLOCKED;
  }

  const targetInfo = eligibility.targetInfo || determineProfitTarget(ms, eligibility.setup, movement);
  const scores = eligibility.scores || (eligibility.setup ? calculateSetupScore(eligibility.setup, ms, targetInfo, 2) : null);
  const regimeLabel = ms.regime ? ms.regime.label : (ms.choppy ? 'Choppy' : 'Unknown');

  return {
    active: true,
    ts: Date.now(),
    decision,
    engineStatus,
    regime: regimeLabel,
    movement,
    movementClass: movement.movementClass,
    movementScore: movement.compositeScore,
    setup: eligibility.setup || null,
    setups: eligibility.setups || [],
    bestSetupType: eligibility.setup ? eligibility.setup.type : null,
    setupScore: scores,
    targetInfo,
    spotTargetPoints: targetInfo && targetInfo.target_points,
    spotTargetPrice: targetInfo && targetInfo.target_points && Number.isFinite(ms.spot)
      ? +(ms.spot + (eligibility.setup && eligibility.setup.direction === 'bullish' ? 1 : -1) * targetInfo.target_points).toFixed(2) : null,
    targetConfidence: targetInfo ? targetInfo.target_confidence : 0,
    blockers: eligibility.blockers || [],
    reasons: [eligibility.reason].filter(Boolean),
    entryAllowed: engineStatus === FNO_TSE_DECISION.TRADE_ALLOWED
      && brain && (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY'),
    blockReason: eligibility.action === 'BLOCK' ? eligibility.reason : (eligibility.action === 'WAIT' ? eligibility.reason : null),
    settings: cfg,
  };
}

function convertSpotTargetToOptionBracket(optPrice, ctx, spotTargetPoints, direction) {
  if (!Number.isFinite(optPrice) || optPrice <= 0 || !Number.isFinite(spotTargetPoints)) {
    return { target: null, sl: null, premiumMove: null, deltaUsed: null };
  }
  let delta = 0.45;
  if (ctx && ctx.decay && ctx.decay.snapshot && ctx.decay.snapshot.now && Number.isFinite(ctx.decay.snapshot.now.delta)) {
    delta = Math.max(0.15, Math.min(0.85, Math.abs(ctx.decay.snapshot.now.delta)));
  }
  const premiumMove = +(delta * spotTargetPoints).toFixed(2);
  const slMove = +(premiumMove * 0.5).toFixed(2);
  return {
    target: +(optPrice + premiumMove).toFixed(2),
    sl: +(Math.max(0.05, optPrice - slMove)).toFixed(2),
    premiumMove, deltaUsed: delta, spotTargetPoints,
  };
}

function resolveTradeSetupBracket(optPrice, ctx, brain, tradingType, optionType) {
  if (!isTradeSetupEngineActive()) return null;
  const tsd = makeTradeDecision(ctx, brain, {});
  if (!tsd.active || !tsd.spotTargetPoints || !tsd.entryAllowed) return null;
  const dir = optionType === 'PE' ? 'bearish' : 'bullish';
  if (tsd.setup && tsd.setup.direction !== dir) return null;
  const bracket = convertSpotTargetToOptionBracket(optPrice, ctx, tsd.spotTargetPoints, dir);
  if (!bracket.target || bracket.sl >= optPrice) return null;
  return {
    ...bracket,
    source: 'trade_setup_' + tsd.movementClass + '_' + tsd.spotTargetPoints + 'pt',
    tradeSetupDecision: tsd,
    config: {
      id: 'tse_' + tsd.spotTargetPoints + 'pt',
      label: `TSE ${tsd.spotTargetPoints}pt (${tsd.movementClass})`,
      autoAdjusted: true, trailingEnabled: true, partialExitEnabled: true,
    },
  };
}

function resolvePullbackContinuationBracket(optPrice, ctx, brain, tradingType, optionType) {
  return resolveTradeSetupBracket(optPrice, ctx, brain, tradingType, optionType);
}

function applyTradeSetupInfluence(brain, tsd) {
  if (!brain || !tsd || !tsd.active) return brain;
  brain.tradeSetupDecision = tsd;
  brain.pullbackContinuation = legacyPullbackFromTsd(tsd);
  if (tsd.entryAllowed) return brain;
  if (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY') {
    brain.decision = tsd.decision === 'NO_TRADE' ? 'NO_TRADE' : 'WAIT';
    brain.reason = (brain.reason || '') + ` [TSE: ${tsd.blockReason || (tsd.reasons && tsd.reasons[0]) || tsd.engineStatus}]`;
    brain.tradeSetupBlocked = true;
  }
  return brain;
}

function applyPullbackContinuationInfluence(brain, setup) {
  if (setup && setup.active && setup.engineStatus) return applyTradeSetupInfluence(brain, setup);
  if (setup && setup.active) {
    const tsd = makeTradeDecision(setup.ctx || {}, brain, {});
    return applyTradeSetupInfluence(brain, tsd);
  }
  return brain;
}

function legacyPullbackFromTsd(tsd) {
  if (!tsd || !tsd.active) return { active: false };
  return {
    active: true, ts: tsd.ts, direction: tsd.setup ? tsd.setup.direction : null,
    phase: tsd.setup ? tsd.setup.state : tsd.engineStatus,
    phaseReason: tsd.blockReason || (tsd.reasons && tsd.reasons[0]),
    movementTier: tsd.movementClass && tsd.movementClass.toLowerCase(),
    spotTargetPoints: tsd.spotTargetPoints,
    spotTargetPrice: tsd.spotTargetPrice,
    selectionReason: tsd.targetInfo ? tsd.targetInfo.reason : null,
    feasibility: { feasible: !!(tsd.targetInfo && tsd.targetInfo.feasible), reason: tsd.targetInfo ? tsd.targetInfo.reason : '' },
    entryAllowed: tsd.entryAllowed,
    blockReason: tsd.blockReason,
    classification: tsd.movement,
    factorBreakdown: tsd.movement && tsd.movement.metrics ? {
      velocity: tsd.movement.metrics.velocityScore,
      momentum: tsd.movement.metrics.momentumScore,
      trendStrength: tsd.movement.metrics.trendStrengthScore,
      candleStructure: tsd.movement.metrics.candleStructureScore,
      volatility: tsd.movement.metrics.volatilityScore,
    } : {},
  };
}

function computePullbackContinuationSetup(ctx, brain, opts) {
  const tsd = makeTradeDecision(ctx, brain, opts);
  return legacyPullbackFromTsd(tsd);
}

function checkTradeSetupEntryGate(brain, ctx, optionType) {
  if (!isTradeSetupEngineActive()) return { allowed: true };
  const tsd = (brain && brain.tradeSetupDecision) ? brain.tradeSetupDecision : makeTradeDecision(ctx, brain, {});
  if (!tsd.active) return { allowed: true };
  const dir = optionType === 'PE' ? 'bearish' : 'bullish';
  if (tsd.entryAllowed && tsd.setup && tsd.setup.direction === dir) return { allowed: true, tsd };
  return { allowed: false, reason: `Trade Setup Engine: ${tsd.blockReason || (tsd.reasons && tsd.reasons[0]) || tsd.engineStatus}`, tsd };
}

function checkPullbackContinuationEntryGate(brain, ctx, optionType) {
  return checkTradeSetupEntryGate(brain, ctx, optionType);
}

function logTradeSetupDecision(tsd, sym, brain, outcome) {
  if (!tsd || !tsd.active) return;
  try {
    const log = JSON.parse(localStorage.getItem(FNO_TSE_LOG_KEY) || '[]');
    log.push({
      ts: tsd.ts, sym, instrument: sym,
      direction: tsd.setup ? tsd.setup.direction : null,
      decision: tsd.decision, engineStatus: tsd.engineStatus,
      regime: tsd.regime, setupType: tsd.bestSetupType,
      movementClass: tsd.movementClass, movementScore: tsd.movementScore,
      setupScore: tsd.setupScore, targetPoints: tsd.spotTargetPoints,
      targetFeasibility: tsd.targetInfo, targetConfidence: tsd.targetConfidence,
      brainDecision: brain ? brain.decision : null,
      blockers: tsd.blockers, reasons: tsd.reasons, entryAllowed: tsd.entryAllowed,
      outcome: outcome || null,
    });
    while (log.length > FNO_TSE_LOG_MAX) log.shift();
    localStorage.setItem(FNO_TSE_LOG_KEY, JSON.stringify(log));
  } catch (e) { /* quota */ }
}

function logPullbackContinuationObservation(setup, sym, brain) {
  logTradeSetupDecision(setup && setup.engineStatus ? setup : legacyPullbackFromTsd(setup), sym, brain);
}

function getTradeSetupDecisionLog() {
  try { return JSON.parse(localStorage.getItem(FNO_TSE_LOG_KEY) || '[]'); } catch (e) { return []; }
}

function computeSetupPerformanceStats(log) {
  log = log || getTradeSetupDecisionLog();
  const bySetup = {};
  const byMovement = { FAST: { n: 0, wins: 0 }, MEDIUM: { n: 0, wins: 0 }, SLOW: { n: 0, wins: 0 } };
  log.filter(e => e.outcome && typeof e.outcome.pnl === 'number').forEach(e => {
    const k = e.setupType || 'unknown';
    if (!bySetup[k]) bySetup[k] = { trades: 0, wins: 0, losses: 0, pnl: 0, target10: 0, target15: 0, target20: 0 };
    const b = bySetup[k];
    b.trades++;
    if (e.outcome.pnl > 0) b.wins++; else b.losses++;
    b.pnl += e.outcome.pnl;
    if (e.targetPoints === 10) b.target10++;
    if (e.targetPoints === 15) b.target15++;
    if (e.targetPoints === 20) b.target20++;
    const mc = e.movementClass || 'SLOW';
    if (byMovement[mc]) { byMovement[mc].n++; if (e.outcome.pnl > 0) byMovement[mc].wins++; }
  });
  Object.keys(bySetup).forEach(k => {
    const b = bySetup[k];
    b.winRate = b.trades ? +((b.wins / b.trades) * 100).toFixed(1) : null;
    b.expectancy = b.trades ? +(b.pnl / b.trades).toFixed(2) : null;
  });
  return { bySetup, byMovement, totalLogged: log.length };
}

function buildPullbackContinuationAttribution(setup) {
  if (!setup) return null;
  if (setup.engineStatus) {
    return {
      engine: 'TradeSetupEngine', engineStatus: setup.engineStatus,
      setupType: setup.bestSetupType, movementClass: setup.movementClass,
      spotTargetPoints: setup.spotTargetPoints, setupScore: setup.setupScore,
      targetInfo: setup.targetInfo, entryAllowed: setup.entryAllowed, blockReason: setup.blockReason,
    };
  }
  return setup.active ? setup : null;
}

function renderTradeSetupMonitor(tsd) {
  if (typeof document === 'undefined') return;
  const box = document.getElementById('tradeSetupMonitorBox') || document.getElementById('pullbackContinuationBox');
  if (!box) return;
  if (!tsd || !tsd.active) { box.style.display = 'none'; return; }
  box.style.display = 'block';
  const tp = typeof fnoThemePalette === 'function' ? fnoThemePalette() : {
    panel: '#422006', line: '#92400e', text: '#e2e8f0', muted: '#94a3b8', pass: '#4ade80', fail: '#f87171', warn: '#fde68a',
  };
  box.style.background = tp.panel;
  box.style.borderColor = tp.line;
  box.style.color = tp.text;
  const sc = tsd.setupScore || {};
  const setupsHtml = (tsd.setups || []).slice(0, 5).map(s => {
    const stColor = s.state === FNO_TSE_SETUP_STATE.CONFIRMED ? tp.pass : s.state === FNO_TSE_SETUP_STATE.WAITING_FOR_CONFIRMATION ? tp.warn : tp.muted;
    return `<div style="font-size:10px;padding:2px 0"><span style="color:${stColor}">${fnoTseEscapeHtml(s.type)}</span> ${s.score}/100 · ${fnoTseEscapeHtml(s.state)}</div>`;
  }).join('') || `<div style="color:${tp.muted};font-size:10px">No active setups</div>`;

  const decColor = tsd.engineStatus === FNO_TSE_DECISION.TRADE_ALLOWED ? tp.pass : tsd.engineStatus === FNO_TSE_DECISION.SETUP_WAITING ? tp.warn : tp.fail;
  box.innerHTML = [
    `<b style="color:${tp.warn}">📊 Trade Setup Engine</b>`,
    `<div style="font-size:11px;margin-top:4px"><b>CURRENT MARKET</b></div>`,
    `Regime: ${fnoTseEscapeHtml(tsd.regime)} · Movement: <b>${fnoTseEscapeHtml(tsd.movementClass || '—')}</b> (score ${tsd.movementScore != null ? tsd.movementScore : '—'})`,
    `<div style="font-size:11px;margin-top:6px"><b>ACTIVE SETUPS</b></div>`,
    setupsHtml,
    `<div style="font-size:11px;margin-top:6px"><b>BEST SETUP</b></div>`,
    tsd.setup ? `${fnoTseEscapeHtml(tsd.setup.type)} · ${fnoTseEscapeHtml(tsd.setup.state)} · score ${sc.normalized != null ? sc.normalized : '—'}/100` : 'None',
    tsd.spotTargetPoints ? `Target: <b>${tsd.spotTargetPoints} points</b> · confidence ${((tsd.targetConfidence || 0) * 100).toFixed(0)}%` : 'Target: —',
    tsd.blockers && tsd.blockers.length ? `<span style="color:${tp.fail}">Blockers: ${fnoTseEscapeHtml(tsd.blockers.join('; '))}</span>` : '',
    `<div style="margin-top:6px;font-size:12px;color:${decColor}"><b>Decision: ${fnoTseEscapeHtml(tsd.engineStatus)}</b>${tsd.entryAllowed ? ' — TRADE ALLOWED' : tsd.decision === 'WAIT' ? ' — WAIT' : ' — NO TRADE'}</div>`,
    tsd.blockReason ? `<div style="font-size:10px;color:${tp.muted}">${fnoTseEscapeHtml(tsd.blockReason)}</div>` : '',
  ].join('');
}

function renderPullbackContinuationPanel(setup) {
  renderTradeSetupMonitor(setup && setup.engineStatus ? setup : null);
}

function recordTradeSetupOutcome(tradeId, outcome) {
  try {
    const log = getTradeSetupDecisionLog();
    for (let i = log.length - 1; i >= 0; i--) {
      if (!log[i].outcome && log[i].entryAllowed) {
        log[i].outcome = outcome;
        log[i].tradeId = tradeId;
        break;
      }
    }
    localStorage.setItem(FNO_TSE_LOG_KEY, JSON.stringify(log));
  } catch (e) { /* no-op */ }
}
