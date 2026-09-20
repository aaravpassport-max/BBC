#!/usr/bin/env node
const assert = require('assert');
const { buildIndianEnglishAnnouncement, getAnnouncementDisplayText } = require('../reminder-voice');

function test(name, fn) {
  try {
    fn();
    console.log(`✅ ${name}`);
  } catch (err) {
    console.error(`❌ ${name}: ${err.message}`);
    process.exitCode = 1;
  }
}

test('medicine announcement uses natural Indian English', () => {
  const text = buildIndianEnglishAnnouncement({
    name: 'Metformin',
    food_timing: 'after',
    why_it_matters: 'For diabetes control.',
  }, 'medicine');
  assert.ok(text.includes('Time to take your medicine'));
  assert.ok(text.includes('after food'));
  assert.ok(!text.includes('Kindly be advised'));
});

test('bill announcement mentions rupees and late fee', () => {
  const text = buildIndianEnglishAnnouncement({ name: 'Electricity', amount: 1200 }, 'bill');
  assert.ok(text.includes('rupees'));
  assert.ok(text.includes('late fee'));
});

test('critical reminder is direct but not robotic', () => {
  const text = buildIndianEnglishAnnouncement({
    title: 'Pay school fees',
    why_it_matters: 'Last date is today.',
    priority: 'critical',
  }, 'reminder');
  assert.ok(text.includes('Important reminder'));
  assert.ok(text.includes('right away'));
});

test('display text falls back to why_it_matters', () => {
  const text = getAnnouncementDisplayText({ title: 'Walk', why_it_matters: 'Daily exercise helps.' }, 'reminder');
  assert.ok(text.includes('Walk'));
  assert.ok(text.includes('exercise'));
});

console.log('\nVoice text tests finished.');
