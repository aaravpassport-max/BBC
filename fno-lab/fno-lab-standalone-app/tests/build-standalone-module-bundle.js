'use strict';
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..', 'assets');
const files = [
  'fno-lab-core.js',
  'trading-modes-engine.js',
  'liquidity-behaviour-engine.js',
  'trade-setup-engine.js',
  'scalping-profit-engine.js',
  'first-momentum-scalper-engine.js',
  'strategy-diagnostic-report.js',
];
const out = path.join(__dirname, 'fixtures', 'standalone-full-module.js');
let combined = '';
for (const f of files) {
  combined += fs.readFileSync(path.join(root, f), 'utf8') + '\n';
}
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, combined);
const { execSync } = require('child_process');
execSync(`node --check "${out}"`, { stdio: 'pipe' });
console.log('standalone-full-module.js OK', combined.length, 'bytes');
