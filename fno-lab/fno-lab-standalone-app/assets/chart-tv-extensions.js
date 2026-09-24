/**
 * Chart extensions: user drawings, layout presets, price alerts.
 * Loaded after chart-indicator-engine.js, before fno-lab-core.js.
 */
(function (global) {
  'use strict';

  const DRAWINGS_KEY = 'fno_chart_drawings_v1';
  const PRESETS_KEY = 'fno_chart_layout_presets_v1';
  const ALERTS_KEY = 'fno_chart_price_alerts_v1';
  const VIEW_KEY = 'fno_chart_view_v1';

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
      }
    });
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

  function addPriceAlert(symbol, price, direction, message) {
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
      a.triggeredAt = Date.now();
      a.enabled = false;
      fired.push(a);
    });
    if (fired.length) savePriceAlerts(list);
    return fired;
  }

  function notifyPriceAlert(alert) {
    const title = 'Chart price alert';
    const body = alert.message || `${alert.symbol} ${alert.direction} ${alert.price}`;
    try {
      if (global.Notification && Notification.permission === 'granted') {
        new Notification(title, { body });
      }
    } catch (e) { /* ignore */ }
    if (typeof global.fnoChartShowToast === 'function') global.fnoChartShowToast(body);
    else if (typeof global.console !== 'undefined') console.warn('[chart alert]', body);
  }

  global.FNO_CHART_EXTENSIONS = {
    DRAWINGS_KEY,
    PRESETS_KEY,
    ALERTS_KEY,
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
  };
})(typeof window !== 'undefined' ? window : global);
