/**
 * Pullback Continuation Setup Engine (PBCE)
 * Dynamic 10 / 15 / 20 index-point targets from live movement classification.
 * Trend → impulse → retracement → continuation confirmation before entry.
 */
const FNO_PBCE_LOG_KEY = 'fno_pullback_continuation_log_v1';
const FNO_PBCE_LOG_MAX = 1500;
const FNO_PBCE_SPOT_TARGETS = { slow: 10, medium: 15, fast: 20 };
const FNO_PBCE_PHASES = {
  NO_SETUP: 'NO_SETUP',
  TREND_ESTABLISHED: 'TREND_ESTABLISHED',
  IMPULSE: 'IMPULSE',
  RETRACING: 'RETRACING',
  CONTINUATION_CONFIRMED: 'CONTINUATION_CONFIRMED',
};

function fnoPbceEscapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function isPullbackContinuationActive() {
  if (typeof fnoSettings === 'undefined') return false;
  const s = fnoSettings.get();
  if (s.pullbackContinuationEnabled === false) return false;
  return !!(s.scalpingProfitProfileEnabled && s.tradingTypes && s.tradingTypes.scalping);
}

function resolveTradeDirectionFromBrain(brain) {
  if (!brain) return null;
  if (brain.decision === 'BUY_READY') return 'bullish';
  if (brain.decision === 'SELL_READY') return 'bearish';
  return null;
}

function computeMovementSpeedMetrics(ctx, brain) {
  const candles = (ctx && ctx.candles) || [];
  const closes = candles.map(c => c.c).filter(Number.isFinite);
  const spot = Number.isFinite(ctx && ctx.spot) ? ctx.spot : (closes.length ? closes[closes.length - 1] : null);
  const out = {
    spot,
    velocityPointsPerMin: null,
    velocityScore: 0,
    momentumScore: 0,
    trendStrengthScore: 0,
    candleStructureScore: 0,
    volatilityScore: 0,
    compositeScore: 0,
    expectedPoints5Min: null,
    expectedPoints10Min: null,
    intradayRangePct: null,
    ema9: null,
    ema21: null,
    rsi: null,
    macdHist: null,
    reasons: [],
  };
  if (!closes.length || closes.length < 6 || !Number.isFinite(spot)) {
    out.reasons.push('Insufficient candle data for movement metrics');
    return out;
  }

  const e9 = typeof ema === 'function' ? ema(closes, 9) : [];
  const e21 = typeof ema === 'function' ? ema(closes, 21) : [];
  const last = closes.length - 1;
  out.ema9 = Number.isFinite(e9[last]) ? e9[last] : null;
  out.ema21 = Number.isFinite(e21[last]) ? e21[last] : null;

  const lookback = Math.min(5, closes.length - 1);
  const startSpot = closes[last - lookback];
  const minutes = lookback;
  if (Number.isFinite(startSpot) && startSpot > 0) {
    out.velocityPointsPerMin = Math.abs(spot - startSpot) / minutes;
    out.expectedPoints5Min = out.velocityPointsPerMin * 5;
    out.expectedPoints10Min = out.velocityPointsPerMin * 10;
    if (out.velocityPointsPerMin >= 3.5) { out.velocityScore = 100; out.reasons.push(`Velocity ${out.velocityPointsPerMin.toFixed(2)} pts/min — very fast`); }
    else if (out.velocityPointsPerMin >= 2.0) { out.velocityScore = 80; out.reasons.push(`Velocity ${out.velocityPointsPerMin.toFixed(2)} pts/min — fast`); }
    else if (out.velocityPointsPerMin >= 1.0) { out.velocityScore = 55; out.reasons.push(`Velocity ${out.velocityPointsPerMin.toFixed(2)} pts/min — moderate`); }
    else if (out.velocityPointsPerMin >= 0.4) { out.velocityScore = 30; out.reasons.push(`Velocity ${out.velocityPointsPerMin.toFixed(2)} pts/min — slow`); }
    else { out.velocityScore = 10; out.reasons.push(`Velocity ${out.velocityPointsPerMin.toFixed(2)} pts/min — very slow`); }
  }

  if (typeof rsiCalc === 'function') {
    const rsi = rsiCalc(closes, 14);
    out.rsi = rsi[last];
    if (Number.isFinite(out.rsi)) {
      const rsiMomentum = Math.abs(out.rsi - 50) / 50;
      out.momentumScore = Math.min(100, Math.round(rsiMomentum * 100));
      out.reasons.push(`RSI ${out.rsi.toFixed(1)} → momentum score ${out.momentumScore}`);
    }
  }
  if (typeof macdCalc === 'function') {
    const macd = macdCalc(closes);
    out.macdHist = macd.histogram[last];
    if (Number.isFinite(out.macdHist)) {
      const macdBoost = Math.min(40, Math.abs(out.macdHist) * 80);
      out.momentumScore = Math.min(100, out.momentumScore + macdBoost);
    }
  }

  if (Number.isFinite(out.ema9) && Number.isFinite(out.ema21) && out.ema21 > 0) {
    const spreadPct = Math.abs((out.ema9 - out.ema21) / out.ema21) * 100;
    out.trendStrengthScore = Math.min(100, Math.round(spreadPct * 400));
    out.reasons.push(`EMA9/21 spread ${spreadPct.toFixed(3)}% → trend score ${out.trendStrengthScore}`);
  }

  const bodies = [];
  for (let i = Math.max(1, last - 4); i <= last; i++) {
    bodies.push(Math.abs(closes[i] - closes[i - 1]));
  }
  const sessionRange = Math.max(...closes) - Math.min(...closes);
  const avgBody = bodies.length ? bodies.reduce((a, b) => a + b, 0) / bodies.length : 0;
  if (sessionRange > 0) {
    out.candleStructureScore = Math.min(100, Math.round((avgBody / sessionRange) * 300));
    out.intradayRangePct = (sessionRange / spot) * 100;
    out.reasons.push(`Avg candle body ${avgBody.toFixed(2)} vs session range ${sessionRange.toFixed(1)} pts`);
  }

  if (typeof historicalVolPct === 'function') {
    const hv = historicalVolPct(closes, 20);
    if (Number.isFinite(hv)) {
      out.volatilityScore = hv >= 18 ? 90 : hv >= 14 ? 70 : hv >= 10 ? 45 : 25;
      out.reasons.push(`Realized vol ${hv.toFixed(1)}% → vol score ${out.volatilityScore}`);
    }
  } else if (Number.isFinite(out.intradayRangePct)) {
    out.volatilityScore = out.intradayRangePct >= 0.8 ? 75 : out.intradayRangePct >= 0.4 ? 50 : 25;
  }

  out.compositeScore = Math.round(
    out.velocityScore * 0.35
    + out.momentumScore * 0.25
    + out.trendStrengthScore * 0.15
    + out.candleStructureScore * 0.15
    + out.volatilityScore * 0.10
  );
  return out;
}

function classifyMovementSpeed(metrics) {
  metrics = metrics || { compositeScore: 0, reasons: [] };
  let tier = 'slow';
  let spotTargetPoints = FNO_PBCE_SPOT_TARGETS.slow;
  if (metrics.compositeScore >= 65) {
    tier = 'fast';
    spotTargetPoints = FNO_PBCE_SPOT_TARGETS.fast;
  } else if (metrics.compositeScore >= 40) {
    tier = 'medium';
    spotTargetPoints = FNO_PBCE_SPOT_TARGETS.medium;
  }
  const label = tier === 'fast' ? 'Fast / strong momentum' : tier === 'medium' ? 'Medium / moderate momentum' : 'Slow / weak momentum';
  return {
    tier,
    spotTargetPoints,
    compositeScore: metrics.compositeScore,
    label,
    selectionReason: `${label} (composite ${metrics.compositeScore}) → ${spotTargetPoints}-point spot target`,
    factorBreakdown: {
      velocity: metrics.velocityScore,
      momentum: metrics.momentumScore,
      trendStrength: metrics.trendStrengthScore,
      candleStructure: metrics.candleStructureScore,
      volatility: metrics.volatilityScore,
    },
    metrics,
  };
}

function detectPullbackContinuationPhase(ctx, brain, direction) {
  if (!direction) {
    return { phase: FNO_PBCE_PHASES.NO_SETUP, reason: 'No trade direction (WAIT/NO_TRADE)' };
  }
  const candles = (ctx && ctx.candles) || [];
  const closes = candles.map(c => c.c).filter(Number.isFinite);
  if (closes.length < 21) {
    return { phase: FNO_PBCE_PHASES.NO_SETUP, reason: 'Need 21+ candles for pullback pattern' };
  }
  const e9 = ema(closes, 9);
  const e21 = ema(closes, 21);
  const last = closes.length - 1;
  const spot = Number.isFinite(ctx.spot) ? ctx.spot : closes[last];
  const bullish = direction === 'bullish';
  const trendOk = bullish ? (e9[last] > e21[last] && spot > e21[last]) : (e9[last] < e21[last] && spot < e21[last]);
  if (!trendOk) {
    return { phase: FNO_PBCE_PHASES.NO_SETUP, reason: `Trend not aligned — need ${bullish ? 'EMA9>EMA21 and spot above EMA21' : 'EMA9<EMA21 and spot below EMA21'}` };
  }

  const impulseLookback = Math.min(8, last);
  const impulseStart = closes[last - impulseLookback];
  const impulseMove = spot - impulseStart;
  const impulsePts = bullish ? impulseMove : -impulseMove;
  const minImpulse = 8;
  if (impulsePts < minImpulse) {
    return { phase: FNO_PBCE_PHASES.TREND_ESTABLISHED, reason: `Trend OK but impulse only ${impulsePts.toFixed(1)} pts (need ≥${minImpulse})`, impulsePoints: impulsePts };
  }

  const retraceWindow = closes.slice(last - 5, last + 1);
  const impulseHigh = bullish ? Math.max(...retraceWindow) : Math.min(...retraceWindow);
  const pullbackFromExtreme = bullish ? (impulseHigh - spot) : (spot - impulseHigh);
  const nearEmaZone = Number.isFinite(e9[last]) && Math.abs(spot - e9[last]) <= Math.max(3, Math.abs(spot - e21[last]) * 0.35);
  const retracing = pullbackFromExtreme >= 2 && (nearEmaZone || pullbackFromExtreme >= 4);

  if (retracing && impulsePts >= minImpulse) {
    const lastMove = closes[last] - closes[last - 1];
    const resuming = bullish ? lastMove > 0.5 : lastMove < -0.5;
    const rsi = typeof rsiCalc === 'function' ? rsiCalc(closes, 14)[last] : null;
    const momentumOk = bullish ? (Number.isFinite(rsi) ? rsi > 45 : lastMove > 0) : (Number.isFinite(rsi) ? rsi < 55 : lastMove < 0);
    if (resuming && momentumOk) {
      return {
        phase: FNO_PBCE_PHASES.CONTINUATION_CONFIRMED,
        reason: `Retrace ${pullbackFromExtreme.toFixed(1)} pts toward EMA zone — price resuming ${direction} (last candle ${lastMove >= 0 ? '+' : ''}${lastMove.toFixed(1)})`,
        impulsePoints: impulsePts,
        retracePoints: pullbackFromExtreme,
      };
    }
    return {
      phase: FNO_PBCE_PHASES.RETRACING,
      reason: `Pullback ${pullbackFromExtreme.toFixed(1)} pts — waiting for continuation candle`,
      impulsePoints: impulsePts,
      retracePoints: pullbackFromExtreme,
    };
  }

  if (impulsePts >= minImpulse * 1.5) {
    return { phase: FNO_PBCE_PHASES.IMPULSE, reason: `Strong ${impulsePts.toFixed(1)}-pt impulse — no retrace yet`, impulsePoints: impulsePts };
  }
  return { phase: FNO_PBCE_PHASES.TREND_ESTABLISHED, reason: 'Trend established — building impulse', impulsePoints: impulsePts };
}

function evaluateSpotTargetFeasibility(ctx, metrics, classification, direction) {
  const targetPoints = classification.spotTargetPoints;
  const spot = metrics && Number.isFinite(metrics.spot) ? metrics.spot : (ctx && ctx.spot);
  if (!Number.isFinite(spot) || !Number.isFinite(targetPoints)) {
    return { feasible: false, reason: 'Missing spot or target points', expectedPoints5Min: null, headroomPoints: null };
  }
  const expected5 = metrics && Number.isFinite(metrics.expectedPoints5Min) ? metrics.expectedPoints5Min : null;
  const expected10 = metrics && Number.isFinite(metrics.expectedPoints10Min) ? metrics.expectedPoints10Min : null;
  const volRangePts = metrics && Number.isFinite(metrics.intradayRangePct) ? (metrics.intradayRangePct / 100) * spot * 0.15 : null;
  const expectedMove = expected5 != null ? expected5 : volRangePts;

  let headroomPoints = null;
  if (ctx && ctx.candles && ctx.candles.length) {
    const closes = ctx.candles.map(c => c.c).filter(Number.isFinite);
    const sessionHigh = Math.max(...closes);
    const sessionLow = Math.min(...closes);
    headroomPoints = direction === 'bullish' ? (sessionHigh - spot) : (spot - sessionLow);
  }

  const velocityOk = expectedMove == null || targetPoints <= expectedMove * 1.35;
  const headroomOk = headroomPoints == null || targetPoints <= headroomPoints + 5;
  const feasible = velocityOk && headroomOk;
  const reasons = [];
  if (!velocityOk) reasons.push(`${targetPoints}pt target exceeds ~${expectedMove.toFixed(1)}pt expected 5-min move`);
  if (!headroomOk) reasons.push(`Only ${headroomPoints.toFixed(1)}pt room to session ${direction === 'bullish' ? 'high' : 'low'}`);
  if (feasible) {
    reasons.push(`${targetPoints}pt target within expected ${expectedMove != null ? expectedMove.toFixed(1) : '?'}pt short-term range`);
  }
  return {
    feasible,
    reason: reasons.join('; '),
    expectedPoints5Min: expected5,
    expectedPoints10Min: expected10,
    headroomPoints,
    targetPoints,
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
    premiumMove,
    deltaUsed: delta,
    spotTargetPoints,
  };
}

function computePullbackContinuationSetup(ctx, brain, opts) {
  opts = opts || {};
  if (!isPullbackContinuationActive()) {
    return { active: false, reason: 'Pullback Continuation engine OFF or scalping profile inactive' };
  }
  const direction = opts.direction || resolveTradeDirectionFromBrain(brain);
  const metrics = computeMovementSpeedMetrics(ctx, brain);
  const classification = classifyMovementSpeed(metrics);
  const pattern = detectPullbackContinuationPhase(ctx, brain, direction);
  const feasibility = evaluateSpotTargetFeasibility(ctx, metrics, classification, direction);

  const entryAllowed = pattern.phase === FNO_PBCE_PHASES.CONTINUATION_CONFIRMED
    && feasibility.feasible
    && (brain && (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY'));

  const blockReason = !entryAllowed
    ? (pattern.phase !== FNO_PBCE_PHASES.CONTINUATION_CONFIRMED
      ? `Setup phase ${pattern.phase}: ${pattern.reason}`
      : !feasibility.feasible
        ? `Target not feasible: ${feasibility.reason}`
        : 'Decision not BUY/SELL ready')
    : null;

  const spot = metrics.spot;
  const spotTargetPrice = Number.isFinite(spot) && direction
    ? +(spot + (direction === 'bullish' ? 1 : -1) * classification.spotTargetPoints).toFixed(2)
    : null;

  return {
    active: true,
    ts: Date.now(),
    direction,
    phase: pattern.phase,
    phaseReason: pattern.reason,
    impulsePoints: pattern.impulsePoints,
    retracePoints: pattern.retracePoints,
    classification,
    movementTier: classification.tier,
    spotTargetPoints: classification.spotTargetPoints,
    spotTargetPrice,
    selectionReason: classification.selectionReason,
    factorBreakdown: classification.factorBreakdown,
    feasibility,
    entryAllowed,
    blockReason,
    metrics,
  };
}

function resolvePullbackContinuationBracket(optPrice, ctx, brain, tradingType, optionType) {
  const setup = computePullbackContinuationSetup(ctx, brain, {
    direction: optionType === 'PE' ? 'bearish' : optionType === 'CE' ? 'bullish' : resolveTradeDirectionFromBrain(brain),
  });
  if (!setup.active) return null;
  const bracket = convertSpotTargetToOptionBracket(optPrice, ctx, setup.spotTargetPoints, setup.direction);
  if (!bracket.target || !bracket.sl || bracket.sl >= optPrice || bracket.target <= optPrice) return null;
  return {
    ...bracket,
    source: 'pullback_continuation_' + setup.movementTier + '_' + setup.spotTargetPoints + 'pt',
    setup,
    config: {
      id: 'pullback_' + setup.spotTargetPoints + 'pt',
      label: `PB ${setup.spotTargetPoints}pt (${setup.movementTier})`,
      autoAdjusted: true,
      trailingEnabled: true,
      partialExitEnabled: true,
    },
  };
}

function applyPullbackContinuationInfluence(brain, setup) {
  if (!brain || !setup || !setup.active) return brain;
  brain.pullbackContinuation = setup;
  if (!setup.entryAllowed && (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY')) {
    brain.decision = 'WAIT';
    brain.reason = (brain.reason || '') + ` [PBCE: ${setup.blockReason}]`;
    brain.pullbackContinuationBlocked = true;
  }
  return brain;
}

function checkPullbackContinuationEntryGate(brain, ctx, optionType) {
  if (!isPullbackContinuationActive()) return { allowed: true };
  const setup = (brain && brain.pullbackContinuation)
    ? brain.pullbackContinuation
    : computePullbackContinuationSetup(ctx, brain, {
      direction: optionType === 'PE' ? 'bearish' : (optionType === 'CE' ? 'bullish' : resolveTradeDirectionFromBrain(brain)),
    });
  if (!setup.active) return { allowed: true };
  if (setup.entryAllowed) return { allowed: true, setup };
  return {
    allowed: false,
    reason: `Pullback Continuation: ${setup.blockReason}`,
    setup,
  };
}

function logPullbackContinuationObservation(setup, sym, brain) {
  if (!setup || !setup.active) return;
  try {
    const log = JSON.parse(localStorage.getItem(FNO_PBCE_LOG_KEY) || '[]');
    log.push({
      ts: setup.ts || Date.now(),
      sym: sym || null,
      decision: brain ? brain.decision : null,
      phase: setup.phase,
      movementTier: setup.movementTier,
      spotTargetPoints: setup.spotTargetPoints,
      selectionReason: setup.selectionReason,
      feasibility: setup.feasibility,
      entryAllowed: setup.entryAllowed,
      blockReason: setup.blockReason,
      factorBreakdown: setup.factorBreakdown,
    });
    while (log.length > FNO_PBCE_LOG_MAX) log.shift();
    localStorage.setItem(FNO_PBCE_LOG_KEY, JSON.stringify(log));
  } catch (e) { /* quota */ }
}

function getPullbackContinuationLog() {
  try { return JSON.parse(localStorage.getItem(FNO_PBCE_LOG_KEY) || '[]'); } catch (e) { return []; }
}

function renderPullbackContinuationPanel(setup) {
  if (typeof document === 'undefined') return;
  const box = document.getElementById('pullbackContinuationBox');
  if (!box) return;
  if (!setup || !setup.active) { box.style.display = 'none'; return; }
  box.style.display = 'block';
  const tp = typeof fnoThemePalette === 'function' ? fnoThemePalette() : {
    panel: '#422006', line: '#92400e', text: '#e2e8f0', muted: '#94a3b8', pass: '#4ade80', fail: '#f87171', warn: '#fde68a',
  };
  box.style.background = tp.panel;
  box.style.borderColor = tp.line;
  box.style.color = tp.text;
  const phaseColor = setup.phase === FNO_PBCE_PHASES.CONTINUATION_CONFIRMED ? tp.pass
    : setup.phase === FNO_PBCE_PHASES.RETRACING ? tp.warn : tp.muted;
  const feasColor = setup.feasibility && setup.feasibility.feasible ? tp.pass : tp.fail;
  const fb = setup.factorBreakdown || {};
  box.innerHTML = [
    `<b style="color:${tp.warn}">↩️ Pullback Continuation Engine</b> <span style="color:${tp.muted}">(dynamic 10/15/20pt targets)</span>`,
    `<span style="color:${phaseColor}">Phase: ${fnoPbceEscapeHtml(setup.phase)}</span> — ${fnoPbceEscapeHtml(setup.phaseReason || '')}`,
    `Movement: <b>${fnoPbceEscapeHtml(setup.movementTier || '')}</b> (composite ${setup.classification ? setup.classification.compositeScore : '—'}) → <b>${setup.spotTargetPoints}pt</b> spot target`,
    `Selection: ${fnoPbceEscapeHtml(setup.selectionReason || '')}`,
    `Factors — velocity ${fb.velocity || 0}, momentum ${fb.momentum || 0}, trend ${fb.trendStrength || 0}, candles ${fb.candleStructure || 0}, vol ${fb.volatility || 0}`,
    `<span style="color:${feasColor}">Feasibility: ${setup.feasibility && setup.feasibility.feasible ? '✓' : '✗'} ${fnoPbceEscapeHtml(setup.feasibility ? setup.feasibility.reason : '')}</span>`,
    setup.entryAllowed
      ? `<span style="color:${tp.pass}">✓ Entry allowed — continuation confirmed with feasible ${setup.spotTargetPoints}pt target</span>`
      : `<span style="color:${tp.fail}">✗ Entry blocked — ${fnoPbceEscapeHtml(setup.blockReason || 'setup incomplete')}</span>`,
  ].map(line => `<div style="padding:3px 0;font-size:11px">${line}</div>`).join('');
}

function buildPullbackContinuationAttribution(setup) {
  if (!setup || !setup.active) return null;
  return {
    phase: setup.phase,
    movementTier: setup.movementTier,
    spotTargetPoints: setup.spotTargetPoints,
    spotTargetPrice: setup.spotTargetPrice,
    selectionReason: setup.selectionReason,
    factorBreakdown: setup.factorBreakdown,
    feasibility: setup.feasibility,
    entryAllowed: setup.entryAllowed,
    blockReason: setup.blockReason,
    reclassifiedEachRefresh: true,
  };
}
