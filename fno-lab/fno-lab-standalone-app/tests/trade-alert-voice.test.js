'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

assert.match(coreSrc, /function ensureTradeAlertVoicesLoaded/);
assert.match(coreSrc, /function pickTradeAlertVoice/);
assert.match(coreSrc, /function primeTradeAlertAudio/);
assert.match(coreSrc, /scoreTradeAlertVoice/);
assert.match(coreSrc, /rupees/);
assert.match(coreSrc, /testTradeAlertVoicePreview/);
assert.match(coreSrc, /speakTradeAlert\(speech, voiceVol/);
assert.doesNotMatch(coreSrc, /notifyTradeExecution\(sample\)/);

console.log('trade-alert-voice tests passed.');
