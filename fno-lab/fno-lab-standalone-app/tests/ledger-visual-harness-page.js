'use strict';
/**
 * Serves a browser page that runs the same ledger pipeline as
 * ledger-e2e-multitrade.test.js (real production helpers, not full WP UI).
 *
 *   node tests/ledger-visual-harness-page.js
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const PORT = Number(process.env.FNO_LEDGER_VISUAL_PORT || 8767);
const ROOT = path.join(__dirname, '..');
const coreSrc = fs.readFileSync(path.join(ROOT, 'assets/fno-lab-core.js'), 'utf8');

function sliceFn(name) {
  const start = coreSrc.indexOf(`function ${name}`);
  if (start < 0) throw new Error('missing ' + name);
  let depth = 0;
  let i = start;
  while (i < coreSrc.length) {
    if (coreSrc[i] === '{') depth++;
    if (coreSrc[i] === '}') {
      depth--;
      if (depth === 0) return coreSrc.slice(start, i + 1);
    }
    i++;
  }
  throw new Error('unclosed ' + name);
}

const fnBlock = [
  'normalizeTradeTimestampMs',
  'formatISTTime',
  'formatISTDate',
  'formatLedgerWhenIST',
  'formatLedgerWhenRangeIST',
  'formatLedgerStrikeCell',
  'formatLedgerActionLabel',
  'escapeHtml',
  'fingerprintJournalRow',
  'journalRowsLikelySame',
  'dedupeJournalRows',
  'stripJournalUiFields',
  'mergeJournalRowsForDisplay',
  'getServerJournalForMerge',
  'buildUnifiedJournalForLedger',
  'journalClearOpenPositionMarker',
  'journalRecordOpenPosition',
  'openAutoTradeLedgerRow',
  'openMarkerToLedgerRow',
  'resolveOpenRowForLedger',
  'isLedgerCompletedRoundTrip',
  'filterLedgerTableRows',
  'assignLedgerDisplayIds',
  'ledgerTradeNetPnl',
  'ledgerTradeGrossPnl',
  'formatLedgerRsWhole',
  'computeTradeCosts',
].map(sliceFn).join('\n');

const pageJs = `
const STORAGE = { journal: 'fno_journal_v8', autoTrades: 'fno_autotrades_v8' };
let fnoLastGoodServerJournal = null;
const store = {};
function load(k){ try { return JSON.parse(localStorage.getItem(k) || store[k] || '[]'); } catch { return []; } }
function save(k,v){ const s = JSON.stringify(v); store[k]=s; localStorage.setItem(k,s); }
function loadObj(k){ try { return JSON.parse(localStorage.getItem(k) || store[k] || '{}'); } catch { return {}; } }
function loadLocalJournalArray(){ const raw = load(STORAGE.journal); return Array.isArray(raw)?raw:[]; }
function saveJournalArray(rows) { save(STORAGE.journal, rows); return true; }
function scheduleTradeLedgerRefresh() {}
function paintTradeLedgerFromLocalNow() {}
${fnBlock}

function simulateCloseJournalAdd(entry) {
  journalClearOpenPositionMarker(entry.openTradeId || entry.openedAt);
  const j = loadLocalJournalArray().slice();
  j.push(entry);
  save(STORAGE.journal, j);
}

function buildLedgerTableView(serverRowsFresh) {
  if (Array.isArray(serverRowsFresh) && serverRowsFresh.length) fnoLastGoodServerJournal = serverRowsFresh;
  let trades = buildUnifiedJournalForLedger(serverRowsFresh);
  const openRow = resolveOpenRowForLedger(trades);
  if (openRow) trades = [openRow, ...trades.filter(t => !t._ledgerOpen && t.ledgerPhase !== 'open')];
  return assignLedgerDisplayIds(filterLedgerTableRows(trades));
}

function paintLedger(serverRowsFresh) {
  const tableRows = buildLedgerTableView(serverRowsFresh);
  const summaryEl = document.getElementById('tradeLedgerSummary');
  const bodyEl = document.getElementById('tradeLedgerBody');
  const closed = tableRows.filter(t => !t._ledgerOpen && t.ledgerPhase !== 'open');
  const openCount = tableRows.filter(t => t._ledgerOpen || t.ledgerPhase === 'open').length;
  if (tableRows.length === 0) {
    summaryEl.textContent = 'No trades yet';
    bodyEl.innerHTML = '<tr><td colspan="6" style="padding:10px;color:#64748b">Empty</td></tr>';
    return;
  }
  summaryEl.textContent = 'Total closed: ' + closed.length + ' · Open: ' + openCount;
  bodyEl.innerHTML = tableRows.map(t => {
    const st = (t._ledgerOpen || t.ledgerPhase === 'open') ? 'Open' : 'Closed';
    return '<tr><td>' + escapeHtml(t._ledgerDisplayId||'-') + '</td><td>' + escapeHtml(t.symbol||'') + '</td><td>' + st + '</td><td>' + (typeof t.pnl==='number'?t.pnl:'—') + '</td></tr>';
  }).join('');
}

window.__fnoLoadTradeLedger = function() { paintLedger(null); };

document.getElementById('btnSimOpen').onclick = () => {
  const id = Date.now();
  const open = { id, symbol: 'NIFTY', strike: 25000, optionType: 'CE', entryPrice: 120, qty: 75, openedAt: Date.now(), tradingType: 'scalping' };
  save(STORAGE.autoTrades, open);
  journalRecordOpenPosition(open, 'paper');
  paintLedger(null);
  document.getElementById('harnessStatus').textContent = 'Simulated OPEN';
};
document.getElementById('btnSimClose').onclick = () => {
  const open = loadObj(STORAGE.autoTrades);
  simulateCloseJournalAdd({
    ts: Date.now(), openedAt: open.openedAt||Date.now()-60000, openTradeId: open.id,
    symbol: 'NIFTY', strike: 25000, optionType: 'CE', action: 'AUTO_TARGET_EXIT',
    entryPrice: 120, exitPrice: 135, qty: 75, pnl: 1020, grossPnl: 1125, costsTotal: 105, tradingType: 'scalping'
  });
  journalClearOpenPositionMarker(open.id);
  save(STORAGE.autoTrades, {});
  paintLedger(null);
  document.getElementById('harnessStatus').textContent = 'Simulated CLOSE';
};
document.getElementById('harnessStatus').textContent = 'Core loaded — production ledger helpers';
paintLedger(null);
`;

const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ledger visual harness</title>
<style>body{font-family:system-ui;background:#0f172a;color:#e2e8f0;padding:20px} .btn{margin-right:8px;padding:8px 12px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer}
table{width:100%;border-collapse:collapse;margin-top:12px} td,th{border:1px solid #334155;padding:8px}</style></head><body>
<h1>Ledger visual harness (production merge code)</h1>
<p id="harnessStatus">Booting…</p>
<button type="button" class="btn" id="btnSimOpen">Simulate entry (open row)</button>
<button type="button" class="btn" id="btnSimClose">Simulate exit (closed row)</button>
<div id="tradeLedgerSummary" style="margin-top:16px;font-weight:700">Loading...</div>
<table><thead><tr><th>#</th><th>Symbol</th><th>Status</th><th>Net</th></tr></thead>
<tbody id="tradeLedgerBody"><tr><td colspan="4">Loading...</td></tbody></table>
<script>${pageJs}<\/script></body></html>`;

http.createServer((req, res) => {
  if (req.url === '/' || req.url === '/index.html') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(html);
    return;
  }
  res.writeHead(404);
  res.end('not found');
}).listen(PORT, '127.0.0.1', () => {
  console.log(`Ledger harness page: http://127.0.0.1:${PORT}/`);
});
