/**
 * First Momentum Scalper (Mode 8) — micro-momentum options scalping.
 * Tier A safety → market-state filter → Tier B confluence (not all 193 factors) →
 * acceleration entry → premium +Rs targets with momentum-aware exits.
 */
const FNO_FMS_LOG_KEY = 'fno_first_momentum_log_v1';
const FNO_FMS_LOG_MAX = 2000;

const FNO_FMS_MARKET_STATE = {
  STRONG_BULL: 'strong_bullish_momentum',
  STRONG_BEAR: 'strong_bearish_momentum',
  SLOW_BULL: 'slow_bullish',
  SLOW_BEAR: 'slow_bearish',
  RANGE: 'range_choppy',
  NO_TRADE: 'extremely_low_vol_no_trade',
};

function fmsEscapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function isFirstMomentumScalperModeActive() {
  if (typeof getActiveTradingModeProfile !== 'function') return false;
  const m = getActiveTradingModeProfile();
  return !!(m && (m.firstMomentumScalper || m.id === 'first_momentum_scalper'));
}

function getFirstMomentumScalperConfig() {
  const mode = typeof getActiveTradingModeProfile === 'function' ? getActiveTradingModeProfile() : {};
  const s = typeof fnoSettings !== 'undefined' ? fnoSettings.get() : {};
  return {
    minTierBScore: mode.minTierBScore != null ? mode.minTierBScore : (s.fmsMinTierBScore != null ? s.fmsMinTierBScore : 6),
    strongTierBScore: mode.strongTierBScore != null ? mode.strongTierBScore : (s.fmsStrongTierBScore != null ? s.fmsStrongTierBScore : 8),
    initialTargetPoints: mode.initialTargetPoints != null ? mode.initialTargetPoints : (s.fmsInitialTargetPoints != null ? s.fmsInitialTargetPoints : 10),
    extendedTargetPoints: mode.extendedTargetPoints != null ? mode.extendedTargetPoints : (s.fmsExtendedTargetPoints != null ? s.fmsExtendedTargetPoints : 15),
    momentumHalfLifeMinutes: mode.momentumHalfLifeMinutes != null ? mode.momentumHalfLifeMinutes : (s.fmsMomentumHalfLifeMinutes != null ? s.fmsMomentumHalfLifeMinutes : 3),
    maxHoldingMinutes: s.fmsMaxHoldingMinutes != null ? s.fmsMaxHoldingMinutes : 8,
    thesisMinutes: s.fmsThesisMinutes != null ? s.fmsThesisMinutes : 2,
    minPremiumPctForTarget: s.fmsMinPremiumPctForTarget != null ? s.fmsMinPremiumPctForTarget : 6,
    microRangeWindow: s.fmsMicroRangeWindow != null ? s.fmsMicroRangeWindow : 12,
    stopPremiumPoints: s.fmsStopPremiumPoints != null ? s.fmsStopPremiumPoints : 12,
  };
}

function classifyFirstMomentumMarketState(ctx, breakout) {
  const vix = ctx && typeof ctx.vix === 'number' ? ctx.vix : null;
  const candles = ctx && ctx.candles;
  if (vix != null && vix < 10) return { state: FNO_FMS_MARKET_STATE.NO_TRADE, tradeable: false, reason: `VIX ${vix.toFixed(1)} — extremely low vol, wait for imbalance` };
  const cond = breakout && breakout.condition ? breakout.condition : 'insufficient_data';
  if (cond === 'insufficient_data') return { state: FNO_FMS_MARKET_STATE.RANGE, tradeable: true, reason: 'Building micro-range — need more candles' };
  if (cond === 'choppy') return { state: FNO_FMS_MARKET_STATE.RANGE, tradeable: false, reason: 'Choppy microstructure — no continuous scalping' };
  if (cond === 'range_bound') return { state: FNO_FMS_MARKET_STATE.RANGE, tradeable: true, reason: 'Range-bound — wait for micro breakout only' };
  if (cond === 'breakout_up' || cond === 'reversal_up') {
    const strong = vix != null && vix >= 14;
    return { state: strong ? FNO_FMS_MARKET_STATE.STRONG_BULL : FNO_FMS_MARKET_STATE.SLOW_BULL, tradeable: true, reason: breakout.reason };
  }
  if (cond === 'breakdown' || cond === 'reversal_down') {
    const strong = vix != null && vix >= 14;
    return { state: strong ? FNO_FMS_MARKET_STATE.STRONG_BEAR : FNO_FMS_MARKET_STATE.SLOW_BEAR, tradeable: true, reason: breakout.reason };
  }
  if (cond === 'false_breakout_up' || cond === 'false_breakdown') {
    return { state: FNO_FMS_MARKET_STATE.RANGE, tradeable: false, reason: breakout.reason };
  }
  return { state: FNO_FMS_MARKET_STATE.RANGE, tradeable: true, reason: 'Neutral micro state' };
}

function computeFirstMomentumTierA(ctx, brain, optionType) {
  const blockers = [];
  if (brain && brain.criticalFails && brain.criticalFails.length) {
    blockers.push(`Critical: ${brain.criticalFails.map(f => f.factor).join(', ')}`);
  }
  const timeCheck = typeof checkSufficientTimeRemaining === 'function'
    ? checkSufficientTimeRemaining(ctx || {}, 'scalping') : { sufficient: true };
  if (!timeCheck.sufficient) blockers.push(timeCheck.reason || 'Insufficient time before square-off');
  const leg = optionType === 'PE'
    ? (ctx && ctx.ocRow && ctx.ocRow.PE)
    : (ctx && ctx.ocRow && ctx.ocRow.CE);
  if (!leg || typeof leg.lastPrice !== 'number' || leg.lastPrice <= 0) {
    blockers.push('Option premium unavailable');
  } else if (typeof checkSpreadLevel === 'function') {
    const sp = checkSpreadLevel(leg);
    const maxPct = typeof getModeSpreadHardBlockPct === 'function' ? getModeSpreadHardBlockPct() : 14;
    if (sp.spreadPct != null && sp.spreadPct > maxPct) blockers.push(`Spread ${sp.spreadPct.toFixed(1)}% > ${maxPct}%`);
  }
  return { pass: blockers.length === 0, blockers };
}

function spotMomentumPoints(ctx, direction) {
  const candles = ctx && ctx.candles;
  if (!candles || candles.length < 6) return 0;
  const closes = candles.map(c => c.c);
  const n = closes.length;
  const move = closes[n - 1] - closes[n - 6];
  if (direction === 'bullish' && move > 0) return move >= 12 ? 2 : 1;
  if (direction === 'bearish' && move < 0) return move <= -12 ? 2 : 1;
  return 0;
}

function computeFirstMomentumTierB(ctx, brain, direction, breakout) {
  let score = 0;
  const parts = [];
  const cond = breakout && breakout.condition;
  if (direction === 'bullish' && (cond === 'breakout_up' || cond === 'reversal_up')) { score += 2; parts.push('micro breakout up +2'); }
  if (direction === 'bearish' && (cond === 'breakdown' || cond === 'reversal_down')) { score += 2; parts.push('micro breakdown +2'); }
  const mom = spotMomentumPoints(ctx, direction);
  if (mom) { score += mom; parts.push(`spot impulse +${mom}`); }
  if (ctx && Number.isFinite(ctx.spot) && Number.isFinite(ctx.vwap)) {
    if (direction === 'bullish' && ctx.spot > ctx.vwap) { score += 1; parts.push('above close-avg +1'); }
    if (direction === 'bearish' && ctx.spot < ctx.vwap) { score += 1; parts.push('below close-avg +1'); }
  }
  if (ctx && Number.isFinite(ctx.futuresPrice) && Number.isFinite(ctx.spot)) {
    const futPrem = ctx.futuresPrice - ctx.spot;
    if (direction === 'bullish' && futPrem >= 0) { score += 1; parts.push('futures not discounting +1'); }
    if (direction === 'bearish' && futPrem <= 0) { score += 1; parts.push('futures not premium +1'); }
  }
  const ds = brain && typeof brain.directionalScoreAvailableOnly === 'number'
    ? brain.directionalScoreAvailableOnly
    : (brain && typeof brain.directionalScore === 'number' ? brain.directionalScore : 0);
  const th = typeof getEffectiveDecisionThresholds === 'function' ? getEffectiveDecisionThresholds() : { buyThreshold: 5, sellThreshold: -9 };
  if (direction === 'bullish' && ds >= th.buyThreshold - 1) { score += 2; parts.push('brain directional aligned +2'); }
  else if (direction === 'bullish' && ds >= th.buyThreshold - 3) { score += 1; parts.push('brain directional developing +1'); }
  if (direction === 'bearish' && ds <= th.sellThreshold + 1) { score += 2; parts.push('brain directional aligned +2'); }
  else if (direction === 'bearish' && ds <= th.sellThreshold + 3) { score += 1; parts.push('brain directional developing +1'); }
  const li = ctx && ctx.liquidityInputs;
  if (li && li.breakoutCondition) {
    const bc = li.breakoutCondition.condition || li.breakoutCondition;
    if (direction === 'bullish' && bc === 'breakout_up') { score += 1; parts.push('liquidity breakout +1'); }
    if (direction === 'bearish' && bc === 'breakdown') { score += 1; parts.push('liquidity breakdown +1'); }
  }
  if (typeof isMicrostructureDaemonOnline === 'function' && isMicrostructureDaemonOnline(ctx)) {
    score += 1; parts.push('microstructure live +1');
  }
  return { score, parts, maxConceptual: 12 };
}

function inferFirstMomentumDirection(ctx, brain, breakout) {
  const cond = breakout && breakout.condition;
  if (cond === 'breakout_up' || cond === 'reversal_up') return 'bullish';
  if (cond === 'breakdown' || cond === 'reversal_down') return 'bearish';
  if (brain && brain.decision === 'BUY_READY') return 'bullish';
  if (brain && brain.decision === 'SELL_READY') return 'bearish';
  const ds = brain && typeof brain.directionalScoreAvailableOnly === 'number' ? brain.directionalScoreAvailableOnly : 0;
  if (ds > 2) return 'bullish';
  if (ds < -2) return 'bearish';
  return null;
}

function premiumTargetRs(optPrice, cfg, extended) {
  const pts = extended ? cfg.extendedTargetPoints : cfg.initialTargetPoints;
  const pctTarget = optPrice * (cfg.minPremiumPctForTarget / 100);
  const rs = Math.max(pts, pctTarget);
  return +rs.toFixed(2);
}

function computeFirstMomentumScalper(ctx, brain, opts) {
  opts = opts || {};
  const cfg = getFirstMomentumScalperConfig();
  const active = isFirstMomentumScalperModeActive();
  const ts = Date.now();
  if (!active) {
    return { active: false, ts, entryAllowed: false, engineStatus: 'MODE_OFF' };
  }
  const window = cfg.microRangeWindow;
  const breakout = typeof computeBreakoutReversalCondition === 'function'
    ? computeBreakoutReversalCondition(ctx && ctx.candles, window)
    : { condition: 'insufficient_data' };
  const market = classifyFirstMomentumMarketState(ctx, breakout);
  const direction = inferFirstMomentumDirection(ctx, brain, breakout);
  const optionType = direction === 'bearish' ? 'PE' : direction === 'bullish' ? 'CE' : null;
  const tierA = computeFirstMomentumTierA(ctx, brain, optionType || 'CE');
  const tierB = direction ? computeFirstMomentumTierB(ctx, brain, direction, breakout) : { score: 0, parts: [] };

  let entryAllowed = false;
  let blockReason = null;
  if (!market.tradeable && market.state !== FNO_FMS_MARKET_STATE.RANGE) {
    blockReason = market.reason;
  } else if (!tierA.pass) {
    blockReason = tierA.blockers[0];
  } else if (!direction) {
    blockReason = 'No micro-momentum direction yet';
  } else if (market.state === FNO_FMS_MARKET_STATE.RANGE && tierB.score < cfg.strongTierBScore) {
    blockReason = `Range/chop — need tier B ≥ ${cfg.strongTierBScore} (have ${tierB.score})`;
  } else if (tierB.score < cfg.minTierBScore) {
    blockReason = `Confluence ${tierB.score} < min ${cfg.minTierBScore}`;
  } else {
    entryAllowed = true;
  }

  const optPrice = ctx && ctx.optPrice;
  let targetRs = null;
  let stopRs = null;
  if (Number.isFinite(optPrice) && optPrice > 0) {
    targetRs = premiumTargetRs(optPrice, cfg, false);
    stopRs = Math.max(cfg.stopPremiumPoints, optPrice * 0.08);
  }

  const summary = entryAllowed
    ? `${direction === 'bullish' ? 'Long CE' : 'Long PE'} · tier B ${tierB.score} · target +Rs${targetRs || cfg.initialTargetPoints}`
    : (blockReason || 'Waiting');

  return {
    active: true,
    ts,
    marketState: market.state,
    marketReason: market.reason,
    tradeable: market.tradeable,
    direction,
    optionType,
    tierA,
    tierB,
    tierBScore: tierB.score,
    entryAllowed,
    blockReason,
    summary,
    premiumTargetRs: targetRs,
    premiumExtendedTargetRs: targetRs != null ? premiumTargetRs(optPrice, cfg, true) : null,
    premiumStopRs: stopRs,
    momentumHalfLifeMinutes: cfg.momentumHalfLifeMinutes,
    maxHoldingMinutes: cfg.maxHoldingMinutes,
    thesisMinutes: cfg.thesisMinutes,
    breakout,
    cfg,
  };
}

function applyFirstMomentumScalperInfluence(brain, fms) {
  if (!fms || !fms.active) return;
  brain.firstMomentumScalper = fms;
  if (!fms.entryAllowed) {
    if (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY') {
      brain.decision = 'WAIT';
      brain.reason = `First Momentum Scalper blocked: ${fms.blockReason}`;
    }
    return;
  }
  const want = fms.direction === 'bullish' ? 'BUY_READY' : 'SELL_READY';
  brain.decision = want;
  brain.reason = `First Momentum Scalper — ${fms.summary} (${fms.marketState})`;
  if (fms.tierBScore >= fms.cfg.strongTierBScore && brain.confidence === 'Low') {
    brain.confidence = 'Medium';
  }
}

function resolveFirstMomentumScalperBracket(optPrice, ctx, brain, tradingType, optionType) {
  if (!isFirstMomentumScalperModeActive() || tradingType !== 'scalping') return null;
  const fms = (brain && brain.firstMomentumScalper)
    ? brain.firstMomentumScalper
    : computeFirstMomentumScalper(ctx, brain, {});
  if (!fms.active || !fms.entryAllowed || !Number.isFinite(optPrice) || optPrice <= 0) return null;
  const cfg = fms.cfg || getFirstMomentumScalperConfig();
  const tgtRs = fms.premiumTargetRs != null ? fms.premiumTargetRs : premiumTargetRs(optPrice, cfg, false);
  const extRs = fms.premiumExtendedTargetRs != null ? fms.premiumExtendedTargetRs : premiumTargetRs(optPrice, cfg, true);
  const stopRs = fms.premiumStopRs != null ? fms.premiumStopRs : cfg.stopPremiumPoints;
  return {
    target: +(optPrice + extRs).toFixed(2),
    sl: +(Math.max(0.05, optPrice - stopRs)).toFixed(2),
    source: 'first_momentum_scalper',
    fms,
    initialTargetPrice: +(optPrice + tgtRs).toFixed(2),
    extendedTargetPrice: +(optPrice + extRs).toFixed(2),
    initialTargetRs: tgtRs,
    extendedTargetRs: extRs,
  };
}

function computeFirstMomentumPremiumProgress(open, livePrice) {
  if (!open || !Number.isFinite(open.entryPrice) || !Number.isFinite(livePrice)) return { pts: 0, pct: 0 };
  const pts = livePrice - open.entryPrice;
  const pct = open.entryPrice > 0 ? (pts / open.entryPrice) * 100 : 0;
  return { pts, pct };
}

function isFirstMomentumAccelerating(ctx, open, livePrice) {
  const prog = computeFirstMomentumPremiumProgress(open, livePrice);
  if (prog.pts >= 8) return true;
  const mfe = open.mfe != null ? open.mfe : open.entryPrice;
  if (livePrice >= mfe - 0.5 && prog.pts >= 5) return true;
  const dir = open.optionType === 'PE' ? 'bearish' : 'bullish';
  return spotMomentumPoints(ctx, dir) >= 2;
}

function evaluateFirstMomentumScalperExit(open, ctx, brain, livePrice) {
  if (!open || !open.firstMomentumScalperTrade) return null;
  const fms = open.firstMomentumSnapshot || (brain && brain.firstMomentumScalper) || {};
  const cfg = fms.cfg || getFirstMomentumScalperConfig();
  const holdMin = open.openedAt ? (Date.now() - open.openedAt) / 60000 : 0;
  const prog = computeFirstMomentumPremiumProgress(open, livePrice);
  const initRs = open.fmsInitialTargetRs != null ? open.fmsInitialTargetRs : cfg.initialTargetPoints;
  const extRs = open.fmsExtendedTargetRs != null ? open.fmsExtendedTargetRs : cfg.extendedTargetPoints;

  if (prog.pts >= extRs) {
    return { exit: true, reason: 'FMS_EXTENDED_TARGET', label: `+Rs${prog.pts.toFixed(1)} premium (≥ +${extRs})` };
  }
  if (prog.pts >= initRs) {
    if (!isFirstMomentumAccelerating(ctx, open, livePrice)) {
      return { exit: true, reason: 'FMS_INITIAL_TARGET', label: `+Rs${prog.pts.toFixed(1)} booked — momentum fading` };
    }
  }
  if (holdMin >= cfg.momentumHalfLifeMinutes && prog.pts < initRs * 0.35) {
    return { exit: true, reason: 'FMS_HALF_LIFE', label: 'Momentum half-life — thesis did not develop' };
  }
  if (holdMin >= cfg.thesisMinutes && prog.pts < 2) {
    return { exit: true, reason: 'FMS_TIME_STOP', label: 'Time stop — no impulse' };
  }
  if (holdMin >= cfg.maxHoldingMinutes) {
    return { exit: true, reason: 'FMS_MAX_HOLD', label: 'Max scalp holding time' };
  }
  const leg = open.optionType === 'PE' ? (ctx && ctx.ocRow && ctx.ocRow.PE) : (ctx && ctx.ocRow && ctx.ocRow.CE);
  if (leg && typeof checkSpreadLevel === 'function') {
    const sp = checkSpreadLevel(leg);
    const entrySpread = open.fmsEntrySpreadPct;
    if (entrySpread != null && sp.spreadPct != null && sp.spreadPct > entrySpread + 4) {
      return { exit: true, reason: 'FMS_SPREAD_WIDEN', label: 'Spread widened — exit' };
    }
  }
  if (brain && open.optionType === 'CE' && brain.decision === 'SELL_READY') {
    return { exit: true, reason: 'FMS_OPPOSITE', label: 'Opposite momentum signal' };
  }
  if (brain && open.optionType === 'PE' && brain.decision === 'BUY_READY') {
    return { exit: true, reason: 'FMS_OPPOSITE', label: 'Opposite momentum signal' };
  }
  if (open.fmsTriggerSpot != null && ctx && Number.isFinite(ctx.spot)) {
    if (open.optionType === 'CE' && ctx.spot < open.fmsTriggerSpot - 4) {
      return { exit: true, reason: 'FMS_SPOT_REVERSAL', label: 'Spot reversed through trigger' };
    }
    if (open.optionType === 'PE' && ctx.spot > open.fmsTriggerSpot + 4) {
      return { exit: true, reason: 'FMS_SPOT_REVERSAL', label: 'Spot reversed through trigger' };
    }
  }
  return null;
}

function logFirstMomentumObservation(fms, sym, brain) {
  if (!fms || !fms.active) return;
  try {
    const log = JSON.parse(localStorage.getItem(FNO_FMS_LOG_KEY) || '[]');
    log.push({
      ts: fms.ts, sym,
      entryAllowed: fms.entryAllowed,
      blockReason: fms.blockReason,
      marketState: fms.marketState,
      tierBScore: fms.tierBScore,
      direction: fms.direction,
      brainDecision: brain ? brain.decision : null,
      summary: fms.summary,
    });
    while (log.length > FNO_FMS_LOG_MAX) log.shift();
    localStorage.setItem(FNO_FMS_LOG_KEY, JSON.stringify(log));
  } catch (e) { /* quota */ }
}

function renderFirstMomentumScalperPanel(fms) {
  if (typeof document === 'undefined') return;
  const box = document.getElementById('firstMomentumScalperBox');
  if (!box) return;
  if (!fms || !fms.active) { box.style.display = 'none'; return; }
  box.style.display = 'block';
  const tp = typeof fnoThemePalette === 'function' ? fnoThemePalette() : { pass: '#4ade80', fail: '#f87171', muted: '#94a3b8', text: '#e2e8f0' };
  const statusColor = fms.entryAllowed ? tp.pass : tp.fail;
  const tierParts = (fms.tierB && fms.tierB.parts) ? fms.tierB.parts.join(' · ') : '—';
  box.innerHTML = `
    <div style="font-weight:700;color:#fde68a;margin-bottom:6px">⚡ Mode 8 — First Momentum Scalper</div>
    <div style="font-size:11px;line-height:1.45">
      <div>Market: <b>${fmsEscapeHtml(fms.marketState)}</b> — ${fmsEscapeHtml(fms.marketReason || '')}</div>
      <div>Tier B confluence: <b>${fms.tierBScore}</b> / min ${fms.cfg.minTierBScore} (strong ${fms.cfg.strongTierBScore})</div>
      <div style="color:${tp.muted};font-size:10px;margin:4px 0">${fmsEscapeHtml(tierParts)}</div>
      <div style="color:${statusColor};font-weight:600">${fms.entryAllowed ? 'ENTRY OK' : 'WAIT'} — ${fmsEscapeHtml(fms.summary)}</div>
      <div style="color:${tp.muted};font-size:10px;margin-top:4px">Targets: +Rs${fms.cfg.initialTargetPoints} (book if fade) → +Rs${fms.cfg.extendedTargetPoints} if accelerating · half-life ${fms.cfg.momentumHalfLifeMinutes}m · max hold ${fms.cfg.maxHoldingMinutes}m</div>
    </div>`;
}
