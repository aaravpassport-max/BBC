/**
 * Pine Script v5 indicator subset → FNO Formula transpiler.
 * Not a Pine runtime: unsupported TV features fail with explicit errors.
 */
(function (global) {
  'use strict';

  const SUBSET_VERSION = 1;
  const MAX_SOURCE_LEN = 120000;

  const UNSUPPORTED_PATTERNS = [
    { re: /\brequest\.security\b/, msg: 'request.security() is not supported' },
    { re: /\bstrategy\s*\(/, msg: 'strategy() scripts are not supported (indicators only)' },
    { re: /\blibrary\s*\(/, msg: 'Pine libraries are not supported' },
    { re: /\bimport\s+/, msg: 'import is not supported' },
    { re: /\bbarstate\./, msg: 'barstate.* is not supported' },
    { re: /\barray\./, msg: 'array.* is not supported' },
    { re: /\bmatrix\./, msg: 'matrix.* is not supported' },
    { re: /\bfor\s+/, msg: 'for loops are not supported' },
    { re: /\bwhile\s+/, msg: 'while loops are not supported' },
    { re: /\bif\s+/, msg: 'if statements are not supported in this Pine subset' },
    { re: /\bswitch\s+/, msg: 'switch is not supported' },
    { re: /\bvar\s+/, msg: 'var persistent state is not supported' },
    { re: /\bvarip\s+/, msg: 'varip is not supported' },
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

  function findUnsupported(raw) {
    const body = stripComments(raw);
    for (let i = 0; i < UNSUPPORTED_PATTERNS.length; i++) {
      const hit = UNSUPPORTED_PATTERNS[i];
      if (hit.re.test(body)) return hit.msg;
    }
    return null;
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

  function transpileTaCalls(expr) {
    let s = String(expr);
    s = s.replace(/\bta\.(ema|sma|rsi|highest|lowest)\s*\(/gi, (_, fn) => fn.toLowerCase() + '(');
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
    if (/[^a-zA-Z0-9_(),.+\-*/\s]/.test(s)) {
      return 'Expression uses constructs outside the Pine subset (only price sources, ta.ema/sma/rsi/highest/lowest, +−*/ and inputs)';
    }
    if (/\b(input\.|plot\s*\(|indicator\s*\(|color\.)/.test(s)) return 'Expression still contains Pine calls — simplify to a single plot series';
    return null;
  }

  function transpileAssignmentRhs(rhs, varFormulas, params) {
    let expr = transpileTaCalls(rhs.replace(/;+\s*$/, '').trim());
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
    const unsup = findUnsupported(raw);
    if (unsup) return { ok: false, error: unsup };
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

    lines.forEach((line) => {
      const meta = parseIndicatorMeta(line);
      if (meta) {
        name = meta.name;
        type = meta.type;
        return;
      }
      const inp = parseInputLine(line);
      if (inp) {
        if (inp.error) warnings.push(inp.error);
        else params[inp.varName] = inp.defval;
        return;
      }
      const plot = extractPlot(line);
      if (plot) {
        plotCount++;
        if (plotCount === 1) {
          plotExpr = plot.plotExpr;
          plotColor = plot.color;
        }
        return;
      }
      const assign = line.match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/);
      if (assign && !/\binput\./.test(assign[2]) && !/\bplot\s*\(/.test(assign[2])) {
        const vname = assign[1].toLowerCase();
        try {
          varFormulas[vname] = transpileAssignmentRhs(assign[2], varFormulas, params);
        } catch (e) {
          warnings.push(`${vname}: ${e.message || String(e)}`);
        }
      }
    });

    if (!plotExpr) return { ok: false, error: 'Could not parse plot() expression' };
    if (plotCount > 1) warnings.push('Only the first plot() is rendered; extra plots are ignored in this subset');

    let formula = transpileTaCalls(plotExpr);
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
        pineSource: raw.slice(0, MAX_SOURCE_LEN),
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
      pineSource: raw.slice(0, MAX_SOURCE_LEN),
    };
  }

  function looksLikePineSource(raw) {
    const s = String(raw || '').trim();
    if (!s) return false;
    if (s.startsWith('{') || s.startsWith('[')) return false;
    return /^\/\/@version/i.test(s) || /\bindicator\s*\(/i.test(s) || (/\bplot\s*\(/i.test(s) && /\b(ta\.|input\.)/i.test(s));
  }

  global.FNO_CHART_PINE = {
    SUBSET_VERSION,
    compilePineScript,
    looksLikePineSource,
  };
})(typeof window !== 'undefined' ? window : global);
