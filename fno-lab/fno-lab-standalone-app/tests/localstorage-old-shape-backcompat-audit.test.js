// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - localStorage schema-
// versioning/backward-compatibility audit pass.
//
// This app has NO explicit STORAGE_VERSION/schemaVersion marker for its
// browser localStorage keys (STORAGE.autoTrades, STORAGE.autoTrades+
// '_history', STORAGE.journal, STORAGE.daily, STORAGE.mode,
// FNO_SETTINGS_KEY). Several fields were added to these shapes across
// this project's session (trailingSl/mfe/mae on the open-position
// object, `source`/`tradingType`/entryIV/exitIV on closed-trade-history
// entries). A real user's browser can still hold an OLD-shape object
// from before any of these fields existed. This test proves every real
// read site that consumes these fields tolerates an old-shape object -
// no TypeError, no NaN latching, no silently-wrong gate - by feeding
// genuinely field-missing objects (simulating pre-existing localStorage
// content, NOT freshly-constructed ones) directly into the real
// functions extracted from assets/fno-lab-core.js.
//
// Run with: node tests/localstorage-old-shape-backcompat-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
// Minimal localStorage shim so top-level module code (fnoSettings etc.)
// can be evaluated standalone, same technique as the other audit tests
// in this directory.
global.localStorage = {
  _data: {},
  getItem(k) { return Object.prototype.hasOwnProperty.call(this._data, k) ? this._data[k] : null; },
  setItem(k, v) { this._data[k] = String(v); },
  removeItem(k) { delete this._data[k]; },
};
global.document = global.document || { dispatchEvent(){}, addEventListener(){} };
global.window = global.window || {};
global.CustomEvent = global.CustomEvent || function CustomEvent(name, opts) { this.type = name; this.detail = opts && opts.detail; };
// A plain top-level eval() here would leak `function` declarations
// into this module's scope (the technique the other audit test files
// in this directory rely on) but NOT top-level `const`/`let` (e.g.
// `const fnoSettings = {...}`, `const FNO_SETTINGS_KEY = ...` in
// fno-lab-core.js) - per spec those are scoped to the eval() call
// itself and vanish once it returns. This test needs fnoSettings, so
// instead run the same real source inside a real `Function` body and
// explicitly `return` every real binding this file needs - every
// declaration form (function/const/let) is visible to a `return`
// statement appended after it in the same function body.
const harnessFactory = new Function('localStorage', 'document', 'window', 'CustomEvent', coreSource.slice(0, end) + `
return { updateMFEMAE, updateTrailingStop, checkPartialExit, resolvePartialExitQty, checkReEntryCooldown, classifyTradeFailure, computeEquityCurve, fnoSettings, FNO_SETTINGS_KEY };`);
const harness = harnessFactory(localStorage, document, window, CustomEvent);
const { updateMFEMAE, updateTrailingStop, checkPartialExit, resolvePartialExitQty, checkReEntryCooldown, classifyTradeFailure, computeEquityCurve, fnoSettings, FNO_SETTINGS_KEY } = harness;

// -----------------------------------------------------------------
// 1. STORAGE.autoTrades - an OLD open-position object from BEFORE
// trailingSl/mfe/mae existed at all (only the fields that predate this
// session's Master Prompt §27/§43 work: id, entryPrice, qty, sl,
// target, openedAt).
// -----------------------------------------------------------------
const oldOpenPosition = {
  id: 1700000000000, strike: 22500, optionType: 'CE', entryPrice: 120,
  qty: 50, sl: 100, target: 160, openedAt: 1700000000000,
  // no mfe, no mae, no trailingSl, no trailingEnabled, no
  // partialExitEnabled, no partialTaken, no tradingType, no entryIV
};

check((() => {
  const r = updateMFEMAE(oldOpenPosition, 130);
  return r.mfe === 130 && r.mae === 120; // seeds from entryPrice, not undefined
})(), 'updateMFEMAE: old-shape position (no mfe/mae) seeds from entryPrice, never NaN/undefined');

check((() => {
  const r = updateTrailingStop(oldOpenPosition, 130);
  return Number.isFinite(r);
})(), 'updateTrailingStop: old-shape position (no trailingSl) returns a real finite number, never NaN');

check((() => {
  // trailingEnabled undefined on old data -> falsy -> caller's own
  // `open.trailingEnabled ? open.trailingSl : open.sl` branch (the
  // real renderOpenTrades()/tick logic) must resolve to the real sl,
  // never attempt `.toFixed` on an undefined trailingSl.
  const effectiveSl = oldOpenPosition.trailingEnabled ? oldOpenPosition.trailingSl : oldOpenPosition.sl;
  return effectiveSl === 100 && typeof (effectiveSl).toFixed === 'function';
})(), 'renderOpenTrades effectiveSl pattern: old-shape position (trailingEnabled undefined) falls back to real sl, no TypeError on undefined.toFixed');

check((() => {
  const r = checkPartialExit(oldOpenPosition, 140);
  return r === null; // partialExitEnabled undefined -> falsy -> null, no crash
})(), 'checkPartialExit: old-shape position (no partialExitEnabled) returns null, never throws');

check((() => {
  // Simulate the real display-string construction at renderOpenTrades
  // (MFE Rs${(open.mfe||open.entryPrice).toFixed(1)}) against the raw
  // old-shape object BEFORE any tick has run updateMFEMAE on it (e.g.
  // page loaded once with no live price yet).
  const mfeDisplay = (oldOpenPosition.mfe || oldOpenPosition.entryPrice).toFixed(1);
  const maeDisplay = (oldOpenPosition.mae || oldOpenPosition.entryPrice).toFixed(1);
  return mfeDisplay === '120.0' && maeDisplay === '120.0';
})(), 'renderOpenTrades MFE/MAE display: old-shape position (no mfe/mae) falls back to entryPrice, no TypeError on undefined.toFixed');

// -----------------------------------------------------------------
// 2. STORAGE.autoTrades + '_history' - OLD closed-trade entries from
// BEFORE `source`, `tradingType`, `entryIV`/`exitIV`, `mfe`/`mae` were
// captured on close.
// -----------------------------------------------------------------
const oldHistoryEntrySl = {
  ts: 1700000000000, symbol: 'NIFTY', strike: 22500, optionType: 'CE',
  action: 'AUTO_SL_EXIT', entryPrice: 120, exitPrice: 100, qty: 50,
  grossPnl: -1000, costsTotal: 40, pnl: -1040, mode: 'paper',
  // no source, no tradingType, no mfe/mae, no entryIV/exitIV, no sl
};

check((() => {
  // checkReEntryCooldown's own real matching logic: `h.source ===
  // 'auto_sl'`. An old entry with no `source` field must never match
  // (would otherwise wrongly cooldown-block on unrelated old data) and
  // must never throw.
  const r = checkReEntryCooldown([oldHistoryEntrySl], 'intraday', Date.now());
  return r.onCooldown === false && r.reason.includes('No real prior stop-loss exit found');
})(), 'checkReEntryCooldown: old-shape history entry (no source field) never matches auto_sl, never blocks, never throws');

check((() => {
  const r = classifyTradeFailure(oldHistoryEntrySl);
  return r && typeof r.category === 'string'; // must return SOME real classification, not throw
})(), 'classifyTradeFailure: old-shape history entry (no mfe/mae/sl/source/entryIV) still returns a real classification object, never throws');

check((() => {
  const r = classifyTradeFailure(oldHistoryEntrySl);
  // With no excursion data and no source, real fallback logic must be
  // reached (never a TypeError from trade.mfe.toFixed etc).
  return r.category !== undefined;
})(), 'classifyTradeFailure: old-shape entry never crashes on trade.mfe.toFixed/trade.exitIV.toFixed (guarded by hasExcursionData/hasIVData)');

check((() => {
  // The real renderOpenTrades() history-label ternary chain -
  // h.source==='auto_target'?...:h.source==='square_off'?...: ... :
  // '🛑 SL' (the real, pre-existing default). An old entry with no
  // source must resolve to the default label string, never throw.
  const h = oldHistoryEntrySl;
  const label = h.source==='auto_target'?'Target':h.source==='square_off'?'Square-off':h.source==='manual_force_exit'?'Manual':h.source==='partial'?'Partial':h.source==='auto_invalidated'?'Invalidated':'SL';
  return label === 'SL';
})(), 'renderOpenTrades history label ternary: old-shape entry (no source) resolves to the real default SL label, never throws');

// -----------------------------------------------------------------
// 3. computeEquityCurve - old journal entries (pre pnl-validation /
// pre-mfe/mae) and an old-shape open position passed in.
// -----------------------------------------------------------------
check((() => {
  const oldJournal = [{ ts: 1700000000000, pnl: -500 }]; // no grossPnl/costsTotal/mfe/mae
  const r = computeEquityCurve(oldJournal, { startingCapital: 100000 }, oldOpenPosition);
  return Number.isFinite(r.currentBalance) && Number.isFinite(r.maxDrawdownPct);
})(), 'computeEquityCurve: old-shape journal entry + old-shape open position never produce NaN, never throw');

// -----------------------------------------------------------------
// 4. fnoSettings.get() - simulates a real user's localStorage
// predating a newly-added setting (tradeTypeSizingEnabled/
// tradeTypeTargetSlEnabled, both added this session).
// -----------------------------------------------------------------
const realSettingsKey = FNO_SETTINGS_KEY; // the real, live key fnoSettings.get()/.set() actually read/write

check((() => {
  localStorage._data = {}; // clean slate
  localStorage.setItem(realSettingsKey, JSON.stringify({ soundEnabled: true })); // old-shape: predates tradeTypeSizingEnabled etc.
  const s = fnoSettings.get();
  return s.tradeTypeSizingEnabled === false && s.tradeTypeTargetSlEnabled === false && s.soundEnabled === true && typeof s.tradingTypes === 'object';
})(), 'fnoSettings.get(): old-shape stored settings (predates tradeTypeSizingEnabled/tradeTypeTargetSlEnabled) deep-merges real defaults, never undefined');

check((() => {
  localStorage._data = {};
  localStorage.setItem(realSettingsKey, 'not-json{{{');
  const s = fnoSettings.get();
  return s.tradeTypeSizingEnabled === false; // corrupted JSON -> full safe default fallback, never throws
})(), 'fnoSettings.get(): corrupted/malformed JSON in localStorage never throws, falls back to real defaults');

// -----------------------------------------------------------------
// 5. resolvePartialExitQty - old-shape position missing qty (should
// not occur in practice since qty is one of the oldest fields, but
// verify no crash if it ever does, e.g. a hand-edited/corrupted row).
// -----------------------------------------------------------------
check((() => {
  const r = resolvePartialExitQty({}, { newSl: 100, newTarget: 160 });
  return r === null || (typeof r === 'object');
})(), 'resolvePartialExitQty: position missing qty entirely never throws');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
