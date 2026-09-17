'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
assert.ok(coreSrc.includes('function mergeJournalRowsForDisplay'), 'ledger merge helper must exist');
assert.ok(coreSrc.includes('function buildUnifiedJournalForLedger'), 'ledger must use unified merge');
assert.ok(coreSrc.includes('fnoLastGoodServerJournal'), 'ledger must cache last good server list');
assert.ok(coreSrc.includes('function reconcileLocalJournalFromServer'), 'ledger must persist server+local union');
assert.ok(!coreSrc.includes('local-${fingerprintJournalRow'), 'ledger must not show raw fingerprint ids');
assert.ok(coreSrc.includes('partial_fill_rejected'), 'all-or-nothing entry must block depth partial fills');
assert.ok(coreSrc.includes('_fnoTradeAlertVoiceQueue'), 'voice queue must be separate from immediate sound');
assert.ok(!coreSrc.includes('_fnoTradeAlertQueue'), 'serial sound+voice queue removed');

console.log('ledger-merge checks OK');
