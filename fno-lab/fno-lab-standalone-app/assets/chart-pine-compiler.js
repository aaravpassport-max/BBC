/**
 * Pine Script v5 indicator subset → FNO Formula transpiler.
 * Not a Pine runtime: unsupported TV features fail with explicit errors.
 */
(function (global) {
  'use strict';

  const SUBSET_VERSION = 6;
  const MAX_SOURCE_LEN = 120000;

  const SUBSET_HELP =
    'This app compiles a Pine subset (not TradingView). Supported: indicator(), input.int/float, one plot(), '
    + 'ta.ema/sma/rsi/highest/lowest/vwap, math, and same-chart request.security(syminfo.tickerid, "D", close). '
    + 'Not supported: strategies, imports, loops, arrays in logic, other symbols in request.security. '
    + 'Block if/else/for/var/array lines that are not needed for your plot() are often auto-removed — keep plot(ta.ema(close, 14)) or use Load sample EMA.';

  /** Full-source scan (avoid \\bif\\b — it false-positives on plot(..., color=if close>open ? ...)). */
  const BODY_UNSUPPORTED_PATTERNS = [
    { re: /\bstrategy\s*\(/, msg: 'strategy() scripts are not supported (indicators only)' },
    { re: /\blibrary\s*\(/, msg: 'Pine libraries are not supported' },
    { re: /\bimport\s+/, msg: 'import is not supported' },
    { re: /\bbarstate\./, msg: 'barstate.* is not supported' },
    { re: /\barray\./, msg: 'array.* is not supported' },
    { re: /\bmatrix\./, msg: 'matrix.* is not supported' },
  ];

  const COLOR_MAP = {
    'color.blue': '#2962FF',
    'color.red': '#F23645',
    'color.green': '#089981',
    'color.orange': '#FF9800',
    'color.yellow': '#FDD835',
    'color.purple': '#9C27B0',
    'color.gray': '#787B86',
    'color.white': '#FFFFFF',
    'color.black': '#000000',
    'color.aqua': '#00BCD4',
    'color.fuchsia': '#E040FB',
    'color.lime': '#00E676',
    'color.navy': '#311B92',
    'color.olive': '#808000',
    'color.teal': '#00897B',
    'color.maroon': '#880E4F',
    'color.silver': '#B2B5BE',
  };

  function stripComments(src) {
    let out = '';
    let i = 0;
    while (i < src.length) {
      if (src[i] === '/' && src[i + 1] === '/') {
        while (i < src.length && src[i] !== '\n') i++;
        continue;
      }
      if (src[i] === '/' && src[i + 1] === '*') {
        i += 2;
        while (i < src.length && !(src[i] === '*' && src[i + 1] === '/')) i++;
        i += 2;
        continue;
      }
      out += src[i];
      i++;
    }
    return out;
  }

  function normalizeLines(src) {
    return stripComments(String(src || ''))
      .split(/\r?\n/)
      .map((l) => l.trim())
      .filter((l) => l.length > 0);
  }

  function findUnsupportedLineMessage(line) {
    const body = stripComments(String(line || '')).trim();
    if (!body) return null;
    if (/^\s*plot\s*\(/i.test(body)) {
      if (/\barray\./.test(body)) return 'array.* is not supported';
      if (/\bmatrix\./.test(body)) return 'matrix.* is not supported';
      return null;
    }
    if (/^\s*if\b/i.test(body)) return 'if statements are not supported in this Pine subset';
    if (/^\s*else\b/i.test(body)) return 'else branches are not supported in this Pine subset';
    if (/^\s*for\s+/i.test(body)) return 'for loops are not supported';
    if (/^\s*while\s+/i.test(body)) return 'while loops are not supported';
    if (/^\s*switch\s+/i.test(body)) return 'switch is not supported';
    if (/^\s*var\s+/i.test(body)) return 'var persistent state is not supported';
    if (/^\s*varip\s+/i.test(body)) return 'varip is not supported';
    if (/\b(array\.|matrix\.|barstate\.)\b/.test(body)) {
      if (/\barray\./.test(body)) return 'array.* is not supported';
      if (/\bmatrix\./.test(body)) return 'matrix.* is not supported';
      return 'barstate.* is not supported';
    }
    if (/\bstrategy\s*\(|\blibrary\s*\(|\bimport\s+/.test(body)) {
      if (/\bstrategy\s*\(/.test(body)) return 'strategy() scripts are not supported (indicators only)';
      if (/\blibrary\s*\(/.test(body)) return 'Pine libraries are not supported';
      return 'import is not supported';
    }
    return null;
  }

  function findUnsupported(raw) {
    const lines = String(raw || '').split(/\r?\n/);
    for (let li = 0; li < lines.length; li++) {
      const msg = findUnsupportedLineMessage(lines[li]);
      if (msg) return msg;
    }
    const body = stripComments(raw);
    for (let i = 0; i < BODY_UNSUPPORTED_PATTERNS.length; i++) {
      const hit = BODY_UNSUPPORTED_PATTERNS[i];
      if (hit.re.test(body)) return hit.msg;
    }
    return null;
  }

  function findUnsupportedDetail(raw) {
    const lines = String(raw || '').split(/\r?\n/);
    for (let li = 0; li < lines.length; li++) {
      const msg = findUnsupportedLineMessage(lines[li]);
      if (msg) {
        const body = stripComments(lines[li]);
        const call = body.match(/\b(array|matrix|barstate)\.[a-z_]+|\b(strategy|library)\s*\(|\bimport\s+\S+|\b(if|for|while|switch|var|varip)\b/i);
        return { msg, line: li + 1, snippet: lines[li].trim().slice(0, 120), call: call ? call[0] : msg };
      }
    }
    const msg = findUnsupported(raw);
    return { msg: msg || 'Unsupported Pine construct', line: null, snippet: '', call: '' };
  }

  function lineHasPlotCall(line) {
    return /\bplot\s*\(/i.test(stripComments(String(line || '')));
  }

  function plotLineIsSupported(line) {
    if (!lineHasPlotCall(line)) return false;
    const body = stripComments(String(line || ''));
    if (/\barray\.|\bmatrix\.|\bbarstate\./i.test(body)) return false;
    return true;
  }

  function lineIndent(line) {
    const m = String(line || '').match(/^(\s*)/);
    return m ? m[1].length : 0;
  }

  function lineBlockedForStrip(line) {
    const body = stripComments(String(line || '')).trim();
    if (!body) return true;
    if (/^\s*\/\//.test(line)) return true;
    if (/^\s*if\b/i.test(body)) return true;
    if (/^\s*else\b/i.test(body)) return true;
    if (/^\s*for\b/i.test(body)) return true;
    if (/^\s*while\b/i.test(body)) return true;
    if (/^\s*switch\b/i.test(body)) return true;
    if (/^\s*var\b/i.test(body)) return true;
    if (/^\s*varip\b/i.test(body)) return true;
    if (/=\s*if\b/i.test(body) && !/^\s*plot\s*\(/i.test(body)) return true;
    if (/\b(array\.|matrix\.|barstate\.)\b/.test(body)) return true;
    if (/\bstrategy\s*\(|\blibrary\s*\(|\bimport\s+/.test(body)) return true;
    return false;
  }

  function isBlockOpenerLine(line) {
    const body = stripComments(String(line || '')).trim();
    return /^\s*(if\b|for\b|while\b|switch\b|else\b)/i.test(body);
  }

  function buildStrippedPineSource(raw) {
    const lines = String(raw || '').split(/\r?\n/);
    const kept = [];
    const hoistedPlots = [];
    let skipIndent = null;

    lines.forEach((line) => {
      const body = stripComments(line);
      const trimmed = body.trim();
      if (!trimmed) return;

      const indent = lineIndent(body);

      if (skipIndent != null) {
        if (indent > skipIndent) {
          if (lineHasPlotCall(line) && plotLineIsSupported(line)) hoistedPlots.push(trimmed);
          return;
        }
        skipIndent = null;
      }

      if (lineBlockedForStrip(line)) {
        if (isBlockOpenerLine(line) && !lineHasPlotCall(line)) skipIndent = indent;
        if (lineHasPlotCall(line) && plotLineIsSupported(line)) hoistedPlots.push(trimmed);
        return;
      }

      if (parseIndicatorMeta(trimmed)) { kept.push(line); return; }
      if (parseInputLine(trimmed)) { kept.push(line); return; }
      if (extractPlot(trimmed)) { kept.push(line); return; }
      if (/^[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*.+$/.test(trimmed) && !/\b(input\.|plot\s*\()/.test(trimmed.split('=')[1])) {
        if (/=\s*if\b/i.test(trimmed)) return;
        kept.push(line);
      }
    });

    hoistedPlots.forEach((p) => {
      if (!kept.some((k) => stripComments(k).trim() === p)) kept.push(p);
    });
    if (!kept.some((k) => lineHasPlotCall(k))) {
      String(raw || '').split(/\r?\n/).forEach((line) => {
        const t = stripComments(line).trim();
        if (t && plotLineIsSupported(line)) kept.push(t);
      });
    }
    return kept.join('\n');
  }

  function formatUnsupportedMessage(raw, shortMsg) {
    const d = findUnsupportedDetail(raw);
    let out = d.msg || shortMsg || 'Unsupported Pine construct';
    if (d.line) out += ` (line ${d.line}${d.call ? ': ' + d.call : ''})`;
    if (/array/i.test(out)) {
      out += '. Arrays cannot run in FNO — delete array blocks or use a single plot(ta.ema(close, 14)) / Formula tab.';
    } else if (/if statements|else branches/i.test(out)) {
      out += '. Remove if/else blocks or use a script whose only plot() uses ta.ema/sma/rsi(close,…) — FNO can auto-strip extra if/else lines when the plot does not depend on them.';
    }
    out += '. ' + SUBSET_HELP;
    return out;
  }

  function parseIndicatorMeta(line) {
    if (!/\bindicator\s*\(/i.test(line)) return null;
    const titleMatch = line.match(/indicator\s*\(\s*"([^"]+)"/i)
      || line.match(/indicator\s*\(\s*'([^']+)'/i)
      || line.match(/title\s*=\s*"([^"]+)"/i);
    const name = titleMatch ? titleMatch[1].slice(0, 80) : 'Pine indicator';
    let type = 'overlay';
    if (/overlay\s*=\s*false/i.test(line) || /overlay\s*:\s*false/i.test(line)) type = 'panel';
    if (/overlay\s*=\s*true/i.test(line) || /overlay\s*:\s*true/i.test(line)) type = 'overlay';
    return { name, type };
  }

  function parseInputLine(line) {
    const m = line.match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*input\.(int|float)\s*\(([\s\S]+)\)\s*;?\s*$/);
    if (!m) return null;
    const varName = m[1].toLowerCase();
    const args = m[3];
    let defval = null;
    const named = args.match(/defval\s*=\s*([0-9.]+)/i);
    if (named) defval = Number(named[1]);
    else {
      const first = args.match(/^\s*([0-9.]+)/);
      if (first) defval = Number(first[1]);
    }
    if (!Number.isFinite(defval)) return { error: `Could not read default for input "${varName}" — use input.int(14, "Title")` };
    return { varName, defval };
  }

  const PINE_TF_MINUTES = {
    '1': 1, '3': 3, '5': 5, '15': 15, '30': 30, '45': 45, '60': 60,
    '120': 120, '180': 180, '240': 240, 'D': 1440, '1D': 1440, 'W': 10080, '1W': 10080,
  };

  function parsePineTimeframeMinutes(tfRaw) {
    const s = String(tfRaw || '').trim().replace(/["']/g, '').toUpperCase();
    if (Object.prototype.hasOwnProperty.call(PINE_TF_MINUTES, s)) return PINE_TF_MINUTES[s];
    const n = Number(s);
    if (Number.isFinite(n) && n > 0) return n;
    return null;
  }

  function isSameChartSecuritySymbol(symArg) {
    const a = String(symArg || '').trim();
    if (/^syminfo\.(tickerid|ticker)\b/i.test(a)) return true;
    if (/^ticker\b/i.test(a)) return true;
    if (a === '""' || a === "''") return true;
    if (/^["'][A-Za-z0-9._-]+["']$/.test(a) && !/:/.test(a)) return true;
    if (/["'][A-Za-z]+:/.test(a)) return false;
    if (/^["']/.test(a)) return false;
    return true;
  }

  function transpileRequestSecurity(expr) {
    let s = String(expr);
    const re = /request\.security\s*\(\s*([^,]+?)\s*,\s*["']([^"']+)["']\s*,\s*(close|open|high|low|volume|hl2|hlc3|ohlc4)\s*\)/gi;
    s = s.replace(re, (full, symArg, tf, field) => {
      if (!isSameChartSecuritySymbol(symArg)) {
        throw new Error('Cross-symbol request.security() is not supported — use syminfo.tickerid on the current chart only');
      }
      const mins = parsePineTimeframeMinutes(tf);
      if (!mins) throw new Error(`Unsupported timeframe "${tf}" in request.security() — use 5, 15, 60, 240, D, W`);
      return `htf(${field.toLowerCase()}, ${mins})`;
    });
    if (/request\.security/i.test(s)) {
      throw new Error(
        'request.security() only supports same-chart OHLCV (e.g. dailyClose = request.security(syminfo.tickerid, "D", close)) — not arbitrary expressions'
      );
    }
    return s;
  }

  function transpileTaCalls(expr) {
    let s = String(expr);
    s = s.replace(/\bta\.(ema|sma|rsi|highest|lowest|vwap)\s*\(/gi, (_, fn) => fn.toLowerCase() + '(');
    const unknownTa = s.match(/\bta\.([a-z_]+)\s*\(/i);
    if (unknownTa) {
      throw new Error(`Unsupported ta.${unknownTa[1]}() — supported: ema, sma, rsi, highest, lowest, vwap`);
    }
    return s;
  }

  function applyPineExprTransforms(expr) {
    let s = transpileRequestSecurity(expr);
    s = transpileTaCalls(s);
    return s;
  }

  function isIdentChar(ch) {
    return /[a-zA-Z0-9_]/.test(ch);
  }

  function substituteVars(expr, varFormulas) {
    let s = String(expr);
    const names = Object.keys(varFormulas).sort((a, b) => b.length - a.length);
    names.forEach((name) => {
      const repl = '(' + varFormulas[name] + ')';
      let out = '';
      let i = 0;
      while (i < s.length) {
        if (s.slice(i, i + name.length).toLowerCase() === name.toLowerCase()) {
          const before = i > 0 ? s[i - 1] : '';
          const after = i + name.length < s.length ? s[i + name.length] : '';
          if (!isIdentChar(before) && !isIdentChar(after)) {
            out += repl;
            i += name.length;
            continue;
          }
        }
        out += s[i];
        i++;
      }
      s = out;
    });
    return s;
  }

  function validateFormulaShape(expr) {
    const s = String(expr || '').trim();
    if (!s) return 'Plot expression is empty';
    if (/\.get\s*\(|\barray\b|\bmatrix\b/i.test(s)) {
      return 'Expression uses arrays/matrix calls — not supported; use ta.ema/sma/rsi(close,…) only';
    }
    if (/[^a-zA-Z0-9_(),.+\-*/\s]/.test(s)) {
      return 'Expression uses constructs outside the Pine subset (only price sources, ta.ema/sma/rsi/highest/lowest, +−*/ and inputs)';
    }
    if (/\b(input\.|plot\s*\(|indicator\s*\(|color\.)/.test(s)) return 'Expression still contains Pine calls — simplify to a single plot series';
    return null;
  }

  function transpileAssignmentRhs(rhs, varFormulas, params) {
    let expr = applyPineExprTransforms(rhs.replace(/;+\s*$/, '').trim());
    expr = substituteVars(expr, varFormulas);
    return expr.trim();
  }

  function extractPlot(line) {
    const idx = line.search(/\bplot\s*\(/i);
    if (idx < 0) return null;
    const start = line.indexOf('(', idx);
    if (start < 0) return null;
    let depth = 0;
    let end = -1;
    for (let i = start; i < line.length; i++) {
      if (line[i] === '(') depth++;
      else if (line[i] === ')') {
        depth--;
        if (depth === 0) { end = i; break; }
      }
    }
    if (end < 0) return null;
    const inner = line.slice(start + 1, end);
    const commaAt = findTopLevelComma(inner);
    const plotExpr = (commaAt >= 0 ? inner.slice(0, commaAt) : inner).trim();
    const tail = commaAt >= 0 ? inner.slice(commaAt + 1) : '';
    let color = null;
    const colorNamed = tail.match(/\bcolor\s*=\s*(color\.[a-z]+)/i);
    if (colorNamed) color = COLOR_MAP[colorNamed[1].toLowerCase()] || null;
    const colorHex = tail.match(/\bcolor\s*=\s*(#[0-9a-fA-F]{3,8})/);
    if (colorHex) color = colorHex[1];
    return { plotExpr, color };
  }

  function findTopLevelComma(s) {
    let depth = 0;
    for (let i = 0; i < s.length; i++) {
      const ch = s[i];
      if (ch === '(') depth++;
      else if (ch === ')') depth--;
      else if (ch === ',' && depth === 0) return i;
    }
    return -1;
  }

  function compilePineScript(source) {
    const raw = String(source || '');
    if (!raw.trim()) return { ok: false, error: 'Pine script is empty' };
    if (raw.length > MAX_SOURCE_LEN) return { ok: false, error: 'Pine script is too large' };

    const body = stripComments(raw);
    if (/\bstrategy\s*\(|\blibrary\s*\(|\bimport\s+/.test(body)) {
      return { ok: false, error: formatUnsupportedMessage(raw, findUnsupported(raw)) };
    }

    const stripped = buildStrippedPineSource(raw);
    if (
      stripped
      && stripped.trim() !== raw.trim()
      && /\bindicator\s*\(/i.test(stripped)
      && /\bplot\s*\(/i.test(stripped)
    ) {
      const retry = compilePineScriptInner(stripped, raw);
      if (retry.ok) {
        retry.warnings = (retry.warnings || []).concat([
          'Auto-removed unsupported Pine lines (if/else bodies, arrays, var, loops, etc.). Confirm the compiled plot matches your intent.',
        ]);
        retry.strippedCompile = true;
        return retry;
      }
    }

    const unsup = findUnsupported(raw);
    if (unsup) {
      if (stripped && stripped.trim() !== raw.trim()) {
        const retry2 = compilePineScriptInner(stripped, raw);
        return {
          ok: false,
          error: 'After removing if/else and other unsupported lines, compile still failed: '
            + (retry2.error || 'plot() may depend on removed logic')
            + '. Use a single plot(ta.ema(close, 14)) or Formula ema(close, 14). ' + SUBSET_HELP,
        };
      }
      return { ok: false, error: formatUnsupportedMessage(raw, unsup) };
    }
    return compilePineScriptInner(raw, raw);
  }

  function compilePineScriptInner(workingSource, pineSourceOriginal) {
    const raw = String(workingSource || '');
    if (!/\bindicator\s*\(/i.test(raw)) {
      return { ok: false, error: 'Pine subset requires indicator() — strategy and library scripts are not supported' };
    }
    if (!/\bplot\s*\(/i.test(raw)) {
      return { ok: false, error: 'At least one plot() call is required' };
    }

    const lines = normalizeLines(raw);
    const warnings = [];
    let name = 'Pine indicator';
    let type = 'overlay';
    const params = {};
    const varFormulas = {};
    let plotExpr = null;
    let plotColor = null;
    let plotCount = 0;
    let fatalError = null;

    for (let li = 0; li < lines.length; li++) {
      const line = lines[li];
      const meta = parseIndicatorMeta(line);
      if (meta) {
        name = meta.name;
        type = meta.type;
        continue;
      }
      const inp = parseInputLine(line);
      if (inp) {
        if (inp.error) warnings.push(inp.error);
        else params[inp.varName] = inp.defval;
        continue;
      }
      const plot = extractPlot(line);
      if (plot) {
        plotCount++;
        if (plotCount === 1) {
          plotExpr = plot.plotExpr;
          plotColor = plot.color;
        }
        continue;
      }
      const assign = line.match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/);
      if (assign && !/\binput\./.test(assign[2]) && !/\bplot\s*\(/.test(assign[2])) {
        const vname = assign[1].toLowerCase();
        try {
          varFormulas[vname] = transpileAssignmentRhs(assign[2], varFormulas, params);
        } catch (e) {
          const msg = e.message || String(e);
          if (/request\.security|Cross-symbol|Unsupported timeframe|Unsupported ta\./i.test(msg)) {
            fatalError = msg;
            break;
          }
          warnings.push(`${vname}: ${msg}`);
        }
      }
    }

    if (fatalError) return { ok: false, error: fatalError };

    if (!plotExpr) return { ok: false, error: 'Could not parse plot() expression' };
    if (plotCount > 1) warnings.push('Only the first plot() is rendered; extra plots are ignored in this subset');

    let formula;
    try {
      formula = applyPineExprTransforms(plotExpr);
    } catch (e) {
      return { ok: false, error: e.message || String(e) };
    }
    formula = substituteVars(formula, varFormulas);
    formula = formula.replace(/\s+/g, ' ').trim();
    const shapeErr = validateFormulaShape(formula);
    if (shapeErr) return { ok: false, error: shapeErr };

    const lower = formula.toLowerCase();
    if (type === 'panel' && lower.includes('rsi(')) {
      return {
        ok: true,
        subsetVersion: SUBSET_VERSION,
        name,
        type,
        formula,
        params,
        color: plotColor || '#a78bfa',
        panelMin: 0,
        panelMax: 100,
        warnings,
        pineSource: String(pineSourceOriginal || raw).slice(0, MAX_SOURCE_LEN),
      };
    }

    return {
      ok: true,
      subsetVersion: SUBSET_VERSION,
      name,
      type,
      formula,
      params,
      color: plotColor || '#f472b6',
      warnings,
      pineSource: String(pineSourceOriginal || raw).slice(0, MAX_SOURCE_LEN),
    };
  }

  function looksLikePineSource(raw) {
    const s = String(raw || '').trim();
    if (!s) return false;
    if (s.startsWith('{') || s.startsWith('[')) return false;
    return /^\/\/@version/i.test(s) || /\bindicator\s*\(/i.test(s) || (/\bplot\s*\(/i.test(s) && /\b(ta\.|input\.)/i.test(s));
  }

  function subsetHelpText() {
    return SUBSET_HELP;
  }

  global.FNO_CHART_PINE = {
    SUBSET_VERSION,
    SUBSET_HELP,
    compilePineScript,
    looksLikePineSource,
    subsetHelpText,
  };
})(typeof window !== 'undefined' ? window : global);
