/**
 * Chart extensions: user drawings, layout presets, price alerts.
 * Loaded after chart-indicator-engine.js, before fno-lab-core.js.
 */
(function (global) {
  'use strict';

  const DRAWINGS_KEY = 'fno_chart_drawings_v1';
  const PRESETS_KEY = 'fno_chart_layout_presets_v1';
  const ALERTS_KEY = 'fno_chart_price_alerts_v1';
  const IND_ALERTS_KEY = 'fno_chart_indicator_alerts_v1';
  const WATCHLIST_KEY = 'fno_chart_watchlist_v1';
  const VIEW_KEY = 'fno_chart_view_v1';
  const ALLOWED_WATCHLIST_SYMBOLS = ['NIFTY', 'BANKNIFTY', 'FINNIFTY'];

  /** Previous sampled values for indicator alert crossing (per alert id). */
  const alertPrevSamples = {};

  function storageGet(key) {
    try {
      return global.localStorage && global.localStorage.getItem(key);
    } catch (e) { return null; }
  }
  function storageSet(key, val) {
    try {
      if (global.localStorage) global.localStorage.setItem(key, val);
    } catch (e) { /* quota */ }
  }

  function loadAllDrawings() {
    try {
      const raw = storageGet(DRAWINGS_KEY);
      if (!raw) return {};
      const o = JSON.parse(raw);
      return o && typeof o === 'object' ? o : {};
    } catch (e) { return {}; }
  }

  function saveAllDrawings(map) {
    storageSet(DRAWINGS_KEY, JSON.stringify(map || {}));
  }

  function loadDrawingsForSymbol(symbol) {
    const sym = String(symbol || 'DEFAULT').toUpperCase();
    const all = loadAllDrawings();
    return Array.isArray(all[sym]) ? all[sym] : [];
  }

  function saveDrawingsForSymbol(symbol, list) {
    const sym = String(symbol || 'DEFAULT').toUpperCase();
    const all = loadAllDrawings();
    all[sym] = list || [];
    saveAllDrawings(all);
  }

  function addDrawing(symbol, drawing) {
    const list = loadDrawingsForSymbol(symbol);
    const entry = Object.assign({ id: 'd_' + Date.now().toString(36) }, drawing);
    list.push(entry);
    saveDrawingsForSymbol(symbol, list);
    return entry;
  }

  function clearDrawings(symbol) {
    saveDrawingsForSymbol(symbol, []);
  }

  function removeDrawing(symbol, id) {
    saveDrawingsForSymbol(symbol, loadDrawingsForSymbol(symbol).filter(d => d.id !== id));
  }

  function barIndexForTs(visible, ts) {
    if (!visible.length || typeof ts !== 'number') return -1;
    let best = -1;
    let bestDiff = Infinity;
    visible.forEach((cd, i) => {
      const d = Math.abs(cd.t - ts);
      if (d < bestDiff) { bestDiff = d; best = i; }
    });
    return best;
  }

  function renderDrawings(ctx, geom) {
    const {
      drawings, visible, padL, padT, plotW, plotH, slot, yFor,
    } = geom;
    if (!ctx || !drawings || !drawings.length) return;
    drawings.forEach((d) => {
      ctx.lineWidth = d.lineWidth || 1.5;
      ctx.strokeStyle = d.color || '#eab308';
      if (d.type === 'hline' && Number.isFinite(d.price)) {
        const y = yFor(d.price);
        if (y < padT || y > padT + plotH) return;
        ctx.beginPath();
        ctx.moveTo(padL, y);
        ctx.lineTo(padL + plotW, y);
        ctx.stroke();
        if (d.label) {
          ctx.fillStyle = d.color || '#eab308';
          ctx.font = '10px sans-serif';
          ctx.fillText(String(d.label), padL + 4, y - 3);
        }
      } else if (d.type === 'trend' && Number.isFinite(d.p1) && Number.isFinite(d.p2)) {
        const i1 = barIndexForTs(visible, d.t1);
        const i2 = barIndexForTs(visible, d.t2);
        if (i1 < 0 || i2 < 0) return;
        const x1 = padL + slot * i1 + slot / 2;
        const x2 = padL + slot * i2 + slot / 2;
        const y1 = yFor(d.p1);
        const y2 = yFor(d.p2);
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
      } else if (d.type === 'vline' && typeof d.t === 'number') {
        const i = barIndexForTs(visible, d.t);
        if (i < 0) return;
        const x = padL + slot * i + slot / 2;
        ctx.beginPath();
        ctx.moveTo(x, padT);
        ctx.lineTo(x, padT + plotH);
        ctx.stroke();
      }
    });
  }

  function loadWatchlist() {
    try {
      const raw = storageGet(WATCHLIST_KEY);
      if (!raw) return ALLOWED_WATCHLIST_SYMBOLS.slice();
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return ALLOWED_WATCHLIST_SYMBOLS.slice();
      const clean = parsed.map(s => String(s).toUpperCase()).filter(s => ALLOWED_WATCHLIST_SYMBOLS.includes(s));
      return clean.length ? clean : ALLOWED_WATCHLIST_SYMBOLS.slice();
    } catch (e) {
      return ALLOWED_WATCHLIST_SYMBOLS.slice();
    }
  }

  function saveWatchlist(symbols) {
    const clean = (symbols || []).map(s => String(s).toUpperCase()).filter(s => ALLOWED_WATCHLIST_SYMBOLS.includes(s));
    storageSet(WATCHLIST_KEY, JSON.stringify(clean.length ? clean : ALLOWED_WATCHLIST_SYMBOLS));
    return clean.length ? clean : ALLOWED_WATCHLIST_SYMBOLS.slice();
  }

  function toggleWatchlistSymbol(symbol) {
    const sym = String(symbol || '').toUpperCase();
    if (!ALLOWED_WATCHLIST_SYMBOLS.includes(sym)) return { ok: false, error: 'Symbol not supported' };
    let list = loadWatchlist();
    if (list.includes(sym)) {
      if (list.length <= 1) return { ok: false, error: 'Watchlist must keep at least one symbol' };
      list = list.filter(s => s !== sym);
    } else {
      list = list.concat([sym]);
    }
    saveWatchlist(list);
    return { ok: true, list };
  }

  function loadLayoutPresets() {
    try {
      const raw = storageGet(PRESETS_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) { return []; }
  }

  function saveLayoutPresets(list) {
    storageSet(PRESETS_KEY, JSON.stringify(list || []));
  }

  function captureLayoutPreset(name) {
    if (!name || !String(name).trim()) return { ok: false, error: 'Preset name required' };
    if (typeof FNO_CHART_INDICATORS === 'undefined') return { ok: false, error: 'Indicators not loaded' };
    const viewRaw = storageGet(VIEW_KEY);
    let view = null;
    try { view = viewRaw ? JSON.parse(viewRaw) : null; } catch (e) { view = null; }
    const preset = {
      id: 'p_' + Date.now().toString(36),
      name: String(name).trim().slice(0, 60),
      savedAt: Date.now(),
      view,
      instances: FNO_CHART_INDICATORS.loadInstances(),
    };
    const list = loadLayoutPresets().filter(p => p.name !== preset.name);
    list.push(preset);
    saveLayoutPresets(list);
    return { ok: true, preset };
  }

  function applyLayoutPreset(presetId) {
    const preset = loadLayoutPresets().find(p => p.id === presetId);
    if (!preset) return { ok: false, error: 'Preset not found' };
    if (preset.view) storageSet(VIEW_KEY, JSON.stringify(preset.view));
    if (preset.instances && typeof FNO_CHART_INDICATORS !== 'undefined') {
      FNO_CHART_INDICATORS.saveInstances(preset.instances);
    }
    return { ok: true, preset };
  }

  function deleteLayoutPreset(presetId) {
    saveLayoutPresets(loadLayoutPresets().filter(p => p.id !== presetId));
  }

  function loadPriceAlerts() {
    try {
      const raw = storageGet(ALERTS_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) { return []; }
  }

  function savePriceAlerts(list) {
    storageSet(ALERTS_KEY, JSON.stringify(list || []));
  }

  function addPriceAlert(symbol, price, direction, message, repeat) {
    if (!Number.isFinite(price)) return { ok: false, error: 'Invalid price' };
    const dir = direction === 'below' ? 'below' : 'above';
    const list = loadPriceAlerts();
    const entry = {
      id: 'a_' + Date.now().toString(36),
      symbol: String(symbol || 'ANY').toUpperCase(),
      price,
      direction: dir,
      message: String(message || `Price ${dir} ${price}`).slice(0, 200),
      enabled: true,
      triggeredAt: null,
      repeat: !!repeat,
    };
    list.push(entry);
    savePriceAlerts(list);
    return { ok: true, alert: entry };
  }

  function removePriceAlert(id) {
    savePriceAlerts(loadPriceAlerts().filter(a => a.id !== id));
  }

  /**
   * Call once per chart render with latest close for symbol.
   * Returns newly triggered alerts (also marks them triggered).
   */
  function evaluatePriceAlerts(symbol, closePrice, prevClose) {
    if (!Number.isFinite(closePrice)) return [];
    const sym = String(symbol || '').toUpperCase();
    const list = loadPriceAlerts();
    const fired = [];
    list.forEach((a) => {
      if (!a.enabled || a.triggeredAt) return;
      if (a.symbol !== 'ANY' && a.symbol !== sym) return;
      const crossedAbove = Number.isFinite(prevClose) && prevClose <= a.price && closePrice > a.price;
      const crossedBelow = Number.isFinite(prevClose) && prevClose >= a.price && closePrice < a.price;
      const hit = (a.direction === 'above' && crossedAbove) || (a.direction === 'below' && crossedBelow);
      if (!hit) return;
      markAlertTriggered(a, fired);
    });
    if (fired.length) savePriceAlerts(list);
    return fired;
  }

  function markAlertTriggered(alert, fired) {
    fired.push(alert);
    if (alert.repeat) return;
    alert.triggeredAt = Date.now();
    alert.enabled = false;
  }

  function rearmAlert(id) {
    const sid = String(id || '');
    if (sid.startsWith('ia_')) {
      const list = loadIndicatorAlerts();
      const row = list.find(a => a.id === sid);
      if (!row) return { ok: false, error: 'Alert not found' };
      row.triggeredAt = null;
      row.enabled = true;
      saveIndicatorAlerts(list);
      Object.keys(alertPrevSamples).filter(k => k === sid || k.indexOf(sid + '_') === 0).forEach(k => delete alertPrevSamples[k]);
      return { ok: true };
    }
    const list = loadPriceAlerts();
    const row = list.find(a => a.id === sid);
    if (!row) return { ok: false, error: 'Alert not found' };
    row.triggeredAt = null;
    row.enabled = true;
    savePriceAlerts(list);
    return { ok: true };
  }

  function notifyPriceAlert(alert) {
    notifyChartAlert(alert.message || `${alert.symbol} ${alert.direction} ${alert.price}`, 'Chart price alert');
  }

  function notifyChartAlert(body, title) {
    title = title || 'Chart alert';
    try {
      if (global.Notification && Notification.permission === 'granted') {
        new Notification(title, { body });
      }
    } catch (e) { /* ignore */ }
    if (typeof global.fnoChartShowToast === 'function') global.fnoChartShowToast(body);
    else if (typeof global.console !== 'undefined') console.warn('[chart alert]', body);
  }

  function loadIndicatorAlerts() {
    try {
      const raw = storageGet(IND_ALERTS_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) { return []; }
  }

  function saveIndicatorAlerts(list) {
    storageSet(IND_ALERTS_KEY, JSON.stringify(list || []));
  }

  function indicatorAlertLabel(a) {
    if (a.kind === 'close_cross_ema') return `Close ${a.direction} EMA`;
    if (a.kind === 'macd_zero') return `MACD cross zero ${a.direction}`;
    if (a.kind === 'macd_signal') return `MACD ${a.direction} signal`;
    if (a.kind === 'bb_upper') return 'Close cross upper BB';
    if (a.kind === 'bb_lower') return 'Close cross lower BB';
    return `RSI ${a.direction} ${a.level}`;
  }

  const INDICATOR_ALERT_KINDS = ['rsi', 'close_cross_ema', 'macd_zero', 'macd_signal', 'bb_upper', 'bb_lower'];

  function addIndicatorAlert(opts) {
    const o = opts || {};
    let kind = String(o.kind || 'rsi');
    if (INDICATOR_ALERT_KINDS.indexOf(kind) < 0) kind = 'rsi';
    const level = Number(o.level);
    if (kind === 'rsi' && !Number.isFinite(level)) return { ok: false, error: 'RSI level required' };
    const direction = o.direction === 'below' ? 'below' : 'above';
    const defaultMsg = indicatorAlertLabel({ kind, direction, level });
    const entry = {
      id: 'ia_' + Date.now().toString(36),
      kind,
      symbol: String(o.symbol || 'ANY').toUpperCase(),
      level: kind === 'rsi' ? level : null,
      direction,
      message: String(o.message || defaultMsg).slice(0, 200),
      enabled: true,
      triggeredAt: null,
      repeat: !!o.repeat,
    };
    const list = loadIndicatorAlerts();
    list.push(entry);
    saveIndicatorAlerts(list);
    return { ok: true, alert: entry };
  }

  function removeIndicatorAlert(id) {
    saveIndicatorAlerts(loadIndicatorAlerts().filter(a => a.id !== id));
    delete alertPrevSamples[id];
  }

  function seriesValueAtBar(bundle, typeId, lineId, barIndex) {
    if (!bundle || barIndex == null || barIndex < 0) return NaN;
    const panels = bundle.panels || [];
    for (let i = 0; i < panels.length; i++) {
      const p = panels[i];
      if (p.typeId !== typeId || !p.panel || !p.panel.lines) continue;
      const line = p.panel.lines.find(l => l.id === lineId) || p.panel.lines[0];
      if (line && line.values && Number.isFinite(line.values[barIndex])) return line.values[barIndex];
    }
    const overlays = bundle.overlays || [];
    for (let j = 0; j < overlays.length; j++) {
      const ov = overlays[j];
      if (ov.typeId !== typeId || !ov.lines) continue;
      const line = ov.lines.find(l => l.id === lineId) || ov.lines[0];
      if (line && line.values && Number.isFinite(line.values[barIndex])) return line.values[barIndex];
    }
    return NaN;
  }

  function lineCross(prevA, prevB, curA, curB, direction) {
    if (!Number.isFinite(prevA) || !Number.isFinite(prevB) || !Number.isFinite(curA) || !Number.isFinite(curB)) return false;
    if (direction === 'above') return prevA <= prevB && curA > curB;
    return prevA >= prevB && curA < curB;
  }

  function evaluateIndicatorAlerts(symbol, bundle, closePrice, prevClose, lastBarIdx, prevBarIdx) {
    const sym = String(symbol || '').toUpperCase();
    const list = loadIndicatorAlerts();
    const fired = [];
    list.forEach((a) => {
      if (!a.enabled || a.triggeredAt) return;
      if (a.symbol !== 'ANY' && a.symbol !== sym) return;
      let hit = false;
      if (a.kind === 'rsi') {
        const cur = seriesValueAtBar(bundle, 'rsi', 'rsi', lastBarIdx);
        const prev = Number.isFinite(alertPrevSamples[a.id]) ? alertPrevSamples[a.id] : NaN;
        if (Number.isFinite(cur)) alertPrevSamples[a.id] = cur;
        if (!Number.isFinite(cur) || !Number.isFinite(prev)) return;
        const crossedAbove = prev <= a.level && cur > a.level;
        const crossedBelow = prev >= a.level && cur < a.level;
        hit = (a.direction === 'above' && crossedAbove) || (a.direction === 'below' && crossedBelow);
      } else if (a.kind === 'close_cross_ema') {
        if (!Number.isFinite(closePrice) || !Number.isFinite(prevClose) || prevBarIdx == null) return;
        const emaNow = seriesValueAtBar(bundle, 'ema', 'main', lastBarIdx);
        const emaPrev = seriesValueAtBar(bundle, 'ema', 'main', prevBarIdx);
        if (!Number.isFinite(emaNow) || !Number.isFinite(emaPrev)) return;
        hit = lineCross(prevClose, emaPrev, closePrice, emaNow, a.direction);
      } else if (a.kind === 'macd_zero') {
        const cur = seriesValueAtBar(bundle, 'macd', 'macd', lastBarIdx);
        const prev = Number.isFinite(alertPrevSamples[a.id]) ? alertPrevSamples[a.id] : NaN;
        if (Number.isFinite(cur)) alertPrevSamples[a.id] = cur;
        if (!Number.isFinite(cur) || !Number.isFinite(prev)) return;
        hit = lineCross(prev, 0, cur, 0, a.direction);
      } else if (a.kind === 'macd_signal') {
        const curM = seriesValueAtBar(bundle, 'macd', 'macd', lastBarIdx);
        const curS = seriesValueAtBar(bundle, 'macd', 'signal', lastBarIdx);
        const prevM = seriesValueAtBar(bundle, 'macd', 'macd', prevBarIdx);
        const prevS = seriesValueAtBar(bundle, 'macd', 'signal', prevBarIdx);
        if (prevBarIdx == null) return;
        hit = lineCross(prevM, prevS, curM, curS, a.direction);
      } else if (a.kind === 'bb_upper') {
        if (!Number.isFinite(closePrice) || !Number.isFinite(prevClose) || prevBarIdx == null) return;
        const bandNow = seriesValueAtBar(bundle, 'bollinger', 'upper', lastBarIdx);
        const bandPrev = seriesValueAtBar(bundle, 'bollinger', 'upper', prevBarIdx);
        if (!Number.isFinite(bandNow) || !Number.isFinite(bandPrev)) return;
        hit = lineCross(prevClose, bandPrev, closePrice, bandNow, 'above');
      } else if (a.kind === 'bb_lower') {
        if (!Number.isFinite(closePrice) || !Number.isFinite(prevClose) || prevBarIdx == null) return;
        const bandNow = seriesValueAtBar(bundle, 'bollinger', 'lower', lastBarIdx);
        const bandPrev = seriesValueAtBar(bundle, 'bollinger', 'lower', prevBarIdx);
        if (!Number.isFinite(bandNow) || !Number.isFinite(bandPrev)) return;
        hit = lineCross(prevClose, bandPrev, closePrice, bandNow, 'below');
      } else return;
      if (!hit) return;
      markAlertTriggered(a, fired);
    });
    if (fired.length) saveIndicatorAlerts(list);
    return fired;
  }

  function listAllAlerts() {
    return {
      price: loadPriceAlerts(),
      indicator: loadIndicatorAlerts(),
    };
  }

  function removeAlertById(id) {
    if (String(id).startsWith('ia_')) removeIndicatorAlert(id);
    else removePriceAlert(id);
  }

  function formatAlertsListHtml(activeSymbol) {
    const all = listAllAlerts();
    const sym = String(activeSymbol || '').toUpperCase();
    const rows = [];
    const btn = (id, label, cls) => `<button type="button" class="btn ${cls}" data-id="${id}" style="padding:1px 6px;font-size:9px">${label}</button>`;
    all.price.forEach((a) => {
      if (a.symbol !== 'ANY' && a.symbol !== sym) return;
      const st = a.triggeredAt ? 'triggered' : (a.enabled ? 'armed' : 'off');
      const rep = a.repeat ? ' · repeat' : '';
      const extra = a.triggeredAt ? btn(a.id, 'Re-arm', 'chart-alert-rearm') : '';
      rows.push(`<div style="display:flex;gap:6px;align-items:center;margin-bottom:3px"><span style="flex:1">💰 ${a.symbol} ${a.direction} ${a.price}${rep} <span style="color:#64748b">(${st})</span></span>${extra}${btn(a.id, '✕', 'chart-alert-del')}</div>`);
    });
    all.indicator.forEach((a) => {
      if (a.symbol !== 'ANY' && a.symbol !== sym) return;
      const st = a.triggeredAt ? 'triggered' : (a.enabled ? 'armed' : 'off');
      const label = indicatorAlertLabel(a);
      const rep = a.repeat ? ' · repeat' : '';
      const extra = a.triggeredAt ? btn(a.id, 'Re-arm', 'chart-alert-rearm') : '';
      rows.push(`<div style="display:flex;gap:6px;align-items:center;margin-bottom:3px"><span style="flex:1">📊 ${a.symbol} ${label}${rep} <span style="color:#64748b">(${st})</span></span>${extra}${btn(a.id, '✕', 'chart-alert-del')}</div>`);
    });
    return rows.length ? rows.join('') : '<span style="color:#64748b">No active alerts for this symbol.</span>';
  }

  global.FNO_CHART_EXTENSIONS = {
    DRAWINGS_KEY,
    PRESETS_KEY,
    ALERTS_KEY,
    IND_ALERTS_KEY,
    WATCHLIST_KEY,
    ALLOWED_WATCHLIST_SYMBOLS,
    loadDrawingsForSymbol,
    saveDrawingsForSymbol,
    addDrawing,
    clearDrawings,
    removeDrawing,
    renderDrawings,
    loadLayoutPresets,
    saveLayoutPresets,
    captureLayoutPreset,
    applyLayoutPreset,
    deleteLayoutPreset,
    loadPriceAlerts,
    savePriceAlerts,
    addPriceAlert,
    removePriceAlert,
    evaluatePriceAlerts,
    notifyPriceAlert,
    notifyChartAlert,
    loadWatchlist,
    saveWatchlist,
    toggleWatchlistSymbol,
    loadIndicatorAlerts,
    saveIndicatorAlerts,
    addIndicatorAlert,
    removeIndicatorAlert,
    evaluateIndicatorAlerts,
    seriesValueAtBar,
    listAllAlerts,
    removeAlertById,
    formatAlertsListHtml,
    rearmAlert,
    markAlertTriggered,
    indicatorAlertLabel,
    INDICATOR_ALERT_KINDS,
  };
})(typeof window !== 'undefined' ? window : global);
