/**
 * ScalpingProfitEngine — independent layered decision pipeline for short-duration
 * scalping opportunities. Works alongside (never replaces) existing brain/TSE logic.
 * Pipeline: Market Safety → Regime → Direction → Setup → Entry Timing → Trade Quality
 * → Execution Quality → Enter/NO TRADE → Active Management → Analytics.
 */
const FNO_SPE_LOG_KEY = 'fno_scalping_profit_log_v1';
const FNO_SPE_LOG_MAX = 4000;
const FNO_SPE_STATS_KEY = 'fno_scalping_profit_stats_v1';
const FNO_SPE_STATE_KEY = 'fno_scalping_profit_session_v1';
const FNO_SPE_MISSED_KEY = 'fno_scalping_profit_missed_v1';
const FNO_SPE_LEARNING_KEY = 'fno_scalping_profit_learning_v1';
const FNO_SPE_MIN_SAMPLE_WF = 12;
const FNO_SPE_MIN_SAMPLE_MC = 8;
const FNO_SPE_TRAIN_SPLIT = 0.6;
const FNO_SPE_VALIDATION_SPLIT = 0.2;
const FNO_SPE_MC_SIMULATIONS = 500;

const FNO_SPE_MODE = { OFF: 'OFF', ON: 'ON', PAPER_ONLY: 'PAPER_ONLY', SIGNAL_ONLY: 'SIGNAL_ONLY' };

const FNO_SPE_REGIME = {
  TREND_UP: 'TREND_UP', TREND_DOWN: 'TREND_DOWN', RANGE: 'RANGE',
  BREAKOUT: 'BREAKOUT', BREAKDOWN: 'BREAKDOWN', HIGH_VOLATILITY: 'HIGH_VOLATILITY',
  LOW_VOLATILITY: 'LOW_VOLATILITY', CHOP: 'CHOP', EXHAUSTION: 'EXHAUSTION', UNKNOWN: 'UNKNOWN',
};

const FNO_SPE_SETUP = {
  MOMENTUM_CONTINUATION: 'momentum_continuation',
  PULLBACK_ENTRY: 'pullback_entry',
  BREAKOUT: 'breakout',
  BREAKOUT_FAILURE: 'breakout_failure',
  MOMENTUM_EXHAUSTION: 'momentum_exhaustion',
  RANGE_REVERSAL: 'range_reversal',
};

const FNO_SPE_EXIT = {
  TARGET_HIT: 'TARGET_HIT', STOP_HIT: 'STOP_HIT', MOMENTUM_FAILURE: 'MOMENTUM_FAILURE',
  STRUCTURE_FAILURE: 'STRUCTURE_FAILURE', TIME_STOP: 'TIME_STOP', EXHAUSTION: 'EXHAUSTION',
  OPPOSITE_SIGNAL: 'OPPOSITE_SIGNAL', PROFIT_PROTECTION: 'PROFIT_PROTECTION',
  RISK_LOCK: 'RISK_LOCK', MANUAL_OVERRIDE: 'MANUAL_OVERRIDE', SYSTEM_ERROR: 'SYSTEM_ERROR',
};

const FNO_SPE_NO_TRADE = {
  LOW_SCORE: 'LOW_SCORE', WIDE_SPREAD: 'WIDE_SPREAD', INSUFFICIENT_LIQUIDITY: 'INSUFFICIENT_LIQUIDITY',
  EXCESSIVE_EXTENSION: 'EXCESSIVE_EXTENSION', INSUFFICIENT_ROOM: 'INSUFFICIENT_ROOM',
  LOW_REGIME_CONFIDENCE: 'LOW_REGIME_CONFIDENCE', CHOP: 'CHOP', EVENT_RISK: 'EVENT_RISK',
  EXPECTED_MOVE_TOO_SMALL: 'EXPECTED_MOVE_TOO_SMALL', POOR_RR: 'POOR_RR',
  EXCESSIVE_SLIPPAGE: 'EXCESSIVE_SLIPPAGE', COOLDOWN: 'COOLDOWN', DAILY_LIMIT: 'DAILY_LIMIT',
  CONSECUTIVE_LOSS_LIMIT: 'CONSECUTIVE_LOSS_LIMIT', SESSION_LOCK: 'SESSION_LOCK',
  STALE_DATA: 'STALE_DATA', ENGINE_OFF: 'ENGINE_OFF', SIGNAL_ONLY: 'SIGNAL_ONLY',
  WEAK_DIRECTION: 'WEAK_DIRECTION', NO_SETUP: 'NO_SETUP', THESIS_UNCLEAR: 'THESIS_UNCLEAR',
};

const FNO_SPE_FAILURE = {
  LATE_ENTRY: 'Late Entry', CHASING: 'Chasing', FALSE_BREAKOUT: 'False Breakout',
  MOMENTUM_FAILURE: 'Momentum Failure', TREND_FAILURE: 'Trend Failure', RANGE_NOISE: 'Range Noise',
  POOR_LIQUIDITY: 'Poor Liquidity', WIDE_SPREAD: 'Wide Spread', SLIPPAGE: 'Slippage',
  UNEXPECTED_VOLATILITY: 'Unexpected Volatility', SR_COLLISION: 'Support/Resistance Collision',
  INSUFFICIENT_ROOM: 'Insufficient Room', BAD_TIMING: 'Bad Timing', WRONG_REGIME: 'Wrong Regime',
  SIGNAL_CONFLICT: 'Signal Conflict', OVERTRADING: 'Overtrading', REPEATED_ENTRY: 'Repeated Entry',
  EXIT_TOO_LATE: 'Exit Too Late', EXIT_TOO_EARLY: 'Exit Too Early', TIME_DECAY: 'Time Decay',
  EXECUTION_FAILURE: 'Execution Failure', DATA_DELAY: 'Data Delay', DATA_QUALITY: 'Data Quality Issue',
};

const FNO_SPE_SESSION = {
  NORMAL: 'NORMAL', HOT: 'HOT', COLD: 'COLD', CAUTIOUS: 'CAUTIOUS', RECOVERY: 'RECOVERY', LOCKED: 'LOCKED',
};

const FNO_SPE_VELOCITY = {
  SLOW: 'SLOW', NORMAL: 'NORMAL', FAST: 'FAST', EXPLOSIVE: 'EXPLOSIVE',
  DECELERATING: 'DECELERATING', EXHAUSTING: 'EXHAUSTING',
};

const FNO_SPE_SETTING_META = {
  scalpingProfitEngineEnabled: { default: true, min: 0, max: 1, desc: 'Master SPE toggle', reason: 'Independent scalping methodology layer' },
  scalpingProfitEngineMode: { default: 'PAPER_ONLY', allowed: ['OFF', 'ON', 'PAPER_ONLY', 'SIGNAL_ONLY'], desc: 'Runtime mode', reason: 'Paper-first validation per spec §45' },
  speMinTradeQualityScore: { default: 70, min: 40, max: 95, desc: 'Minimum composite trade quality', reason: 'Below 60 = no trade per spec §14' },
  speMinRegimeConfidence: { default: 60, min: 30, max: 95, desc: 'Minimum regime confidence', reason: 'Avoid low-confidence regime trades' },
  speMinDirectionConfidence: { default: 65, min: 40, max: 95, desc: 'Minimum direction confidence', reason: 'Weak direction must not force trades' },
  speAntiChaseThresholdPct: { default: 75, min: 50, max: 95, desc: 'Max move completion % before reject', reason: 'Anti-chase engine §11' },
  speSpreadThresholdPct: { default: 0.12, min: 0.05, max: 0.5, desc: 'Max spread % of price', reason: 'Market safety §6' },
  speMinExpectedMovePts: { default: 8, min: 4, max: 30, desc: 'Minimum expected favorable move (pts)', reason: 'Must cover costs §15' },
  speMinRiskReward: { default: 1.5, min: 1, max: 4, desc: 'Minimum net R:R after costs', reason: 'Cost-aware profitability §27' },
  speMaxHoldingMinutes: { default: 12, min: 2, max: 60, desc: 'Maximum scalp holding time', reason: 'Time stop §18' },
  speTimeStopMinutes: { default: 8, min: 1, max: 30, desc: 'Thesis must develop within (min)', reason: 'Trade decay §19' },
  speMaxTradesPerSession: { default: 8, min: 1, max: 30, desc: 'Max trades per session', reason: 'Anti-overtrade §28' },
  speMaxConsecutiveLosses: { default: 3, min: 1, max: 10, desc: 'Consecutive loss cap', reason: 'Loss protection §23' },
  speCooldownSeconds: { default: 90, min: 0, max: 600, desc: 'Base cooldown after trade', reason: 'Cooldown engine §29' },
  speMinLiquidityScore: { default: 50, min: 20, max: 95, desc: 'Minimum liquidity score', reason: 'Market safety §6' },
  speMinTimeBetweenTradesSec: { default: 60, min: 0, max: 600, desc: 'Minimum gap between entries', reason: 'Trade frequency §28' },
  speChopMinScoreBoost: { default: 10, min: 0, max: 30, desc: 'Extra min score in chop', reason: 'Chop detector §34' },
  speTargetSlow: { default: 10, min: 5, max: 15, desc: 'Slow movement target pts', reason: 'Dynamic target §16' },
  speTargetMedium: { default: 15, min: 10, max: 20, desc: 'Medium movement target pts', reason: 'Dynamic target §16' },
  speTargetFast: { default: 20, min: 15, max: 30, desc: 'Fast movement target pts', reason: 'Dynamic target §16' },
  speProfitProtectionPct: { default: 50, min: 20, max: 90, desc: 'Protect % of target when momentum fades', reason: 'Profit protection §21' },
  speTradeHealthExitThreshold: { default: 30, min: 10, max: 60, desc: 'Exit when trade health below', reason: 'Trade decay §19' },
  speMomentumThreshold: { default: 55, min: 20, max: 90, desc: 'Minimum momentum score', reason: 'Momentum monitor §20' },
  speSlippageEstimatePts: { default: 0.5, min: 0.1, max: 5, desc: 'Expected slippage (spot pts)', reason: 'Execution quality §26' },
  speSafetyMarginPts: { default: 1, min: 0, max: 5, desc: 'Extra safety margin pts', reason: 'Market safety §6' },
  speEventRiskEnabled: { default: false, min: 0, max: 1, desc: 'Optional event-risk input', reason: 'Event risk §6 — off when no calendar' },
};

function fnoSpeEscapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function getScalpingProfitSettings() {
  const s = typeof fnoSettings !== 'undefined' ? fnoSettings.get() : {};
  const g = (key) => {
    const m = FNO_SPE_SETTING_META[key];
    const v = s[key];
    if (v != null && typeof v === typeof m.default) return v;
    return m ? m.default : null;
  };
  return {
    enabled: s.scalpingProfitEngineEnabled !== false,
    mode: g('scalpingProfitEngineMode') || FNO_SPE_MODE.PAPER_ONLY,
    minTradeQuality: g('speMinTradeQualityScore'),
    minRegimeConfidence: g('speMinRegimeConfidence'),
    minDirectionConfidence: g('speMinDirectionConfidence'),
    antiChasePct: g('speAntiChaseThresholdPct'),
    spreadThresholdPct: g('speSpreadThresholdPct'),
    minExpectedMove: g('speMinExpectedMovePts'),
    minRiskReward: g('speMinRiskReward'),
    maxHoldingMinutes: g('speMaxHoldingMinutes'),
    timeStopMinutes: g('speTimeStopMinutes'),
    maxTradesPerSession: g('speMaxTradesPerSession'),
    maxConsecutiveLosses: g('speMaxConsecutiveLosses'),
    cooldownSeconds: g('speCooldownSeconds'),
    minLiquidityScore: g('speMinLiquidityScore'),
    minTimeBetweenTrades: g('speMinTimeBetweenTradesSec'),
    chopScoreBoost: g('speChopMinScoreBoost'),
    targetSlow: g('speTargetSlow'),
    targetMedium: g('speTargetMedium'),
    targetFast: g('speTargetFast'),
    profitProtectionPct: g('speProfitProtectionPct'),
    tradeHealthExit: g('speTradeHealthExitThreshold'),
    momentumThreshold: g('speMomentumThreshold'),
    slippageEstimate: g('speSlippageEstimatePts'),
    safetyMargin: g('speSafetyMarginPts'),
    eventRiskEnabled: s.speEventRiskEnabled === true,
  };
}

function isScalpingProfitEngineActive() {
  if (typeof fnoSettings === 'undefined') return false;
  const s = fnoSettings.get();
  if (s.scalpingProfitEngineEnabled === false) return false;
  if (s.scalpingProfitEngineMode === FNO_SPE_MODE.OFF) return false;
  return !!(s.scalpingProfitProfileEnabled && s.tradingTypes && s.tradingTypes.scalping);
}

function isScalpingProfitEngineEntryBlocking() {
  if (!isScalpingProfitEngineActive()) return false;
  const mode = getScalpingProfitSettings().mode;
  return mode === FNO_SPE_MODE.ON || mode === FNO_SPE_MODE.PAPER_ONLY;
}

function buildSpeMarketState(ctx, brain) {
  if (typeof buildMarketState === 'function') return buildMarketState(ctx, brain);
  const candles = (ctx && ctx.candles) || [];
  const closes = candles.map(c => c.c).filter(Number.isFinite);
  const last = closes.length - 1;
  const spot = Number.isFinite(ctx && ctx.spot) ? ctx.spot : (last >= 0 ? closes[last] : null);
  return { valid: closes.length >= 21 && Number.isFinite(spot), spot, closes, candles, last, ctx, brain };
}

function getSpeSessionState() {
  try { return JSON.parse(localStorage.getItem(FNO_SPE_STATE_KEY) || '{}'); } catch (e) { return {}; }
}

function saveSpeSessionState(st) {
  try { localStorage.setItem(FNO_SPE_STATE_KEY, JSON.stringify(st)); } catch (e) { /* quota */ }
}

function evaluateMarketSafety(ms, ctx, cfg) {
  const out = {
    safe: true, liquidityScore: null, spreadPts: null, spreadPct: null, spreadOk: null, spreadSkipped: false,
    liquiditySkipped: false, staleDataSkipped: false, skippedLayers: [], unavailableInputs: [],
    slippageEstimate: cfg.slippageEstimate, volatilityClass: 'NORMAL', eventRisk: false,
    reasons: [], blockers: [],
  };
  if (!ms.valid) {
    out.skippedLayers.push('marketSafety');
    out.unavailableInputs.push('candles');
    out.reasons.push('Candle data unavailable — market safety skipped (not scored as fail)');
    return out;
  }
  const leg = ctx && ctx.ocRow && ctx.ocRow.CE;
  const bid = leg && Number.isFinite(leg.bidprice) ? leg.bidprice : null;
  const ask = leg && Number.isFinite(leg.askPrice) ? leg.askPrice : null;
  const hasDepth = leg && (Number.isFinite(leg.bidQty) || Number.isFinite(leg.askQty));
  const bidQty = hasDepth && Number.isFinite(leg.bidQty) ? leg.bidQty : null;
  const askQty = hasDepth && Number.isFinite(leg.askQty) ? leg.askQty : null;
  if (bid != null && ask != null && ask >= bid) {
    out.spreadPts = +(ask - bid).toFixed(4);
    out.spreadPct = bid > 0 ? +((out.spreadPts / bid) * 100).toFixed(3) : null;
    out.spreadOk = out.spreadPct == null || out.spreadPct <= cfg.spreadThresholdPct;
    if (!out.spreadOk) out.blockers.push(FNO_SPE_NO_TRADE.WIDE_SPREAD);
  } else {
    out.spreadSkipped = true;
    out.unavailableInputs.push('bidAsk');
    out.reasons.push('Bid/ask unavailable — spread check skipped (not treated as pass or fail)');
  }
  if (bidQty != null || askQty != null) {
    const depthScore = Math.min(100, Math.round(((bidQty || 0) + (askQty || 0)) / 100));
    out.liquidityScore = Math.max(depthScore, ms.atr && ms.atr > 0 ? 55 : 40);
    if (out.liquidityScore < cfg.minLiquidityScore) out.blockers.push(FNO_SPE_NO_TRADE.INSUFFICIENT_LIQUIDITY);
  } else {
    out.liquiditySkipped = true;
    out.unavailableInputs.push('orderBookDepth');
    out.reasons.push('Order-book depth unavailable — liquidity check skipped');
  }
  let volClass = 'NORMAL';
  if (typeof computeMarketRegime === 'function' && ms.regime) {
    if (ms.regime.volatility === 'High Vol') volClass = 'HIGH';
    else if (ms.regime.volatility === 'Low Vol') volClass = 'LOW';
  }
  out.volatilityClass = volClass;
  if (cfg.eventRiskEnabled && ctx && ctx.eventRisk === true) {
    out.eventRisk = true;
    out.blockers.push(FNO_SPE_NO_TRADE.EVENT_RISK);
  }
  if (ctx && typeof ctx.ocFetchedAt === 'number' && typeof checkOptionChainFreshness === 'function') {
    const fresh = checkOptionChainFreshness(ctx.ocFetchedAt, Date.now());
    if (fresh.stale) out.blockers.push(FNO_SPE_NO_TRADE.STALE_DATA);
  } else if (ctx && ctx.ocRow && typeof ctx.ocFetchedAt !== 'number') {
    out.staleDataSkipped = true;
    out.unavailableInputs.push('ocFetchedAt');
    out.reasons.push('Option-chain freshness timestamp unavailable — stale-data check skipped');
  }
  out.safe = out.blockers.length === 0;
  return out;
}

function detectSpeRegime(ms, ctx) {
  let regime = FNO_SPE_REGIME.UNKNOWN;
  let confidence = 40;
  let strength = 'LOW';
  if (!ms.valid) return { regime, confidence, strength, label: regime, skipped: true, unavailableInputs: ['candles'] };
  const br = ms.choppy ? 'choppy' : null;
  if (br || ms.choppy) {
    regime = FNO_SPE_REGIME.CHOP;
    confidence = 72;
    strength = 'MEDIUM';
  } else if (ms.regime) {
    const t = ms.regime.trend;
    const v = ms.regime.volatility;
    const ext = ms.regime.extendedStates || {};
    if (ext.breakout) { regime = FNO_SPE_REGIME.BREAKOUT; confidence = 78; strength = 'HIGH'; }
    else if (ext.breakdown) { regime = FNO_SPE_REGIME.BREAKDOWN; confidence = 78; strength = 'HIGH'; }
    else if (v === 'High Vol') { regime = FNO_SPE_REGIME.HIGH_VOLATILITY; confidence = 70; strength = 'MEDIUM'; }
    else if (v === 'Low Vol') { regime = FNO_SPE_REGIME.LOW_VOLATILITY; confidence = 65; strength = 'LOW'; }
    else if (t === 'Bullish') { regime = FNO_SPE_REGIME.TREND_UP; confidence = 75; strength = ms.emaSpreadPct > 0.2 ? 'HIGH' : 'MEDIUM'; }
    else if (t === 'Bearish') { regime = FNO_SPE_REGIME.TREND_DOWN; confidence = 75; strength = ms.emaSpreadPct < -0.2 ? 'HIGH' : 'MEDIUM'; }
    else { regime = FNO_SPE_REGIME.RANGE; confidence = 60; strength = 'MEDIUM'; }
  }
  if (typeof classifyMovement === 'function') {
    const mv = classifyMovement(ms);
    if (mv.movementClass === 'INSUFFICIENT' && ms.last >= 5) {
      const recent = ms.closes.slice(-5);
      const swing = Math.max(...recent) - Math.min(...recent);
      if (swing > (ms.atr || 1) * 3) { regime = FNO_SPE_REGIME.EXHAUSTION; confidence = 68; strength = 'MEDIUM'; }
    }
  }
  return { regime, confidence, strength, label: regime };
}

function evaluateSpeDirection(ms, regimeInfo, brain) {
  let direction = 'NEUTRAL';
  let confidence = 40;
  const reasons = [];
  if (!ms.valid) return { direction, confidence, reasons, skipped: true, unavailableInputs: ['candles'] };
  if (brain && brain.decision === 'BUY_READY') { direction = 'BULLISH'; confidence += 25; reasons.push('Brain BUY_READY'); }
  else if (brain && brain.decision === 'SELL_READY') { direction = 'BEARISH'; confidence += 25; reasons.push('Brain SELL_READY'); }
  if (ms.ema9 != null && ms.ema21 != null) {
    if (ms.ema9 > ms.ema21) { if (direction !== 'BEARISH') direction = 'BULLISH'; confidence += 15; reasons.push('EMA9>EMA21'); }
    else if (ms.ema9 < ms.ema21) { if (direction !== 'BULLISH') direction = 'BEARISH'; confidence += 15; reasons.push('EMA9<EMA21'); }
  }
  if (regimeInfo.regime === FNO_SPE_REGIME.TREND_UP && direction === 'BULLISH') confidence += 10;
  if (regimeInfo.regime === FNO_SPE_REGIME.TREND_DOWN && direction === 'BEARISH') confidence += 10;
  if (regimeInfo.regime === FNO_SPE_REGIME.CHOP) confidence -= 15;
  confidence = Math.max(0, Math.min(100, confidence));
  return { direction, confidence, reasons };
}

function detectSpeSetups(ms, directionInfo, regimeInfo) {
  const setups = [];
  if (!ms.valid || directionInfo.direction === 'NEUTRAL') return setups;
  const dir = directionInfo.direction;
  const look = ms.closes.slice(Math.max(0, ms.last - 8), ms.last + 1);
  const impulse = look.length >= 2 ? look[look.length - 1] - look[0] : 0;
  const pullback = look.length >= 4 ? look[look.length - 2] - look[look.length - 1] : 0;

  if (Math.abs(impulse) >= (ms.atr || 2) * 2 && Math.sign(impulse) === (dir === 'bullish' ? 1 : -1)) {
    setups.push({ type: FNO_SPE_SETUP.MOMENTUM_CONTINUATION, direction: dir, score: 70, reason: 'Directional impulse with trend alignment' });
  }
  if (Math.abs(pullback) >= (ms.atr || 1) * 0.5 && Math.sign(pullback) !== (dir === 'bullish' ? 1 : -1)) {
    setups.push({ type: FNO_SPE_SETUP.PULLBACK_ENTRY, direction: dir, score: 72, reason: 'Pullback within trend' });
  }
  if (regimeInfo.regime === FNO_SPE_REGIME.BREAKOUT && dir === 'bullish') {
    setups.push({ type: FNO_SPE_SETUP.BREAKOUT, direction: 'bullish', score: 75, reason: 'Breakout regime' });
  }
  if (regimeInfo.regime === FNO_SPE_REGIME.BREAKDOWN && dir === 'bearish') {
    setups.push({ type: FNO_SPE_SETUP.BREAKOUT, direction: 'bearish', score: 75, reason: 'Breakdown regime' });
  }
  if (regimeInfo.regime === FNO_SPE_REGIME.RANGE || regimeInfo.regime === FNO_SPE_REGIME.CHOP) {
    if (typeof headroomPoints === 'function') {
      const room = headroomPoints(ms, dir);
      if (room >= (ms.atr || 3) * 2) {
        setups.push({ type: FNO_SPE_SETUP.RANGE_REVERSAL, direction: dir, score: 62, reason: 'Range boundary rejection candidate' });
      }
    }
  }
  if (regimeInfo.regime === FNO_SPE_REGIME.EXHAUSTION) {
    setups.push({ type: FNO_SPE_SETUP.MOMENTUM_EXHAUSTION, direction: dir === 'bullish' ? 'bearish' : 'bullish', score: 58, reason: 'Exhaustion — exit/reversal watch only' });
  }
  if (typeof findAllSetups === 'function') {
    const tseSetups = findAllSetups(ms);
    tseSetups.forEach(ts => {
      if (ts.direction === dir && ts.state === 'CONFIRMED') {
        setups.push({ type: ts.type, direction: ts.direction, score: 80, reason: 'TSE confirmed: ' + ts.type, fromTse: true });
      }
    });
  }
  return setups.sort((a, b) => b.score - a.score);
}

function evaluateEntryTiming(ms, setup, cfg) {
  const out = { velocityClass: FNO_SPE_VELOCITY.SLOW, velocityPtsMin: 0, acceleration: 0, timingScore: 50, extended: false, reasons: [] };
  if (!ms.valid || !setup) return out;
  const lookback = Math.min(5, ms.last);
  const start = ms.closes[ms.last - lookback];
  out.velocityPtsMin = Math.abs(ms.spot - start) / Math.max(1, lookback);
  if (out.velocityPtsMin >= 3.5) out.velocityClass = FNO_SPE_VELOCITY.EXPLOSIVE;
  else if (out.velocityPtsMin >= 2) out.velocityClass = FNO_SPE_VELOCITY.FAST;
  else if (out.velocityPtsMin >= 0.8) out.velocityClass = FNO_SPE_VELOCITY.NORMAL;
  if (ms.last >= 6) {
    const v1 = ms.closes[ms.last] - ms.closes[ms.last - 3];
    const v0 = ms.closes[ms.last - 3] - ms.closes[ms.last - 6];
    out.acceleration = v1 - v0;
    if (out.acceleration < 0 && out.velocityClass === FNO_SPE_VELOCITY.FAST) out.velocityClass = FNO_SPE_VELOCITY.DECELERATING;
    if (out.acceleration < 0 && out.velocityPtsMin >= 2.5) out.velocityClass = FNO_SPE_VELOCITY.EXHAUSTING;
  }
  out.timingScore = out.velocityClass === FNO_SPE_VELOCITY.EXPLOSIVE ? 35 : out.velocityClass === FNO_SPE_VELOCITY.FAST ? 70 : out.velocityClass === FNO_SPE_VELOCITY.NORMAL ? 85 : 60;
  if (out.velocityClass === FNO_SPE_VELOCITY.EXHAUSTING || out.velocityClass === FNO_SPE_VELOCITY.EXPLOSIVE) {
    out.extended = true;
    out.reasons.push('Extended or exhausting velocity');
  }
  return out;
}

function evaluateAntiChase(ms, expectedMove, cfg) {
  if (!ms.valid || !expectedMove || !expectedMove.favorablePts) return { chasePct: 0, blocked: false };
  const lookback = Math.min(8, ms.last);
  const moveDone = Math.abs(ms.spot - ms.closes[ms.last - lookback]);
  const chasePct = expectedMove.favorablePts > 0 ? Math.round((moveDone / expectedMove.favorablePts) * 100) : 0;
  return { chasePct, blocked: chasePct >= cfg.antiChasePct, moveDone, expected: expectedMove.favorablePts };
}

function computeAvailableRoom(ms, direction, targetPts) {
  if (typeof headroomPoints === 'function') {
    const room = headroomPoints(ms, direction);
    return { roomPts: room, sufficient: room >= targetPts, nearestDist: room };
  }
  return { roomPts: ms.sessionHigh - ms.spot, sufficient: true, nearestDist: null };
}

function evaluateExpectedMove(ms, timing, cfg, movement) {
  let favorablePts = cfg.minExpectedMove;
  let adversePts = cfg.minExpectedMove * 0.5;
  const vel = timing.velocityClass;
  if (vel === FNO_SPE_VELOCITY.SLOW || (movement && movement.movementClass === 'SLOW')) {
    favorablePts = cfg.targetSlow;
    adversePts = cfg.targetSlow * 0.5;
  } else if (vel === FNO_SPE_VELOCITY.FAST || (movement && movement.movementClass === 'FAST')) {
    favorablePts = cfg.targetFast;
    adversePts = cfg.targetFast * 0.5;
  } else {
    favorablePts = cfg.targetMedium;
    adversePts = cfg.targetMedium * 0.5;
  }
  if (timing.expectedPoints5Min && Number.isFinite(timing.expectedPoints5Min)) {
    favorablePts = Math.max(favorablePts, Math.min(cfg.targetFast, timing.expectedPoints5Min));
  } else if (typeof classifyMovement === 'function') {
    const mv = movement || classifyMovement(ms);
    if (mv.targetPoints) favorablePts = mv.targetPoints;
  }
  const costPts = cfg.slippageEstimate + cfg.safetyMargin + (ms.spot > 0 ? (cfg.spreadThresholdPct / 100) * ms.spot * 0.01 : 0.5);
  const netEdge = favorablePts - adversePts - costPts;
  const rr = adversePts > 0 ? favorablePts / adversePts : 0;
  return {
    favorablePts, adversePts, holdingMinutes: cfg.timeStopMinutes, costPts, netEdge,
    netEdgePositive: netEdge > 0, riskReward: rr, estimatedSlippage: cfg.slippageEstimate,
  };
}

function evaluateTradeQuality(layers, cfg) {
  const weights = {
    marketSafety: 12, regime: 10, direction: 12, setup: 15, timing: 12,
    momentum: 8, room: 8, expectedMove: 10, execution: 8, session: 5,
  };
  const breakdown = {};
  const usedLayers = [];
  const skippedLayers = [];
  const scoreFor = (key, val) => {
    if (val == null) { skippedLayers.push(key); return null; }
    usedLayers.push(key);
    return val;
  };
  const safetySkipped = layers.safety && layers.safety.skippedLayers && layers.safety.skippedLayers.includes('marketSafety');
  breakdown.marketSafety = safetySkipped ? null : scoreFor('marketSafety', layers.safety ? (layers.safety.safe ? 90 : 30) : null);
  breakdown.regime = scoreFor('regime', layers.regime && !layers.regime.skipped ? layers.regime.confidence : null);
  breakdown.direction = scoreFor('direction', layers.direction && !layers.direction.skipped ? layers.direction.confidence : null);
  breakdown.setup = scoreFor('setup', layers.setup ? layers.setup.score : null);
  breakdown.timing = scoreFor('timing', layers.timing ? layers.timing.timingScore : null);
  breakdown.momentum = scoreFor('momentum', layers.timing ? (layers.timing.velocityPtsMin >= 1 ? 70 : 40) : null);
  breakdown.room = scoreFor('room', layers.room ? (layers.room.sufficient ? 85 : 25) : null);
  breakdown.expectedMove = scoreFor('expectedMove', layers.expectedMove ? (layers.expectedMove.netEdgePositive ? 80 : 35) : null);
  breakdown.execution = scoreFor('execution', layers.execution && !layers.execution.skipped ? layers.execution.score : null);
  breakdown.session = scoreFor('session', layers.session && layers.session.state !== FNO_SPE_SESSION.LOCKED ? 75 : (layers.session ? 20 : null));
  let sum = 0;
  let totalW = 0;
  Object.keys(weights).forEach(k => {
    if (breakdown[k] == null) return;
    sum += breakdown[k] * weights[k];
    totalW += weights[k];
  });
  const normalized = totalW > 0 ? Math.round(sum / totalW) : null;
  let grade = 'No Trade';
  if (normalized == null) grade = 'Insufficient Data';
  else if (normalized >= 90) grade = 'Exceptional';
  else if (normalized >= 80) grade = 'High Quality';
  else if (normalized >= 70) grade = 'Acceptable';
  else if (normalized >= 60) grade = 'Selective';
  return {
    normalized, grade, breakdown, weights, usedLayers, skippedLayers,
    layersUsedCount: usedLayers.length, layersSkippedCount: skippedLayers.length,
    recalculatedFromAvailableOnly: skippedLayers.length > 0,
  };
}

function buildSpeFactorDataAvailability(ms, layers, quality, safety, execution) {
  const unavailable = [];
  const used = [];
  const layerMap = [
    ['marketSafety', layers.safety, layers.safety && layers.safety.skippedLayers && layers.safety.skippedLayers.includes('marketSafety')],
    ['regime', layers.regime, layers.regime && layers.regime.skipped],
    ['direction', layers.direction, layers.direction && layers.direction.skipped],
    ['setup', layers.setup, !layers.setup],
    ['timing', layers.timing, !ms.valid || !layers.setup],
    ['execution', layers.execution, layers.execution && layers.execution.skipped],
  ];
  layerMap.forEach(([name, layer, skipped]) => {
    if (skipped) unavailable.push(name);
    else if (layer) used.push(name);
  });
  (safety && safety.unavailableInputs || []).forEach(u => { if (!unavailable.includes(u)) unavailable.push(u); });
  const skippedCount = unavailable.length;
  const usedCount = used.length;
  const total = skippedCount + usedCount;
  const coveragePct = total > 0 ? +(usedCount / total * 100).toFixed(1) : 0;
  let missingDataImpact = 'none';
  if (!ms.valid || coveragePct < 35) missingDataImpact = 'high';
  else if (coveragePct < 60 || skippedCount >= 2) missingDataImpact = 'low';
  return {
    unavailableInputs: unavailable,
    usedLayers: used,
    coveragePct,
    tradeQualityScoreAvailableOnly: quality && quality.normalized != null ? quality.normalized : null,
    missingDataImpact,
    decisionAffectedByMissingData: skippedCount > 0,
    summary: `${usedCount} SPE layers used, ${skippedCount} skipped — unavailable inputs never scored as fail`,
  };
}

function evaluateExecutionQuality(ctx, expectedMove, cfg) {
  const leg = ctx && ctx.ocRow && ctx.ocRow.CE;
  const spreadPct = leg && leg.bidprice > 0 && leg.askPrice > leg.bidprice
    ? ((leg.askPrice - leg.bidprice) / leg.bidprice) * 100 : null;
  if (spreadPct == null) {
    return { score: null, spreadPct: null, slippageVsProfit: null, acceptable: true, skipped: true,
      reason: 'Spread data unavailable — execution quality skipped (not scored as fail)' };
  }
  let score = 75;
  if (spreadPct > cfg.spreadThresholdPct) score -= 30;
  const slippageVsProfit = expectedMove && expectedMove.favorablePts > 0
    ? (cfg.slippageEstimate / expectedMove.favorablePts) * 100 : 0;
  if (slippageVsProfit > 25) score -= 20;
  return { score: Math.max(0, score), spreadPct, slippageVsProfit, acceptable: score >= 55, skipped: false };
}

function evaluateSpeSessionState(journalToday, regimeInfo, cfg) {
  const st = getSpeSessionState();
  const today = journalToday || st.journalToday || [];
  const speTrades = today.filter(t => t.source === 'scalping_profit' || (t.factorSnapshot && t.factorSnapshot.scalpingProfitDecision));
  const losses = speTrades.filter(t => typeof t.pnl === 'number' && t.pnl < 0);
  const wins = speTrades.filter(t => typeof t.pnl === 'number' && t.pnl > 0);
  let consecutiveLosses = 0;
  for (let i = speTrades.length - 1; i >= 0; i--) {
    if (typeof speTrades[i].pnl !== 'number') continue;
    if (speTrades[i].pnl < 0) consecutiveLosses++;
    else break;
  }
  let state = FNO_SPE_SESSION.NORMAL;
  if (st.locked) state = FNO_SPE_SESSION.LOCKED;
  else if (consecutiveLosses >= cfg.maxConsecutiveLosses) state = FNO_SPE_SESSION.CAUTIOUS;
  else if (losses.length >= cfg.maxConsecutiveLosses) state = FNO_SPE_SESSION.RECOVERY;
  else if (wins.length >= 3 && losses.length === 0) state = FNO_SPE_SESSION.HOT;
  else if (losses.length > wins.length) state = FNO_SPE_SESSION.COLD;
  if (regimeInfo.regime === FNO_SPE_REGIME.CHOP) state = state === FNO_SPE_SESSION.HOT ? FNO_SPE_SESSION.CAUTIOUS : state;
  const minScoreBoost = regimeInfo.regime === FNO_SPE_REGIME.CHOP ? cfg.chopScoreBoost : 0;
  return { state, consecutiveLosses, tradeCount: speTrades.length, minScoreBoost, locked: state === FNO_SPE_SESSION.LOCKED };
}

function evaluateSpeCooldown(cfg) {
  const st = getSpeSessionState();
  if (!st.lastTradeTs) return { allowed: true, remainingSec: 0 };
  const elapsed = (Date.now() - st.lastTradeTs) / 1000;
  let cd = cfg.cooldownSeconds;
  if (st.lastExitReason === FNO_SPE_EXIT.STOP_HIT) cd = Math.max(cd, cfg.cooldownSeconds * 1.5);
  if (st.consecutiveLosses >= 2) cd = Math.max(cd, cfg.cooldownSeconds * 2);
  const remaining = Math.max(0, cd - elapsed);
  return { allowed: remaining <= 0, remainingSec: Math.ceil(remaining), cooldownSec: cd };
}

function evaluateAntiOvertrade(sessionInfo, cooldown, cfg) {
  const blockers = [];
  if (sessionInfo.tradeCount >= cfg.maxTradesPerSession) blockers.push(FNO_SPE_NO_TRADE.DAILY_LIMIT);
  if (sessionInfo.consecutiveLosses >= cfg.maxConsecutiveLosses) blockers.push(FNO_SPE_NO_TRADE.CONSECUTIVE_LOSS_LIMIT);
  if (sessionInfo.locked) blockers.push(FNO_SPE_NO_TRADE.SESSION_LOCK);
  if (!cooldown.allowed) blockers.push(FNO_SPE_NO_TRADE.COOLDOWN);
  return { allowed: blockers.length === 0, blockers };
}

function buildSpeExplanation(layers, quality, decision) {
  const lines = [];
  if (decision === 'ENTER') {
    lines.push(`SCALP ${layers.direction && layers.direction.direction === 'bearish' ? 'SHORT' : 'LONG'}`);
    lines.push(`Regime: ${layers.regime ? layers.regime.regime : '?'} (${layers.regime ? layers.regime.confidence : 0}%)`);
    lines.push(`Setup: ${layers.setup ? layers.setup.type : 'none'}`);
    lines.push(`Trade Quality: ${quality.normalized}/100 (${quality.grade})`);
    if (layers.expectedMove) {
      lines.push(`Expected Move: ${layers.expectedMove.favorablePts}pt / Risk: ${layers.expectedMove.adversePts}pt`);
      lines.push(`Target: ${layers.expectedMove.favorablePts} Stop: ${layers.expectedMove.adversePts}`);
    }
    if (layers.room) lines.push(`Available Room: ${layers.room.roomPts != null ? layers.room.roomPts.toFixed(1) : '?'} points`);
    lines.push('Invalidation: Momentum collapse OR structure break OR time decay');
  } else {
    lines.push(`NO TRADE — ${layers.noTradeReason || 'Insufficient edge'}`);
  }
  return lines.join('\n');
}

function computeScalpingProfitEngine(ctx, brain, opts) {
  opts = opts || {};
  const cfg = getScalpingProfitSettings();
  const ms = buildSpeMarketState(ctx, brain);
  const movement = typeof classifyMovement === 'function' ? classifyMovement(ms) : null;

  if (!isScalpingProfitEngineActive()) {
    return {
      active: false, decision: 'BYPASS', entryAllowed: true, mode: cfg.mode,
      reasons: ['Scalping Profit Engine disabled or OFF mode'],
    };
  }

  const journalToday = opts.journalToday || [];
  const safety = evaluateMarketSafety(ms, ctx, cfg);
  const regime = detectSpeRegime(ms, ctx);
  const direction = evaluateSpeDirection(ms, regime, brain);
  const setups = detectSpeSetups(ms, direction, regime);
  const bestSetup = setups[0] || null;
  const timing = evaluateEntryTiming(ms, bestSetup, cfg);
  const expectedMove = evaluateExpectedMove(ms, timing, cfg, movement);
  const antiChase = evaluateAntiChase(ms, expectedMove, cfg);
  const room = bestSetup ? computeAvailableRoom(ms, bestSetup.direction, expectedMove.favorablePts) : { sufficient: false, roomPts: 0 };
  const execution = evaluateExecutionQuality(ctx, expectedMove, cfg);
  const session = evaluateSpeSessionState(journalToday, regime, cfg);
  const cooldown = evaluateSpeCooldown(cfg);
  const overtrade = evaluateAntiOvertrade(session, cooldown, cfg);

  const layers = { safety, regime, direction, setup: bestSetup, timing, expectedMove, room, execution, session };
  const quality = evaluateTradeQuality(layers, cfg);
  const factorDataAvailability = buildSpeFactorDataAvailability(ms, layers, quality, safety, execution);
  const minScore = cfg.minTradeQuality + session.minScoreBoost;

  const blockers = [];
  const noTradeReasons = [];

  if (!safety.skippedLayers.includes('marketSafety') && !safety.safe) { blockers.push(...safety.blockers); noTradeReasons.push(...safety.blockers); }
  if (!regime.skipped && regime.confidence < cfg.minRegimeConfidence) { blockers.push(FNO_SPE_NO_TRADE.LOW_REGIME_CONFIDENCE); noTradeReasons.push(FNO_SPE_NO_TRADE.LOW_REGIME_CONFIDENCE); }
  if (!direction.skipped && direction.confidence < cfg.minDirectionConfidence) { blockers.push(FNO_SPE_NO_TRADE.WEAK_DIRECTION); noTradeReasons.push(FNO_SPE_NO_TRADE.WEAK_DIRECTION); }
  if (ms.valid && !bestSetup) { blockers.push(FNO_SPE_NO_TRADE.NO_SETUP); noTradeReasons.push(FNO_SPE_NO_TRADE.NO_SETUP); }
  if (ms.valid && antiChase.blocked) { blockers.push(FNO_SPE_NO_TRADE.EXCESSIVE_EXTENSION); noTradeReasons.push(FNO_SPE_NO_TRADE.EXCESSIVE_EXTENSION); }
  if (ms.valid && !room.sufficient && bestSetup && bestSetup.type !== FNO_SPE_SETUP.BREAKOUT) {
    blockers.push(FNO_SPE_NO_TRADE.INSUFFICIENT_ROOM); noTradeReasons.push(FNO_SPE_NO_TRADE.INSUFFICIENT_ROOM);
  }
  if (ms.valid && expectedMove.favorablePts < cfg.minExpectedMove) { blockers.push(FNO_SPE_NO_TRADE.EXPECTED_MOVE_TOO_SMALL); noTradeReasons.push(FNO_SPE_NO_TRADE.EXPECTED_MOVE_TOO_SMALL); }
  if (ms.valid && expectedMove.riskReward < cfg.minRiskReward) { blockers.push(FNO_SPE_NO_TRADE.POOR_RR); noTradeReasons.push(FNO_SPE_NO_TRADE.POOR_RR); }
  if (ms.valid && !expectedMove.netEdgePositive) { blockers.push(FNO_SPE_NO_TRADE.POOR_RR); }
  if (!execution.skipped && !execution.acceptable) { blockers.push(FNO_SPE_NO_TRADE.EXCESSIVE_SLIPPAGE); noTradeReasons.push(FNO_SPE_NO_TRADE.EXCESSIVE_SLIPPAGE); }
  if (quality.normalized != null && quality.normalized < minScore) { blockers.push(FNO_SPE_NO_TRADE.LOW_SCORE); noTradeReasons.push(FNO_SPE_NO_TRADE.LOW_SCORE); }
  if (ms.valid && regime.regime === FNO_SPE_REGIME.CHOP && quality.normalized != null && quality.normalized < minScore + cfg.chopScoreBoost) {
    blockers.push(FNO_SPE_NO_TRADE.CHOP); noTradeReasons.push(FNO_SPE_NO_TRADE.CHOP);
  }
  if (!overtrade.allowed) { blockers.push(...overtrade.blockers); noTradeReasons.push(...overtrade.blockers); }
  if (typeof checkScalpingCapitalPreservation === 'function' && brain && (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY')) {
    const cp = checkScalpingCapitalPreservation(brain, ctx || {}, { journalToday });
    if (!cp.allowed) { blockers.push(FNO_SPE_NO_TRADE.DAILY_LIMIT); noTradeReasons.push(FNO_SPE_NO_TRADE.DAILY_LIMIT); }
  }

  layers.noTradeReason = noTradeReasons[0] || null;
  const brainAligned = brain && (
    (brain.decision === 'BUY_READY' && direction.direction === 'BULLISH') ||
    (brain.decision === 'SELL_READY' && direction.direction === 'BEARISH')
  );

  let decision = 'NO_TRADE';
  let entryAllowed = false;
  if (blockers.length === 0 && brainAligned && bestSetup) {
    decision = 'ENTER';
    entryAllowed = cfg.mode !== FNO_SPE_MODE.SIGNAL_ONLY;
  } else if (cfg.mode === FNO_SPE_MODE.SIGNAL_ONLY && blockers.length === 0 && bestSetup) {
    decision = 'SIGNAL';
    entryAllowed = true;
  }

  if (cfg.mode === FNO_SPE_MODE.SIGNAL_ONLY && blockers.length) entryAllowed = true;

  const explanation = buildSpeExplanation(layers, quality, decision);
  const dirOpt = direction.direction === 'bearish' ? 'PE' : direction.direction === 'bullish' ? 'CE' : null;

  return {
    active: true,
    ts: Date.now(),
    mode: cfg.mode,
    decision,
    entryAllowed: entryAllowed && brainAligned,
    engineStatus: decision,
    regime: regime.regime,
    regimeConfidence: regime.confidence,
    regimeStrength: regime.strength,
    direction: direction.direction,
    directionConfidence: direction.confidence,
    setup: bestSetup,
    setups,
    timing,
    movement,
    expectedMove,
    antiChase,
    room,
    safety,
    execution,
    session: session.state,
    sessionDetail: session,
    quality,
    tradeQualityScore: quality.normalized,
    tradeQualityGrade: quality.grade,
    spotTargetPoints: expectedMove.favorablePts,
    spotStopPoints: expectedMove.adversePts,
    spotTargetPrice: Number.isFinite(ms.spot) && bestSetup
      ? +(ms.spot + (bestSetup.direction === 'bullish' ? 1 : -1) * expectedMove.favorablePts).toFixed(2) : null,
    blockers,
    noTradeReasons,
    noTradeReason: layers.noTradeReason,
    explanation,
    settings: cfg,
    optionType: dirOpt,
    layers,
    factorDataAvailability,
    decisionAffectedByMissingData: factorDataAvailability.decisionAffectedByMissingData,
  };
}

function applyScalpingProfitInfluence(brain, spe) {
  if (!brain || !spe || !spe.active) return brain;
  brain.scalpingProfitDecision = spe;
  if (!isScalpingProfitEngineEntryBlocking()) return brain;
  if (spe.entryAllowed) return brain;
  if (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY') {
    brain.decision = spe.decision === 'NO_TRADE' ? 'NO_TRADE' : 'WAIT';
    brain.reason = (brain.reason || '') + ` [SPE: ${spe.noTradeReason || (spe.blockers && spe.blockers[0]) || spe.engineStatus}]`;
    brain.scalpingProfitBlocked = true;
  }
  return brain;
}

function checkScalpingProfitEntryGate(brain, ctx, optionType) {
  if (!isScalpingProfitEngineEntryBlocking()) return { allowed: true };
  const spe = (brain && brain.scalpingProfitDecision)
    ? brain.scalpingProfitDecision
    : computeScalpingProfitEngine(ctx, brain, {});
  if (!spe.active) return { allowed: true };
  const dir = optionType === 'PE' ? 'bearish' : 'bullish';
  if (spe.entryAllowed && spe.setup && spe.setup.direction === dir) return { allowed: true, spe };
  return {
    allowed: false,
    reason: `Scalping Profit Engine: ${spe.noTradeReason || (spe.blockers && spe.blockers[0]) || spe.engineStatus}`,
    spe,
  };
}

function resolveScalpingProfitBracket(optPrice, ctx, brain, tradingType, optionType) {
  if (!isScalpingProfitEngineActive() || tradingType !== 'scalping') return null;
  const spe = (brain && brain.scalpingProfitDecision)
    ? brain.scalpingProfitDecision
    : computeScalpingProfitEngine(ctx, brain, {});
  if (!spe.active || !spe.entryAllowed || !spe.spotTargetPoints) return null;
  if (typeof convertSpotTargetToOptionBracket === 'function') {
    const dir = spe.setup ? spe.setup.direction : (optionType === 'PE' ? 'bearish' : 'bullish');
    const bracket = convertSpotTargetToOptionBracket(optPrice, ctx, spe.spotTargetPoints, dir);
    if (bracket && bracket.target && bracket.sl) {
      return { ...bracket, source: 'scalping_profit_engine', spe };
    }
  }
  return null;
}

function computeTradeHealth(open, ctx, brain) {
  if (!open || !open.entrySpot || !ctx || !Number.isFinite(ctx.spot)) return { health: 50, phase: 'NORMAL' };
  const spe = open.scalpingProfitSnapshot || (brain && brain.scalpingProfitDecision);
  const favorable = open.optionType === 'PE'
    ? open.entrySpot - ctx.spot
    : ctx.spot - open.entrySpot;
  const targetPts = spe && spe.spotTargetPoints ? spe.spotTargetPoints : 15;
  const progress = targetPts > 0 ? Math.min(100, (favorable / targetPts) * 100) : 0;
  const holdMin = open.openedAt ? (Date.now() - open.openedAt) / 60000 : 0;
  const cfg = getScalpingProfitSettings();
  let health = 75;
  if (progress >= 50) health += 15;
  if (favorable < 0) health -= 25;
  if (holdMin > cfg.timeStopMinutes && progress < 30) health -= 30;
  if (holdMin > cfg.maxHoldingMinutes) health -= 40;
  health = Math.max(0, Math.min(100, health));
  let phase = 'NORMAL';
  if (health >= 75) phase = 'HEALTHY';
  else if (health >= 60) phase = 'NORMAL';
  else if (health >= 45) phase = 'WEAKENING';
  else if (health >= 30) phase = 'POOR';
  else phase = 'EXIT_CANDIDATE';
  return { health, phase, progress, holdMin, favorable };
}

function evaluateScalpingProfitExit(open, ctx, brain, livePrice) {
  if (!open || !open.scalpingProfitTrade) return null;
  const cfg = getScalpingProfitSettings();
  const health = computeTradeHealth(open, ctx, brain);
  const holdMin = open.openedAt ? (Date.now() - open.openedAt) / 60000 : 0;

  if (health.health < cfg.tradeHealthExit) {
    return { exit: true, reason: FNO_SPE_EXIT.MOMENTUM_FAILURE, label: 'SPE trade health collapsed' };
  }
  if (holdMin >= cfg.maxHoldingMinutes) {
    return { exit: true, reason: FNO_SPE_EXIT.TIME_STOP, label: 'SPE max holding time' };
  }
  if (holdMin >= cfg.timeStopMinutes && health.progress < 25) {
    return { exit: true, reason: FNO_SPE_EXIT.TIME_STOP, label: 'SPE thesis time decay' };
  }
  if (health.progress >= cfg.profitProtectionPct && health.phase === 'WEAKENING') {
    return { exit: true, reason: FNO_SPE_EXIT.PROFIT_PROTECTION, label: 'SPE profit protection' };
  }
  if (brain && open.optionType === 'CE' && brain.decision === 'SELL_READY') {
    return { exit: true, reason: FNO_SPE_EXIT.OPPOSITE_SIGNAL, label: 'SPE opposite brain signal' };
  }
  if (brain && open.optionType === 'PE' && brain.decision === 'BUY_READY') {
    return { exit: true, reason: FNO_SPE_EXIT.OPPOSITE_SIGNAL, label: 'SPE opposite brain signal' };
  }
  return null;
}

function logScalpingProfitDecision(spe, sym, brain) {
  if (!spe || !spe.active) return;
  try {
    const log = JSON.parse(localStorage.getItem(FNO_SPE_LOG_KEY) || '[]');
    log.push({
      ts: spe.ts, sym,
      decision: spe.decision, entryAllowed: spe.entryAllowed,
      regime: spe.regime, regimeConfidence: spe.regimeConfidence,
      direction: spe.direction, directionConfidence: spe.directionConfidence,
      setupType: spe.setup ? spe.setup.type : null,
      tradeQualityScore: spe.tradeQualityScore, tradeQualityGrade: spe.tradeQualityGrade,
      spotTargetPoints: spe.spotTargetPoints, spotStopPoints: spe.spotStopPoints,
      noTradeReasons: spe.noTradeReasons, blockers: spe.blockers,
      brainDecision: brain ? brain.decision : null,
      mode: spe.mode, explanation: spe.explanation,
    });
    while (log.length > FNO_SPE_LOG_MAX) log.shift();
    localStorage.setItem(FNO_SPE_LOG_KEY, JSON.stringify(log));
    if (spe.decision === 'NO_TRADE' && spe.tradeQualityScore >= 75) {
      const missed = JSON.parse(localStorage.getItem(FNO_SPE_MISSED_KEY) || '[]');
      missed.push({ ts: spe.ts, sym, score: spe.tradeQualityScore, reason: spe.noTradeReason, spe: { regime: spe.regime, setup: spe.setup && spe.setup.type } });
      while (missed.length > 500) missed.shift();
      localStorage.setItem(FNO_SPE_MISSED_KEY, JSON.stringify(missed));
    }
  } catch (e) { /* quota */ }
}

function getScalpingProfitLog() {
  try { return JSON.parse(localStorage.getItem(FNO_SPE_LOG_KEY) || '[]'); } catch (e) { return []; }
}

function classifySpeFailureMode(outcome, spe) {
  if (!outcome || outcome.pnl >= 0) return null;
  const reason = outcome.exitReason || '';
  if (reason === FNO_SPE_EXIT.TIME_STOP) return FNO_SPE_FAILURE.TIME_DECAY;
  if (spe && spe.antiChase && spe.antiChase.chasePct > 70) return FNO_SPE_FAILURE.CHASING;
  if (spe && spe.noTradeReasons && spe.noTradeReasons.includes(FNO_SPE_NO_TRADE.WIDE_SPREAD)) return FNO_SPE_FAILURE.WIDE_SPREAD;
  if (reason === FNO_SPE_EXIT.MOMENTUM_FAILURE) return FNO_SPE_FAILURE.MOMENTUM_FAILURE;
  if (reason === FNO_SPE_EXIT.STOP_HIT) return FNO_SPE_FAILURE.TREND_FAILURE;
  return FNO_SPE_FAILURE.BAD_TIMING;
}

function recordScalpingProfitOutcome(tradeId, outcome) {
  try {
    const log = getScalpingProfitLog();
    const entry = log.filter(e => e.tradeId === tradeId).pop();
    if (entry) {
      entry.outcome = outcome;
      entry.failureMode = classifySpeFailureMode(outcome, entry.spe);
      localStorage.setItem(FNO_SPE_LOG_KEY, JSON.stringify(log));
    }
    const st = getSpeSessionState();
    st.lastTradeTs = Date.now();
    st.lastExitReason = outcome.exitReason;
    if (typeof outcome.pnl === 'number' && outcome.pnl < 0) st.consecutiveLosses = (st.consecutiveLosses || 0) + 1;
    else st.consecutiveLosses = 0;
    saveSpeSessionState(st);
  } catch (e) { /* quota */ }
}

function linkScalpingProfitTradeId(tradeId, spe) {
  try {
    const log = getScalpingProfitLog();
    if (log.length) {
      log[log.length - 1].tradeId = tradeId;
      log[log.length - 1].spe = { regime: spe.regime, setup: spe.setup && spe.setup.type, quality: spe.tradeQualityScore };
      localStorage.setItem(FNO_SPE_LOG_KEY, JSON.stringify(log));
    }
  } catch (e) { /* quota */ }
}

function computeScalpingProfitStatistics(log) {
  log = log || getScalpingProfitLog();
  const completed = log.filter(e => e.outcome && typeof e.outcome.pnl === 'number');
  let wins = 0, losses = 0, grossWins = 0, grossLosses = 0, totalPnl = 0;
  const bySetup = {};
  const byRegime = {};
  const byNoTrade = {};
  log.filter(e => e.decision === 'NO_TRADE').forEach(e => {
    (e.noTradeReasons || [e.noTradeReason || 'unknown']).forEach(r => {
      byNoTrade[r] = (byNoTrade[r] || 0) + 1;
    });
  });
  completed.forEach(e => {
    const pnl = e.outcome.pnl;
    totalPnl += pnl;
    if (pnl >= 0) { wins++; grossWins += pnl; } else { losses++; grossLosses += Math.abs(pnl); }
    const sk = e.setupType || 'unknown';
    if (!bySetup[sk]) bySetup[sk] = { n: 0, wins: 0, pnl: 0 };
    bySetup[sk].n++; bySetup[sk].pnl += pnl; if (pnl >= 0) bySetup[sk].wins++;
    const rk = e.regime || 'unknown';
    if (!byRegime[rk]) byRegime[rk] = { n: 0, wins: 0, pnl: 0 };
    byRegime[rk].n++; byRegime[rk].pnl += pnl; if (pnl >= 0) byRegime[rk].wins++;
  });
  const n = completed.length;
  const winRate = n ? (wins / n) * 100 : 0;
  const avgWin = wins ? grossWins / wins : 0;
  const avgLoss = losses ? grossLosses / losses : 0;
  const expectancy = n ? (winRate / 100 * avgWin) - ((100 - winRate) / 100 * avgLoss) : 0;
  const profitFactor = grossLosses > 0 ? grossWins / grossLosses : grossWins > 0 ? Infinity : 0;
  return {
    n, wins, losses, winRate, avgWin, avgLoss, expectancy, profitFactor, totalPnl,
    bySetup, byRegime, byNoTrade, noTradeCount: log.filter(e => e.decision === 'NO_TRADE').length,
  };
}

function renderScalpingProfitDashboard(spe, stats) {
  const box = typeof document !== 'undefined' ? document.getElementById('scalpingProfitEngineBox') : null;
  if (!box) return;
  if (typeof isScalpingProfitProfileActive === 'function' && !isScalpingProfitProfileActive()) {
    box.style.display = 'none';
    return;
  }
  box.style.display = 'block';
  const cfg = getScalpingProfitSettings();
  stats = stats || computeScalpingProfitStatistics();
  const modeLabel = cfg.mode;
  const onOff = isScalpingProfitEngineActive() ? 'ON' : 'OFF';
  let html = `<div style="font-weight:700;color:#fde68a;margin-bottom:6px">⚡ Scalping Profit Engine <span style="color:${onOff === 'ON' ? '#4ade80' : '#94a3b8'}">● ${onOff}</span> <span style="color:#64748b;font-weight:400">(${modeLabel})</span></div>`;
  if (spe && spe.active) {
    const dc = spe.decision === 'ENTER' ? '#4ade80' : spe.decision === 'SIGNAL' ? '#fbbf24' : '#94a3b8';
    html += `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px;margin-bottom:8px">`;
    html += `<div><span style="color:#64748b">Decision</span><br><b style="color:${dc}">${fnoSpeEscapeHtml(spe.decision)}</b></div>`;
    html += `<div><span style="color:#64748b">Regime</span><br>${fnoSpeEscapeHtml(spe.regime)} (${spe.regimeConfidence}%)</div>`;
    html += `<div><span style="color:#64748b">Session</span><br>${fnoSpeEscapeHtml(spe.session)}</div>`;
    html += `<div><span style="color:#64748b">Quality</span><br>${spe.tradeQualityScore}/100 (${fnoSpeEscapeHtml(spe.tradeQualityGrade)})</div>`;
    html += `<div><span style="color:#64748b">Setup</span><br>${spe.setup ? fnoSpeEscapeHtml(spe.setup.type) : '—'}</div>`;
    html += `<div><span style="color:#64748b">Target/Stop</span><br>${spe.spotTargetPoints || '—'}/${spe.spotStopPoints || '—'} pt</div>`;
    html += `</div>`;
    if (spe.noTradeReasons && spe.noTradeReasons.length) {
      html += `<div style="color:#94a3b8;font-size:10px;margin-bottom:6px">No-trade: ${spe.noTradeReasons.slice(0, 4).map(fnoSpeEscapeHtml).join(', ')}</div>`;
    }
    if (spe.explanation) {
      html += `<pre style="font-size:10px;color:#cbd5e1;white-space:pre-wrap;margin:0;background:#020617;padding:8px;border-radius:6px">${fnoSpeEscapeHtml(spe.explanation)}</pre>`;
    }
  } else {
    html += `<div style="color:#64748b">Engine inactive — enable in Settings.</div>`;
  }
  if (stats.n > 0 || stats.noTradeCount > 0) {
    html += `<div style="margin-top:8px;padding-top:8px;border-top:1px solid #334155;font-size:10px;color:#94a3b8">`;
    html += `Today: ${stats.n} trades · WR ${stats.winRate.toFixed(0)}% · Net ₹${stats.totalPnl.toFixed(0)} · PF ${stats.profitFactor === Infinity ? '∞' : stats.profitFactor.toFixed(2)} · Expectancy ₹${stats.expectancy.toFixed(0)} · NO TRADE logged: ${stats.noTradeCount}`;
    html += `</div>`;
  }
  box.innerHTML = html;
}

function renderScalpingProfitAnalytics() {
  const stats = computeScalpingProfitStatistics();
  renderScalpingProfitDashboard(window.FNO_LAST_SPE || null, stats);
  if (typeof renderScalpingProfitLearningPanel === 'function') {
    renderScalpingProfitLearningPanel(computeSpeLearningReport());
  }
}

function buildScalpingProfitAttribution(spe) {
  if (!spe || !spe.active) return null;
  return {
    decision: spe.decision, quality: spe.tradeQualityScore, regime: spe.regime,
    setup: spe.setup && spe.setup.type, targetPts: spe.spotTargetPoints,
  };
}

function notifyScalpingProfitAlert(kind, spe, extra) {
  if (typeof notifyTradeExecution !== 'function') return;
  const labels = {
    OPPORTUNITY: 'SCALP OPPORTUNITY', ENTRY: 'SCALP ENTRY', EXIT: 'SCALP EXIT',
    LOCKED: 'SCALP ENGINE LOCKED', COOLDOWN: 'SCALP ENGINE COOLDOWN',
  };
  if (kind === 'OPPORTUNITY' && spe && spe.decision !== 'ENTER') return;
}

// --- Phase 8: Learning Engine (observation only — never auto-applies) ---

function getSpeCompletedTrades(log) {
  log = log || getScalpingProfitLog();
  return log.filter(e => e.outcome && typeof e.outcome.pnl === 'number').sort((a, b) => (a.ts || 0) - (b.ts || 0));
}

function summarizeSpeTradeSubset(entries, label) {
  let wins = 0, grossW = 0, grossL = 0, total = 0, peak = 0, run = 0, maxDd = 0;
  entries.forEach(e => {
    const pnl = e.outcome.pnl;
    total += pnl;
    run += pnl;
    if (run > peak) peak = run;
    const dd = peak - run;
    if (dd > maxDd) maxDd = dd;
    if (pnl >= 0) { wins++; grossW += pnl; } else grossL += Math.abs(pnl);
  });
  const n = entries.length;
  const winRate = n ? (wins / n) * 100 : 0;
  const avgWin = wins ? grossW / wins : 0;
  const avgLoss = (n - wins) ? grossL / (n - wins) : 0;
  const expectancy = n ? (winRate / 100 * avgWin) - ((100 - winRate) / 100 * avgLoss) : 0;
  const profitFactor = grossL > 0 ? grossW / grossL : grossW > 0 ? Infinity : 0;
  return {
    label, n, wins, losses: n - wins, winRate: +winRate.toFixed(1),
    expectancy: +expectancy.toFixed(2), profitFactor: profitFactor === Infinity ? null : +profitFactor.toFixed(2),
    totalPnl: +total.toFixed(2), maxDrawdown: +maxDd.toFixed(2),
    sampleSufficient: n >= FNO_SPE_MIN_SAMPLE_WF,
  };
}

function computeSpeWalkForwardReport(log) {
  const completed = getSpeCompletedTrades(log);
  const n = completed.length;
  if (n < FNO_SPE_MIN_SAMPLE_WF) {
    return {
      sufficient: false,
      warning: `Need at least ${FNO_SPE_MIN_SAMPLE_WF} completed SPE trades (have ${n}). Run paper mode to accumulate data.`,
      train: null, validation: null, outOfSample: null, folds: [], overfitWarning: null,
    };
  }
  const trainEnd = Math.floor(n * FNO_SPE_TRAIN_SPLIT);
  const valEnd = Math.floor(n * (FNO_SPE_TRAIN_SPLIT + FNO_SPE_VALIDATION_SPLIT));
  const train = completed.slice(0, trainEnd);
  const validation = completed.slice(trainEnd, valEnd);
  const outOfSample = completed.slice(valEnd);

  const trainSum = summarizeSpeTradeSubset(train, 'train');
  const valSum = summarizeSpeTradeSubset(validation, 'validation');
  const oosSum = summarizeSpeTradeSubset(outOfSample, 'out_of_sample');

  const foldCount = Math.min(4, Math.max(2, Math.floor(n / 6)));
  const foldSize = Math.floor(n / foldCount);
  const folds = [];
  for (let i = 0; i < foldCount; i++) {
    const slice = completed.slice(i * foldSize, (i + 1) * foldSize);
    if (slice.length >= 3) folds.push(summarizeSpeTradeSubset(slice, 'fold_' + (i + 1)));
  }

  let overfitWarning = null;
  if (trainSum.sampleSufficient && valSum.n >= 3) {
    if (trainSum.expectancy > 0 && valSum.expectancy <= 0) {
      overfitWarning = 'Train expectancy positive but validation negative — possible overfit';
    } else if (trainSum.expectancy > valSum.expectancy * 2 && valSum.expectancy > 0) {
      overfitWarning = 'Train expectancy much higher than validation — review parameter stability';
    }
  }
  if (oosSum.n >= 3 && valSum.n >= 3 && valSum.expectancy > 0 && oosSum.expectancy <= 0) {
    overfitWarning = (overfitWarning ? overfitWarning + '; ' : '') + 'Validation positive but out-of-sample negative — fragile edge';
  }

  const stable = folds.length >= 2 && folds.every(f => f.expectancy >= 0 || f.n < 4);
  return {
    sufficient: true, train: trainSum, validation: valSum, outOfSample: oosSum,
    folds, overfitWarning, stable, totalTrades: n,
  };
}

function shuffleArray(arr) {
  const a = arr.slice();
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    const t = a[i]; a[i] = a[j]; a[j] = t;
  }
  return a;
}

function computeSpeMonteCarloAnalysis(log, opts) {
  opts = opts || {};
  const sims = opts.simulations || FNO_SPE_MC_SIMULATIONS;
  const completed = getSpeCompletedTrades(log);
  const pnls = completed.map(e => e.outcome.pnl);
  if (pnls.length < FNO_SPE_MIN_SAMPLE_MC) {
    return {
      sufficient: false,
      warning: `Need at least ${FNO_SPE_MIN_SAMPLE_MC} completed trades for Monte Carlo (have ${pnls.length}).`,
      simulations: 0,
    };
  }

  function runMcSeries(pnlSeries) {
    const drawdowns = [];
    const finals = [];
    const streaks = [];
    for (let s = 0; s < sims; s++) {
      const seq = shuffleArray(pnlSeries);
      let equity = 0, peak = 0, maxDd = 0, lossStreak = 0, maxLossStreak = 0;
      seq.forEach(p => {
        equity += p;
        if (equity > peak) peak = equity;
        const dd = peak - equity;
        if (dd > maxDd) maxDd = dd;
        if (p < 0) { lossStreak++; if (lossStreak > maxLossStreak) maxLossStreak = lossStreak; }
        else lossStreak = 0;
      });
      drawdowns.push(maxDd);
      finals.push(equity);
      streaks.push(maxLossStreak);
    }
    drawdowns.sort((a, b) => a - b);
    finals.sort((a, b) => a - b);
    streaks.sort((a, b) => a - b);
    const pct = (arr, p) => arr[Math.min(arr.length - 1, Math.floor(arr.length * p))];
    return {
      medianMaxDrawdown: +pct(drawdowns, 0.5).toFixed(2),
      p95MaxDrawdown: +pct(drawdowns, 0.95).toFixed(2),
      medianFinalPnl: +pct(finals, 0.5).toFixed(2),
      p5FinalPnl: +pct(finals, 0.05).toFixed(2),
      p95FinalPnl: +pct(finals, 0.95).toFixed(2),
      medianMaxLossStreak: pct(streaks, 0.5),
      p95MaxLossStreak: pct(streaks, 0.95),
      ruinRatePct: +((finals.filter(f => f < -Math.abs(pct(finals, 0.5)) * 2).length / sims) * 100).toFixed(1),
    };
  }

  const baseline = runMcSeries(pnls);
  const costStress = [1, 1.5, 2].map(mult => {
    const stressed = pnls.map(p => p - (mult - 1) * Math.abs(p) * 0.08);
    const r = runMcSeries(stressed);
    return { multiplier: mult, medianFinalPnl: r.medianFinalPnl, p5FinalPnl: r.p5FinalPnl, fragile: r.p5FinalPnl < 0 };
  });
  const fragile = costStress.some(c => c.fragile);

  return {
    sufficient: true, simulations: sims, sampleSize: pnls.length, baseline, costStress, fragile,
    warning: fragile ? 'Edge may collapse under modest cost increase (×1.5 slippage stress)' : null,
  };
}

function computeSpeRegimeSetupMatrix(log) {
  log = log || getScalpingProfitLog();
  const completed = getSpeCompletedTrades(log);
  const matrix = {};
  completed.forEach(e => {
    const setup = e.setupType || 'unknown';
    const regime = e.regime || 'UNKNOWN';
    const key = setup + '|' + regime;
    if (!matrix[key]) matrix[key] = { setup, regime, n: 0, wins: 0, pnl: 0 };
    matrix[key].n++;
    matrix[key].pnl += e.outcome.pnl;
    if (e.outcome.pnl >= 0) matrix[key].wins++;
  });
  const rows = Object.values(matrix).map(r => {
    r.winRate = r.n ? +((r.wins / r.n) * 100).toFixed(1) : 0;
    r.expectancy = r.n ? +(r.pnl / r.n).toFixed(2) : 0;
    r.grade = r.n < 3 ? '--' : r.expectancy > 50 ? '++' : r.expectancy > 0 ? '+' : r.expectancy > -50 ? '-' : '--';
    r.sampleSufficient = r.n >= 5;
    return r;
  }).sort((a, b) => b.expectancy - a.expectancy);
  return { rows, sufficient: completed.length >= FNO_SPE_MIN_SAMPLE_WF };
}

function computeSpeMissedTradeAnalysis() {
  try {
    const missed = JSON.parse(localStorage.getItem(FNO_SPE_MISSED_KEY) || '[]');
    const validated = missed.filter(m => m.validated != null);
    const pending = missed.length - validated.length;
    let wouldWin = 0, wouldLose = 0;
    validated.forEach(m => {
      if (m.wouldHaveWon) wouldWin++;
      else wouldLose++;
    });
    const byReason = {};
    missed.forEach(m => {
      const r = m.reason || 'unknown';
      byReason[r] = (byReason[r] || 0) + 1;
    });
    return {
      total: missed.length, pending, validated: validated.length,
      wouldWin, wouldLose, byReason,
      filterBeneficial: wouldLose >= wouldWin,
      note: pending > 0 ? `${pending} high-quality rejections not yet back-tested against subsequent price action` : null,
    };
  } catch (e) {
    return { total: 0, pending: 0, validated: 0, wouldWin: 0, wouldLose: 0, byReason: {} };
  }
}

function computeSpeFilterCalibration(log) {
  log = log || getScalpingProfitLog();
  const noTrades = log.filter(e => e.decision === 'NO_TRADE');
  const byFilter = {};
  noTrades.forEach(e => {
    (e.noTradeReasons || [e.noTradeReason || 'unknown']).forEach(r => {
      if (!byFilter[r]) byFilter[r] = { count: 0, avgQuality: 0, _q: 0 };
      byFilter[r].count++;
      byFilter[r]._q += e.tradeQualityScore || 0;
    });
  });
  Object.keys(byFilter).forEach(r => {
    byFilter[r].avgQuality = byFilter[r].count ? Math.round(byFilter[r]._q / byFilter[r].count) : 0;
    delete byFilter[r]._q;
  });
  return { totalNoTrade: noTrades.length, byFilter };
}

function computeSpeTargetBucketAnalysis(log) {
  const completed = getSpeCompletedTrades(log);
  const buckets = { 10: { n: 0, pnl: 0, wins: 0 }, 15: { n: 0, pnl: 0, wins: 0 }, 20: { n: 0, pnl: 0, wins: 0 } };
  completed.forEach(e => {
    const tp = e.spotTargetPoints;
    const b = tp <= 12 ? 10 : tp <= 17 ? 15 : 20;
    buckets[b].n++;
    buckets[b].pnl += e.outcome.pnl;
    if (e.outcome.pnl >= 0) buckets[b].wins++;
  });
  [10, 15, 20].forEach(b => {
    buckets[b].expectancy = buckets[b].n ? +(buckets[b].pnl / buckets[b].n).toFixed(2) : null;
    buckets[b].winRate = buckets[b].n ? +((buckets[b].wins / buckets[b].n) * 100).toFixed(1) : null;
  });
  return buckets;
}

function computeSpeParameterCandidates(log, wf, mc, matrix) {
  log = log || getScalpingProfitLog();
  wf = wf || computeSpeWalkForwardReport(log);
  mc = mc || computeSpeMonteCarloAnalysis(log);
  matrix = matrix || computeSpeRegimeSetupMatrix(log);
  const cfg = getScalpingProfitSettings();
  const candidates = [];
  const now = Date.now();

  if (wf.overfitWarning) {
    candidates.push({
      id: 'wf_overfit_' + now, status: 'CANDIDATE', parameter: 'multiple',
      oldValue: 'current', newValue: 'tighten filters', reason: wf.overfitWarning,
      dataset: 'walk_forward', performanceBefore: wf.train && wf.train.expectancy,
      performanceAfter: wf.validation && wf.validation.expectancy,
      oosPerformance: wf.outOfSample && wf.outOfSample.expectancy,
      autoApply: false,
    });
  }
  if (mc.fragile) {
    candidates.push({
      id: 'mc_fragile_' + now, status: 'CANDIDATE', parameter: 'speSlippageEstimatePts',
      oldValue: cfg.slippageEstimate, newValue: +(cfg.slippageEstimate * 1.25).toFixed(2),
      reason: mc.warning || 'Cost stress test shows fragile edge',
      dataset: 'monte_carlo', autoApply: false,
    });
  }
  matrix.rows.filter(r => r.sampleSufficient && r.expectancy < 0).forEach(r => {
    candidates.push({
      id: 'disable_' + r.setup + '_' + r.regime + '_' + now, status: 'CANDIDATE',
      parameter: 'setup_regime_allowlist', oldValue: 'allowed',
      newValue: 'disable ' + r.setup + ' in ' + r.regime,
      reason: `${r.setup} during ${r.regime}: ${r.n} trades, expectancy ₹${r.expectancy}`,
      dataset: 'regime_setup_matrix', performanceBefore: r.expectancy, autoApply: false,
    });
  });

  const completed = getSpeCompletedTrades(log);
  const chasingLosses = completed.filter(e => e.failureMode === FNO_SPE_FAILURE.CHASING).length;
  if (chasingLosses >= 2) {
    candidates.push({
      id: 'anti_chase_' + now, status: 'CANDIDATE', parameter: 'speAntiChaseThresholdPct',
      oldValue: cfg.antiChasePct, newValue: Math.max(50, cfg.antiChasePct - 5),
      reason: `${chasingLosses} losses classified as Chasing`,
      dataset: 'failure_library', autoApply: false,
    });
  }

  const lowScoreLosses = completed.filter(e => e.outcome.pnl < 0 && (e.tradeQualityScore || 0) < cfg.minTradeQuality + 5);
  if (lowScoreLosses.length >= 2) {
    candidates.push({
      id: 'min_quality_' + now, status: 'CANDIDATE', parameter: 'speMinTradeQualityScore',
      oldValue: cfg.minTradeQuality, newValue: Math.min(95, cfg.minTradeQuality + 3),
      reason: `${lowScoreLosses.length} losses entered near minimum quality threshold`,
      dataset: 'trade_quality', autoApply: false,
    });
  }

  const chopRows = matrix.rows.filter(r => r.regime === FNO_SPE_REGIME.CHOP && r.sampleSufficient && r.expectancy < 0);
  if (chopRows.length) {
    candidates.push({
      id: 'chop_boost_' + now, status: 'CANDIDATE', parameter: 'speChopMinScoreBoost',
      oldValue: cfg.chopScoreBoost, newValue: Math.min(30, cfg.chopScoreBoost + 5),
      reason: 'Negative expectancy setups in CHOP regime',
      dataset: 'regime_setup_matrix', autoApply: false,
    });
  }

  return candidates;
}

function saveSpeLearningCandidates(candidates) {
  try {
    const existing = JSON.parse(localStorage.getItem(FNO_SPE_LEARNING_KEY) || '[]');
    const merged = existing.concat(candidates).slice(-100);
    localStorage.setItem(FNO_SPE_LEARNING_KEY, JSON.stringify(merged));
  } catch (e) { /* quota */ }
}

function getSpeLearningCandidates() {
  try { return JSON.parse(localStorage.getItem(FNO_SPE_LEARNING_KEY) || '[]'); } catch (e) { return []; }
}

function computeSpeLearningReport(log) {
  log = log || getScalpingProfitLog();
  const wf = computeSpeWalkForwardReport(log);
  const mc = computeSpeMonteCarloAnalysis(log);
  const matrix = computeSpeRegimeSetupMatrix(log);
  const missed = computeSpeMissedTradeAnalysis();
  const filters = computeSpeFilterCalibration(log);
  const targets = computeSpeTargetBucketAnalysis(log);
  const stats = computeScalpingProfitStatistics(log);
  const candidates = computeSpeParameterCandidates(log, wf, mc, matrix);
  saveSpeLearningCandidates(candidates.filter(c => c.status === 'CANDIDATE'));
  return { wf, mc, matrix, missed, filters, targets, stats, candidates, generatedAt: Date.now() };
}

function renderScalpingProfitLearningPanel(report) {
  const box = typeof document !== 'undefined' ? document.getElementById('scalpingProfitLearningBox') : null;
  if (!box) return;
  if (typeof isScalpingProfitProfileActive === 'function' && !isScalpingProfitProfileActive()) {
    box.style.display = 'none';
    return;
  }
  if (!isScalpingProfitEngineActive()) {
    box.style.display = 'none';
    return;
  }
  box.style.display = 'block';
  report = report || computeSpeLearningReport();
  const { wf, mc, matrix, missed, filters, targets, candidates, stats } = report;

  let html = `<div style="font-weight:700;color:#c4b5fd;margin-bottom:6px">🧠 SPE Learning Engine <span style="color:#64748b;font-weight:400">(Phase 8 — observation only, never auto-applies)</span></div>`;

  if (!wf.sufficient) {
    html += `<div style="color:#94a3b8;font-size:10px;margin-bottom:8px">${fnoSpeEscapeHtml(wf.warning)}</div>`;
  } else {
    html += `<div style="font-size:10px;margin-bottom:8px"><b style="color:#fde68a">Walk-forward</b> `;
    html += `Train ${wf.train.n}t · ₹${wf.train.expectancy} exp · `;
    html += `Val ${wf.validation.n}t · ₹${wf.validation.expectancy} exp · `;
    html += `OOS ${wf.outOfSample.n}t · ₹${wf.outOfSample.expectancy} exp`;
    if (wf.overfitWarning) html += `<br><span style="color:#fbbf24">⚠ ${fnoSpeEscapeHtml(wf.overfitWarning)}</span>`;
    html += `</div>`;
  }

  if (!mc.sufficient) {
    html += `<div style="color:#64748b;font-size:10px;margin-bottom:8px">${fnoSpeEscapeHtml(mc.warning)}</div>`;
  } else {
    html += `<div style="font-size:10px;margin-bottom:8px"><b style="color:#fde68a">Monte Carlo</b> (${mc.simulations} sims, n=${mc.sampleSize}) · `;
    html += `Med DD ₹${mc.baseline.medianMaxDrawdown} · P95 DD ₹${mc.baseline.p95MaxDrawdown} · `;
    html += `Med final ₹${mc.baseline.medianFinalPnl} · P5 ₹${mc.baseline.p5FinalPnl}`;
    if (mc.fragile) html += `<br><span style="color:#f87171">⚠ ${fnoSpeEscapeHtml(mc.warning)}</span>`;
    html += `</div>`;
    html += `<div style="font-size:10px;color:#64748b;margin-bottom:8px">Cost stress: `;
    html += mc.costStress.map(c => `×${c.multiplier} → P5 ₹${c.p5FinalPnl}${c.fragile ? ' ⚠' : ''}`).join(' · ');
    html += `</div>`;
  }

  if (matrix.sufficient && matrix.rows.length) {
    html += `<div style="font-size:10px;margin-bottom:6px"><b style="color:#fde68a">Setup × Regime</b> (top rows)</div>`;
    html += `<div style="font-size:10px;color:#94a3b8;margin-bottom:8px">`;
    matrix.rows.slice(0, 6).forEach(r => {
      html += `<div>${fnoSpeEscapeHtml(r.setup)} / ${fnoSpeEscapeHtml(r.regime)}: ${r.n}t ${r.grade} exp ₹${r.expectancy}</div>`;
    });
    html += `</div>`;
  }

  html += `<div style="font-size:10px;margin-bottom:6px"><b style="color:#fde68a">Target buckets</b> `;
  html += [10, 15, 20].map(b => `${b}pt: ${targets[b].n}t exp ₹${targets[b].expectancy != null ? targets[b].expectancy : '—'}`).join(' · ');
  html += `</div>`;

  if (filters.totalNoTrade > 0) {
    const topFilters = Object.entries(filters.byFilter).sort((a, b) => b[1].count - a[1].count).slice(0, 4);
    html += `<div style="font-size:10px;margin-bottom:6px"><b style="color:#fde68a">No-trade filters</b> (${filters.totalNoTrade} total): `;
    html += topFilters.map(([k, v]) => `${fnoSpeEscapeHtml(k)}×${v.count}`).join(', ');
    html += `</div>`;
  }

  if (missed.total > 0) {
    html += `<div style="font-size:10px;margin-bottom:6px"><b style="color:#fde68a">Missed trades</b> ${missed.total} logged`;
    if (missed.validated > 0) html += ` · validated ${missed.wouldWin}W/${missed.wouldLose}L`;
    if (missed.note) html += `<br><span style="color:#64748b">${fnoSpeEscapeHtml(missed.note)}</span>`;
    html += `</div>`;
  }

  if (candidates.length) {
    html += `<div style="font-size:10px;margin-top:8px;padding-top:8px;border-top:1px solid #4c1d95"><b style="color:#fde68a">Parameter candidates</b> (require manual approval — never auto-applied)</div>`;
    candidates.slice(0, 5).forEach(c => {
      html += `<div style="padding:4px 0;font-size:10px;color:#cbd5e1">`;
      html += `<span style="color:#a78bfa">CANDIDATE</span> ${fnoSpeEscapeHtml(c.parameter)}: ${fnoSpeEscapeHtml(String(c.oldValue))} → ${fnoSpeEscapeHtml(String(c.newValue))}`;
      html += `<br><span style="color:#64748b">${fnoSpeEscapeHtml(c.reason)}</span></div>`;
    });
  } else if (stats.n >= FNO_SPE_MIN_SAMPLE_WF) {
    html += `<div style="font-size:10px;color:#4ade80;margin-top:6px">No parameter changes suggested — current settings appear stable on available sample.</div>`;
  }

  box.innerHTML = html;
}

function renderScalpingProfitLearningAnalytics() {
  renderScalpingProfitLearningPanel(computeSpeLearningReport());
}
