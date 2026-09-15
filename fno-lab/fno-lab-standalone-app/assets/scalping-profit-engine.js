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
    safe: false, liquidityScore: 0, spreadPts: null, spreadPct: null, spreadOk: false,
    slippageEstimate: cfg.slippageEstimate, volatilityClass: 'NORMAL', eventRisk: false,
    reasons: [], blockers: [],
  };
  if (!ms.valid) {
    out.blockers.push('Missing or stale candle data');
    return out;
  }
  const leg = ctx && ctx.ocRow && ctx.ocRow.CE;
  const bid = leg && Number.isFinite(leg.bidprice) ? leg.bidprice : null;
  const ask = leg && Number.isFinite(leg.askPrice) ? leg.askPrice : null;
  const bidQty = leg && Number.isFinite(leg.bidQty) ? leg.bidQty : 0;
  const askQty = leg && Number.isFinite(leg.askQty) ? leg.askQty : 0;
  if (bid != null && ask != null && ask >= bid) {
    out.spreadPts = +(ask - bid).toFixed(4);
    out.spreadPct = bid > 0 ? +((out.spreadPts / bid) * 100).toFixed(3) : null;
    out.spreadOk = out.spreadPct == null || out.spreadPct <= cfg.spreadThresholdPct;
    if (!out.spreadOk) out.blockers.push(FNO_SPE_NO_TRADE.WIDE_SPREAD);
  } else {
    out.spreadOk = true;
  }
  const depthScore = Math.min(100, Math.round((bidQty + askQty) / 100));
  out.liquidityScore = Math.max(depthScore, ms.atr && ms.atr > 0 ? 55 : 40);
  if (out.liquidityScore < cfg.minLiquidityScore) out.blockers.push(FNO_SPE_NO_TRADE.INSUFFICIENT_LIQUIDITY);
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
  }
  out.safe = out.blockers.length === 0;
  return out;
}

function detectSpeRegime(ms, ctx) {
  let regime = FNO_SPE_REGIME.UNKNOWN;
  let confidence = 40;
  let strength = 'LOW';
  if (!ms.valid) return { regime, confidence, strength, label: regime };
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
  if (!ms.valid) return { direction, confidence, reasons };
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
  const totalW = Object.values(weights).reduce((a, b) => a + b, 0);
  let sum = 0;
  const breakdown = {};
  breakdown.marketSafety = layers.safety ? (layers.safety.safe ? 90 : 30) : 50;
  breakdown.regime = layers.regime ? layers.regime.confidence : 40;
  breakdown.direction = layers.direction ? layers.direction.confidence : 40;
  breakdown.setup = layers.setup ? layers.setup.score : 0;
  breakdown.timing = layers.timing ? layers.timing.timingScore : 50;
  breakdown.momentum = layers.timing && layers.timing.velocityPtsMin >= 1 ? 70 : 40;
  breakdown.room = layers.room && layers.room.sufficient ? 85 : 25;
  breakdown.expectedMove = layers.expectedMove && layers.expectedMove.netEdgePositive ? 80 : 35;
  breakdown.execution = layers.execution ? layers.execution.score : 60;
  breakdown.session = layers.session && layers.session.state !== FNO_SPE_SESSION.LOCKED ? 75 : 20;
  Object.keys(weights).forEach(k => { sum += (breakdown[k] || 0) * weights[k]; });
  const normalized = Math.round(sum / totalW);
  let grade = 'No Trade';
  if (normalized >= 90) grade = 'Exceptional';
  else if (normalized >= 80) grade = 'High Quality';
  else if (normalized >= 70) grade = 'Acceptable';
  else if (normalized >= 60) grade = 'Selective';
  return { normalized, grade, breakdown, weights };
}

function evaluateExecutionQuality(ctx, expectedMove, cfg) {
  let score = 75;
  const leg = ctx && ctx.ocRow && ctx.ocRow.CE;
  const spreadPct = leg && leg.bidprice > 0 && leg.askPrice > leg.bidprice
    ? ((leg.askPrice - leg.bidprice) / leg.bidprice) * 100 : null;
  if (spreadPct != null && spreadPct > cfg.spreadThresholdPct) score -= 30;
  const slippageVsProfit = expectedMove && expectedMove.favorablePts > 0
    ? (cfg.slippageEstimate / expectedMove.favorablePts) * 100 : 0;
  if (slippageVsProfit > 25) score -= 20;
  return { score: Math.max(0, score), spreadPct, slippageVsProfit, acceptable: score >= 55 };
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
  const minScore = cfg.minTradeQuality + session.minScoreBoost;

  const blockers = [];
  const noTradeReasons = [];

  if (!safety.safe) { blockers.push(...safety.blockers); noTradeReasons.push(...safety.blockers); }
  if (regime.confidence < cfg.minRegimeConfidence) { blockers.push(FNO_SPE_NO_TRADE.LOW_REGIME_CONFIDENCE); noTradeReasons.push(FNO_SPE_NO_TRADE.LOW_REGIME_CONFIDENCE); }
  if (direction.confidence < cfg.minDirectionConfidence) { blockers.push(FNO_SPE_NO_TRADE.WEAK_DIRECTION); noTradeReasons.push(FNO_SPE_NO_TRADE.WEAK_DIRECTION); }
  if (!bestSetup) { blockers.push(FNO_SPE_NO_TRADE.NO_SETUP); noTradeReasons.push(FNO_SPE_NO_TRADE.NO_SETUP); }
  if (antiChase.blocked) { blockers.push(FNO_SPE_NO_TRADE.EXCESSIVE_EXTENSION); noTradeReasons.push(FNO_SPE_NO_TRADE.EXCESSIVE_EXTENSION); }
  if (!room.sufficient && bestSetup && bestSetup.type !== FNO_SPE_SETUP.BREAKOUT) {
    blockers.push(FNO_SPE_NO_TRADE.INSUFFICIENT_ROOM); noTradeReasons.push(FNO_SPE_NO_TRADE.INSUFFICIENT_ROOM);
  }
  if (expectedMove.favorablePts < cfg.minExpectedMove) { blockers.push(FNO_SPE_NO_TRADE.EXPECTED_MOVE_TOO_SMALL); noTradeReasons.push(FNO_SPE_NO_TRADE.EXPECTED_MOVE_TOO_SMALL); }
  if (expectedMove.riskReward < cfg.minRiskReward) { blockers.push(FNO_SPE_NO_TRADE.POOR_RR); noTradeReasons.push(FNO_SPE_NO_TRADE.POOR_RR); }
  if (!expectedMove.netEdgePositive) { blockers.push(FNO_SPE_NO_TRADE.POOR_RR); }
  if (!execution.acceptable) { blockers.push(FNO_SPE_NO_TRADE.EXCESSIVE_SLIPPAGE); noTradeReasons.push(FNO_SPE_NO_TRADE.EXCESSIVE_SLIPPAGE); }
  if (quality.normalized < minScore) { blockers.push(FNO_SPE_NO_TRADE.LOW_SCORE); noTradeReasons.push(FNO_SPE_NO_TRADE.LOW_SCORE); }
  if (regime.regime === FNO_SPE_REGIME.CHOP && quality.normalized < minScore + cfg.chopScoreBoost) {
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
