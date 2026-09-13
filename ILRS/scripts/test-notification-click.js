#!/usr/bin/env node
const assert = require('assert');
const {
  isActivateResponse,
  isActionResponse,
  registerClickHandler,
  registerActionHandler,
  fireClickHandler,
  fireActionHandler,
} = require('../notifications');

function test(name, fn) {
  try {
    fn();
    console.log(`✅ ${name}`);
  } catch (err) {
    console.error(`❌ ${name}: ${err.message}`);
    process.exitCode = 1;
  }
}

test('isActivateResponse accepts Windows toast responses', () => {
  assert.strictEqual(isActivateResponse('activate'), true);
  assert.strictEqual(isActivateResponse('clicked'), true);
  assert.strictEqual(isActivateResponse('userAction'), true);
  assert.strictEqual(isActivateResponse('dismissed'), false);
});

test('registerClickHandler fires once', () => {
  let count = 0;
  registerClickHandler('test-key', () => { count += 1; });
  assert.strictEqual(fireClickHandler('test-key'), true);
  assert.strictEqual(count, 1);
  assert.strictEqual(fireClickHandler('test-key'), false);
});

test('isActionResponse detects toast action labels', () => {
  assert.strictEqual(isActionResponse('Done'), true);
  assert.strictEqual(isActionResponse('Snooze'), true);
  assert.strictEqual(isActionResponse('dismissed'), false);
});

test('registerActionHandler fires action and clears click handler', () => {
  let action = '';
  let clicked = false;
  registerClickHandler('action-key', () => { clicked = true; });
  registerActionHandler('action-key', (a) => { action = a; });
  assert.strictEqual(fireActionHandler('action-key', 'Done'), true);
  assert.strictEqual(action, 'Done');
  assert.strictEqual(fireClickHandler('action-key'), false);
  assert.strictEqual(clicked, false);
});

console.log('\nNotification click tests finished.');
