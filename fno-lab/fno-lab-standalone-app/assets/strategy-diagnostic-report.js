// ====================================================================
// Strategy Performance & Diagnostic Report Generator (v1.0)
// Measurement/diagnostic layer only — never modifies trading rules.
// ====================================================================

const FNO_DIAGNOSTIC_REPORT_SCHEMA = '1.0.0';

function filterReportWindow(entries, opts) {
  opts = opts || {};
  const startTs = typeof opts.startTs === 'number' ? opts.startTs : null;
  const endTs = typeof opts.endTs === 'number' ? opts.endTs : null;
  const sym = opts.symbol || null;
  const strategyVersion = opts.strategyVersion || null;
  return (entries || []).filter(e => {
    const ts = e.ts != null ? e.ts : e.timestamp;
    if (startTs != null && ts < startTs) return false;
    if (endTs != null && ts > endTs) return false;
    if (sym && e.sym !== sym && e.symbol !== sym) return false;
    if (strategyVersion && e.strategyVersion && e.strategyVersion !== strategyVersion) return false;
    return true;
  });
}

function computePerformanceMetrics(trades) {
  const closed = (trades || []).filter(t => typeof t.pnl === 'number');
  const wins = closed.filter(t => t.pnl > 0);
  const losses = closed.filter(t => t.pnl < 0);
  const breakeven = closed.filter(t => t.pnl === 0);
  const grossProfit = wins.reduce((s, t) => s + (typeof t.grossPnl === 'number' ? t.grossPnl : t.pnl), 0);
  const grossLoss = losses.reduce((s, t) => s + Math.abs(typeof t.grossPnl === 'number' ? t.grossPnl : t.pnl), 0);
  const totalCharges = closed.reduce((s, t) => s + (t.costsTotal || 0), 0);
  const netPnl = closed.reduce((s, t) => s + t.pnl, 0);
  const winRate = closed.length ? (wins.length / closed.length) * 100 : null;
  const avgWin = wins.length ? wins.reduce((s, t) => s + t.pnl, 0) / wins.length : null;
  const avgLoss = losses.length ? losses.reduce((s, t) => s + t.pnl, 0) / losses.length : null;
  const profitFactor = grossLoss > 0 ? grossProfit / grossLoss : (grossProfit > 0 ? null : 0);
  const expectancy = closed.length ? netPnl / closed.length : null;

  let peak = 0, equity = 0, maxDrawdown = 0, maxConsecWins = 0, maxConsecLosses = 0, cw = 0, cl = 0;
  const sorted = closed.slice().sort((a, b) => (a.ts || 0) - (b.ts || 0));
  sorted.forEach(t => {
    equity += t.pnl;
    if (equity > peak) peak = equity;
    const dd = peak - equity;
    if (dd > maxDrawdown) maxDrawdown = dd;
    if (t.pnl > 0) { cw++; cl = 0; if (cw > maxConsecWins) maxConsecWins = cw; }
    else if (t.pnl < 0) { cl++; cw = 0; if (cl > maxConsecLosses) maxConsecLosses = cl; }
    else { cw = 0; cl = 0; }
  });

  const holdingTimes = sorted.filter(t => t.openedAt && t.ts).map(t => (t.ts - t.openedAt) / 60000);
  const avgHoldingMin = holdingTimes.length ? holdingTimes.reduce((a, b) => a + b, 0) / holdingTimes.length : null;

  const mfeStats = sorted.filter(t => typeof t.mfe === 'number' && typeof t.entryPrice === 'number');
  const maeStats = mfeStats.filter(t => typeof t.mae === 'number');
  const avgMfePct = mfeStats.length
    ? mfeStats.reduce((s, t) => s + ((t.mfe - t.entryPrice) / t.entryPrice * 100), 0) / mfeStats.length
    : null;
  const avgMaePct = maeStats.length
    ? maeStats.reduce((s, t) => s + ((t.entryPrice - t.mae) / t.entryPrice * 100), 0) / maeStats.length
    : null;

  const targetHits = sorted.filter(t => (t.source || t.action || '').includes('target')).length;
  const slHits = sorted.filter(t => (t.source || t.action || '').includes('sl') || (t.action || '').includes('SL')).length;

  return {
    totalTrades: closed.length,
    wins: wins.length,
    losses: losses.length,
    breakeven: breakeven.length,
    grossProfit: +grossProfit.toFixed(2),
    grossLoss: +grossLoss.toFixed(2),
    totalCharges: +totalCharges.toFixed(2),
    netPnl: +netPnl.toFixed(2),
    winRatePct: winRate != null ? +winRate.toFixed(1) : null,
    avgWin: avgWin != null ? +avgWin.toFixed(2) : null,
    avgLoss: avgLoss != null ? +avgLoss.toFixed(2) : null,
    profitFactor: profitFactor != null ? +profitFactor.toFixed(2) : null,
    expectancy: expectancy != null ? +expectancy.toFixed(2) : null,
    maxDrawdown: +maxDrawdown.toFixed(2),
    maxConsecutiveWins: maxConsecWins,
    maxConsecutiveLosses: maxConsecLosses,
    avgHoldingMinutes: avgHoldingMin != null ? +avgHoldingMin.toFixed(1) : null,
    avgMfePct: avgMfePct != null ? +avgMfePct.toFixed(2) : null,
    avgMaePct: avgMaePct != null ? +avgMaePct.toFixed(2) : null,
    targetExits: targetHits,
    stopLossExits: slHits,
    trades: sorted,
  };
}

function buildSignalAndRejectionStats(decisionLog, blockedAttempts) {
  const log = decisionLog || [];
  const signals = log.filter(e => e.decision === 'BUY_READY' || e.decision === 'SELL_READY');
  const opened = signals.filter(e => e.tradeOpened);
  const blockedSetups = signals.filter(e => !e.tradeOpened);
  const rejections = [];

  blockedSetups.forEach(e => {
    rejections.push({
      ts: e.ts,
      sym: e.sym,
      decision: e.decision,
      tradingType: e.tradingType,
      blockReason: e.blockReason || 'unspecified',
      rejectionCategory: e.rejectionCategory || null,
      rejectionSubcategory: e.rejectionSubcategory || null,
      pretradeGateFinalAction: e.pretradeGateFinalAction || null,
      pretradeGateTriggeredIds: e.pretradeGateTriggeredIds || [],
      directionalScore: e.directionalScore,
      weightedDirectionalScore: e.weightedDirectionalScore,
      operatorIntelBias: e.operatorIntelBias || null,
      operatorIntelConfidence: e.operatorIntelConfidence || null,
      operatorIntelScore: e.operatorIntelScore || null,
      regimeLabel: e.regimeLabel || null,
      strategyVersion: e.strategyVersion || null,
      signalOptionType: e.signalOptionType || null,
      atmStrike: e.atmStrike || null,
      signalEntryPremium: e.signalEntryPremium || null,
      reason: e.reason || null,
      topFactors: e.topFactors || null,
      indicatorSettings: e.indicatorSettings || null,
    });
  });

  (blockedAttempts || []).forEach(a => {
    rejections.push({
      ts: a.timestamp,
      sym: a.symbol || null,
      decision: null,
      tradingType: a.tradingType,
      blockReason: (a.triggered || []).map(t => `${t.id}: ${t.reason || t.condition || ''}`).join(' | ') || a.summary || 'Failure-mode block',
      rejectionCategory: 'failure_mode_attempt',
      pretradeGateTriggeredIds: (a.triggered || []).map(t => t.id),
      strike: a.strike,
      optionType: a.optionType,
      lastPrice: a.lastPrice,
      finalAction: a.finalAction,
    });
  });

  rejections.sort((a, b) => (b.ts || 0) - (a.ts || 0));

  const reasonCounts = {};
  rejections.forEach(r => {
    const key = (r.blockReason || 'unspecified').slice(0, 160);
    reasonCounts[key] = (reasonCounts[key] || 0) + 1;
  });
  const topReasons = Object.entries(reasonCounts).sort((a, b) => b[1] - a[1]).slice(0, 15)
    .map(([reason, count]) => ({ reason, count }));

  return {
    totalRefreshes: log.length,
    totalSignals: signals.length,
    buySignals: signals.filter(e => e.decision === 'BUY_READY').length,
    sellSignals: signals.filter(e => e.decision === 'SELL_READY').length,
    tradesTaken: opened.length,
    setupsBlocked: blockedSetups.length,
    blockedAttemptsLogged: (blockedAttempts || []).length,
    rejectionRecords: rejections,
    topRejectionReasons: topReasons,
  };
}

function buildEligibilityPipeline(decisionLog) {
  const log = decisionLog || [];
  const pipeline = {
    totalRefreshes: log.length,
    waitBelowThreshold: log.filter(e => e.decision === 'WAIT').length,
    noTradeCritical: log.filter(e => e.decision === 'NO_TRADE').length,
    weightedScoreBlocked: log.filter(e => e.tradeTypeWeightingAdjustment).length,
    strategyPassed: 0,
    operatorGateClean: 0,
    riskGateClean: 0,
    executionPassed: 0,
    actualTrades: 0,
  };
  log.forEach(e => {
    const isSetup = e.decision === 'BUY_READY' || e.decision === 'SELL_READY';
    if (!isSetup) return;
    pipeline.strategyPassed++;
    const opIds = e.pretradeGateTriggeredIds || [];
    const operatorBlocked = opIds.some(id => id === 'FM077' || id === 'FM032' || id === 'FM031');
    if (!operatorBlocked) pipeline.operatorGateClean++;
    const gate = e.pretradeGateFinalAction || 'none';
    if (gate !== 'block' && gate !== 'reject') pipeline.riskGateClean++;
    if (e.tradeOpened) {
      pipeline.executionPassed++;
      pipeline.actualTrades++;
    }
  });
  const eligibilityRate = pipeline.totalRefreshes
    ? +(pipeline.strategyPassed / pipeline.totalRefreshes * 100).toFixed(1)
    : null;
  const executionRate = pipeline.strategyPassed
    ? +(pipeline.actualTrades / pipeline.strategyPassed * 100).toFixed(1)
    : null;
  let overRestricted = null;
  if (pipeline.totalRefreshes >= 30 && eligibilityRate != null && eligibilityRate < 5) {
    overRestricted = { level: 'warning', label: 'Potentially Over-Restricted Strategy', detail: `Only ${eligibilityRate}% of refreshes reached BUY/SELL (last ${pipeline.totalRefreshes} logged refreshes).` };
  } else if (pipeline.strategyPassed >= 15 && executionRate != null && executionRate < 5) {
    overRestricted = { level: 'warning', label: 'Potentially Over-Restricted Strategy', detail: `${pipeline.strategyPassed} setups but ${executionRate}% execution rate — filters or Autonomous Mode may be blocking most entries.` };
  }
  return { ...pipeline, eligibilityRatePct: eligibilityRate, executionRatePct: executionRate, overRestrictedAlert: overRestricted };
}

function buildOperatorIntelAnalysis(decisionLog, trades, missedOpp) {
  const log = decisionLog || [];
  const trapBlocks = log.filter(e => (e.pretradeGateTriggeredIds || []).some(id => id === 'FM077' || id === 'FM032') || /TRAP|wrong.?side/i.test(e.blockReason || ''));
  const withBias = log.filter(e => e.operatorIntelBias);
  const biasCounts = {};
  withBias.forEach(e => { biasCounts[e.operatorIntelBias] = (biasCounts[e.operatorIntelBias] || 0) + 1; });

  const missedWithOperator = (missedOpp && missedOpp.blockedButFavorable || []).filter(m =>
    /operator|FM077|FM032|trap/i.test(m.blockReason || '') || (m.pretradeGateTriggeredIds || []).some(id => id === 'FM077' || id === 'FM032')
  );

  const tradesWithBias = (trades || []).filter(t => t.factorSnapshot && t.factorSnapshot.operatorBias);
  const winsByBias = {};
  const lossesByBias = {};
  tradesWithBias.forEach(t => {
    const b = t.factorSnapshot.operatorBias;
    if (t.pnl > 0) winsByBias[b] = (winsByBias[b] || 0) + 1;
    if (t.pnl < 0) lossesByBias[b] = (lossesByBias[b] || 0) + 1;
  });

  return {
    biasFrequency: biasCounts,
    operatorRelatedBlocks: trapBlocks.length,
    operatorRelatedMissedOpportunities: missedWithOperator.length,
    tradeOutcomesByOperatorBias: { wins: winsByBias, losses: lossesByBias },
    sampleMissedWithOperator: missedWithOperator.slice(0, 10),
  };
}

function buildRegimeAndTimeAnalysis(trades, decisionLog) {
  const regimeStats = {};
  (trades || []).forEach(t => {
    const regime = (t.factorSnapshot && t.factorSnapshot.regime && t.factorSnapshot.regime.label) || 'unknown';
    if (!regimeStats[regime]) regimeStats[regime] = { trades: 0, netPnl: 0, wins: 0, losses: 0 };
    regimeStats[regime].trades++;
    regimeStats[regime].netPnl += t.pnl || 0;
    if (t.pnl > 0) regimeStats[regime].wins++;
    if (t.pnl < 0) regimeStats[regime].losses++;
  });

  const hourStats = {};
  const hourLabel = (ts) => {
    const h = new Date(ts).getHours();
    return `${String(h).padStart(2, '0')}:00–${String(h).padStart(2, '0')}:59 (local)`;
  };
  (decisionLog || []).forEach(e => {
    if (e.decision === 'BUY_READY' || e.decision === 'SELL_READY') {
      const label = hourLabel(e.ts);
      if (!hourStats[label]) hourStats[label] = { signals: 0, trades: 0, netPnl: 0 };
      hourStats[label].signals++;
    }
  });
  (trades || []).forEach(t => {
    const label = hourLabel(t.ts);
    if (!hourStats[label]) hourStats[label] = { signals: 0, trades: 0, netPnl: 0 };
    hourStats[label].trades++;
    hourStats[label].netPnl += t.pnl || 0;
  });

  return { byRegime: regimeStats, byHour: hourStats };
}

function buildIndicatorCategoryAnalysis(trades) {
  const cats = ['Market', 'Flow', 'Tech', 'Vol', 'Microstructure', 'Operator Intel'];
  const out = {};
  cats.forEach(cat => {
    out[cat] = { supportingWins: 0, opposingWins: 0, supportingLosses: 0, opposingLosses: 0 };
  });
  (trades || []).forEach(t => {
    if (!t.factorSnapshot || !t.factorSnapshot.factors) return;
    const isWin = t.pnl > 0;
    Object.values(t.factorSnapshot.factors).forEach(f => {
      if (!f || !f.cat || !cats.includes(f.cat)) return;
      if (f.pass === null || typeof f.score !== 'number' || f.score === 0) return;
      const bucket = out[f.cat];
      if (f.score > 0) { if (isWin) bucket.supportingWins++; else bucket.supportingLosses++; }
      if (f.score < 0) { if (isWin) bucket.opposingWins++; else bucket.opposingLosses++; }
    });
  });
  return out;
}

function buildEntryExitAnalysis(trades) {
  const rows = [];
  let goodEntry = 0, poorEntry = 0, goodExit = 0, poorExit = 0, mfeCapturedSum = 0, mfeCapturedN = 0;
  (trades || []).forEach(t => {
    const q = typeof diagnoseEntryExitQuality === 'function' ? diagnoseEntryExitQuality(t) : null;
    if (!q) return;
    rows.push({ ts: t.ts, symbol: t.symbol, pnl: t.pnl, ...q });
    if (/Good/.test(q.entryQuality)) goodEntry++; else if (/Poor/.test(q.entryQuality)) poorEntry++;
    if (/Good/.test(q.exitQuality)) goodExit++; else if (/Poor/.test(q.exitQuality)) poorExit++;
    mfeCapturedSum += q.mfeCapturedPct;
    mfeCapturedN++;
  });
  return {
    samples: rows.slice(-30),
    goodEntries: goodEntry,
    poorEntries: poorEntry,
    goodExits: goodExit,
    poorExits: poorExit,
    avgMfeCapturedPct: mfeCapturedN ? +(mfeCapturedSum / mfeCapturedN).toFixed(1) : null,
  };
}

function buildComponentCounterfactualHints(trades, decisionLog) {
  const hints = [];
  const perf = computePerformanceMetrics(trades);
  if (perf.totalTrades >= 5) {
    hints.push({
      component: 'Full strategy (as executed)',
      sampleTrades: perf.totalTrades,
      winRatePct: perf.winRatePct,
      netPnl: perf.netPnl,
      note: 'Baseline — all closed trades with current rule set.',
    });
  }
  const catAnalysis = buildIndicatorCategoryAnalysis(trades);
  Object.entries(catAnalysis).forEach(([cat, s]) => {
    const supWin = s.supportingWins, oppLoss = s.opposingLosses;
    if (supWin + oppLoss >= 3 && oppLoss > supWin) {
      hints.push({
        component: `${cat} category (when score opposed trade direction)`,
        sampleTrades: oppLoss,
        note: `${oppLoss} losing trade(s) had negative ${cat} factor scores — worth testing whether tightening ${cat} weight reduces losses (counterfactual — not auto-applied).`,
      });
    }
  });
  const nearMiss = (decisionLog || []).filter(e => e.decision === 'WAIT' && e.tradeTypeWeightingAdjustment);
  if (nearMiss.length >= 10) {
    hints.push({
      component: 'Weighted-score safety gate',
      sampleSignals: nearMiss.length,
      note: `${nearMiss.length} refreshes downgraded/blocked by trade-type category weighting — review whether this protects or over-filters (see eligibility funnel).`,
    });
  }
  return hints;
}

function buildStrategyAssumptions(report) {
  const items = [];
  const push = (area, evidence, suggestion, confidence) => {
    items.push({ area, evidence, suggestion, confidence, autoApply: false });
  };

  const p = report.performance;
  const pipe = report.eligibilityPipeline;
  const sig = report.signalsAndRejections;
  const missed = report.missedOpportunityAnalysis;

  if (pipe.overRestrictedAlert) {
    push('Trade frequency / filter stack', pipe.overRestrictedAlert.detail,
      'Review top rejection reasons and eligibility funnel before loosening any rule — test one change at a time in paper mode.', 'high');
  }
  if (sig.topRejectionReasons[0] && /autonomous mode/i.test(sig.topRejectionReasons[0].reason)) {
    push('Execution path', `${sig.topRejectionReasons[0].count} blocks: Autonomous Mode OFF`,
      'Start Autonomous Mode during market hours — not a strategy change.', 'high');
  }
  if (sig.topRejectionReasons.some(r => /spread|rejection|execution/i.test(r.reason))) {
    const r = sig.topRejectionReasons.find(x => /spread|rejection|execution/i.test(x.reason));
    push('Execution realism', `${r.count} blocks tied to spread/execution simulation`,
      'Verify strikes have tight spreads; consider session times with better liquidity — do not disable realistic execution without evidence.', 'medium');
  }
  if (missed && missed.blockedButFavorable && missed.blockedButFavorable.length >= 5) {
    const opMissed = missed.blockedButFavorable.filter(m => /FM0|failure|operator/i.test(m.blockReason || '')).length;
    if (opMissed >= 3) {
      push('Operator Intel / FM gates', `${opMissed} favorable moves blocked by operator or FM-related reasons`,
        'Compare missed-opportunity premium estimates vs actual trade outcomes — test hard-veto vs soft-warning only if evidence shows net benefit.', 'medium');
    } else {
      push('Thresholds / FM gates', `${missed.blockedButFavorable.length} blocked setups saw favorable spot/premium move afterward`,
        'Inspect each block reason — some may be protective, some may be over-tight. Do not loosen globally.', 'medium');
    }
  }
  if (p.totalTrades >= 5 && p.winRatePct != null && p.winRatePct < 40) {
    push('Overall edge', `Win rate ${p.winRatePct}% over ${p.totalTrades} trades, net Rs${p.netPnl}`,
      'Review losing-trade failure categories and entry/exit quality before changing thresholds.', 'medium');
  }
  if (report.entryExitAnalysis && report.entryExitAnalysis.poorExits >= 3 && report.entryExitAnalysis.poorExits > report.entryExitAnalysis.goodExits) {
    push('Exit logic', `${report.entryExitAnalysis.poorExits} poor exits vs ${report.entryExitAnalysis.goodExits} good (MFE capture)`,
      'Consider trailing stop or partial exit tests — target/SL may be leaving profit on table.', 'medium');
  }
  if (p.totalTrades >= 5 && p.profitFactor != null && p.profitFactor < 1) {
    push('Risk/reward', `Profit factor ${p.profitFactor} below 1.0`,
      'Average loss magnitude vs average win — review stop width and target distance for scalping profile.', 'medium');
  }
  if (items.length === 0) {
    push('Insufficient sample', 'Not enough closed trades and/or logged refreshes yet',
      'Continue paper trading with Autonomous Mode ON to build evidence before changing rules.', 'low');
  }
  return items;
}

function buildRecommendedTests(assumptions, componentHints) {
  const tests = [];
  assumptions.forEach(a => {
    if (a.confidence === 'high' || a.confidence === 'medium') {
      tests.push({ priority: a.confidence, area: a.area, proposedTest: a.suggestion, requiresVersionBump: /threshold|weight|filter|operator/i.test(a.suggestion) });
    }
  });
  componentHints.forEach(h => {
    tests.push({ priority: 'medium', area: h.component, proposedTest: h.note, requiresVersionBump: true });
  });
  return tests.slice(0, 12);
}

function compareStrategyVersions(decisionLog, trades, versionA, versionB) {
  const logA = filterReportWindow(decisionLog, { strategyVersion: versionA });
  const logB = filterReportWindow(decisionLog, { strategyVersion: versionB });
  const tradesA = (trades || []).filter(t => (t.factorSnapshot && t.factorSnapshot.strategyVersion === versionA));
  const tradesB = (trades || []).filter(t => (t.factorSnapshot && t.factorSnapshot.strategyVersion === versionB));
  return {
    versionA: { version: versionA, refreshes: logA.length, signals: logA.filter(e => e.decision === 'BUY_READY' || e.decision === 'SELL_READY').length, performance: computePerformanceMetrics(tradesA) },
    versionB: { version: versionB, refreshes: logB.length, signals: logB.filter(e => e.decision === 'BUY_READY' || e.decision === 'SELL_READY').length, performance: computePerformanceMetrics(tradesB) },
    caveat: 'Version tags must be present on decision-log rows and trade factor snapshots — bump FNO_STRATEGY_VERSION when changing rules so periods are comparable.',
  };
}

function buildStrategyDiagnosticReport(opts) {
  opts = opts || {};
  const period = {
    startTs: opts.startTs || null,
    endTs: opts.endTs || null,
    symbol: opts.symbol || null,
    strategyVersion: opts.strategyVersion || null,
    label: opts.periodLabel || 'All available history',
  };

  const rawDecisionLog = opts.decisionLog || (typeof getDecisionLog === 'function' ? getDecisionLog() : []);
  const rawTrades = opts.tradeHistory || (typeof load === 'function' && typeof STORAGE !== 'undefined'
    ? load(STORAGE.autoTrades + '_history') : []);
  const rawBlocked = opts.blockedAttempts || (typeof loadBlockedTradeAttempts === 'function'
    ? loadBlockedTradeAttempts() : (typeof load === 'function' && typeof STORAGE !== 'undefined' ? load(STORAGE.blockedAttempts) : []));

  const decisionLog = filterReportWindow(rawDecisionLog, period);
  const tradeHistory = filterReportWindow(rawTrades, period);
  const blockedAttempts = filterReportWindow(rawBlocked, period);

  const performance = computePerformanceMetrics(tradeHistory);
  const signalsAndRejections = buildSignalAndRejectionStats(decisionLog, blockedAttempts);
  const eligibilityPipeline = buildEligibilityPipeline(decisionLog);
  const missedOpportunityAnalysis = typeof computeMissedOpportunityAnalysis === 'function'
    ? computeMissedOpportunityAnalysis(decisionLog, opts.missedOppOpts || { lookforwardMinutes: 15 })
    : { blockedButFavorable: [], waitNearMissButMoved: [], unpredictableMoves: [] };
  const falsePositiveAnalysis = typeof computeFalsePositiveAnalysis === 'function'
    ? computeFalsePositiveAnalysis(tradeHistory) : { losingTrades: [], byFmId: {} };
  const operatorIntel = buildOperatorIntelAnalysis(decisionLog, tradeHistory, missedOpportunityAnalysis);
  const regimeAndTime = buildRegimeAndTimeAnalysis(tradeHistory, decisionLog);
  const indicatorPerformance = buildIndicatorCategoryAnalysis(tradeHistory);
  const entryExitAnalysis = buildEntryExitAnalysis(tradeHistory);
  const componentHints = buildComponentCounterfactualHints(tradeHistory, decisionLog);
  const failureAnalysis = typeof computeFailureAnalysis === 'function'
    ? computeFailureAnalysis(tradeHistory) : { categories: new Map(), totalLosses: 0 };

  const report = {
    meta: {
      schemaVersion: FNO_DIAGNOSTIC_REPORT_SCHEMA,
      generatedAt: new Date().toISOString(),
      generatedAtISTHint: 'Use browser local time; server IST available in PHP exports when logged in.',
      pluginVersion: opts.pluginVersion || (typeof FNO_PLUGIN_VERSION !== 'undefined' ? FNO_PLUGIN_VERSION : null),
      activeStrategyVersion: typeof FNO_STRATEGY_VERSION !== 'undefined' ? FNO_STRATEGY_VERSION : null,
      period,
      disclaimer: 'Diagnostic report only — does not modify trading rules. Findings are evidence for human review and controlled A/B tests.',
    },
    executiveSummary: {
      totalRefreshes: signalsAndRejections.totalRefreshes,
      totalSignals: signalsAndRejections.totalSignals,
      tradesTaken: signalsAndRejections.tradesTaken,
      setupsBlocked: signalsAndRejections.setupsBlocked,
      netPnl: performance.netPnl,
      winRatePct: performance.winRatePct,
      profitFactor: performance.profitFactor,
      eligibilityRatePct: eligibilityPipeline.eligibilityRatePct,
      executionRatePct: eligibilityPipeline.executionRatePct,
      overRestricted: eligibilityPipeline.overRestrictedAlert,
      topBlockReason: signalsAndRejections.topRejectionReasons[0] || null,
      missedOpportunitiesGenuine: (missedOpportunityAnalysis.blockedButFavorable || []).length,
    },
    performance,
    signalsAndRejections,
    eligibilityPipeline,
    missedOpportunityAnalysis,
    falsePositiveAnalysis,
    operatorIntel,
    regimeAndTime,
    indicatorPerformance,
    entryExitAnalysis,
    componentCounterfactualHints: componentHints,
    failureAnalysis: {
      totalLosses: failureAnalysis.totalLosses,
      categories: failureAnalysis.categories instanceof Map
        ? Array.from(failureAnalysis.categories.entries()).map(([cat, v]) => ({ category: cat, ...v }))
        : [],
    },
    winningTrades: performance.trades.filter(t => t.pnl > 0).slice(-50),
    losingTrades: performance.trades.filter(t => t.pnl < 0).slice(-50),
    strategyAssumptionsAndImprovements: [],
    recommendedFurtherTests: [],
    rawData: {
      decisionLogSample: decisionLog.slice(-200),
      rejectionSample: signalsAndRejections.rejectionRecords.slice(0, 100),
      tradeSample: tradeHistory.slice(-100),
    },
  };

  report.strategyAssumptionsAndImprovements = buildStrategyAssumptions(report);
  report.recommendedFurtherTests = buildRecommendedTests(report.strategyAssumptionsAndImprovements, componentHints);

  if (opts.compareVersionA && opts.compareVersionB) {
    report.strategyVersionComparison = compareStrategyVersions(rawDecisionLog, rawTrades, opts.compareVersionA, opts.compareVersionB);
  }

  return report;
}

function formatDiagnosticReportMarkdown(report) {
  const r = report;
  const lines = [];
  const h = (t) => { lines.push(`\n## ${t}\n`); };
  const p = (t) => lines.push(t);

  lines.push('# F&O Lab — Trading Performance & Strategy Diagnostic Report');
  p(`Generated: ${r.meta.generatedAt}`);
  p(`Schema: ${r.meta.schemaVersion} | Plugin: ${r.meta.pluginVersion || 'n/a'} | Strategy: ${r.meta.activeStrategyVersion || 'n/a'}`);
  p(`Period: ${r.meta.period.label}`);
  p(`\n*${r.meta.disclaimer}*`);

  h('1. Executive Summary');
  const e = r.executiveSummary;
  p(`- Refreshes logged: **${e.totalRefreshes}**`);
  p(`- BUY/SELL signals: **${e.totalSignals}** | Trades opened: **${e.tradesTaken}** | Setups blocked: **${e.setupsBlocked}**`);
  p(`- Net P&L: **Rs${e.netPnl}** | Win rate: **${e.winRatePct != null ? e.winRatePct + '%' : 'n/a'}** | Profit factor: **${e.profitFactor != null ? e.profitFactor : 'n/a'}**`);
  p(`- Eligibility: **${e.eligibilityRatePct != null ? e.eligibilityRatePct + '%' : 'n/a'}** | Execution rate: **${e.executionRatePct != null ? e.executionRatePct + '%' : 'n/a'}**`);
  if (e.overRestricted) p(`- ⚠️ **${e.overRestricted.label}**: ${e.overRestricted.detail}`);
  if (e.topBlockReason) p(`- Top block reason (${e.topBlockReason.count}×): ${e.topBlockReason.reason}`);

  h('2. Overall Performance');
  const perf = r.performance;
  p(`| Metric | Value |`);
  p(`|--------|-------|`);
  ['totalTrades', 'wins', 'losses', 'breakeven', 'grossProfit', 'grossLoss', 'totalCharges', 'netPnl', 'winRatePct', 'avgWin', 'avgLoss', 'profitFactor', 'expectancy', 'maxDrawdown', 'maxConsecutiveWins', 'maxConsecutiveLosses', 'avgHoldingMinutes', 'avgMfePct', 'avgMaePct', 'targetExits', 'stopLossExits'].forEach(k => {
    if (perf[k] != null) p(`| ${k} | ${perf[k]} |`);
  });

  h('3. Trade Statistics');
  p(`See Overall Performance and raw trade sample in JSON export.`);

  h('4–5. Winning / Losing Trade Analysis');
  p(`Winning trades in sample: ${r.winningTrades.length} | Losing: ${r.losingTrades.length}`);

  h('6. Rejected Trade Analysis');
  p(`Total rejection records: ${r.signalsAndRejections.rejectionRecords.length}`);
  r.signalsAndRejections.topRejectionReasons.forEach(x => p(`- (${x.count}×) ${x.reason}`));

  h('7. Missed Opportunity Analysis');
  p(`Genuine blocked-but-favorable: ${(r.missedOpportunityAnalysis.blockedButFavorable || []).length}`);
  p(`Near-miss WAIT moves: ${(r.missedOpportunityAnalysis.waitNearMissButMoved || []).length}`);

  h('8. Operator Intel Performance');
  p(JSON.stringify(r.operatorIntel.biasFrequency, null, 2));

  h('9. Indicator Performance (by category)');
  p(JSON.stringify(r.indicatorPerformance, null, 2));

  h('10. Entry/Exit Analysis');
  p(`Good entries: ${r.entryExitAnalysis.goodEntries} | Poor: ${r.entryExitAnalysis.poorEntries}`);
  p(`Good exits: ${r.entryExitAnalysis.goodExits} | Poor: ${r.entryExitAnalysis.poorExits}`);
  p(`Avg MFE captured: ${r.entryExitAnalysis.avgMfeCapturedPct != null ? r.entryExitAnalysis.avgMfeCapturedPct + '%' : 'n/a'}`);

  h('11. Market Regime Analysis');
  p(JSON.stringify(r.regimeAndTime.byRegime, null, 2));

  h('12. Risk Analysis');
  p(`Max drawdown Rs${perf.maxDrawdown} | Max consecutive losses ${perf.maxConsecutiveLosses}`);

  h('13. Strategy Assumptions & Potential Improvements');
  r.strategyAssumptionsAndImprovements.forEach(a => {
    p(`### ${a.area} (${a.confidence} confidence)`);
    p(`**Evidence:** ${a.evidence}`);
    p(`**Suggested test (NOT auto-applied):** ${a.suggestion}`);
  });

  h('14. Over-Restriction Detection — Trade Eligibility Pipeline');
  const pipe = r.eligibilityPipeline;
  p(`Potential setups (BUY/SELL): ${pipe.strategyPassed}`);
  p(`→ Operator gate clean: ${pipe.operatorGateClean}`);
  p(`→ Risk gate clean: ${pipe.riskGateClean}`);
  p(`→ Actual trades: ${pipe.actualTrades}`);

  h('15. Recommended Areas for Further Testing');
  r.recommendedFurtherTests.forEach(t => p(`- [${t.priority}] ${t.area}: ${t.proposedTest}`));

  h('16. Raw Data');
  p(`Full machine-readable data included in JSON export (decision log sample, rejections, trades).`);

  if (r.strategyVersionComparison) {
    h('Appendix: Strategy Version Comparison');
    p(JSON.stringify(r.strategyVersionComparison, null, 2));
  }

  return lines.join('\n');
}

function formatDiagnosticReportCsv(report) {
  const rows = [];
  const add = (section, cols) => { rows.push([section, ...cols]); };
  rows.push(['F&O Lab Strategy Diagnostic Report', report.meta.generatedAt, report.meta.schemaVersion]);
  rows.push([]);
  add('EXECUTIVE', ['metric', 'value']);
  Object.entries(report.executiveSummary).forEach(([k, v]) => {
    if (typeof v === 'object') return;
    rows.push(['EXECUTIVE', k, v]);
  });
  rows.push([]);
  add('PERFORMANCE', ['metric', 'value']);
  Object.entries(report.performance).forEach(([k, v]) => {
    if (k === 'trades' || typeof v === 'object') return;
    rows.push(['PERFORMANCE', k, v]);
  });
  rows.push([]);
  add('REJECTIONS', ['count', 'reason']);
  report.signalsAndRejections.topRejectionReasons.forEach(r => rows.push(['REJECTIONS', r.count, r.reason]));
  rows.push([]);
  add('ASSUMPTIONS', ['area', 'confidence', 'evidence', 'suggestion']);
  report.strategyAssumptionsAndImprovements.forEach(a => rows.push(['ASSUMPTIONS', a.area, a.confidence, a.evidence, a.suggestion]));
  return rows.map(r => r.map(c => {
    const s = c == null ? '' : String(c);
    return s.includes(',') || s.includes('"') ? `"${s.replace(/"/g, '""')}"` : s;
  }).join(',')).join('\n');
}

function downloadStrategyDiagnosticReport(format, opts) {
  if (typeof document === 'undefined') return null;
  const report = buildStrategyDiagnosticReport(opts);
  const stamp = new Date().toISOString().slice(0, 10);
  let blob, filename, mime;
  if (format === 'json') {
    blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
    filename = `fno-strategy-diagnostic-${stamp}.json`;
    mime = 'application/json';
  } else if (format === 'csv') {
    blob = new Blob([formatDiagnosticReportCsv(report)], { type: 'text/csv;charset=utf-8' });
    filename = `fno-strategy-diagnostic-${stamp}.csv`;
    mime = 'text/csv';
  } else {
    blob = new Blob([formatDiagnosticReportMarkdown(report)], { type: 'text/markdown;charset=utf-8' });
    filename = `fno-strategy-diagnostic-${stamp}.md`;
    mime = 'text/markdown';
  }
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  return report;
}

function renderStrategyDiagnosticPreview(opts) {
  if (typeof document === 'undefined') return null;
  const el = document.getElementById('strategyDiagnosticPreview');
  if (!el) return null;
  const report = buildStrategyDiagnosticReport(opts);
  const e = report.executiveSummary;
  const alert = e.overRestricted
    ? `<div style="padding:6px;margin-bottom:8px;background:#422006;border-radius:6px;color:#fde68a;font-size:11px">⚠️ ${escapeHtml(e.overRestricted.label)}: ${escapeHtml(e.overRestricted.detail)}</div>`
    : '';
  el.innerHTML = alert + `
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;font-size:11px;margin-bottom:8px">
      <div style="background:#020617;padding:6px;border-radius:6px;text-align:center"><div style="color:#94a3b8">Signals</div><div style="font-weight:800">${e.totalSignals}</div></div>
      <div style="background:#020617;padding:6px;border-radius:6px;text-align:center"><div style="color:#94a3b8">Opened</div><div style="font-weight:800;color:#4ade80">${e.tradesTaken}</div></div>
      <div style="background:#020617;padding:6px;border-radius:6px;text-align:center"><div style="color:#94a3b8">Blocked</div><div style="font-weight:800;color:#f87171">${e.setupsBlocked}</div></div>
      <div style="background:#020617;padding:6px;border-radius:6px;text-align:center"><div style="color:#94a3b8">Net P&L</div><div style="font-weight:800">${e.netPnl != null ? 'Rs' + e.netPnl : '—'}</div></div>
    </div>
    <div style="font-size:10px;color:#64748b">Win rate ${e.winRatePct != null ? e.winRatePct + '%' : 'n/a'} · PF ${e.profitFactor != null ? e.profitFactor : 'n/a'} · Eligibility ${e.eligibilityRatePct != null ? e.eligibilityRatePct + '%' : 'n/a'} · ${report.strategyAssumptionsAndImprovements.length} assumption review item(s)</div>
  `;
  return report;
}

function getStrategyReportUiOptions() {
  const periodEl = document.getElementById('strategyReportPeriod');
  const symEl = document.getElementById('sym');
  const compareA = document.getElementById('strategyReportCompareA');
  const compareB = document.getElementById('strategyReportCompareB');
  const period = periodEl ? periodEl.value : 'all';
  const sym = symEl ? String(symEl.value || 'NIFTY').trim().toUpperCase() : 'NIFTY';
  const now = Date.now();
  let startTs = null;
  const endTs = now;
  let periodLabel = 'All available history';
  if (period === 'today') {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    startTs = d.getTime();
    periodLabel = 'Today';
  } else if (period === '7d') {
    startTs = now - 7 * 86400000;
    periodLabel = 'Last 7 days';
  } else if (period === '21d') {
    startTs = now - 21 * 86400000;
    periodLabel = 'Last 21 days';
  }
  const opts = {
    symbol: sym,
    startTs,
    endTs,
    periodLabel,
    pluginVersion: typeof FNO_PLUGIN_VERSION !== 'undefined' ? FNO_PLUGIN_VERSION : null,
  };
  if (compareA && compareA.value.trim()) opts.compareVersionA = compareA.value.trim();
  if (compareB && compareB.value.trim()) opts.compareVersionB = compareB.value.trim();
  return opts;
}

function bindStrategyDiagnosticReportUi() {
  const periodEl = document.getElementById('strategyReportPeriod');
  const compareA = document.getElementById('strategyReportCompareA');
  const compareB = document.getElementById('strategyReportCompareB');
  const refresh = () => renderStrategyDiagnosticPreview(getStrategyReportUiOptions());
  if (periodEl) periodEl.addEventListener('change', refresh);
  if (compareA) compareA.addEventListener('change', refresh);
  if (compareB) compareB.addEventListener('change', refresh);
  const jsonBtn = document.getElementById('downloadStrategyReportJson');
  const mdBtn = document.getElementById('downloadStrategyReportMd');
  const csvBtn = document.getElementById('downloadStrategyReportCsv');
  if (jsonBtn) jsonBtn.addEventListener('click', () => downloadStrategyDiagnosticReport('json', getStrategyReportUiOptions()));
  if (mdBtn) mdBtn.addEventListener('click', () => downloadStrategyDiagnosticReport('md', getStrategyReportUiOptions()));
  if (csvBtn) csvBtn.addEventListener('click', () => downloadStrategyDiagnosticReport('csv', getStrategyReportUiOptions()));
  refresh();
}

if (typeof window !== 'undefined') {
  window.getStrategyReportUiOptions = getStrategyReportUiOptions;
  window.buildStrategyDiagnosticReport = buildStrategyDiagnosticReport;
  window.downloadStrategyDiagnosticReport = downloadStrategyDiagnosticReport;
  window.renderStrategyDiagnosticPreview = renderStrategyDiagnosticPreview;
  window.compareStrategyVersions = compareStrategyVersions;
  window.formatDiagnosticReportMarkdown = formatDiagnosticReportMarkdown;
  window.formatDiagnosticReportCsv = formatDiagnosticReportCsv;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindStrategyDiagnosticReportUi);
  } else {
    bindStrategyDiagnosticReportUi();
  }
}
