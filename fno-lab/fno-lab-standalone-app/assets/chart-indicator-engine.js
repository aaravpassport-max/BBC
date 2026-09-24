/**
 * Pluggable chart indicator registry (TradingView-style).
 * Overlay indicators share the price scale; panel indicators render in separate canvases.
 * Loaded before fno-lab-core.js — no dependency on the chart renderer.
 */
(function (global) {
  'use strict';

  const STORAGE_KEY = 'fno_chart_indicators_v1';
  const CUSTOM_DEFS_KEY = 'fno_chart_custom_indicators_v1';
  const CUSTOM_TYPE_PREFIX = 'custom:';

  function candleSource(name, candles) {
    const n = String(name || '').toLowerCase();
    if (n === 'close') return candles.map(c => c.c);
    if (n === 'open') return candles.map(c => (Number.isFinite(c.o) ? c.o : c.c));
    if (n === 'high') return candles.map(c => (Number.isFinite(c.h) ? c.h : c.c));
    if (n === 'low') return candles.map(c => (Number.isFinite(c.l) ? c.l : c.c));
    if (n === 'volume') return candles.map(c => (Number.isFinite(c.v) ? c.v : 0));
    if (n === 'hl2') return candles.map(c => ((Number.isFinite(c.h) ? c.h : c.c) + (Number.isFinite(c.l) ? c.l : c.c)) / 2);
    if (n === 'hlc3') return candles.map(c => {
      const h = Number.isFinite(c.h) ? c.h : c.c;
      const l = Number.isFinite(c.l) ? c.l : c.c;
      return (h + l + c.c) / 3;
    });
    if (n === 'ohlc4') return candles.map(c => {
      const o = Number.isFinite(c.o) ? c.o : c.c;
      const h = Number.isFinite(c.h) ? c.h : c.c;
      const l = Number.isFinite(c.l) ? c.l : c.c;
      return (o + h + l + c.c) / 4;
    });
    throw new Error(`Unknown source "${name}" — use close, open, high, low, volume, hl2, hlc3, ohlc4`);
  }

  function tokenizeFormula(input) {
    const s = String(input || '').trim();
    const tokens = [];
    let i = 0;
    while (i < s.length) {
      const ch = s[i];
      if (/\s/.test(ch)) { i++; continue; }
      if ('(),+-*/'.includes(ch)) { tokens.push({ type: ch }); i++; continue; }
      if (/[0-9.]/.test(ch)) {
        let j = i + 1;
        while (j < s.length && /[0-9.]/.test(s[j])) j++;
        tokens.push({ type: 'number', value: parseFloat(s.slice(i, j)) });
        i = j; continue;
      }
      if (/[a-zA-Z_]/.test(ch)) {
        let j = i + 1;
        while (j < s.length && /[a-zA-Z0-9_]/.test(s[j])) j++;
        tokens.push({ type: 'ident', value: s.slice(i, j).toLowerCase() });
        i = j; continue;
      }
      throw new Error(`Invalid character "${ch}" in formula`);
    }
    return tokens;
  }

  function parseFormula(input) {
    const tokens = tokenizeFormula(input);
    let pos = 0;
    function peek() { return tokens[pos]; }
    function consume(type) {
      const t = tokens[pos];
      if (!t || (type && t.type !== type)) throw new Error(`Formula parse error near token ${pos + 1}`);
      pos++;
      return t;
    }
    function parseExpr() {
      let node = parseTerm();
      while (peek() && (peek().type === '+' || peek().type === '-')) {
        const op = consume().type;
        node = { type: 'binop', op, left: node, right: parseTerm() };
      }
      return node;
    }
    function parseTerm() {
      let node = parseFactor();
      while (peek() && (peek().type === '*' || peek().type === '/')) {
        const op = consume().type;
        node = { type: 'binop', op, left: node, right: parseFactor() };
      }
      return node;
    }
    function parseFactor() {
      const t = peek();
      if (!t) throw new Error('Unexpected end of formula');
      if (t.type === 'number') { consume('number'); return { type: 'number', value: t.value }; }
      if (t.type === 'ident') {
        consume('ident');
        if (peek() && peek().type === '(') {
          consume('(');
          const args = [];
          if (!(peek() && peek().type === ')')) {
            args.push(parseExpr());
            while (peek() && peek().type === ',') { consume(','); args.push(parseExpr()); }
          }
          consume(')');
          return { type: 'call', name: t.value, args };
        }
        return { type: 'ident', name: t.value };
      }
      if (t.type === '(') {
        consume('(');
        const inner = parseExpr();
        consume(')');
        return inner;
      }
      throw new Error(`Unexpected token ${t.type}`);
    }
    const ast = parseExpr();
    if (pos < tokens.length) throw new Error('Unexpected trailing tokens in formula');
    return ast;
  }

  function resolveParamIdent(name, params) {
    if (!params || typeof params !== 'object') return null;
    const key = String(name || '').toLowerCase();
    if (Object.prototype.hasOwnProperty.call(params, key) && Number.isFinite(Number(params[key]))) {
      return Number(params[key]);
    }
    return null;
  }

  function evalSeriesNode(node, candles, params) {
    if (node.type === 'number') {
      return candles.map(() => node.value);
    }
    if (node.type === 'ident') {
      const paramVal = resolveParamIdent(node.name, params);
      if (paramVal != null) return candles.map(() => paramVal);
      return candleSource(node.name, candles);
    }
    if (node.type === 'binop') {
      const a = evalSeriesNode(node.left, candles, params);
      const b = evalSeriesNode(node.right, candles, params);
      return a.map((v, i) => {
        const x = v;
        const y = b[i];
        if (!Number.isFinite(x) || !Number.isFinite(y)) return NaN;
        if (node.op === '+') return x + y;
        if (node.op === '-') return x - y;
        if (node.op === '*') return x * y;
        if (node.op === '/') return y === 0 ? NaN : x / y;
        return NaN;
      });
    }
    if (node.type === 'call') {
      const fn = node.name;
      if (fn === 'vwap') {
        if (node.args.length) throw new Error('vwap() takes no arguments');
        return sessionVwapSeries(candles);
      }
      if (fn === 'ema' || fn === 'sma' || fn === 'rsi') {
        if (node.args.length !== 2) throw new Error(`${fn}() requires 2 arguments`);
        const src = evalSeriesNode(node.args[0], candles, params);
        const periodNode = node.args[1];
        let p = null;
        if (periodNode.type === 'number') p = periodNode.value;
        else if (periodNode.type === 'ident') {
          p = resolveParamIdent(periodNode.name, params);
          if (p == null) throw new Error(`${fn}() period must be a number or param name (e.g. period)`);
        } else throw new Error(`${fn}() period must be a number or param`);
        if (fn === 'ema') return emaSeries(src, p);
        if (fn === 'sma') return smaSeries(src, p);
        return rsiSeries(src, p);
      }
      if (fn === 'highest' || fn === 'lowest') {
        if (node.args.length !== 2) throw new Error(`${fn}() requires 2 arguments`);
        const src = evalSeriesNode(node.args[0], candles, params);
        const periodNode = node.args[1];
        let pRaw = null;
        if (periodNode.type === 'number') pRaw = periodNode.value;
        else if (periodNode.type === 'ident') {
          pRaw = resolveParamIdent(periodNode.name, params);
          if (pRaw == null) throw new Error(`${fn}() period must be a number or param name`);
        } else throw new Error(`${fn}() period must be a number or param`);
        const p = Math.max(1, pRaw | 0);
        return src.map((_, i) => {
          if (i + 1 < p) return NaN;
          let hi = -Infinity;
          let lo = Infinity;
          for (let j = i - p + 1; j <= i; j++) {
            if (Number.isFinite(src[j])) { hi = Math.max(hi, src[j]); lo = Math.min(lo, src[j]); }
          }
          return fn === 'highest' ? hi : lo;
        });
      }
      throw new Error(`Unknown function "${fn}" — use ema, sma, rsi, vwap, highest, lowest`);
    }
    throw new Error('Invalid formula AST');
  }

  function evaluateFormula(formula, candles, params) {
    if (!formula || !String(formula).trim()) return { error: 'Formula is empty', values: null };
    if (!Array.isArray(candles) || !candles.length) return { error: 'No candles', values: null };
    try {
      const ast = parseFormula(String(formula).trim());
      const values = evalSeriesNode(ast, candles, params || null);
      if (!values || values.length !== candles.length) return { error: 'Formula did not produce a series', values: null };
      return { error: null, values };
    } catch (e) {
      return { error: e.message || String(e), values: null };
    }
  }

  function loadCustomDefinitions() {
    try {
      const raw = global.localStorage && global.localStorage.getItem(CUSTOM_DEFS_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function saveCustomDefinitions(defs) {
    try {
      if (global.localStorage) global.localStorage.setItem(CUSTOM_DEFS_KEY, JSON.stringify(defs || []));
    } catch (e) { /* quota */ }
  }

  function normalizeCustomParams(raw) {
    if (!raw || typeof raw !== 'object') return {};
    const out = {};
    Object.keys(raw).forEach((k) => {
      const v = Number(raw[k]);
      if (Number.isFinite(v)) out[String(k).toLowerCase()] = v;
    });
    return out;
  }

  function validateCustomDefinition(def, sampleCandles) {
    if (!def || !def.name || !String(def.name).trim()) return 'Name is required';
    if (def.type !== 'overlay' && def.type !== 'panel') return 'Type must be overlay or panel';
    const sample = sampleCandles && sampleCandles.length ? sampleCandles : [{ t: 1, o: 10, h: 11, l: 9, c: 10, v: 1 }];
    const out = evaluateFormula(def.formula, sample, normalizeCustomParams(def.params));
    if (out.error) return out.error;
    return null;
  }

  function compilePineScript(source) {
    const pine = global.FNO_CHART_PINE;
    if (!pine || typeof pine.compilePineScript !== 'function') {
      return { ok: false, error: 'Pine compiler is not loaded' };
    }
    return pine.compilePineScript(source);
  }

  function compiledPineToDefinition(compiled) {
    if (!compiled || !compiled.ok) return null;
    return {
      name: compiled.name,
      type: compiled.type,
      formula: compiled.formula,
      params: compiled.params,
      color: compiled.color,
      panelMin: compiled.panelMin,
      panelMax: compiled.panelMax,
      source: 'pine',
      pineSource: compiled.pineSource,
      pineSubsetVersion: compiled.subsetVersion,
    };
  }

  function saveCustomDefinitionFromPine(source) {
    const compiled = compilePineScript(source);
    if (!compiled.ok) return { ok: false, error: compiled.error, warnings: compiled.warnings };
    const def = compiledPineToDefinition(compiled);
    const saved = saveCustomDefinition(def);
    if (!saved.ok) return saved;
    return { ok: true, def: saved.def, typeId: saved.typeId, warnings: compiled.warnings, compiled };
  }

  function buildCustomRegistryEntry(def) {
    const typeId = CUSTOM_TYPE_PREFIX + def.id;
    return {
      id: typeId,
      name: def.name,
      type: def.type,
      custom: true,
      customId: def.id,
      defaultParams: {
        formula: def.formula,
        color: def.color || '#f472b6',
        lineWidth: def.lineWidth != null ? def.lineWidth : 1.5,
        lineStyle: def.lineStyle || 'solid',
        panelMin: def.panelMin,
        panelMax: def.panelMax,
        panelHeight: def.panelHeight || 100,
      },
      paramSchema: [
        { key: 'formula', label: 'Formula', type: 'text' },
        { key: 'params', label: 'Params (JSON)', type: 'text' },
      ],
      compute(candles, params) {
        const p = Object.assign({}, def, params);
        const ev = evaluateFormula(p.formula, candles, normalizeCustomParams(p.params || def.params));
        if (ev.error || !ev.values) return def.type === 'panel' ? { panel: null } : { lines: [] };
        const style = { color: p.color, lineWidth: p.lineWidth, lineStyle: p.lineStyle };
        if (def.type === 'overlay') {
          return { lines: [{ id: 'main', values: ev.values, ...style }] };
        }
        const panelMin = Number.isFinite(p.panelMin) ? p.panelMin : (String(p.formula).toLowerCase().includes('rsi') ? 0 : null);
        const panelMax = Number.isFinite(p.panelMax) ? p.panelMax : (String(p.formula).toLowerCase().includes('rsi') ? 100 : null);
        return {
          panel: {
            id: 'custom_' + def.id,
            title: def.name,
            min: panelMin,
            max: panelMax,
            autoScale: panelMin == null || panelMax == null,
            panelHeight: p.panelHeight || 100,
            lines: [{ id: 'main', values: ev.values, ...style }],
          },
        };
      },
    };
  }

  function getDefinition(typeId) {
    if (REGISTRY[typeId]) return REGISTRY[typeId];
    if (typeId && String(typeId).indexOf(CUSTOM_TYPE_PREFIX) === 0) {
      const cid = String(typeId).slice(CUSTOM_TYPE_PREFIX.length);
      const def = loadCustomDefinitions().find(d => d.id === cid);
      if (def) return buildCustomRegistryEntry(def);
    }
    return null;
  }

  function listCustomDefinitions() {
    return loadCustomDefinitions();
  }

  function saveCustomDefinition(def) {
    const err = validateCustomDefinition(def);
    if (err) return { ok: false, error: err };
    const defs = loadCustomDefinitions();
    const entry = {
      id: def.id || ('c_' + Date.now().toString(36)),
      name: String(def.name).trim().slice(0, 80),
      type: def.type === 'panel' ? 'panel' : 'overlay',
      formula: String(def.formula).trim().slice(0, 2000),
      params: normalizeCustomParams(def.params),
      color: def.color || '#f472b6',
      lineWidth: def.lineWidth != null ? def.lineWidth : 1.5,
      lineStyle: def.lineStyle || 'solid',
      panelMin: def.panelMin,
      panelMax: def.panelMax,
      panelHeight: def.panelHeight || 100,
      source: def.source === 'pine' ? 'pine' : 'formula',
      pineSource: def.pineSource ? String(def.pineSource).slice(0, 120000) : undefined,
      pineSubsetVersion: def.pineSubsetVersion,
    };
    if (!entry.pineSource) delete entry.pineSource;
    if (!entry.pineSubsetVersion) delete entry.pineSubsetVersion;
    const idx = defs.findIndex(d => d.id === entry.id);
    if (idx >= 0) defs[idx] = entry;
    else defs.push(entry);
    saveCustomDefinitions(defs);
    return { ok: true, def: entry, typeId: CUSTOM_TYPE_PREFIX + entry.id };
  }

  function deleteCustomDefinition(customId) {
    const defs = loadCustomDefinitions().filter(d => d.id !== customId);
    saveCustomDefinitions(defs);
    let inst = loadInstances();
    const prefix = CUSTOM_TYPE_PREFIX + customId;
    inst = inst.filter(i => i.typeId !== prefix);
    saveInstances(inst);
    return defs;
  }

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
    volume: {
      id: 'volume',
      name: 'Volume',
      type: 'panel',
      defaultParams: { color: '#64748b', panelHeight: 72 },
      paramSchema: [],
      compute(candles, params) {
        const vols = candles.map(c => (Number.isFinite(c.v) ? c.v : 0));
        return {
          panel: {
            id: 'volume',
            title: 'Volume',
            autoScale: true,
            panelHeight: params.panelHeight || 72,
            histogram: { values: vols, upColor: '#4ade80', downColor: '#f87171' },
            lines: [],
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
    const def = getDefinition(typeId);
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
      const def = getDefinition(inst.typeId);
      if (!def) return;
      const params = mergeParams(inst.typeId, inst.params);
      const result = def.compute(candles, params);
      if (def.type === 'overlay' && result && result.lines && result.lines.length) {
        overlays.push({ instanceId: inst.instanceId, typeId: inst.typeId, name: def.name, lines: result.lines });
      } else if (def.type === 'panel' && result && result.panel) {
        panels.push({ instanceId: inst.instanceId, typeId: inst.typeId, panel: result.panel });
      }
    });
    return { overlays, panels };
  }

  function listTypes(filterQuery) {
    const q = filterQuery ? String(filterQuery).trim().toLowerCase() : '';
    const builtIn = Object.keys(REGISTRY).map((id) => {
      const r = REGISTRY[id];
      return { id, name: r.name, type: r.type, defaultParams: r.defaultParams, paramSchema: r.paramSchema || [], custom: false };
    });
    const custom = loadCustomDefinitions().map((d) => ({
      id: CUSTOM_TYPE_PREFIX + d.id,
      name: d.name + ' (custom)',
      type: d.type,
      defaultParams: buildCustomRegistryEntry(d).defaultParams,
      paramSchema: [{ key: 'formula', label: 'Formula', type: 'text' }],
      custom: true,
    }));
    const all = builtIn.concat(custom);
    if (!q) return all;
    return all.filter((t) => t.name.toLowerCase().includes(q) || t.id.toLowerCase().includes(q));
  }

  function exportCustomDefinitionsPack() {
    return {
      format: 'fno-indicator-pack-v1',
      version: 1,
      exportedAt: Date.now(),
      indicators: loadCustomDefinitions(),
    };
  }

  function importCustomDefinitionsPack(raw, options) {
    const opts = options || { merge: true, addToChart: false };
    const rawStr = typeof raw === 'string' ? raw : JSON.stringify(raw);
    const pine = global.FNO_CHART_PINE;
    if (pine && typeof pine.looksLikePineSource === 'function' && pine.looksLikePineSource(rawStr)) {
      const res = saveCustomDefinitionFromPine(rawStr);
      if (!res.ok) return { ok: false, error: res.error || 'Pine compile failed' };
      return { ok: true, saved: [res.def], errors: res.warnings || [], addToChart: !!opts.addToChart, pine: true };
    }
    let data;
    try {
      data = typeof raw === 'string' ? JSON.parse(raw) : raw;
    } catch (e) {
      return { ok: false, error: 'Invalid JSON file (or paste Pine in the Pine panel)' };
    }
    let list = [];
    if (data && data.format === 'fno-indicator-pack-v1' && Array.isArray(data.indicators)) {
      list = data.indicators;
    } else if (data && data.name && data.formula) {
      list = [data];
    } else if (Array.isArray(data)) {
      list = data;
    } else {
      return { ok: false, error: 'Unrecognized indicator pack (use fno-indicator-pack-v1 or single indicator object)' };
    }
    const saved = [];
    const errors = [];
    list.forEach((item) => {
      const res = saveCustomDefinition(item);
      if (res.ok) saved.push(res.def);
      else errors.push((item.name || 'indicator') + ': ' + res.error);
    });
    if (!saved.length) return { ok: false, error: errors.join('; ') || 'Nothing imported' };
    return { ok: true, saved, errors, addToChart: !!opts.addToChart };
  }

  function addInstance(instances, typeId) {
    const def = getDefinition(typeId);
    if (!def) return instances;
    const instanceId = `${String(typeId).replace(/[^a-z0-9]/gi, '_')}_${Date.now().toString(36)}`;
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
    CUSTOM_DEFS_KEY,
    loadInstances,
    saveInstances,
    computeForCandles,
    listTypes,
    addInstance,
    removeInstance,
    updateInstance,
    mergeParams,
    getDefinition,
    evaluateFormula,
    loadCustomDefinitions,
    saveCustomDefinition,
    deleteCustomDefinition,
    listCustomDefinitions,
    validateCustomDefinition,
    exportCustomDefinitionsPack,
    importCustomDefinitionsPack,
    compilePineScript,
    saveCustomDefinitionFromPine,
    normalizeCustomParams,
    /** @deprecated chart uses session VWAP; brain factors may still use closeAvgProxy */
    sessionVwapSeries,
    emaSeries,
  };
})(typeof window !== 'undefined' ? window : global);
