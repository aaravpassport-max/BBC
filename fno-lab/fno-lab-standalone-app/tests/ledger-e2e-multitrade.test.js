'use strict';
/**
 * End-to-end simulation of the trade ledger pipeline (open alert → journal →
 * merge → table rows) without WordPress. Uses the same functions as production.
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

function sliceFn(name) {
  const start = coreSrc.indexOf(`function ${name}`);
  assert.ok(start >= 0, `missing ${name}`);
  let depth = 0;
  let i = start;
  while (i < coreSrc.length) {
    if (coreSrc[i] === '{') depth++;
    if (coreSrc[i] === '}') {
      depth--;
      if (depth === 0) {
        return coreSrc.slice(start, i + 1);
      }
    }
    i++;
  }
  throw new Error(`unclosed ${name}`);
}

const boot = `
var STORAGE = { journal: 'fno_journal_v8', autoTrades: 'fno_autotrades_v8' };
let fnoLastGoodServerJournal = null;
let fnoLastGoodServerJournalAt = 0;
let fnoLedgerRefreshTimer = null;
function scheduleTradeLedgerRefresh() {}
const store = {};
function resetSimStorage() {
  for (const k of Object.keys(store)) delete store[k];
  fnoLastGoodServerJournal = null;
  fnoLastGoodServerJournalAt = 0;
}
function setLastGoodServerJournal(rows) {
  fnoLastGoodServerJournal = Array.isArray(rows) ? rows : null;
}
function load(k){ try { return JSON.parse(store[k] || '[]'); } catch { return []; } }
function save(k,v){ store[k] = JSON.stringify(v); }
function loadObj(k){ try { return JSON.parse(store[k] || '{}'); } catch { return {}; } }
function loadLocalJournalArray() {
  const raw = load(STORAGE.journal);
  return Array.isArray(raw) ? raw : [];
}
${sliceFn('normalizeTradeTimestampMs')}
${sliceFn('formatISTTime')}
${sliceFn('formatISTDate')}
${sliceFn('formatLedgerWhenIST')}
${sliceFn('fingerprintJournalRow')}
${sliceFn('journalRowsLikelySame')}
${sliceFn('dedupeJournalRows')}
${sliceFn('stripJournalUiFields')}
${sliceFn('mergeJournalRowsForDisplay')}
${sliceFn('getServerJournalForMerge')}
${sliceFn('buildUnifiedJournalForLedger')}
${sliceFn('reconcileLocalJournalFromServer')}
${sliceFn('journalClearOpenPositionMarker')}
${sliceFn('journalRecordOpenPosition')}
${sliceFn('openAutoTradeLedgerRow')}
${sliceFn('openMarkerToLedgerRow')}
${sliceFn('resolveOpenRowForLedger')}
${sliceFn('isLedgerCompletedRoundTrip')}
${sliceFn('filterLedgerTableRows')}
${sliceFn('assignLedgerDisplayIds')}
${sliceFn('ledgerTradeNetPnl')}

function simulateCloseJournalAdd(entry) {
  journalClearOpenPositionMarker(entry.openTradeId || entry.openedAt);
  const j = loadLocalJournalArray().slice();
  j.push(entry);
  save(STORAGE.journal, j);
}

function buildLedgerTableView(serverRowsFresh, autoTradesObj) {
  if (Array.isArray(serverRowsFresh) && serverRowsFresh.length) {
    fnoLastGoodServerJournal = serverRowsFresh;
  }
  save(STORAGE.autoTrades, autoTradesObj && autoTradesObj.id ? autoTradesObj : {});
  let trades = buildUnifiedJournalForLedger(serverRowsFresh);
  const openRow = resolveOpenRowForLedger(trades);
  if (openRow) trades = [openRow, ...trades.filter(t => !t._ledgerOpen && t.ledgerPhase !== 'open')];
  const tableRows = assignLedgerDisplayIds(filterLedgerTableRows(trades));
  const closed = tableRows.filter(t => !t._ledgerOpen && t.ledgerPhase !== 'open');
  const openCount = tableRows.filter(t => t._ledgerOpen || t.ledgerPhase === 'open').length;
  return { tableRows, closed, openCount, rawCount: trades.length };
}

function simulateOpen(open, mode) {
  save(STORAGE.autoTrades, open);
  journalRecordOpenPosition(open, mode || 'paper');
}
`;

const ctx = {};
vm.createContext(ctx);
vm.runInContext(boot, ctx);
const api = ctx;

console.log('\n=== ledger E2E multi-trade simulation ===\n');

// --- Scenario 1: single open shows in ledger ---
api.resetSimStorage();
const open1 = { id: 1001, symbol: 'NIFTY', strike: 25000, optionType: 'CE', entryPrice: 200, qty: 75, openedAt: Date.now(), tradingType: 'scalping' };
api.simulateOpen(open1);
let view = api.buildLedgerTableView(null, open1);
assert.strictEqual(view.openCount, 1, 'open trade must appear as 1 open row');
assert.strictEqual(view.closed.length, 0, 'no closed rows while open');
console.log('OK scenario 1: open → 1 open row');

// --- Scenario 2: close → closed row, open gone ---
const close1 = {
  ts: Date.now() + 1000,
  openedAt: open1.openedAt,
  openTradeId: open1.id,
  symbol: 'NIFTY',
  strike: 25000,
  optionType: 'CE',
  action: 'AUTO_SL_EXIT',
  entryPrice: 200,
  exitPrice: 190,
  qty: 75,
  pnl: -850,
  grossPnl: -750,
  costsTotal: 100,
  tradingType: 'scalping',
};
api.simulateCloseJournalAdd(close1);
api.journalClearOpenPositionMarker(open1.id);
view = api.buildLedgerTableView(null, {});
assert.strictEqual(view.openCount, 0, 'open row gone after close');
assert.strictEqual(view.closed.length, 1, 'one closed row');
assert.strictEqual(view.closed[0].pnl, -850);
console.log('OK scenario 2: close → 1 closed row, 0 open');

// --- Scenario 3: three sequential trades accumulate ---
api.resetSimStorage();
const closes = [];
for (let i = 0; i < 3; i++) {
  const id = 2000 + i;
  const openedAt = Date.now() + i * 60000;
  api.simulateOpen({ id, symbol: 'NIFTY', strike: 25000 + i, optionType: 'CE', entryPrice: 100 + i, qty: 75, openedAt, tradingType: 'scalping' });
  const entry = {
    ts: openedAt + 30000,
    openedAt,
    openTradeId: id,
    symbol: 'NIFTY',
    strike: 25000 + i,
    optionType: 'CE',
    action: 'AUTO_TARGET_EXIT',
    entryPrice: 100 + i,
    exitPrice: 120 + i,
    qty: 75,
    pnl: 1000 + i,
    grossPnl: 1100,
    costsTotal: 100,
    tradingType: 'scalping',
  };
  api.simulateCloseJournalAdd(entry);
  api.journalClearOpenPositionMarker(id);
  closes.push(entry);
}
view = api.buildLedgerTableView(null, {});
assert.strictEqual(view.closed.length, 3, 'three closed trades must all appear');
assert.strictEqual(view.openCount, 0);
console.log('OK scenario 3: 3 sequential round-trips → 3 closed rows');

// --- Scenario 4: server history + local new close (logged-in merge) ---
api.resetSimStorage();
const serverHistory = [];
for (let i = 0; i < 5; i++) {
  serverHistory.push({
    id: i + 1,
    ts: Date.now() - (5 - i) * 86400000,
    symbol: 'NIFTY',
    strike: 24000,
    optionType: 'CE',
    action: 'AUTO_SL_EXIT',
    entryPrice: 200,
    exitPrice: 195,
    qty: 75,
    pnl: -500,
    grossPnl: -400,
    costsTotal: 100,
    tradingStyle: 'scalping',
  });
}
// local-only new close not yet on server
const newClose = {
  ts: Date.now(),
  openedAt: Date.now() - 60000,
  symbol: 'NIFTY',
  strike: 25100,
  optionType: 'PE',
  action: 'AUTO_SIGNAL_INVALIDATED',
  entryPrice: 150,
  exitPrice: 140,
  qty: 75,
  pnl: -820,
  grossPnl: -750,
  costsTotal: 70,
  tradingType: 'scalping',
};
api.simulateCloseJournalAdd(newClose);
view = api.buildLedgerTableView(serverHistory, {});
assert.strictEqual(view.closed.length, 6, '5 server + 1 local-only close');
console.log('OK scenario 4: server 5 + local 1 → 6 closed rows');

// --- Scenario 5: reconcile race must not drop fresh close ---
api.resetSimStorage();
api.simulateCloseJournalAdd(newClose);
const beforeLen = api.loadLocalJournalArray().length;
assert.strictEqual(beforeLen, 1);
// simulate stale server fetch started before close landed: empty server reconcile
api.reconcileLocalJournalFromServer([]);
const afterLen = api.loadLocalJournalArray().length;
assert.ok(afterLen >= beforeLen, 'reconcile must not shrink journal after fresh close');
view = api.buildLedgerTableView([], {});
assert.strictEqual(view.closed.length, 1, 'closed row still visible after empty reconcile');
console.log('OK scenario 5: reconcile race does not drop fresh close');

// --- Scenario 6: failed server fetch uses cached server + new local ---
api.resetSimStorage();
api.setLastGoodServerJournal(serverHistory);
api.simulateCloseJournalAdd(newClose);
const unifiedLen = api.buildUnifiedJournalForLedger(null).length;
assert.strictEqual(unifiedLen, 6, 'merge must use cached server when fetch returns null');
view = api.buildLedgerTableView(null, {});
assert.strictEqual(view.closed.length, 6, 'cached server + local close when fetch null');
console.log('OK scenario 6: cached server + local on failed fetch');

// --- Scenario 7: open marker survives cleared autoTrades until close (alert path) ---
api.resetSimStorage();
api.simulateOpen(open1);
api.save('fno_autotrades_v8', {});
view = api.buildLedgerTableView(null, {});
assert.strictEqual(view.openCount, 1, 'journal open marker visible without autoTrades object');
console.log('OK scenario 7: open marker without live autoTrades');

console.log('\nAll ledger E2E multi-trade scenarios passed.\n');
