'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const start = coreSrc.indexOf('function formatInrForTradeSpeech');
const end = coreSrc.indexOf('function playSingleTelephoneRingBurst', start);
const boot = [
  'function escapeHtml(s){ return String(s==null?"":s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }',
  coreSrc.slice(start, end),
  'return { buildTradeAlertSpeech, formatStrikeForTradeSpeech };',
].join('\n');
const api = new Function(boot)();

const entrySpeech = api.buildTradeAlertSpeech({ kind: 'entry', symbol: 'NIFTY', strike: 25000, optionType: 'CE', entryPrice: 185.5, qty: 50, tradingType: 'scalping' });
assert.ok(entrySpeech.includes('Trade alert'), entrySpeech);
assert.ok(entrySpeech.includes('NIFTY'), entrySpeech);
assert.ok(entrySpeech.includes('25,000') || entrySpeech.includes('25000'), entrySpeech);
assert.ok(entrySpeech.includes('CE'), entrySpeech);
assert.ok(entrySpeech.includes('185.50'), entrySpeech);

const exitSpeech = api.buildTradeAlertSpeech({ kind: 'exit', symbol: 'NIFTY', strike: 25000, optionType: 'CE', entryPrice: 185.5, exitPrice: 212.3, qty: 50, netPnl: 500, exitReason: 'target', actionLabel: 'AUTO_TARGET_EXIT' });
assert.ok(exitSpeech.includes('exited at'), exitSpeech);
assert.ok(exitSpeech.includes('Entry price'), exitSpeech);
assert.ok(exitSpeech.includes('target hit'), exitSpeech);

assert.ok(/notifyTradeExecution\(/.test(coreSrc));
assert.ok(/kind: 'entry'/.test(coreSrc));
assert.ok(/kind: 'exit'/.test(coreSrc));
assert.ok(/kind: 'partial_exit'/.test(coreSrc));
assert.ok(!/playTradeEntrySound\(\)/.test(coreSrc.replace(/function playTradeEntrySound[\s\S]*?\}/, '')));

console.log('All trade alert system tests passed.');
