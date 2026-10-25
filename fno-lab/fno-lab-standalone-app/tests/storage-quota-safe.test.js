'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

assert.match(coreSrc, /function safeStorageSetItem\(/);
assert.match(coreSrc, /function attemptStorageQuotaRecovery\(/);
assert.match(coreSrc, /safeStorageSetItem\('fno_autonomous_paper_default_v16377'/);
assert.match(coreSrc, /fnoSettings\.set failed \(storage full\)/);

console.log('storage-quota-safe tests passed.');
