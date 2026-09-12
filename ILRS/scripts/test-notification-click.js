#!/usr/bin/env node
const assert = require('assert');
const {
  isActivateResponse,
  registerClickHandler,
  fireClickHandler,
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

console.log('\nNotification click tests finished.');
