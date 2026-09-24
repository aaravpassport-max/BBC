/**
 * Pluggable chart indicator registry (TradingView-style).
 * Overlay indicators share the price scale; panel indicators render in separate canvases.
 * Loaded before fno-lab-core.js — no dependency on the chart renderer.
 */
(function (global) {
  'use strict';

  const STORAGE_KEY = 'fno_chart_indicators_v1';

  function emaSeries(closes, period) {
    const p = Math.max(1, period | 0);
    const k = 2 / (p + 1);
    const firstFinite = closes.find(Number.isFinite);
    let e = Number.isFinite(closes[0]) ? closes[0] : firstFinite;
    return closes.map((v, i) => {
      if (i === 0) return e;
      if (!Number.isFinite(v)) return Number.isFinite(e) ? e : NaN;
      e = Number.isFinite(e) ? (v * k + e * (1 - k)) : v;
      return e;
    });
  }

  function smaSeries(closes, period) {
    const p = Math.max(1, period | 0);
    return closes.map((_, i) => {
      if (i + 1 < p) return NaN;
      let sum = 0, n = 0;
      for (let j = i - p + 1; j <= i; j++) {
        if (Number.isFinite(closes[j])) { sum += closes[j]; n++; }
      }
      return n ? sum / n : NaN;
    });
  }

  function sessionVwapSeries(candles) {
    let cumVol = 0;
    let cumTpVol = 0;
    return candles.map((cd) => {
      const h = Number.isFinite(cd.h) ? cd.h : cd.c;
      const l = Number.isFinite(cd.l) ? cd.l : cd.c;
      const c = Number.isFinite(cd.c) ? cd.c : NaN;
      if (!Number.isFinite(c)) return NaN;
      const tp = (h + l + c) / 3;
      const vol = Number.isFinite(cd.v) && cd.v > 0 ? cd.v : 1;
      cumTpVol += tp * vol;
      cumVol += vol;
      return cumVol > 0 ? cumTpVol / cumVol : tp;
    });
  }

  function bollingerSeries(closes, period, mult) {
    const mid = smaSeries(closes, period);
    const upper = [], lower = [];
    const p = Math.max(1, period | 0);
    const m = mult > 0 ? mult : 2;
    closes.forEach((_, i) => {
      const mu = mid[i];
      if (!Number.isFinite(mu) || i + 1 < p) {
        upper.push(NaN); lower.push(NaN); return;
      }
      let sumSq = 0, n = 0;
      for (let j = i - p + 1; j <= i; j++) {
        if (Number.isFinite(closes[j])) { const d = closes[j] - mu; sumSq += d * d; n++; }
      }
      const std = n > 1 ? Math.sqrt(sumSq / n) : 0;
      upper.push(mu + m * std);
      lower.push(mu - m * std);
    });
    return { middle: mid, upper, lower };
  }

  function rsiSeries(closes, period) {
    const p = Math.max(2, period | 0);
    const out = closes.map(() => NaN);
    let avgGain = 0, avgLoss = 0;
    for (let i = 1; i < closes.length; i++) {
      const ch = closes[i] - closes[i - 1];
      const gain = ch > 0 ? ch : 0;
      const loss = ch < 0 ? -ch : 0;
      if (i <= p) {
        avgGain += gain; avgLoss += loss;
        if (i === p) {
          avgGain /= p; avgLoss /= p;
          out[i] = avgLoss === 0 ? 100 : 100 - (100 / (1 + avgGain / avgLoss));
        }
        continue;
      }
      avgGain = (avgGain * (p - 1) + gain) / p;
      avgLoss = (avgLoss * (p - 1) + loss) / p;
      out[i] = avgLoss === 0 ? 100 : 100 - (100 / (1 + avgGain / avgLoss));
    }
    return out;
  }

  function macdSeries(closes, fast, slow, signalPeriod) {
    const emaFast = emaSeries(closes, fast);
    const emaSlow = emaSeries(closes, slow);
    const macd = closes.map((_, i) => (Number.isFinite(emaFast[i]) && Number.isFinite(emaSlow[i])) ? emaFast[i] - emaSlow[i] : NaN);
    const signal = emaSeries(macd.map(v => Number.isFinite(v) ? v : 0), signalPeriod);
    const hist = macd.map((v, i) => (Number.isFinite(v) && Number.isFinite(signal[i])) ? v - signal[i] : NaN);
    return { macd, signal, hist };
  }

  function stochasticSeries(candles, kPeriod, dPeriod) {
    const k = Math.max(1, kPeriod | 0);
    const d = Math.max(1, dPeriod | 0);
    const kLine = candles.map((cd, i) => {
      if (i + 1 < k) return NaN;
      let hi = -Infinity, lo = Infinity;
      for (let j = i - k + 1; j <= i; j++) {
        const h = Number.isFinite(candles[j].h) ? candles[j].h : candles[j].c;
        const l = Number.isFinite(candles[j].l) ? candles[j].l : candles[j].c;
        if (Number.isFinite(h)) hi = Math.max(hi, h);
        if (Number.isFinite(l)) lo = Math.min(lo, l);
      }
      const c = Number.isFinite(cd.c) ? cd.c : NaN;
      if (!Number.isFinite(c) || hi === lo || !Number.isFinite(hi)) return NaN;
      return ((c - lo) / (hi - lo)) * 100;
    });
    const dLine = smaSeries(kLine, d);
    return { k: kLine, d: dLine };
  }

  const REGISTRY = {
    ema: {
      id: 'ema',
      name: 'EMA',
      type: 'overlay',
      defaultParams: { period: 21, color: '#facc15', lineWidth: 1.5, lineStyle: 'solid' },
      paramSchema: [{ key: 'period', label: 'Period', type: 'number', min: 2, max: 500 }],
      compute(candles, params) {
        const closes = candles.map(c => c.c);
        return { lines: [{ id: 'main', values: emaSeries(closes, params.period), color: params.color, lineWidth: params.lineWidth, lineStyle: params.lineStyle }] };
      },
    },
    sma: {
      id: 'sma',
      name: 'SMA',
      type: 'overlay',
      defaultParams: { period: 50, color: '#a78bfa', lineWidth: 1.5, lineStyle: 'solid' },
      paramSchema: [{ key: 'period', label: 'Period', type: 'number', min: 2, max: 500 }],
      compute(candles, params) {
        const closes = candles.map(c => c.c);
        return { lines: [{ id: 'main', values: smaSeries(closes, params.period), color: params.color, lineWidth: params.lineWidth, lineStyle: params.lineStyle }] };
      },
    },
    vwap: {
      id: 'vwap',
      name: 'VWAP',
      type: 'overlay',
      defaultParams: { color: '#38bdf8', lineWidth: 1.5, lineStyle: 'solid' },
      paramSchema: [],
      compute(candles, params) {
        return { lines: [{ id: 'main', values: sessionVwapSeries(candles), color: params.color, lineWidth: params.lineWidth, lineStyle: params.lineStyle }] };
      },
    },
    bollinger: {
      id: 'bollinger',
      name: 'Bollinger Bands',
      type: 'overlay',
      defaultParams: { period: 20, stdDev: 2, color: '#94a3b8', lineWidth: 1, lineStyle: 'dashed' },
      paramSchema: [
        { key: 'period', label: 'Period', type: 'number', min: 5, max: 200 },
        { key: 'stdDev', label: 'Std dev', type: 'number', min: 0.5, max: 4, step: 0.1 },
      ],
      compute(candles, params) {
        const closes = candles.map(c => c.c);
        const bb = bollingerSeries(closes, params.period, params.stdDev);
        const c = params.color;
        return {
          lines: [
            { id: 'upper', values: bb.upper, color: c, lineWidth: params.lineWidth, lineStyle: 'dashed' },
            { id: 'middle', values: bb.middle, color: c, lineWidth: params.lineWidth, lineStyle: 'solid' },
            { id: 'lower', values: bb.lower, color: c, lineWidth: params.lineWidth, lineStyle: 'dashed' },
          ],
        };
      },
    },
    rsi: {
      id: 'rsi',
      name: 'RSI',
      type: 'panel',
      defaultParams: { period: 14, color: '#f472b6', lineWidth: 1.5, panelHeight: 100 },
      paramSchema: [{ key: 'period', label: 'Period', type: 'number', min: 2, max: 100 }],
      compute(candles, params) {
        const closes = candles.map(c => c.c);
        return {
          panel: {
            id: 'rsi',
            title: `RSI (${params.period})`,
            min: 0, max: 100,
            lines: [{ id: 'rsi', values: rsiSeries(closes, params.period), color: params.color, lineWidth: params.lineWidth }],
            bands: [{ y: 70, color: '#475569' }, { y: 30, color: '#475569' }],
          },
        };
      },
    },
    macd: {
      id: 'macd',
      name: 'MACD',
      type: 'panel',
      defaultParams: { fast: 12, slow: 26, signal: 9, color: '#22d3ee', lineWidth: 1.5, panelHeight: 100 },
      paramSchema: [
        { key: 'fast', label: 'Fast', type: 'number', min: 2, max: 50 },
        { key: 'slow', label: 'Slow', type: 'number', min: 5, max: 100 },
        { key: 'signal', label: 'Signal', type: 'number', min: 2, max: 50 },
      ],
      compute(candles, params) {
        const closes = candles.map(c => c.c);
        const m = macdSeries(closes, params.fast, params.slow, params.signal);
        return {
          panel: {
            id: 'macd',
            title: 'MACD',
            autoScale: true,
            lines: [
              { id: 'macd', values: m.macd, color: params.color, lineWidth: params.lineWidth },
              { id: 'signal', values: m.signal, color: '#fde68a', lineWidth: 1 },
            ],
            histogram: { values: m.hist, upColor: '#4ade80', downColor: '#f87171' },
          },
        };
      },
    },
    stochastic: {
      id: 'stochastic',
      name: 'Stochastic',
      type: 'panel',
      defaultParams: { kPeriod: 14, dPeriod: 3, color: '#c084fc', lineWidth: 1.5, panelHeight: 100 },
      paramSchema: [
        { key: 'kPeriod', label: '%K period', type: 'number', min: 2, max: 50 },
        { key: 'dPeriod', label: '%D period', type: 'number', min: 1, max: 20 },
      ],
      compute(candles, params) {
        const st = stochasticSeries(candles, params.kPeriod, params.dPeriod);
        return {
          panel: {
            id: 'stoch',
            title: 'Stochastic',
            min: 0, max: 100,
            lines: [
              { id: 'k', values: st.k, color: params.color, lineWidth: params.lineWidth },
              { id: 'd', values: st.d, color: '#fde68a', lineWidth: 1 },
            ],
          },
        };
      },
    },
  };

  function defaultInstances() {
    return [
      { instanceId: 'ema21', typeId: 'ema', enabled: true, params: { period: 21, color: '#facc15', lineWidth: 1.5, lineStyle: 'solid' } },
      { instanceId: 'vwap1', typeId: 'vwap', enabled: true, params: { color: '#38bdf8', lineWidth: 1.5, lineStyle: 'solid' } },
    ];
  }

  function loadInstances() {
    try {
      const raw = global.localStorage && global.localStorage.getItem(STORAGE_KEY);
      if (!raw) return defaultInstances();
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) && parsed.length ? parsed : defaultInstances();
    } catch (e) {
      return defaultInstances();
    }
  }

  function saveInstances(instances) {
    try {
      if (global.localStorage) global.localStorage.setItem(STORAGE_KEY, JSON.stringify(instances));
    } catch (e) { /* quota / private mode */ }
  }

  function mergeParams(typeId, params) {
    const def = REGISTRY[typeId];
    if (!def) return params || {};
    return Object.assign({}, def.defaultParams, params || {});
  }

  function computeForCandles(candles, instances) {
    const overlays = [];
    const panels = [];
    if (!Array.isArray(candles) || !candles.length || !Array.isArray(instances)) {
      return { overlays, panels };
    }
    instances.forEach((inst) => {
      if (!inst || !inst.enabled) return;
      const def = REGISTRY[inst.typeId];
      if (!def) return;
      const params = mergeParams(inst.typeId, inst.params);
      const result = def.compute(candles, params);
      if (def.type === 'overlay' && result && result.lines) {
        overlays.push({ instanceId: inst.instanceId, typeId: inst.typeId, name: def.name, lines: result.lines });
      } else if (def.type === 'panel' && result && result.panel) {
        panels.push({ instanceId: inst.instanceId, typeId: inst.typeId, panel: result.panel });
      }
    });
    return { overlays, panels };
  }

  function listTypes() {
    return Object.keys(REGISTRY).map((id) => {
      const r = REGISTRY[id];
      return { id, name: r.name, type: r.type, defaultParams: r.defaultParams, paramSchema: r.paramSchema || [] };
    });
  }

  function addInstance(instances, typeId) {
    const def = REGISTRY[typeId];
    if (!def) return instances;
    const instanceId = `${typeId}_${Date.now().toString(36)}`;
    return instances.concat([{ instanceId, typeId, enabled: true, params: Object.assign({}, def.defaultParams) }]);
  }

  function removeInstance(instances, instanceId) {
    return instances.filter(i => i.instanceId !== instanceId);
  }

  function updateInstance(instances, instanceId, patch) {
    return instances.map(i => (i.instanceId === instanceId ? Object.assign({}, i, patch, { params: Object.assign({}, i.params, patch.params || {}) }) : i));
  }

  global.FNO_CHART_INDICATORS = {
    REGISTRY,
    STORAGE_KEY,
    loadInstances,
    saveInstances,
    computeForCandles,
    listTypes,
    addInstance,
    removeInstance,
    updateInstance,
    mergeParams,
    /** @deprecated chart uses session VWAP; brain factors may still use closeAvgProxy */
    sessionVwapSeries,
    emaSeries,
  };
})(typeof window !== 'undefined' ? window : global);
