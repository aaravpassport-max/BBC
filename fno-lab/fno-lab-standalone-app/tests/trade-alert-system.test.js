'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const start = coreSrc.indexOf('function formatInrForTradeSpeech');
const symBlockStart = coreSrc.indexOf('function resolveUiSymbol');
const symBlockEnd = coreSrc.indexOf('function formatLotQtyLabel', symBlockStart);
const end = coreSrc.indexOf('function playSingleTelephoneRingBurst', start);
const boot = [
  'function escapeHtml(s){ return String(s==null?"":s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }',
  coreSrc.slice(coreSrc.indexOf('const FNO_EXCHANGE_LOT_SIZES'), coreSrc.indexOf('function fnoThemePalette')),
  coreSrc.slice(symBlockStart, symBlockEnd),
  coreSrc.slice(start, end),
  'return { buildTradeAlertSpeech, formatStrikeForTradeSpeech, normalizeTradeAlertSymbol };',
].join('\n');
const api = new Function(boot)();

const entrySpeech = api.buildTradeAlertSpeech({ kind: 'entry', symbol: 'NIFTY', strike: 25000, optionType: 'CE', entryPrice: 185.5, qty: 50, tradingType: 'scalping' });
assert.strictEqual(
  entrySpeech,
  'BUY. NIFTY 25,000 CE was bought at Rs. 185.50 and total quantity bought is 50',
  entrySpeech
);

const exitSpeech = api.buildTradeAlertSpeech({ kind: 'exit', symbol: 'NIFTY', strike: 25000, optionType: 'CE', entryPrice: 185.5, exitPrice: 212.3, qty: 50, netPnl: 500, exitReason: 'PROFIT_PROTECTION', actionLabel: 'SPE_PROFIT_PROTECTION' });
assert.ok(exitSpeech.includes('scalping profit protection'), exitSpeech);

assert.ok(/normalizeTradeAlertSymbol/.test(coreSrc));
assert.ok(/function validatePaperExitEconomics/.test(coreSrc));

const badSymSpeech = api.buildTradeAlertSpeech({ kind: 'entry', symbol: '[object HTMLSelectElement]', strike: 23200, optionType: 'PE', entryPrice: 96.95, qty: 75, open: { symbol: 'NIFTY' } });
assert.ok(!badSymSpeech.includes('HTMLSelectElement'), badSymSpeech);
assert.ok(badSymSpeech.includes('NIFTY'), badSymSpeech);

assert.ok(/notifyTradeExecution\(/.test(coreSrc));
assert.ok(/kind: 'entry'/.test(coreSrc));
assert.ok(/kind: 'exit'/.test(coreSrc));
assert.ok(/kind: 'partial_exit'/.test(coreSrc));
assert.ok(!/playTradeEntrySound\(\)/.test(coreSrc.replace(/function playTradeEntrySound[\s\S]*?\}/, '')));

console.log('All trade alert system tests passed.');
