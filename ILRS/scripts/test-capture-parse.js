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
assert.equal(C.isPostponedItem({ status: 'active', workflow_status: 'postponed', next_fire: '2026-09-20T09:00:00' }), true);
assert.equal(C.isPostponedItem({ status: 'active', workflow_status: 'pending', next_fire: '2026-09-20T09:00:00' }), false);
assert.equal(C.isPostponedItem({ status: 'completed', workflow_status: 'postponed' }), false);

assert.equal(C.nextFireDateStr('2026-09-14T10:00:00'), '2026-09-14');
assert.equal(
  C.isScheduledOnDate({ status: 'active', next_fire: '2026-09-14T10:00:00', start_date: '2026-09-01' }, '2026-09-14'),
  true,
);
assert.equal(
  C.isScheduledOnDate({ status: 'active', next_fire: '2026-09-14T10:00:00', start_date: '2026-09-01' }, '2026-09-01'),
  false,
);
assert.equal(
  C.isScheduledInMonth({ status: 'active', next_fire: '2026-09-20T09:00:00' }, '2026-09'),
  true,
);
assert.equal(
  C.isScheduledOnDate({ status: 'completed', next_fire: '2026-09-14T10:00:00' }, '2026-09-14'),
  false,
);

assert.deepEqual(
  C.resolveWhenFromExisting({ start_date: '2026-09-25', next_fire: '2026-09-25T10:00:00' }, now),
  { when: 'custom', startDate: '2026-09-25' },
);
assert.deepEqual(
  C.resolveWhenFromExisting({ start_date: '2026-09-14' }, now),
  { when: 'tomorrow', startDate: '2026-09-14' },
);
assert.deepEqual(
  C.resolveWhenFromExisting({ next_fire: '2026-09-13T09:00:00' }, now),
  { when: 'today', startDate: '2026-09-13' },
);

const { execSync } = require('child_process');
for (const file of ['capture-ui.js', 'inquiry-ui.js', 'app.js']) {
  execSync(`node --check ${path.join(__dirname, '..', 'src', file)}`, { stdio: 'pipe' });
}

console.log('✅ capture parse tests passed');
