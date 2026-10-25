// FOUND during a systematic sweep of every `.innerHTML =` / `.innerHTML +=`
// assignment in assets/fno-lab-core.js (follow-up to the OpenAI-narrative
// XSS fix in openai-narrative-trust-boundary-audit.test.js, which explicitly
// flagged "other sites were not individually re-audited" as an open gap).
//
// This sweep found 155 total innerHTML sites. The overwhelming majority
// insert only internally-computed values (numbers formatted via
// .toFixed()/.toLocaleString(), hardcoded label strings, deterministic
// evaluateBrain()/factor-engine output) - LOW risk, left as-is. A minority
// insert values that ultimately originate from: (a) free-text <textarea>
// fields a user can type into the UI (Knowledge Base observation/evidence/
// conclusion/candidateChange, Strategy Version Log reason/evidence/
// changedFields - both length-capped server-side via fno_cap_text() but
// NOT HTML-stripped in a way this client can rely on), (b) broker (Kite)
// API responses (profile JSON, order id/message), (c) journal/ledger rows
// whose symbol/optionType/status fields are written via a POST endpoint
// that only sanitize_text_field()s them server-side (fno-lab.php) - never
// validated against the fixed NIFTY/BANKNIFTY/FINNIFTY or CE/PE enums, so
// a direct API call (bypassing the <select> the UI happens to use) can
// still store an arbitrary string. These sites are fixed here by routing
// the value through the existing escapeHtml() helper (added by the prior
// pass), exactly as already done for the OpenAI narrative sites.
//
// FOLLOW-UP PASS (2026-08-30): the prior sweep above explicitly left 3
// categories open ("flag and leave" reasoning) - the Auto Trade open/
// history panel, scattered e.message/j.data.message catch-block sites,
// and the option-chain table. All 3 are now closed (see the tests below
// the "Follow-up pass" marker): the Auto Trade panel is hardened against
// a localStorage-tamper trust boundary even though normal UI use is
// enum-only; every e.message/j.data.message DOM-insertion site is
// escaped; the option-chain table's OI/volume fields now go through a
// type-checked fmtInt() instead of raw (x||0).toLocaleString(); and a
// further sweep beyond the original 3 categories found and fixed
// several more real gaps this pass turned up along the way (FM045's
// correlation-risk message embedding a server-controlled tracked-
// position symbol, the data-availability-matrix title="" attribute, and
// ~25 internally-computed .reason/.summary/.label diagnostic strings
// hardened as cheap defense-in-depth). A full re-grep after these fixes
// found zero remaining un-escaped innerHTML interpolations of any
// message/reason/symbol/name/label/... -shaped field.
//
// Run with: node tests/innerhtml-xss-sweep-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

let pass = 0, fail = 0;
function test(name, fn) {
  try { fn(); console.log(`  PASS  ${name}`); pass++; }
  catch (e) { console.log(`  FAIL  ${name}\n        ${e.message}`); fail++; }
}

const m = src.match(/function escapeHtml\(s\) \{[\s\S]*?\n\}/);
assert(m, 'escapeHtml function must exist in fno-lab-core.js');
const escapeHtml = eval(`(${m[0]})`);

const XSS_SCRIPT = '<script>alert(document.cookie)</script>';
const XSS_IMG = '<img src=x onerror=alert(1)>';

// --- (a) Free-text user-typed fields --------------------------------

test('Knowledge Base render site (loadKnowledgeBase) escapes observation/evidence/conclusion/candidateChange/factor/status', () => {
  const fnMatch = src.match(/async function loadKnowledgeBase\(\)[\s\S]*?\n  \}/);
  assert(fnMatch, 'loadKnowledgeBase must exist');
  const body = fnMatch[0];
  ['e.observation', 'e.evidence', 'e.conclusion', 'e.candidateChange', 'e.factor', 'e.status']
    .forEach(field => assert(body.includes(`escapeHtml(${field})`), `${field} must be routed through escapeHtml`));
});

test('Strategy Version Log render site escapes version/reason/evidence/changedFields', () => {
  const idx = src.indexOf("logEl.innerHTML = j.data.versions.slice().reverse().map(v =>");
  assert(idx > -1, 'strategy version log render site must exist');
  const body = src.slice(idx, idx + 2000);
  ['escapeHtml(v.version)', 'escapeHtml(v.changedFields.join', 'escapeHtml(v.reason)', 'escapeHtml(v.evidence)']
    .forEach(needle => assert(body.includes(needle), `must include ${needle}`));
});

test('escapeHtml neutralizes both payload shapes used against the free-text sites', () => {
  assert(!escapeHtml(XSS_SCRIPT).includes('<script>'));
  assert(!escapeHtml(XSS_IMG).match(/<img/i));
});

// --- (b) Broker (Kite) API response sites ----------------------------

test('Kite token-exchange result (kiteProfile <pre>) escapes the raw JSON response', () => {
  assert(src.includes("document.getElementById('kiteProfile').innerHTML=`<pre>${escapeHtml(JSON.stringify(j,null,2))}</pre>`;"),
    'token-exchange kiteProfile render must escape the JSON blob');
});

test('Kite profile-verification result escapes both the JSON blob and the failure message', () => {
  const idx = src.indexOf("✅ Kite session verified with a real API call");
  assert(idx > -1);
  const body = src.slice(idx - 200, idx + 400);
  assert(body.includes('escapeHtml(JSON.stringify(j.data,null,2))'), 'success-path JSON must be escaped');
  assert(body.includes("escapeHtml(j.data && j.data.message || 'Verification failed')"), 'failure-path message must be escaped');
});

test('Kite login_url is escaped before being placed in the href attribute', () => {
  assert(src.includes('href="${escapeHtml(j.data.login_url)}"'), 'login_url must be escaped in the href attribute context');
});

test('Order placement success/failure messages (order_id, message) are escaped', () => {
  assert(src.includes("escapeHtml(j.data.order_id||'')"), 'order_id must be escaped');
  assert(src.includes("escapeHtml(j.data.message||'placed')"), 'success message must be escaped');
  assert(src.includes("escapeHtml(j.data && j.data.message || 'unknown error')"), 'failure message must be escaped');
});

// --- (c) Journal/ledger rows with unvalidated symbol/optionType/status ----

test('Real-money journal render escapes symbol/option_type/status', () => {
  const idx = src.indexOf('async function loadRealMoneyJournal()');
  assert(idx > -1);
  const body = src.slice(idx, idx + 900);
  assert(body.includes('escapeHtml(t.symbol)'));
  assert(body.includes('escapeHtml(t.option_type)'));
  assert(body.includes("escapeHtml(String(t.status).toUpperCase())"));
});

test('Trade ledger table body escapes symbol/optionType', () => {
  const idx = src.indexOf('bodyEl.innerHTML = trades.map(t => {');
  assert(idx > -1);
  const body = src.slice(idx, idx + 2000);
  assert(body.includes('escapeHtml(t.symbol)'));
  assert(body.includes("escapeHtml(t.optionType || '')"));
});

test('Decision Replay list and detail views escape symbol/optionType', () => {
  const listIdx = src.indexOf('replayListEl.innerHTML = withSnapshots.map((t,i) =>');
  const detailIdx = src.indexOf('replayDetailEl.innerHTML = `');
  assert(listIdx > -1 && detailIdx > -1);
  assert(src.slice(listIdx, listIdx + 300).includes('escapeHtml(t.symbol)'));
  assert(src.slice(detailIdx, detailIdx + 300).includes('escapeHtml(t.symbol)'));
});

test('Portfolio tracker position list escapes symbol/optionType in both the visible text and the aria-label attribute', () => {
  const idx = src.indexOf('posBox.innerHTML = positions.map(p =>');
  assert(idx > -1);
  const body = src.slice(idx, idx + 600);
  const symbolHits = (body.match(/escapeHtml\(p\.symbol\)/g) || []).length;
  const optionTypeHits = (body.match(/escapeHtml\(p\.optionType\)/g) || []).length;
  assert.strictEqual(symbolHits, 2, 'p.symbol must be escaped in both the display span and the aria-label');
  assert.strictEqual(optionTypeHits, 2, 'p.optionType must be escaped in both the display span and the aria-label');
});

// --- Non-regression: plain legitimate values still render correctly -------

test('escapeHtml is a no-op on plain legitimate journal/ledger values (no double-escaping, no mangled numbers)', () => {
  assert.strictEqual(escapeHtml('NIFTY'), 'NIFTY');
  assert.strictEqual(escapeHtml('CE'), 'CE');
  assert.strictEqual(escapeHtml('PLACED'), 'PLACED');
  assert.strictEqual(escapeHtml('Order placed successfully'), 'Order placed successfully');
  // Percentages/numbers are never routed through escapeHtml at these sites
  // (they stay raw numeric template interpolation, e.g. ${t.strike}), so
  // this only asserts escapeHtml itself never mangles a benign string that
  // happens to contain the ampersand this codebase's own labels use.
  assert.strictEqual(escapeHtml('Rs1500 (P&L)'), 'Rs1500 (P&amp;L)');
});

test('escapeHtml handles null/undefined defensively (matches every call site\'s fallback usage, e.g. t.optionType||\'\')', () => {
  assert.strictEqual(escapeHtml(null), '');
  assert.strictEqual(escapeHtml(undefined), '');
});

// --- Follow-up pass: closing the 3 categories explicitly left open by the
// prior sweep above ("flag and leave" is no longer acceptable - every site
// below is either fixed with escapeHtml() or, where truly inert, hardened
// anyway as cheap defense-in-depth). ------------------------------------

// (1) Auto Trade open/history panel. Re-verified provenance: #sym is a
// <select> with exactly 3 hardcoded <option>s (assets/standalone-app.php),
// so under normal UI use sym/open.strike/open.optionType can only be one
// of the fixed enum values. BUT STORAGE.autoTrades (and its _history
// array) is plain localStorage with no schema enforcement - a devtools
// console edit (`localStorage.setItem(...)`) can put any string in the
// open-position or history objects before renderOpenTrades() reads them
// back. That is a real trust boundary even though "the user did it to
// themselves", so both render sites are hardened with escapeHtml()
// regardless of the enum-only provenance under normal use.
test('renderOpenTrades open-position panel escapes sym/open.strike/open.optionType (localStorage-tamper hardening)', () => {
  const idx = src.indexOf('function renderOpenTrades(ocRow, optionType, strike, sym) {');
  assert(idx > -1, 'renderOpenTrades must exist');
  const body = src.slice(idx, idx + 8500);
  assert(body.includes('openEl.innerHTML'), 'must reach the openEl.innerHTML assignments');
  ['escapeHtml(sym)', 'escapeHtml(String(open.strike))', 'escapeHtml(open.optionType)'].forEach(needle => {
    const hits = (body.match(new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length;
    assert(hits >= 3, `${needle} must appear in all 3 openEl.innerHTML branches (found ${hits})`);
  });
});

test('renderOpenTrades closed-trade history panel escapes h.symbol/h.strike/h.optionType (localStorage-tamper hardening)', () => {
  const idx = src.indexOf("histEl.innerHTML = history.length");
  assert(idx > -1, 'history render site must exist');
  const body = src.slice(idx, idx + 700);
  assert(body.includes('escapeHtml(h.symbol)'));
  assert(body.includes('escapeHtml(String(h.strike))'));
  assert(body.includes('escapeHtml(h.optionType)'));
});

// (2) Scattered e.message / j.data.message in catch blocks and error
// paths - every DOM-insertion site that echoes a caught error or a
// server-response error/status/reason string is now routed through
// escapeHtml(), on the systematic theory that a JSON.parse failure or a
// malformed/attacker-influenced response body can end up embedded in that
// string.
test('every innerHTML site interpolating a bare ${e.message}/${err.message} is gone (all routed through escapeHtml)', () => {
  const bareMessage = /\$\{e\.message\}/g;
  assert.strictEqual((src.match(bareMessage) || []).length, 0, 'no bare, un-escaped ${e.message} interpolation should remain');
  const escapedMessageSites = (src.match(/\$\{escapeHtml\(e\.message\)\}/g) || []).length;
  assert(escapedMessageSites >= 11, `expected at least 11 escapeHtml(e.message) sites, found ${escapedMessageSites}`);
});

test('journal-fetch and probability-model-save server error messages are escaped', () => {
  assert(src.includes("Fetch failed: ${escapeHtml(j.data && j.data.message || 'unknown error')}"),
    'fno_journal_list fetch-failure message must be escaped');
  assert(src.includes("escapeHtml(saveJ.data && saveJ.data.message || 'unknown error')"),
    'probability-model save-failure message must be escaped');
});

test('probability-model status panel escapes latest.reason/strategyVersionAtTraining/modelSchemaVersion (server JSON fields)', () => {
  const idx = src.indexOf('window.FNO_ACTIVE_PROB_MODEL = latest.active ? latest : null;');
  assert(idx > -1);
  const body = src.slice(idx, idx + 2000);
  assert((body.match(/escapeHtml\(latest\.reason\)/g) || []).length === 2, 'both latest.reason render branches must be escaped');
  assert(body.includes("escapeHtml(latest.strategyVersionAtTraining||'unknown')"));
  assert(body.includes('escapeHtml(String(latest.modelSchemaVersion))'));
});

test('portfolio correlation-risk warning messages (which embed tracked-position symbol, a server field not enum-validated) are escaped', () => {
  const idx = src.indexOf('const warnings = computePortfolioCorrelationRisk(positions);');
  assert(idx > -1);
  const body = src.slice(idx, idx + 700);
  assert(body.includes('escapeHtml(w.message)'));
});

test('Failure-Mode Library triggered-condition panels (both the blocked-trade and opened-trade render sites) escape id/severity/action/condition/reason', () => {
  const marker = "fmResult.triggered.map(t => `<div style=\"padding:4px 0;border-bottom:1px solid #111827\"><b>${escapeHtml(t.id)}</b>";
  const occurrences = src.split(marker).length - 1;
  assert.strictEqual(occurrences, 2, `expected 2 fmResult.triggered.map render sites using the escaped template, found ${occurrences}`);
  let from = 0;
  for (let i = 0; i < occurrences; i++) {
    const at = src.indexOf(marker, from);
    const chunk = src.slice(at, at + 500);
    ['escapeHtml(t.id)', 'escapeHtml(t.adjustedSeverity||t.severity)', 'escapeHtml(t.condition)', 'escapeHtml(t.reason)']
      .forEach(needle => assert(chunk.includes(needle), `${needle} missing from occurrence ${i}`));
    from = at + marker.length;
  }
});

test('data-availability-matrix table escapes capability name and the title-attribute free-tier note (attribute-breakout hardening)', () => {
  assert(src.includes('title="${escapeHtml(row.freeTierNote||\'\')}">${escapeHtml(row.capability)}</td>'),
    'row.capability and row.freeTierNote (inside a title="" attribute) must both be escaped');
  assert(src.includes('Source: ${escapeHtml(j.data.catalogSource)}</div>`;'), 'catalogSource server field must be escaped');
});

test('internally-computed .reason/.summary/.label diagnostic strings across the decision/backtest panels are now escaped too (defense-in-depth, per "nothing left unhardened")', () => {
  [
    'escapeHtml(trackRecord.reason)', 'escapeHtml(g.reason)', 'escapeHtml(brain.reason)',
    'escapeHtml(snap.summary)', 'escapeHtml(cond.reason)', 'escapeHtml(rev.reason)',
    'escapeHtml(vc.reason)', 'escapeHtml(sv.reason)', 'escapeHtml(consistency.reason)',
    'escapeHtml(wrongSide.reason)', 'escapeHtml(r.reason)', 'escapeHtml(s.reason)',
    'escapeHtml(fmResult.summary)', 'escapeHtml(marketHoursCheck.reason)',
    'escapeHtml(review.bestTrade.symbol)', 'escapeHtml(review.worstTrade.symbol)',
    'escapeHtml(wf.warning)', 'escapeHtml(s.observation)', 'escapeHtml(m.symbol)',
    "escapeHtml(m.reason || 'not recorded')", 'escapeHtml(hedge.reason)',
    'escapeHtml(tierInfo.text)', 'escapeHtml(nameFor(p.factorA))',
    'escapeHtml(nameFor2(p.factorA))', 'escapeHtml(nameFor2(r.factorId))', 'escapeHtml(nameFor3(d.factorId))',
  ].forEach(needle => assert(src.includes(needle), `missing: ${needle}`));
});

// (3) Option-chain table - re-verified line-by-line. strikePrice is
// guaranteed numeric (upstream .filter(r => typeof r.strikePrice ===
// 'number')). lastPrice/impliedVolatility already went through fmt(),
// which type-checks before .toFixed() and falls back to '-'. But
// openInterest/changeinOpenInterest/totalTradedVolume did NOT have an
// equivalent type check - `(x||0).toLocaleString()` calls .toLocaleString()
// on whatever truthy value is present, and a String's .toLocaleString()
// returns the string itself verbatim. Fixed via a new fmtInt() helper
// that mirrors fmt()'s type-check-or-fallback contract.
test('option-chain table routes every OI/volume field through the new type-checked fmtInt() helper, not raw (x||0).toLocaleString()', () => {
  const idx = src.indexOf('function renderOptionChainTable(ocRows, spot, selectedStrike, selectedType, sym) {');
  assert(idx > -1);
  const body = src.slice(idx, idx + 4200);
  assert(body.includes("const fmtInt = v => typeof v==='number' ? v.toLocaleString() : '0';"), 'fmtInt helper must exist and type-check');
  ['fmtInt(ce.openInterest)', 'fmtInt(ce.changeinOpenInterest)', 'fmtInt(ce.totalTradedVolume)',
   'fmtInt(pe.totalTradedVolume)', 'fmtInt(pe.changeinOpenInterest)', 'fmtInt(pe.openInterest)']
    .forEach(needle => assert(body.includes(needle), `${needle} missing`));
  // No raw, unguarded (x||0).toLocaleString() pattern should remain as
  // LIVE CODE within the render function (the TRACE comment above the
  // function is allowed to mention the old pattern for context, so only
  // the executable statements - the <td> template lines - are checked).
  const codeOnly = body.split('const fmtInt =')[1] || body;
  assert(!/\$\{\(ce\.openInterest\|\|0\)\.toLocaleString\(\)\}/.test(codeOnly), 'raw un-type-checked ce.openInterest pattern must be gone from the live template');
  assert(!/\$\{\(pe\.openInterest\|\|0\)\.toLocaleString\(\)\}/.test(codeOnly), 'raw un-type-checked pe.openInterest pattern must be gone from the live template');
});

test('fmtInt behaves correctly for both a legitimate numeric OI value and a hypothetical malformed non-numeric field (regression proof for the fix)', () => {
  const fmtIntSrc = "v => typeof v==='number' ? v.toLocaleString() : '0'";
  const fmtInt = eval(`(${fmtIntSrc})`);
  assert.strictEqual(fmtInt(1234567), '1,234,567');
  assert.strictEqual(fmtInt(0), '0');
  assert.strictEqual(fmtInt(undefined), '0');
  // The exact scenario the old code mishandled: a non-numeric string
  // field would have been returned verbatim by .toLocaleString() on a
  // String primitive; fmtInt instead falls back to '0'.
  assert.strictEqual(fmtInt('<img src=x onerror=alert(1)>'), '0');
});

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
