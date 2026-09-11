'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const php = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');

assert.ok(/\[data-theme="light"\]\s*\{[\s\S]*--panel-bg:/.test(php), 'light theme defines --panel-bg token');
assert.ok(/\[data-theme="light"\] #settingsModalOverlay > div/.test(php), 'settings modal gets light surface override');
assert.ok(/\[data-theme="light"\] #fno-root \[style\*="background:#020617"\]/.test(php), 'dark inline panels remapped in light mode');
assert.ok(/\[data-theme="light"\] #fno-root \[style\*="color:#e2e8f0"\]/.test(php), 'light gray text remapped for light backgrounds');
assert.ok(/\[data-theme="light"\] \.green\{background:#dcfce7/.test(php), 'semantic badges get light-mode variants');
assert.ok(/#failureModeLibraryBox/.test(php), 'static panel boxes get light-mode text override');
assert.ok(/\[data-theme="light"\] #scalpingSessionReadinessBox/.test(php), 'scalping profit profile panel gets light-mode contrast');

console.log('All light-mode-css tests passed.');
