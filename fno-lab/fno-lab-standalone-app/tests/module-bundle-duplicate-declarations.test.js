const fs = require('fs');
const path = require('path');

const core = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const diag = fs.readFileSync(path.join(__dirname, '../assets/strategy-diagnostic-report.js'), 'utf8');

const count = (src, name) => (src.match(new RegExp(`function ${name}\\(`, 'g')) || []).length;

if (count(core, 'getDecisionLogSetupDecision') !== 1) {
  throw new Error(`expected exactly one getDecisionLogSetupDecision in fno-lab-core.js, got ${count(core, 'getDecisionLogSetupDecision')}`);
}
if (count(diag, 'getDecisionLogSetupDecision') !== 0) {
  throw new Error('getDecisionLogSetupDecision must not be redeclared in strategy-diagnostic-report.js (same script module as core)');
}
console.log('module-bundle-duplicate-declarations.test.js: passed');
