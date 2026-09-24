// FOUND during the OpenAI/LLM trust-boundary audit: generateAiNarrativeIfDue
// (assets/fno-lab-core.js) writes the OpenAI API's returned narrative
// string into the DOM via innerHTML. That string is untrusted, external,
// third-party content (a real, external API response) - a crafted or
// compromised response containing HTML could execute in the page (XSS)
// if inserted unescaped. This test proves the shared escapeHtml() helper
// this audit added neutralizes exactly that class of payload, and that
// evaluateBrain's decision/score never depends on anything network- or
// LLM-derived from that endpoint (confirming the narrative is genuinely
// display-only, not a decision input - traced by reading
// generateAiNarrativeIfDue's call site in fno-lab-core.js, which invokes
// it AFTER `const brain=evaluateBrain(ctx)` already exists, and never
// awaits or feeds its result back into `brain`).
// Run with: node tests/openai-narrative-trust-boundary-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

let pass = 0, fail = 0;
function test(name, fn) {
  try { fn(); console.log(`  PASS  ${name}`); pass++; }
  catch (e) { console.log(`  FAIL  ${name}\n        ${e.message}`); fail++; }
}

// Extract and eval just escapeHtml (zero real dependencies - a pure string function).
const m = src.match(/function escapeHtml\(s\) \{[\s\S]*?\n\}/);
assert(m, 'escapeHtml function must exist in fno-lab-core.js');
const escapeHtml = eval(`(${m[0]})`);

test('escapeHtml neutralizes a <script> tag payload', () => {
  const out = escapeHtml('<script>alert(1)</script>');
  assert(!out.includes('<script>'), 'raw <script> tag must not survive escaping');
  assert.strictEqual(out, '&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('escapeHtml neutralizes an onerror= attribute-injection payload', () => {
  const out = escapeHtml('<img src=x onerror=alert(1)>');
  assert(!/<img/i.test(out), 'raw <img tag must not survive escaping');
});

test('escapeHtml neutralizes a quote-breakout payload targeting an attribute context', () => {
  const out = escapeHtml(`"><svg onload=alert(1)>`);
  assert(!out.includes('"'), 'double quote must be entity-encoded');
  assert(!out.includes('<svg'), 'raw <svg tag must not survive escaping');
});

test('escapeHtml is a no-op on plain narrative prose (no false-positive mangling)', () => {
  const plain = "This is a bullish setup because EMA9 crossed above EMA21, confidence is high.";
  assert.strictEqual(escapeHtml(plain), plain);
});

test('escapeHtml handles null/undefined defensively (fail-safe empty string, never throws)', () => {
  assert.strictEqual(escapeHtml(null), '');
  assert.strictEqual(escapeHtml(undefined), '');
});

test('all 3 innerHTML narrative render sites route the OpenAI-derived value through escapeHtml', () => {
  const fnMatch = src.match(/async function generateAiNarrativeIfDue\([\s\S]*?\n\}/);
  assert(fnMatch, 'generateAiNarrativeIfDue function must exist');
  const body = fnMatch[0];
  assert(body.includes('escapeHtml(j.data.narrative)'), 'success-path narrative must be escaped');
  assert(body.includes('escapeHtml(j.data && j.data.message'), 'error-path server message must be escaped');
  assert(body.includes('escapeHtml(e.message)'), 'catch-path exception message must be escaped');
});

test('generateAiNarrativeIfDue is called AFTER evaluateBrain() already produced brain.decision, never before (decision-input audit)', () => {
  const idx = src.indexOf('const brain=evaluateBrain(ctx);');
  const callIdx = src.indexOf('generateAiNarrativeIfDue(brain, sym, isStaleRefresh);');
  assert(idx > -1 && callIdx > -1, 'both real call sites must exist');
  assert(callIdx > idx, 'the AI-narrative call must come strictly after evaluateBrain() has already produced brain.decision, proving it cannot influence that decision');
});

test('generateAiNarrativeIfDue is fire-and-forget (not awaited) at its call site - cannot block or gate the refresh/decision cycle', () => {
  const callLineMatch = src.match(/^\s*generateAiNarrativeIfDue\(brain, sym, isStaleRefresh\);\s*$/m);
  assert(callLineMatch, 'call site must exist on its own line');
  assert(!callLineMatch[0].trim().startsWith('await'), 'call must not be awaited - a slow/failed OpenAI call must never stall the real refresh cycle that already produced the decision');
});

test('evaluateBrain itself never reads window.FNO_AJAX or any OpenAI/narrative-derived global (decision-input audit, structural)', () => {
  const brainMatch = src.match(/function evaluateBrain\(ctx\)\s*\{/);
  assert(brainMatch, 'evaluateBrain must exist');
  const start = brainMatch.index;
  // Bound the scan to evaluateBrain's own function body by brace counting.
  let depth = 0, i = start, started = false;
  for (; i < src.length; i++) {
    if (src[i] === '{') { depth++; started = true; }
    else if (src[i] === '}') { depth--; if (started && depth === 0) { i++; break; } }
  }
  const body = src.slice(start, i);
  assert(!/narrative/i.test(body), 'evaluateBrain must never reference any "narrative"-named value');
  assert(!body.includes('FNO_AJAX'), 'evaluateBrain must never reach out to the AJAX/network layer the OpenAI call uses');
});

console.log(`\n${pass} passed, ${fail} failed (openai-narrative-trust-boundary-audit.test.js)`);
process.exit(fail > 0 ? 1 : 0);
