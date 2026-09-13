#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const code = fs.readFileSync(path.join(__dirname, '..', 'src', 'capture.js'), 'utf8');
const sandbox = { window: {} };
vm.runInNewContext(code, sandbox);
const C = sandbox.window.ILRSCapture;

const now = new Date(2026, 8, 13, 8, 0, 0); // Sep 13 2026 8am

const p1 = C.parseReminderText('Call John tomorrow at 10am', now);
assert.equal(p1.startDate, '2026-09-14');
assert.equal(p1.time, '10:00');
assert.ok(p1.title.toLowerCase().includes('call john'));
assert.ok(!p1.title.toLowerCase().includes('tomorrow'));

const p2 = C.parseReminderText('Take medicine daily at 8pm', now);
assert.equal(p2.repeatType, 'daily');
assert.equal(p2.time, '20:00');
assert.equal(p2.category, 'medicine');

const p3 = C.parseReminderText('Pay bill next week', now);
assert.equal(p3.startDate, '2026-09-20');

assert.equal(C.isDueToday({ status: 'active', next_fire: '2026-09-13T10:00:00' }, now), true);
assert.equal(C.isDueTomorrow({ status: 'active', next_fire: '2026-09-14T09:00:00' }, now), true);
assert.equal(C.isOverdueItem({ status: 'active', next_fire: '2026-09-12T09:00:00' }, now), true);

console.log('✅ capture parse tests passed');
