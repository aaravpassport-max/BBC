#!/usr/bin/env node
const assert = require('assert');
const {
  isInquiryDueToday,
  isInquiryDueTomorrow,
  isInquiryUpcoming,
  isInquiryFollowUpOverdue,
} = require('../work-scheduling');

function test(name, fn) {
  try {
    fn();
    console.log(`✅ ${name}`);
  } catch (err) {
    console.error(`❌ ${name}: ${err.message}`);
    process.exitCode = 1;
  }
}

const now = new Date(2026, 8, 14, 10, 0, 0); // 2026-09-14
const active = { outcome_status: 'active' };

test('today tab includes inquiry scheduled for today', () => {
  const inq = { ...active, next_follow_up: '2026-09-14' };
  assert.strictEqual(isInquiryDueToday(inq, now), true);
  assert.strictEqual(isInquiryDueTomorrow(inq, now), false);
  assert.strictEqual(isInquiryUpcoming(inq, now), false);
});

test('tomorrow tab includes inquiry scheduled for tomorrow', () => {
  const inq = { ...active, next_follow_up: '2026-09-15' };
  assert.strictEqual(isInquiryDueTomorrow(inq, now), true);
  assert.strictEqual(isInquiryDueToday(inq, now), false);
  assert.strictEqual(isInquiryUpcoming(inq, now), false);
});

test('upcoming tab includes future inquiries after tomorrow', () => {
  const inq = { ...active, next_follow_up: '2026-09-20' };
  assert.strictEqual(isInquiryUpcoming(inq, now), true);
  assert.strictEqual(isInquiryDueTomorrow(inq, now), false);
});

test('overdue tab includes past follow-up dates', () => {
  const inq = { ...active, next_follow_up: '2026-09-10' };
  assert.strictEqual(isInquiryFollowUpOverdue(inq, now), true);
  assert.strictEqual(isInquiryDueToday(inq, now), false);
});

test('closed inquiries are excluded from schedule views', () => {
  const inq = { outcome_status: 'closed_lost', next_follow_up: '2026-09-15' };
  assert.strictEqual(isInquiryDueTomorrow(inq, now), false);
  assert.strictEqual(isInquiryUpcoming(inq, now), false);
});
