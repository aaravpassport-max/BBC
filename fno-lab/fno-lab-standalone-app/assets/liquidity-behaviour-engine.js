/**
 * Operator Behaviour, Liquidity & Crowded-Position Trap Detection Engine
 * Integrates with existing Operator Intel / trap / breakout systems — does NOT
 * duplicate "CE buyers = down" simplistic rules. Outputs probabilistic scores
 * and a real-time state machine for the main brain + pre-trade gates.
 */
const FNO_LIQUIDITY_TRAP_STATES = {
  NORMAL: 'NORMAL',
  CROWDED_POSITIONING: 'CROWDED_POSITIONING',
  KEY_LEVEL_APPROACHING: 'KEY_LEVEL_APPROACHING',
  BREAKOUT_ATTEMPT: 'BREAKOUT_ATTEMPT',
  ACCEPTANCE_OR_REJECTION: 'ACCEPTANCE_OR_REJECTION',
  POTENTIAL_LIQUIDITY_SWEEP: 'POTENTIAL_LIQUIDITY_SWEEP',
  TRAPPED_POSITIONING: 'TRAPPED_POSITIONING',
  FORCED_EXIT: 'FORCED_EXIT',
  MOMENTUM_CONFIRMATION: 'MOMENTUM_CONFIRMATION',
  DIRECTIONAL_EXPANSION: 'DIRECTIONAL_EXPANSION',
};

const FNO_LIQUIDITY_TRAP_LOG_KEY = 'fno_liquidity_trap_log_v1';
const FNO_LIQUIDITY_TRAP_STATE_KEY = 'fno_liquidity_trap_state_v1';

function computeLiquidityMap(ctx) {
  const spot = ctx && Number.isFinite(ctx.spot) ? ctx.spot : null;
  const candles = (ctx && ctx.candles) || [];
  const zones = [];
  if (!spot || !candles.length) return { zones, spot, disclaimer: 'Insufficient candle data for liquidity map' };

  const closes = candles.map(c => c.c).filter(Number.isFinite);
  const n = closes.length;
  const sessionOpen = closes[0];
  const sessionHigh = Math.max(...closes);
  const sessionLow = Math.min(...closes);
  const prevClose = Number.isFinite(ctx.spotPrevClose) ? ctx.spotPrevClose : (n > 1 ? closes[n - 2] : null);

  const addZone = (label, price, kind, weight) => {
    if (!Number.isFinite(price)) return;
    zones.push({
      label, price, kind, weight: weight || 1,
      distPct: ((spot - price) / spot) * 100,
      side: price >= spot ? 'above' : 'below',
    });
  };

  addZone('Session high', sessionHigh, 'swing_high', 1.2);
  addZone('Session low', sessionLow, 'swing_low', 1.2);
  addZone('Session open', sessionOpen, 'open', 0.9);
  if (Number.isFinite(prevClose)) addZone('Previous close', prevClose, 'prior_close', 1);
  if (Number.isFinite(ctx.vwap)) addZone('VWAP proxy', ctx.vwap, 'vwap', 1.1);

  const rangeCond = typeof computeBreakoutReversalCondition === 'function'
    ? computeBreakoutReversalCondition(candles, 20) : null;
  if (rangeCond && Number.isFinite(rangeCond.rangeHigh)) addZone('20-bar range high', rangeCond.rangeHigh, 'range_resistance', 1.3);
  if (rangeCond && Number.isFinite(rangeCond.rangeLow)) addZone('20-bar range low', rangeCond.rangeLow, 'range_support', 1.3);

  const payoffLevels = typeof computeMultiLevelPayoffMap === 'function' && ctx.ocRows
    ? computeMultiLevelPayoffMap(ctx.ocRows, spot, 3) : [];
  payoffLevels.forEach((lv, i) => addZone(`OI payoff L${i + 1}`, lv.strike, 'oi_concentration', 1.1));

  if (ctx.ocRows && ctx.ocRows.length) {
    let maxCeStrike = null, maxCeOi = 0, maxPeStrike = null, maxPeOi = 0;
    ctx.ocRows.forEach(row => {
      const ceOi = row.CE && row.CE.openInterest ? row.CE.openInterest : 0;
      const peOi = row.PE && row.PE.openInterest ? row.PE.openInterest : 0;
      if (ceOi > maxCeOi) { maxCeOi = ceOi; maxCeStrike = row.strikePrice; }
      if (peOi > maxPeOi) { maxPeOi = peOi; maxPeStrike = row.strikePrice; }
    });
    if (maxCeStrike != null) addZone('Max CE OI strike', maxCeStrike, 'call_oi_wall', 1.15);
    if (maxPeStrike != null) addZone('Max PE OI strike', maxPeStrike, 'put_oi_wall', 1.15);
  }

  zones.sort((a, b) => Math.abs(a.distPct) - Math.abs(b.distPct));
  const nearestSupport = zones.filter(z => z.side === 'below').sort((a, b) => b.price - a.price)[0] || null;
  const nearestResistance = zones.filter(z => z.side === 'above').sort((a, b) => a.price - b.price)[0] || null;

  return { zones, spot, nearestSupport, nearestResistance, rangeCond, disclaimer: null };
}

function computePathBetweenLevels(candles, levelA, levelB) {
  if (!candles || candles.length < 5 || !levelA || !levelB || !Number.isFinite(levelA.price) || !Number.isFinite(levelB.price)) {
    return { available: false, reason: 'Need two valid liquidity levels and candle history' };
  }
  const closes = candles.map(c => c.c).filter(Number.isFinite);
  const recent = closes.slice(-10);
  const start = recent[0], end = recent[recent.length - 1];
  const displacement = end - start;
  const displacementPct = start !== 0 ? (displacement / start) * 100 : 0;
  const velocity = recent.length > 1 ? displacement / (recent.length - 1) : 0;
  const mid = (levelA.price + levelB.price) / 2;
  const towardB = levelB.price > levelA.price ? end > start : end < start;
  let testsA = 0, testsB = 0;
  const tol = Math.max(Math.abs(levelA.price), Math.abs(levelB.price)) * 0.001;
  recent.forEach(c => {
    if (Math.abs(c - levelA.price) <= tol) testsA++;
    if (Math.abs(c - levelB.price) <= tol) testsB++;
  });
  return {
    available: true,
    from: levelA.label, to: levelB.label,
    fromPrice: levelA.price, toPrice: levelB.price,
    displacementPct, velocity, towardTarget: towardB,
    testsAtStart: testsA, testsAtEnd: testsB,
    summary: `${levelA.label} (${levelA.price.toFixed(1)}) → ${levelB.label} (${levelB.price.toFixed(1)}): ${displacementPct >= 0 ? '+' : ''}${displacementPct.toFixed(2)}% over ${recent.length} bars, ${testsB} test(s) at target`,
  };
}

function computeLiquiditySweepSignal(candles, liquidityMap, breakoutCondition, reversalSignal, trapSignal) {
  if (!breakoutCondition) return { detected: false, direction: null, confidence: 0, reason: 'No breakout classification available' };

  const falseUp = breakoutCondition.condition === 'false_breakout_up';
  const falseDown = breakoutCondition.condition === 'false_breakdown';
  if (!falseUp && !falseDown) {
    return { detected: false, direction: null, confidence: 0, reason: 'No liquidity sweep pattern (needs failed breakout/breakdown — price returned inside range after probing outside)' };
  }

  let confidence = 35;
  const confirmations = [];
  if (trapSignal && trapSignal.isTrapSignature === true) { confidence += 20; confirmations.push('OI buildup without price follow-through'); }
  if (reversalSignal && reversalSignal.isReversal) {
    const opp = (falseUp && reversalSignal.direction === 'bearish') || (falseDown && reversalSignal.direction === 'bullish');
    if (opp) { confidence += 15; confirmations.push(`Recent ${reversalSignal.direction} reversal`); }
  }
  if (liquidityMap && liquidityMap.nearestResistance && falseUp) confirmations.push(`Rejected near ${liquidityMap.nearestResistance.label}`);
  if (liquidityMap && liquidityMap.nearestSupport && falseDown) confirmations.push(`Rejected near ${liquidityMap.nearestSupport.label}`);

  const direction = falseUp ? 'bearish_trap' : 'bullish_trap';
  return {
    detected: true,
    direction,
    type: falseUp ? 'upside_liquidity_sweep' : 'downside_liquidity_sweep',
    confidence: Math.min(100, confidence),
    reason: `${falseUp ? 'Bull trap / upside sweep' : 'Bear trap / downside sweep'}: ${breakoutCondition.reason}${confirmations.length ? ' | ' + confirmations.join('; ') : ''}`,
    confirmations,
  };
}

function computeAbsorptionProxy(candles) {
  if (!candles || candles.length < 6) return { detected: false, side: null, reason: 'Insufficient candles for absorption check' };
  const recent = candles.slice(-6);
  const closes = recent.map(c => c.c).filter(Number.isFinite);
  if (closes.length < 6) return { detected: false, side: null, reason: 'Non-finite candle data' };

  const range = Math.max(...closes) - Math.min(...closes);
  const netMove = Math.abs(closes[closes.length - 1] - closes[0]);
  const chopRatio = range > 0 ? netMove / range : 0;
  const smallProgress = range > 0 && netMove / range < 0.35 && range / closes[0] * 100 > 0.15;

  if (!smallProgress) return { detected: false, side: null, reason: 'Price displacement proportional to range — no absorption signature' };

  const drift = closes[closes.length - 1] - closes[0];
  const side = drift > 0 ? 'sell_side_absorption' : drift < 0 ? 'buy_side_absorption' : null;
  return {
    detected: !!side,
    side,
    chopRatio,
    reason: side === 'sell_side_absorption'
      ? 'Heavy two-way activity with limited upward progress — possible selling absorption'
      : side === 'buy_side_absorption'
        ? 'Heavy two-way activity with limited downward progress — possible buying absorption'
        : 'Choppy range without directional absorption',
  };
}

function computeCEPETrapStages(ctx, inputs) {
  const stages = { ce: [], pe: [], activePattern: null };
  const { pcr, operatorIntel, breakoutCondition, trapSignal, reversalSignal, netOIChange, priceChangePct, liquidityMap } = inputs;
  const oi = ctx && ctx.ocRow;

  const ceVol = oi && oi.CE ? (oi.CE.totalTradedVolume || 0) : 0;
  const peVol = oi && oi.PE ? (oi.PE.totalTradedVolume || 0) : 0;
  const ceOiCh = oi && oi.CE ? (oi.CE.changeinOpenInterest || 0) : 0;
  const peOiCh = oi && oi.PE ? (oi.PE.changeinOpenInterest || 0) : 0;

  if (Number.isFinite(pcr) && pcr < 0.75 && ceVol > peVol * 1.2) {
    stages.ce.push({ stage: 1, label: 'Heavy CE positioning', detail: `PCR ${pcr.toFixed(2)}, elevated CE volume/OI activity` });
  }
  if (liquidityMap && liquidityMap.nearestResistance && Math.abs(liquidityMap.nearestResistance.distPct) < 0.4) {
    stages.ce.push({ stage: 2, label: 'Approaching resistance', detail: liquidityMap.nearestResistance.label });
  }
  if (breakoutCondition && breakoutCondition.condition === 'false_breakout_up') {
    stages.ce.push({ stage: 3, label: 'Breakout failure', detail: breakoutCondition.reason });
  }
  if (trapSignal && trapSignal.isTrapSignature && Number.isFinite(priceChangePct) && priceChangePct < 0.2) {
    stages.ce.push({ stage: 4, label: 'Premium/conviction deterioration', detail: trapSignal.reason });
  }
  if (Number.isFinite(netOIChange) && netOIChange < 0 && ceOiCh < 0) {
    stages.ce.push({ stage: 5, label: 'Crowded CE unwind', detail: `Net OI ${netOIChange.toLocaleString()}, CE OI change ${ceOiCh.toLocaleString()}` });
  }
  if (reversalSignal && reversalSignal.isReversal && reversalSignal.direction === 'bearish' && stages.ce.length >= 3) {
    stages.ce.push({ stage: 6, label: 'Downside acceleration', detail: reversalSignal.reason });
  }

  if (Number.isFinite(pcr) && pcr > 1.4 && peVol > ceVol * 1.2) {
    stages.pe.push({ stage: 1, label: 'Heavy PE positioning', detail: `PCR ${pcr.toFixed(2)}, elevated PE volume/OI activity` });
  }
  if (liquidityMap && liquidityMap.nearestSupport && Math.abs(liquidityMap.nearestSupport.distPct) < 0.4) {
    stages.pe.push({ stage: 2, label: 'Approaching support', detail: liquidityMap.nearestSupport.label });
  }
  if (breakoutCondition && breakoutCondition.condition === 'false_breakdown') {
    stages.pe.push({ stage: 3, label: 'Breakdown failure', detail: breakoutCondition.reason });
  }
  if (trapSignal && trapSignal.isTrapSignature && Number.isFinite(priceChangePct) && priceChangePct > -0.2) {
    stages.pe.push({ stage: 4, label: 'Premium/conviction deterioration', detail: trapSignal.reason });
  }
  if (Number.isFinite(netOIChange) && netOIChange < 0 && peOiCh < 0) {
    stages.pe.push({ stage: 5, label: 'Crowded PE unwind', detail: `Net OI ${netOIChange.toLocaleString()}, PE OI change ${peOiCh.toLocaleString()}` });
  }
  if (reversalSignal && reversalSignal.isReversal && reversalSignal.direction === 'bullish' && stages.pe.length >= 3) {
    stages.pe.push({ stage: 6, label: 'Upside acceleration', detail: reversalSignal.reason });
  }

  if (stages.ce.length >= 4) stages.activePattern = 'ce_trap_sequence';
  else if (stages.pe.length >= 4) stages.activePattern = 'pe_trap_sequence';
  return stages;
}

function computeLiquidityDisproofChecks(trapEngine, ctx, inputs) {
  const disproofs = [];
  const { breakoutCondition, trapSignal } = inputs;
  if (ctx && ctx.regime && ctx.regime.extendedStates && ctx.regime.extendedStates.includes('vol_expansion')) {
    disproofs.push({ id: 'news_vol', text: 'Sudden vol expansion — move may be event-driven, not a structural trap' });
  }
  if (breakoutCondition && (breakoutCondition.condition === 'breakout_up' || breakoutCondition.condition === 'breakdown')) {
    if (trapSignal && trapSignal.confirmed === true) disproofs.push({ id: 'genuine_break', text: 'Breakout with OI+price confirmation — may be genuine continuation' });
    else disproofs.push({ id: 'active_break', text: 'Active sustained breakout — trap hypothesis weaker until rejection' });
  }
  if (!trapEngine.sweep.detected && trapEngine.trapScore < 40) {
    disproofs.push({ id: 'weak_evidence', text: 'Insufficient multi-factor trap evidence this refresh' });
  }
  return disproofs;
}

function advanceLiquidityTrapState(prevState, signals) {
  const prev = prevState || FNO_LIQUIDITY_TRAP_STATES.NORMAL;
  const { crowded, approaching, breakoutActive, rejected, sweep, trapped, unwind, momentum, expansion } = signals;

  if (expansion) return FNO_LIQUIDITY_TRAP_STATES.DIRECTIONAL_EXPANSION;
  if (momentum) return FNO_LIQUIDITY_TRAP_STATES.MOMENTUM_CONFIRMATION;
  if (unwind) return FNO_LIQUIDITY_TRAP_STATES.FORCED_EXIT;
  if (trapped) return FNO_LIQUIDITY_TRAP_STATES.TRAPPED_POSITIONING;
  if (sweep) return FNO_LIQUIDITY_TRAP_STATES.POTENTIAL_LIQUIDITY_SWEEP;
  if (rejected) return FNO_LIQUIDITY_TRAP_STATES.ACCEPTANCE_OR_REJECTION;
  if (breakoutActive) return FNO_LIQUIDITY_TRAP_STATES.BREAKOUT_ATTEMPT;
  if (approaching) return FNO_LIQUIDITY_TRAP_STATES.KEY_LEVEL_APPROACHING;
  if (crowded) return FNO_LIQUIDITY_TRAP_STATES.CROWDED_POSITIONING;
  return FNO_LIQUIDITY_TRAP_STATES.NORMAL;
}

function computeLiquidityTrapScore(components) {
  let score = 0;
  const weights = {
    crowded: 12, sweep: 18, failedBreak: 15, absorption: 10,
    oiTrap: 12, stageProgress: 15, reversal: 10, wrongSide: 8, disproof: -15,
  };
  if (components.crowded) score += weights.crowded;
  if (components.sweep) score += weights.sweep * (components.sweepStrength || 0.5);
  if (components.failedBreak) score += weights.failedBreak;
  if (components.absorption) score += weights.absorption;
  if (components.oiTrap) score += weights.oiTrap;
  if (components.stageProgress) score += weights.stageProgress * Math.min(1, components.stageCount / 6);
  if (components.reversal) score += weights.reversal;
  if (components.wrongSide) score += weights.wrongSide;
  score += (components.disproofCount || 0) * weights.disproof;
  return Math.max(0, Math.min(100, Math.round(score)));
}

function liquidityTrapScoreLabel(score) {
  if (score <= 30) return 'Weak evidence';
  if (score <= 50) return 'Possible';
  if (score <= 70) return 'Significant';
  if (score <= 85) return 'Strong';
  return 'Extreme';
}

/**
 * Main orchestrator — reuses existing computeTrapSignal, computeBreakoutReversalCondition, etc.
 */
function computeLiquidityTrapEngine(ctx, brain, inputs) {
  inputs = inputs || {};
  const liquidityMap = computeLiquidityMap(ctx);
  const breakoutCondition = inputs.breakoutCondition || (typeof computeBreakoutReversalCondition === 'function' && ctx.candles
    ? computeBreakoutReversalCondition(ctx.candles, 20) : null);
  const reversalSignal = inputs.reversalSignal || (typeof computeReversalSignal === 'function' && ctx.candles
    ? computeReversalSignal(ctx.candles) : null);
  const trapSignal = inputs.trapSignal || null;
  const absorption = computeAbsorptionProxy(ctx.candles);
  const sweep = computeLiquiditySweepSignal(ctx.candles, liquidityMap, breakoutCondition, reversalSignal, trapSignal);
  const stages = computeCEPETrapStages(ctx, { ...inputs, breakoutCondition, trapSignal, reversalSignal, liquidityMap });

  const path = liquidityMap.nearestSupport && liquidityMap.nearestResistance
    ? computePathBetweenLevels(ctx.candles, liquidityMap.nearestSupport, liquidityMap.nearestResistance)
    : { available: false };

  const crowdedBull = Number.isFinite(ctx.pcr) && ctx.pcr < 0.65;
  const crowdedBear = Number.isFinite(ctx.pcr) && ctx.pcr > 1.5;
  const approaching = liquidityMap.nearestSupport && Math.abs(liquidityMap.nearestSupport.distPct) < 0.5
    || liquidityMap.nearestResistance && Math.abs(liquidityMap.nearestResistance.distPct) < 0.5;
  const breakoutActive = breakoutCondition && (breakoutCondition.condition === 'breakout_up' || breakoutCondition.condition === 'breakdown');
  const rejected = breakoutCondition && (breakoutCondition.condition === 'false_breakout_up' || breakoutCondition.condition === 'false_breakdown');

  let wrongSide = null;
  if (typeof computeWrongSidePositioningCheck === 'function') {
    const opt = brain && brain.decision === 'SELL_READY' ? 'PE' : brain && brain.decision === 'BUY_READY' ? 'CE' : null;
    if (opt) wrongSide = computeWrongSidePositioningCheck(opt, ctx.pcr, inputs.multiInstrument || null, ctx.operatorIntel ? ctx.operatorIntel.bias : null);
  }

  const stageCount = Math.max(stages.ce.length, stages.pe.length);
  const disproofs = computeLiquidityDisproofChecks({ sweep, trapScore: 0 }, ctx, { breakoutCondition, trapSignal });

  const trapScore = computeLiquidityTrapScore({
    crowded: crowdedBull || crowdedBear,
    sweep: sweep.detected,
    sweepStrength: sweep.confidence / 100,
    failedBreak: rejected,
    absorption: absorption.detected,
    oiTrap: trapSignal && trapSignal.isTrapSignature === true,
    stageProgress: stageCount >= 3,
    stageCount,
    reversal: reversalSignal && reversalSignal.isReversal,
    wrongSide: wrongSide && wrongSide.isWrongSideWarning,
    disproofCount: disproofs.length,
  });

  let prevState = FNO_LIQUIDITY_TRAP_STATES.NORMAL;
  try {
    const raw = sessionStorage.getItem(FNO_LIQUIDITY_TRAP_STATE_KEY);
    if (raw) prevState = JSON.parse(raw).state || prevState;
  } catch (e) { /* ignore */ }

  const state = advanceLiquidityTrapState(prevState, {
    crowded: crowdedBull || crowdedBear,
    approaching,
    breakoutActive,
    rejected,
    sweep: sweep.detected,
    trapped: stageCount >= 4,
    unwind: stages.ce.some(s => s.stage === 5) || stages.pe.some(s => s.stage === 5),
    momentum: reversalSignal && reversalSignal.isReversal,
    expansion: trapScore >= 70 && sweep.detected && reversalSignal && reversalSignal.isReversal,
  });

  try {
    sessionStorage.setItem(FNO_LIQUIDITY_TRAP_STATE_KEY, JSON.stringify({ state, ts: Date.now(), sym: ctx.sym }));
  } catch (e) { /* ignore */ }

  const bearTrapProb = sweep.direction === 'bearish_trap' ? sweep.confidence / 100
    : stages.pe.length >= 3 ? Math.min(0.7, stages.pe.length / 8) : crowdedBear ? 0.25 : 0;
  const bullTrapProb = sweep.direction === 'bullish_trap' ? sweep.confidence / 100
    : stages.ce.length >= 3 ? Math.min(0.7, stages.ce.length / 8) : crowdedBull ? 0.25 : 0;

  const continuationProb = breakoutActive && trapSignal && trapSignal.confirmed ? 0.65 : breakoutActive ? 0.45 : 0.2;
  const reversalProb = Math.min(0.9, trapScore / 100);

  return {
    liquidityMap,
    pathBetweenLevels: path,
    sweep,
    absorption,
    stages,
    trapScore,
    trapScoreLabel: liquidityTrapScoreLabel(trapScore),
    state,
    stateHistory: prevState !== state ? { from: prevState, to: state } : null,
    disproofs,
    wrongSide,
    probabilities: {
      bullish: Math.max(0, Math.min(1, 0.5 + (bullTrapProb * -0.3) + (stages.pe.length >= 4 ? 0.2 : 0))),
      bearish: Math.max(0, Math.min(1, 0.5 + (bearTrapProb * -0.3) + (stages.ce.length >= 4 ? 0.2 : 0))),
      bullTrap: bullTrapProb,
      bearTrap: bearTrapProb,
      liquiditySweep: sweep.detected ? sweep.confidence / 100 : 0,
      continuation: continuationProb,
      reversal: reversalProb,
    },
    psychology: buildLiquidityPsychologyNarrative(state, sweep, stages),
    generatedAt: Date.now(),
  };
}

function buildLiquidityPsychologyNarrative(state, sweep, stages) {
  const lines = [];
  if (state === FNO_LIQUIDITY_TRAP_STATES.KEY_LEVEL_APPROACHING) lines.push('Market expectation: a key level break may happen.');
  if (state === FNO_LIQUIDITY_TRAP_STATES.BREAKOUT_ATTEMPT) lines.push('Retail confidence rises as price probes outside the range.');
  if (state === FNO_LIQUIDITY_TRAP_STATES.ACCEPTANCE_OR_REJECTION) lines.push('Confidence deteriorates if the break fails to hold.');
  if (state === FNO_LIQUIDITY_TRAP_STATES.POTENTIAL_LIQUIDITY_SWEEP) lines.push('Stops may have been triggered; watch for rapid return inside the range.');
  if (state === FNO_LIQUIDITY_TRAP_STATES.TRAPPED_POSITIONING) lines.push('Crowded participants may be underwater on the failed move.');
  if (state === FNO_LIQUIDITY_TRAP_STATES.FORCED_EXIT) lines.push('Forced exits can add liquidity to the opposite-side move.');
  if (stages.activePattern === 'ce_trap_sequence') lines.push('CE trap sequence active — failed upside breakout risk elevated.');
  if (stages.activePattern === 'pe_trap_sequence') lines.push('PE trap sequence active — failed downside breakdown risk elevated.');
  if (sweep.detected) lines.push(sweep.reason);
  return lines;
}

function applyLiquidityTrapInfluence(brain, trapEngine, optionType) {
  if (!brain || !trapEngine) return brain;
  brain.liquidityBehaviour = trapEngine;

  const score = trapEngine.trapScore;
  const probs = trapEngine.probabilities;
  brain.liquidityProbabilities = probs;

  if (score < 31) return brain;

  const tradeDir = optionType === 'CE' ? 'bullish' : optionType === 'PE' ? 'bearish' : null;
  const opposesBullTrap = tradeDir === 'bullish' && (probs.bullTrap > 0.5 || trapEngine.stages.activePattern === 'ce_trap_sequence');
  const opposesBearTrap = tradeDir === 'bearish' && (probs.bearTrap > 0.5 || trapEngine.stages.activePattern === 'pe_trap_sequence');

  if (score >= 51 && (opposesBullTrap || opposesBearTrap) && brain.confidence === 'High') {
    brain.confidence = 'Medium';
    brain.liquidityConfidenceAdjustment = `Liquidity trap score ${score} (${trapEngine.trapScoreLabel}) — confidence reduced; crowded positioning may be vulnerable`;
  } else if (score >= 51 && (opposesBullTrap || opposesBearTrap)) {
    brain.liquidityConfidenceAdjustment = `Liquidity trap score ${score} — possible trap opposes ${tradeDir} entry; see Liquidity Behaviour panel`;
  }

  return brain;
}

function logLiquidityTrapObservation(trapEngine, sym, spot, brain) {
  if (!trapEngine || trapEngine.trapScore < 31) return;
  try {
    const log = JSON.parse(localStorage.getItem(FNO_LIQUIDITY_TRAP_LOG_KEY) || '[]');
    log.push({
      ts: Date.now(),
      sym,
      spot,
      trapScore: trapEngine.trapScore,
      state: trapEngine.state,
      sweep: trapEngine.sweep.detected ? trapEngine.sweep.type : null,
      pattern: trapEngine.stages.activePattern,
      decision: brain ? brain.decision : null,
      probabilities: trapEngine.probabilities,
      path: trapEngine.pathBetweenLevels.available ? trapEngine.pathBetweenLevels.summary : null,
    });
    while (log.length > 500) log.shift();
    localStorage.setItem(FNO_LIQUIDITY_TRAP_LOG_KEY, JSON.stringify(log));
  } catch (e) { /* ignore */ }
}

function renderLiquidityBehaviourPanel(trapEngine) {
  const box = document.getElementById('liquidityBehaviourBox');
  if (!box) return;
  if (!trapEngine) { box.innerHTML = '<span style="color:#64748b">Loading liquidity behaviour analysis...</span>'; return; }

  const tp = typeof fnoThemePalette === 'function' ? fnoThemePalette() : { text: '#e2e8f0', muted: '#94a3b8', warn: '#fde68a', pass: '#4ade80', fail: '#f87171', panel: '#0e152a', line: '#1e293b' };
  const scoreColor = trapEngine.trapScore >= 71 ? tp.fail : trapEngine.trapScore >= 51 ? tp.warn : tp.muted;
  const zones = (trapEngine.liquidityMap.zones || []).slice(0, 6).map(z =>
    `<div style="padding:2px 0">${escapeHtml(z.label)}: ${z.price.toFixed(1)} (${z.distPct >= 0 ? '+' : ''}${z.distPct.toFixed(2)}%)</div>`
  ).join('') || `<span style="color:${tp.muted}">No zones mapped</span>`;

  const stageHtml = (arr, title) => arr.length
    ? `<div style="margin-top:6px"><b>${title}</b>${arr.map(s => `<div style="font-size:10px;color:${tp.muted};padding:2px 0">S${s.stage}: ${escapeHtml(s.label)} — ${escapeHtml(s.detail)}</div>`).join('')}</div>`
    : '';

  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div><b style="color:${scoreColor}">Trap score: ${trapEngine.trapScore}/100</b> <span style="color:${tp.muted};font-size:10px">(${escapeHtml(trapEngine.trapScoreLabel)})</span></div>
      <div style="font-size:10px;color:${tp.muted}">State: <b style="color:${tp.text}">${escapeHtml(trapEngine.state.replace(/_/g, ' '))}</b></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;font-size:10px;margin-bottom:8px">
      <div style="background:${tp.panel};padding:6px;border-radius:6px;border:1px solid ${tp.line}"><div style="color:${tp.muted}">Sweep</div><div style="color:${trapEngine.sweep.detected ? tp.warn : tp.muted}">${trapEngine.sweep.detected ? 'YES' : 'No'}</div></div>
      <div style="background:${tp.panel};padding:6px;border-radius:6px;border:1px solid ${tp.line}"><div style="color:${tp.muted}">Continuation</div><div>${(trapEngine.probabilities.continuation * 100).toFixed(0)}%</div></div>
      <div style="background:${tp.panel};padding:6px;border-radius:6px;border:1px solid ${tp.line}"><div style="color:${tp.muted}">Reversal</div><div>${(trapEngine.probabilities.reversal * 100).toFixed(0)}%</div></div>
    </div>
    <div style="font-size:11px;margin-bottom:6px"><b>Liquidity map</b>${zones}</div>
    ${trapEngine.pathBetweenLevels.available ? `<div style="font-size:10px;color:${tp.muted};margin-bottom:6px"><b>Path:</b> ${escapeHtml(trapEngine.pathBetweenLevels.summary)}</div>` : ''}
    ${trapEngine.sweep.detected ? `<div style="padding:6px;border-radius:6px;background:${tp.panel};border:1px solid ${tp.line};font-size:10px;margin-bottom:6px;color:${tp.warn}">${escapeHtml(trapEngine.sweep.reason)}</div>` : ''}
    ${stageHtml(trapEngine.stages.ce, 'CE trap stages')}
    ${stageHtml(trapEngine.stages.pe, 'PE trap stages')}
    ${trapEngine.disproofs.length ? `<div style="margin-top:6px;font-size:10px;color:${tp.muted}"><b>Disproof checks:</b> ${trapEngine.disproofs.map(d => escapeHtml(d.text)).join(' · ')}</div>` : ''}
    ${trapEngine.psychology.length ? `<div style="margin-top:6px;font-size:10px;color:${tp.muted}"><b>Psychology:</b> ${trapEngine.psychology.map(p => escapeHtml(p)).join(' · ')}</div>` : ''}
  `;
}
