/**
 * Scalping entry-mode ladder — Conservative through Maximum Opportunity.
 * Existing Conservative + Balanced behavior is preserved exactly via their profiles.
 * Progression relaxes ENTRY willingness and uses confidence-based sizing — not bigger blind risk.
 */
const FNO_MODE_TRADE_LOG_KEY = 'fno_mode_trade_log_v1';
const FNO_MODE_TRADE_LOG_MAX = 2000;

const FNO_SCALPING_TRADING_MODES = {
  conservative: {
    id: 'conservative',
    label: 'Conservative / Current',
    shortLabel: 'Conservative',
    order: 1,
    buyThreshold: 8,
    sellThreshold: -13,
    scalpingFmSafetyProfile: 'strict',
    spreadHardBlockPct: 12,
    weightedScorePolicy: 'block',
    experimental: false,
    capitalPreservation: {
      enabled: true,
      minConfidence: 'High',
      blockWeightedScoreWait: true,
      blockTrapWarnings: true,
      maxLosingTradesPerDay: 1,
      maxDailyLossPctPreservation: 1.5,
    },
    positionSizeMultiplier: { High: 1, Medium: 0.85, Low: 0.65 },
    description: 'Current default — strict FM escalation, High confidence only, weighted-score hard block.',
  },
  balanced: {
    id: 'balanced',
    label: 'Mode 2 — Balanced',
    shortLabel: 'Balanced',
    order: 2,
    buyThreshold: 8,
    sellThreshold: -13,
    scalpingFmSafetyProfile: 'balanced',
    spreadHardBlockPct: 12,
    weightedScorePolicy: 'block',
    experimental: false,
    capitalPreservation: {
      enabled: true,
      minConfidence: 'High',
      blockWeightedScoreWait: true,
      blockTrapWarnings: true,
      maxLosingTradesPerDay: 1,
      maxDailyLossPctPreservation: 1.5,
    },
    positionSizeMultiplier: { High: 1, Medium: 0.85, Low: 0.65 },
    description: 'Same thresholds as Conservative; skips +1 FM severity escalation only.',
  },
  relaxed: {
    id: 'relaxed',
    label: 'Mode 3 — Relaxed',
    shortLabel: 'Relaxed',
    order: 3,
    buyThreshold: 7,
    sellThreshold: -12,
    scalpingFmSafetyProfile: 'balanced',
    spreadHardBlockPct: 13,
    weightedScorePolicy: 'downgrade',
    experimental: false,
    capitalPreservation: {
      enabled: true,
      minConfidence: 'Medium',
      blockWeightedScoreWait: false,
      blockTrapWarnings: true,
      maxLosingTradesPerDay: 1,
      maxDailyLossPctPreservation: 1.5,
    },
    positionSizeMultiplier: { High: 1, Medium: 0.75, Low: 0.5 },
    description: 'Earlier entries on developing momentum; Medium+ confidence; reduced size on weaker setups.',
  },
  opportunity: {
    id: 'opportunity',
    label: 'Mode 4 — Opportunity',
    shortLabel: 'Opportunity',
    order: 4,
    buyThreshold: 6,
    sellThreshold: -11,
    scalpingFmSafetyProfile: 'balanced',
    spreadHardBlockPct: 14,
    weightedScorePolicy: 'off',
    experimental: false,
    capitalPreservation: {
      enabled: true,
      minConfidence: 'Medium',
      blockWeightedScoreWait: false,
      blockTrapWarnings: true,
      maxLosingTradesPerDay: 2,
      maxDailyLossPctPreservation: 1.5,
    },
    positionSizeMultiplier: { High: 1, Medium: 0.6, Low: 0.35 },
    description: 'More borderline-but-defensible entries; dynamic size down on lower confidence.',
  },
  aggressive_controlled: {
    id: 'aggressive_controlled',
    label: 'Mode 5 — Aggressive Controlled',
    shortLabel: 'Aggressive Controlled',
    order: 5,
    buyThreshold: 6,
    sellThreshold: -11,
    scalpingFmSafetyProfile: 'balanced',
    spreadHardBlockPct: 14,
    weightedScorePolicy: 'off',
    experimental: false,
    capitalPreservation: {
      enabled: true,
      minConfidence: 'Medium',
      blockWeightedScoreWait: false,
      blockTrapWarnings: true,
      maxLosingTradesPerDay: 2,
      maxDailyLossPctPreservation: 1.5,
    },
    positionSizeMultiplier: { High: 1, Medium: 0.5, Low: 0.25 },
    description: 'More frequent controlled entries; tight invalidation required; fast exit bias.',
  },
  maximum_opportunity: {
    id: 'maximum_opportunity',
    label: 'Mode 6 — Maximum Opportunity (Experimental)',
    shortLabel: 'Max Opportunity',
    order: 6,
    buyThreshold: 5,
    sellThreshold: -10,
    scalpingFmSafetyProfile: 'balanced',
    spreadHardBlockPct: 15,
    weightedScorePolicy: 'off',
    experimental: true,
    capitalPreservation: {
      enabled: true,
      minConfidence: 'Low',
      blockWeightedScoreWait: false,
      blockTrapWarnings: true,
      maxLosingTradesPerDay: 3,
      maxDailyLossPctPreservation: 1.5,
    },
    positionSizeMultiplier: { High: 0.75, Medium: 0.4, Low: 0.15 },
    description: 'Paper/testing — smallest risk on low confidence; hard SL and daily caps never removed.',
  },
};

const FNO_TRADING_MODE_IDS = Object.keys(FNO_SCALPING_TRADING_MODES);

function normalizeTradingModeId(id) {
  return FNO_TRADING_MODE_IDS.includes(id) ? id : 'conservative';
}

function resolveScalpingTradingMode() {
  if (typeof fnoSettings === 'undefined') return 'conservative';
  const s = fnoSettings.get();
  if (s.scalpingTradingMode) return normalizeTradingModeId(s.scalpingTradingMode);
  return s.scalpingFmSafetyProfile === 'balanced' ? 'balanced' : 'conservative';
}

function getActiveTradingModeProfile() {
  return FNO_SCALPING_TRADING_MODES[resolveScalpingTradingMode()] || FNO_SCALPING_TRADING_MODES.conservative;
}

function applyScalpingTradingModePreset(modeId) {
  const mode = FNO_SCALPING_TRADING_MODES[normalizeTradingModeId(modeId)];
  if (!mode || typeof fnoSettings === 'undefined') return mode;
  try { localStorage.removeItem('fno_threshold_override_v1'); } catch (e) { /* no-op */ }
  const cur = fnoSettings.get();
  fnoSettings.set({
    scalpingTradingMode: mode.id,
    scalpingProfitProfileEnabled: true,
    scalpingFmSafetyProfile: mode.scalpingFmSafetyProfile,
    tradingTypes: { intraday: false, scalping: true, swing: !!(cur.tradingTypes && cur.tradingTypes.swing) },
    tradeTypeTargetSlEnabled: true,
    tradeTypeSizingEnabled: false,
    autoCalibrateThresholdEnabled: mode.experimental ? false : true,
    autoCalibrateTargetWinRatePct: FNO_SCALPING_PROFIT_PROFILE.autoCalibrateTargetWinRatePct,
    scalpingCapitalPreservationEnabled: mode.capitalPreservation.enabled,
    maxLosingTradesPerDay: mode.capitalPreservation.maxLosingTradesPerDay,
    maxDailyLossPctPreservation: mode.capitalPreservation.maxDailyLossPctPreservation,
    scalpingTrailingEnabled: true,
    scalpingPartialExitEnabled: true,
    defaultLots: 2,
    scalpingBracketPreset: cur.scalpingBracketPreset || 'standard',
  });
  if (typeof applyScalpingExecutionControlsFromSettings === 'function') applyScalpingExecutionControlsFromSettings();
  if (typeof syncTargetSlUiFromPreset === 'function') syncTargetSlUiFromPreset();
  if (typeof updateLotQtyHint === 'function') updateLotQtyHint();
  return mode;
}

function confidenceRank(c) {
  return c === 'High' ? 3 : (c === 'Medium' ? 2 : 1);
}

function modeMeetsMinConfidence(mode, confidence) {
  const min = mode.capitalPreservation.minConfidence || 'High';
  return confidenceRank(confidence || 'Low') >= confidenceRank(min);
}

function resolveModePositionSizeMultiplier(confidence, mode) {
  mode = mode || getActiveTradingModeProfile();
  const map = mode.positionSizeMultiplier || { High: 1, Medium: 0.85, Low: 0.65 };
  return map[confidence] != null ? map[confidence] : map.Low;
}

function resolveModeAdjustedLotCount(baseLots, brain, mode) {
  const base = Math.max(1, Math.round(Number(baseLots) || 1));
  if (!brain) return base;
  mode = mode || getActiveTradingModeProfile();
  const mult = resolveModePositionSizeMultiplier(brain.confidence, mode);
  return Math.max(1, Math.round(base * mult));
}

function getModeSpreadHardBlockPct(mode) {
  mode = mode || getActiveTradingModeProfile();
  return mode.spreadHardBlockPct != null ? mode.spreadHardBlockPct : FNO_SCALPING_PROFIT_PROFILE.spreadHardBlockPct;
}

function getModeEffectiveThresholds(mode) {
  mode = mode || getActiveTradingModeProfile();
  return { buyThreshold: mode.buyThreshold, sellThreshold: mode.sellThreshold, source: 'trading_mode_' + mode.id };
}

function wouldModeAcceptSetup(modeId, brain, opts) {
  opts = opts || {};
  const mode = FNO_SCALPING_TRADING_MODES[normalizeTradingModeId(modeId)];
  if (!mode || !brain) return { accepted: false, reason: 'Missing mode or brain' };
  if (brain.decision !== 'BUY_READY' && brain.decision !== 'SELL_READY') {
    return { accepted: false, reason: `Decision is ${brain.decision}` };
  }
  const ds = typeof brain.directionalScore === 'number' ? brain.directionalScore : null;
  const wds = typeof brain.weightedDirectionalScore === 'number' ? brain.weightedDirectionalScore : ds;
  if (ds === null) return { accepted: false, reason: 'No directional score' };
  const crosses = brain.decision === 'BUY_READY'
    ? ds >= mode.buyThreshold
    : ds <= mode.sellThreshold;
  if (!crosses) return { accepted: false, reason: `Score ${ds.toFixed(1)} below mode threshold` };
  if (mode.weightedScorePolicy === 'block' && wds !== null) {
    const wCross = brain.decision === 'BUY_READY' ? wds >= mode.buyThreshold : wds <= mode.sellThreshold;
    if (!wCross) return { accepted: false, reason: 'Weighted score safety block' };
  }
  if (!modeMeetsMinConfidence(mode, brain.confidence)) {
    return { accepted: false, reason: `Requires ${mode.capitalPreservation.minConfidence}+ confidence` };
  }
  if (mode.capitalPreservation.blockTrapWarnings && brain.pretradeGateCheck) {
    const ids = (brain.pretradeGateCheck.triggered || []).map(t => t.id);
    if (brain.pretradeGateCheck.finalAction === 'block' || brain.pretradeGateCheck.finalAction === 'reject') {
      return { accepted: false, reason: `Pre-trade gate ${brain.pretradeGateCheck.finalAction}` };
    }
    const trapIds = ['FM077', 'FM032', 'FM031', 'FM158', 'FM159'];
    if (brain.pretradeGateCheck.finalAction === 'require_confirmation' && ids.some(id => trapIds.includes(id))) {
      return { accepted: false, reason: `Trap/operator warning (${ids.filter(id => trapIds.includes(id)).join(', ')})` };
    }
  }
  return { accepted: true, reason: 'Mode would accept this setup', modeId: mode.id, sizeMultiplier: resolveModePositionSizeMultiplier(brain.confidence, mode) };
}

function buildModeTradeAttribution(brain, ctx, opts) {
  opts = opts || {};
  const mode = getActiveTradingModeProfile();
  const topSignals = (brain.results || [])
    .filter(r => r.pass !== null && Math.abs(r.score || 0) >= 0.5)
    .sort((a, b) => Math.abs(b.score || 0) - Math.abs(a.score || 0))
    .slice(0, 8)
    .map(r => ({ factor: r.factor, pass: r.pass, score: r.score, cat: r.cat }));
  return {
    mode: mode.id,
    modeLabel: mode.label,
    opportunity: brain.decision,
    signals: topSignals,
    confidence: brain.confidence,
    entryReason: brain.reason || null,
    risk: {
      sizeMultiplier: resolveModePositionSizeMultiplier(brain.confidence, mode),
      sl: ctx && ctx.slPrice,
      target: ctx && ctx.targetPrice,
      invalidation: ctx && ctx.slPrice,
    },
    expectedMove: ctx && ctx.targetPrice && ctx.spot ? { target: ctx.targetPrice, spot: ctx.spot } : null,
    balancedWouldAccept: wouldModeAcceptSetup('balanced', brain, opts).accepted,
    relaxedWouldAccept: wouldModeAcceptSetup('relaxed', brain, opts).accepted,
  };
}

function logModeTradeEvent(entry) {
  let log;
  try { log = JSON.parse(localStorage.getItem(FNO_MODE_TRADE_LOG_KEY) || '[]'); } catch (e) { log = []; }
  log.push({ ...entry, ts: entry.ts || Date.now() });
  log = log.slice(-FNO_MODE_TRADE_LOG_MAX);
  try { localStorage.setItem(FNO_MODE_TRADE_LOG_KEY, JSON.stringify(log)); } catch (e) { /* no-op */ }
  return log;
}

function logModeTradeOpen(tradeId, brain, ctx, details) {
  details = details || {};
  const attr = buildModeTradeAttribution(brain, ctx, {});
  return logModeTradeEvent({
    tradeId,
    phase: 'open',
    sym: details.sym || (ctx && ctx.sym),
    mode: attr.mode,
    modeLabel: attr.modeLabel,
    opportunity: attr.opportunity,
    signals: attr.signals,
    confidence: attr.confidence,
    entryReason: attr.entryReason,
    risk: Object.assign({}, attr.risk, details.risk || {}),
    entry: details.entry || null,
    expectedMove: attr.expectedMove,
    invalidation: details.invalidation || (attr.risk && attr.risk.invalidation),
    balancedWouldAccept: attr.balancedWouldAccept,
    relaxedWouldAccept: attr.relaxedWouldAccept,
    balancedRejectRelaxedAccept: !attr.balancedWouldAccept && attr.relaxedWouldAccept,
    sizeMultiplier: attr.risk && attr.risk.sizeMultiplier,
  });
}

function logModeTradeClose(tradeId, open, closeDetails) {
  closeDetails = closeDetails || {};
  const holdingMinutes = (open && open.openedAt && closeDetails.ts)
    ? (closeDetails.ts - open.openedAt) / 60000 : null;
  const fastExit = holdingMinutes != null && holdingMinutes <= 3;
  const riskTakenRs = tradeRiskRs(Object.assign({}, open, closeDetails));
  return logModeTradeEvent({
    tradeId,
    phase: 'close',
    sym: closeDetails.sym,
    mode: (open && open.entrySnapshot && open.entrySnapshot.tradingMode) || resolveScalpingTradingMode(),
    exit: closeDetails.exitPrice,
    exitReason: closeDetails.exitReason,
    exitReasonLabel: closeDetails.exitReasonLabel,
    pnl: closeDetails.pnl,
    holdingMinutes: holdingMinutes != null ? +holdingMinutes.toFixed(2) : null,
    fastExit,
    riskTakenRs,
    balancedRejectRelaxedAccept: !!(open && open.entrySnapshot && open.entrySnapshot.modeAttribution
      && !open.entrySnapshot.modeAttribution.balancedWouldAccept && open.entrySnapshot.modeAttribution.relaxedWouldAccept),
    outcome: classifyBalancedVsRelaxedOutcome(closeDetails, { holdingMinutes, fastExit, exitReason: closeDetails.exitReason }),
  });
}

function getModeTradeLog() {
  try { return JSON.parse(localStorage.getItem(FNO_MODE_TRADE_LOG_KEY) || '[]'); } catch (e) { return []; }
}

function tradeRiskRs(t) {
  if (t.riskTakenRs != null && Number.isFinite(t.riskTakenRs)) return t.riskTakenRs;
  const entry = t.entryPrice || (t.factorSnapshot && t.factorSnapshot.entryPrice);
  const sl = t.sl || (t.factorSnapshot && t.factorSnapshot.sl);
  const qty = t.qty || 1;
  if (Number.isFinite(entry) && Number.isFinite(sl) && entry > sl) return Math.abs((entry - sl) * qty);
  return null;
}

function tradeRewardRs(t) {
  if (t.rewardGeneratedRs != null && Number.isFinite(t.rewardGeneratedRs)) return t.rewardGeneratedRs;
  const entry = t.entryPrice || (t.factorSnapshot && t.factorSnapshot.entryPrice);
  const target = t.target || (t.factorSnapshot && t.factorSnapshot.target);
  const qty = t.qty || 1;
  if (Number.isFinite(entry) && Number.isFinite(target) && target > entry) return Math.abs((target - entry) * qty);
  return null;
}

function classifyBalancedVsRelaxedOutcome(trade, modeLogEntry) {
  if (!trade || typeof trade.pnl !== 'number') return null;
  const isTrap = !!(trade.failureModeCheck && (trade.failureModeCheck.finalAction === 'block' || trade.failureModeCheck.finalAction === 'reject'))
    || (modeLogEntry && modeLogEntry.exitReason === 'trap');
  if (isTrap) return 'trap';
  if (trade.pnl > 0) return 'profitable';
  if (trade.pnl < 0) {
    const fast = modeLogEntry && (modeLogEntry.fastExit || (modeLogEntry.holdingMinutes != null && modeLogEntry.holdingMinutes <= 3));
    if (fast) return 'avoidable';
    return 'loss_making';
  }
  return 'breakeven';
}

function summarizeTradesForMode(trades, modeLog) {
  const closed = (trades || []).filter(t => typeof t.pnl === 'number' && Number.isFinite(t.pnl));
  const wins = closed.filter(t => t.pnl > 0);
  const losses = closed.filter(t => t.pnl < 0);
  const grossProfit = wins.reduce((s, t) => s + t.pnl, 0);
  const grossLoss = losses.reduce((s, t) => s + t.pnl, 0);
  const netPnl = closed.reduce((s, t) => s + t.pnl, 0);
  const winRatePct = closed.length ? +(wins.length / closed.length * 100).toFixed(1) : null;
  const profitFactor = grossLoss !== 0 ? +(grossProfit / Math.abs(grossLoss)).toFixed(2) : (grossProfit > 0 ? null : 0);
  const expectancy = closed.length ? +(netPnl / closed.length).toFixed(2) : null;
  let maxDrawdown = 0, peak = 0, cum = 0, maxConsecLoss = 0, streak = 0;
  const rMultiples = [];
  let fastExits = 0, stopLossExits = 0, trapTrades = 0, falseEntries = 0;
  let totalRiskRs = 0, riskCount = 0, totalRewardRs = 0;
  closed.sort((a, b) => a.ts - b.ts).forEach(t => {
    cum += t.pnl;
    peak = Math.max(peak, cum);
    maxDrawdown = Math.max(maxDrawdown, peak - cum);
    if (t.pnl < 0) { streak++; maxConsecLoss = Math.max(maxConsecLoss, streak); } else streak = 0;
    const risk = tradeRiskRs(t);
    if (risk != null && risk > 0) {
      rMultiples.push(t.pnl / risk);
      totalRiskRs += risk;
      riskCount++;
    }
    const reward = tradeRewardRs(t);
    if (reward != null) totalRewardRs += reward;
    const src = (t.source || '').toLowerCase();
    const act = (t.action || '').toLowerCase();
    if (src === 'auto_sl' || act.includes('sl')) stopLossExits++;
    const holdMin = (t.openedAt && t.ts) ? (t.ts - t.openedAt) / 60000 : null;
    if (holdMin != null && holdMin <= 3) fastExits++;
    if (t.failureModeCheck && (t.failureModeCheck.finalAction === 'block' || t.failureModeCheck.finalAction === 'reject')) trapTrades++;
    if (t.pnl < 0 && t.mfe != null && t.entryPrice != null && t.mfe <= t.entryPrice * 1.002) falseEntries++;
  });
  const avgWin = wins.length ? +(wins.reduce((s, t) => s + t.pnl, 0) / wins.length).toFixed(2) : null;
  const avgLoss = losses.length ? +(losses.reduce((s, t) => s + t.pnl, 0) / losses.length).toFixed(2) : null;
  const withHold = closed.filter(t => t.openedAt && t.ts);
  const avgHold = withHold.length ? +(withHold.reduce((s, t) => s + (t.ts - t.openedAt) / 60000, 0) / withHold.length).toFixed(1) : null;
  const avgR = rMultiples.length ? +(rMultiples.reduce((s, v) => s + v, 0) / rMultiples.length).toFixed(2) : null;
  const pnlPerTrade = closed.length ? +(netPnl / closed.length).toFixed(2) : null;
  const pnlPerUnitRisk = totalRiskRs > 0 ? +(netPnl / totalRiskRs).toFixed(3) : null;
  return {
    tradesTaken: closed.length,
    wins: wins.length,
    losses: losses.length,
    winRatePct,
    avgWin,
    avgLoss,
    profitFactor,
    expectancy,
    avgR,
    maxDrawdown: +maxDrawdown.toFixed(2),
    maxConsecutiveLosses: maxConsecLoss,
    avgHoldingMinutes: avgHold,
    fastExits,
    stopLossExits,
    trapTrades,
    falseEntries,
    netPnl: +netPnl.toFixed(2),
    grossProfit: +grossProfit.toFixed(2),
    grossLoss: +grossLoss.toFixed(2),
    riskTakenRs: +totalRiskRs.toFixed(2),
    rewardGeneratedRs: +totalRewardRs.toFixed(2),
    pnlPerTrade,
    pnlPerUnitRisk,
  };
}

function computeModeComparisonDashboard(opts) {
  opts = opts || {};
  const decisionLog = typeof getDecisionLog === 'function' ? getDecisionLog() : [];
  const journal = typeof load === 'function' ? (load(typeof STORAGE !== 'undefined' ? STORAGE.autoTrades + '_history' : 'fno_autotrades_v8_history') || []) : [];
  const modeLog = getModeTradeLog();
  const sym = opts.symbol || null;
  let entries = decisionLog.slice();
  if (sym) entries = entries.filter(e => e.sym === sym);

  const byMode = {};
  FNO_TRADING_MODE_IDS.forEach(id => {
    const modeEntries = entries.filter(e => (e.tradingMode || e.indicatorSettings && e.indicatorSettings.tradingMode || 'conservative') === id);
    const modeJournal = journal.filter(t => {
      const m = (t.factorSnapshot && t.factorSnapshot.modeAttribution && t.factorSnapshot.modeAttribution.mode)
        || (t.factorSnapshot && t.factorSnapshot.tradingMode)
        || (t.modeAttribution && t.modeAttribution.mode)
        || (t.tradingMode);
      return m === id;
    });
    const modeLog = getModeTradeLog().filter(e => e.mode === id || (e.phase === 'close' && e.tradeId));
    const setups = modeEntries.filter(e => e.decision === 'BUY_READY' || e.decision === 'SELL_READY').length;
    const opportunities = modeEntries.filter(e => e.decision === 'BUY_READY' || e.decision === 'SELL_READY').length;
    const rejected = modeEntries.filter(e => (e.decision === 'BUY_READY' || e.decision === 'SELL_READY') && !e.tradeOpened).length;
    const missed = modeEntries.filter(e => (e.decision === 'BUY_READY' || e.decision === 'SELL_READY') && !e.tradeOpened
      && e.blockReason && !/autonomous mode is not enabled/i.test(e.blockReason)).length;
    byMode[id] = {
      mode: FNO_SCALPING_TRADING_MODES[id],
      opportunitiesDetected: opportunities,
      potentialSetups: setups,
      tradesRejected: rejected,
      missedOpportunities: missed,
      ...summarizeTradesForMode(modeJournal, modeLog),
    };
  });

  const balancedVsRelaxed = [];
  const modeLogAll = getModeTradeLog();
  entries.forEach(e => {
    if (e.decision !== 'BUY_READY' && e.decision !== 'SELL_READY') return;
    const pseudoBrain = {
      decision: e.decision,
      confidence: e.confidence,
      directionalScore: e.directionalScore,
      weightedDirectionalScore: e.weightedDirectionalScore,
      pretradeGateCheck: e.pretradeGateTriggeredIds ? { finalAction: e.pretradeGateFinalAction, triggered: e.pretradeGateTriggeredIds.map(id => ({ id })) } : null,
      results: e.topFactors || [],
      reason: e.reason,
    };
    const bal = wouldModeAcceptSetup('balanced', pseudoBrain);
    const rel = wouldModeAcceptSetup('relaxed', pseudoBrain);
    if (!bal.accepted && rel.accepted) {
      const closeLog = modeLogAll.find(l => l.phase === 'close' && l.ts >= e.ts && l.ts <= e.ts + 86400000);
      balancedVsRelaxed.push({
        ts: e.ts,
        sym: e.sym,
        decision: e.decision,
        score: e.directionalScore,
        confidence: e.confidence,
        tradeOpened: e.tradeOpened,
        blockReason: e.blockReason,
        relaxedReason: rel.reason,
        outcome: closeLog ? closeLog.outcome : (e.tradeOpened ? 'opened_pending' : 'not_taken'),
        pnl: closeLog ? closeLog.pnl : null,
      });
    }
  });

  const activeModeStats = byMode[resolveScalpingTradingMode()] || {};
  const balancedStats = byMode.balanced || {};
  const relaxedStats = byMode.relaxed || {};
  const vsBalanced = {
    additionalTrades: (activeModeStats.tradesTaken || 0) - (balancedStats.tradesTaken || 0),
    additionalNetPnl: +((activeModeStats.netPnl || 0) - (balancedStats.netPnl || 0)).toFixed(2),
    additionalMaxDrawdown: +((activeModeStats.maxDrawdown || 0) - (balancedStats.maxDrawdown || 0)).toFixed(2),
    additionalRiskRs: +((activeModeStats.riskTakenRs || 0) - (balancedStats.riskTakenRs || 0)).toFixed(2),
    expectancyDelta: (activeModeStats.expectancy != null && balancedStats.expectancy != null)
      ? +(activeModeStats.expectancy - balancedStats.expectancy).toFixed(2) : null,
    relaxedVsBalancedExtraTrades: (relaxedStats.tradesTaken || 0) - (balancedStats.tradesTaken || 0),
    relaxedVsBalancedExtraPnl: +((relaxedStats.netPnl || 0) - (balancedStats.netPnl || 0)).toFixed(2),
    relaxedVsBalancedExtraDrawdown: +((relaxedStats.maxDrawdown || 0) - (balancedStats.maxDrawdown || 0)).toFixed(2),
    relaxedVsBalancedExpectancyDelta: (relaxedStats.expectancy != null && balancedStats.expectancy != null)
      ? +(relaxedStats.expectancy - balancedStats.expectancy).toFixed(2) : null,
    balancedVsRelaxedExtraSetups: balancedVsRelaxed.length,
  };

  return {
    activeMode: resolveScalpingTradingMode(),
    activeModeProfile: getActiveTradingModeProfile(),
    byMode,
    balancedVsRelaxed: balancedVsRelaxed.slice(-30),
    vsBalanced,
    modeTradeLogSample: modeLog.slice(-50),
    sampleSize: entries.length,
  };
}

function renderModeComparisonDashboard(sym) {
  if (typeof document === 'undefined') return null;
  const box = document.getElementById('modeComparisonDashboard');
  if (!box) return null;
  if (typeof isScalpingProfitProfileActive === 'function' && !isScalpingProfitProfileActive()) {
    box.style.display = 'none';
    return null;
  }
  box.style.display = 'block';
  const palette = typeof fnoThemePalette === 'function' ? fnoThemePalette() : { panel: '#020617', muted: '#94a3b8', text: '#e2e8f0', pass: '#4ade80', fail: '#f87171', warn: '#fde68a', warnBg: '#422006', line: '#1e293b' };
  const dash = computeModeComparisonDashboard({ symbol: sym || null });
  const active = dash.activeModeProfile;
  const rows = FNO_TRADING_MODE_IDS.map(id => {
    const m = dash.byMode[id];
    if (!m) return '';
    const isActive = id === dash.activeMode;
    return `<tr style="${isActive ? 'background:' + palette.warnBg : ''}">
      <td style="padding:4px;white-space:nowrap">${escapeHtml(m.mode.shortLabel)}${isActive ? ' ✓' : ''}</td>
      <td style="padding:4px;text-align:right">${m.opportunitiesDetected}</td>
      <td style="padding:4px;text-align:right">${m.tradesTaken}</td>
      <td style="padding:4px;text-align:right">${m.tradesRejected}</td>
      <td style="padding:4px;text-align:right">${m.winRatePct != null ? m.winRatePct + '%' : '—'}</td>
      <td style="padding:4px;text-align:right">${m.expectancy != null ? m.expectancy : '—'}</td>
      <td style="padding:4px;text-align:right">${m.avgR != null ? m.avgR : '—'}</td>
      <td style="padding:4px;text-align:right;color:${m.netPnl >= 0 ? palette.pass : palette.fail}">${m.netPnl != null ? m.netPnl : '—'}</td>
      <td style="padding:4px;text-align:right">${m.maxDrawdown != null ? m.maxDrawdown : '—'}</td>
      <td style="padding:4px;text-align:right">${m.pnlPerUnitRisk != null ? m.pnlPerUnitRisk : '—'}</td>
    </tr>`;
  }).join('');

  const bvrRows = dash.balancedVsRelaxed.slice(-8).map(r => {
    const outcomeColor = r.outcome === 'profitable' ? palette.pass
      : (r.outcome === 'loss_making' || r.outcome === 'trap' ? palette.fail : palette.warn);
    const outcomeLabel = r.outcome === 'profitable' ? 'Profitable'
      : r.outcome === 'loss_making' ? 'Loss-making'
      : r.outcome === 'avoidable' ? 'Avoidable (fast exit)'
      : r.outcome === 'trap' ? 'Trap'
      : r.outcome === 'opened_pending' ? 'Opened (pending)'
      : r.outcome === 'not_taken' ? 'Not taken' : (r.outcome || '?');
    return `<div style="font-size:10px;padding:3px 0;border-bottom:1px solid ${palette.line}">${new Date(r.ts).toLocaleTimeString()} ${escapeHtml(r.sym)} ${r.decision} score ${r.score != null ? r.score.toFixed(1) : '?'} — Balanced REJECT / Relaxed ACCEPT · <span style="color:${outcomeColor}">${outcomeLabel}${r.pnl != null ? ' (₹' + r.pnl.toFixed(0) + ')' : ''}</span></div>`;
  }).join('') || `<span style="color:${palette.muted}">No Balanced-reject / Relaxed-accept cases in log yet.</span>`;

  const activeStats = dash.byMode[dash.activeMode] || {};
  box.innerHTML = `
    <div style="font-size:11px;margin-bottom:8px"><b>Active:</b> ${escapeHtml(active.label)} — ${escapeHtml(active.description)}</div>
    <div style="overflow:auto">
      <table style="width:100%;font-size:10px;border-collapse:collapse">
        <thead><tr style="color:${palette.muted}">
          <th align="left">Mode</th><th>Opps</th><th>Trades</th><th>Rejected</th><th>Win%</th><th>Exp</th><th>Avg R</th><th>Net P&L</th><th>Max DD</th><th>P&L/Risk</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
    <div style="margin-top:8px;font-size:10px;color:${palette.muted};display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:4px">
      <span>Fast exits: ${activeStats.fastExits != null ? activeStats.fastExits : '—'}</span>
      <span>SL exits: ${activeStats.stopLossExits != null ? activeStats.stopLossExits : '—'}</span>
      <span>Trap trades: ${activeStats.trapTrades != null ? activeStats.trapTrades : '—'}</span>
      <span>False entries: ${activeStats.falseEntries != null ? activeStats.falseEntries : '—'}</span>
      <span>Missed opps: ${activeStats.missedOpportunities != null ? activeStats.missedOpportunities : '—'}</span>
      <span>Avg hold: ${activeStats.avgHoldingMinutes != null ? activeStats.avgHoldingMinutes + 'm' : '—'}</span>
      <span>Profit factor: ${activeStats.profitFactor != null ? activeStats.profitFactor : '—'}</span>
      <span>Risk taken: ${activeStats.riskTakenRs != null ? '₹' + activeStats.riskTakenRs : '—'}</span>
    </div>
    <div style="margin-top:10px;font-size:10px;color:${palette.muted}">
      <b>Additional Trades vs Additional Risk (active vs Balanced):</b> +${dash.vsBalanced.additionalTrades} trades · +₹${dash.vsBalanced.additionalRiskRs} risk · ΔP&L ${dash.vsBalanced.additionalNetPnl} · Δexpectancy ${dash.vsBalanced.expectancyDelta != null ? dash.vsBalanced.expectancyDelta : '—'} · ΔmaxDD ${dash.vsBalanced.additionalMaxDrawdown}
    </div>
    <div style="margin-top:4px;font-size:10px;color:${palette.muted}">
      <b>Additional Profit vs Additional Drawdown (Relaxed vs Balanced):</b> +${dash.vsBalanced.relaxedVsBalancedExtraTrades} trades · ΔP&L ${dash.vsBalanced.relaxedVsBalancedExtraPnl} · Δexpectancy ${dash.vsBalanced.relaxedVsBalancedExpectancyDelta != null ? dash.vsBalanced.relaxedVsBalancedExpectancyDelta : '—'} · ΔmaxDD ${dash.vsBalanced.relaxedVsBalancedExtraDrawdown}
    </div>
    <div style="margin-top:8px;font-weight:700;font-size:11px">Balanced REJECT → Relaxed ACCEPT (recent)</div>
    <div style="max-height:100px;overflow:auto">${bvrRows}</div>
  `;
  return dash;
}
