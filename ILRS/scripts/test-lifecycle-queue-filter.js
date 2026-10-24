#!/usr/bin/env node
/** Simulates inquiry queue filtering after lifecycle change */
const assert = require('assert');
const { inferLifecycleFromInquiry } = require('../work-lifecycle');

function filterByTab(items, tab) {
  if (tab === 'all') return items;
  return items.filter((i) => inferLifecycleFromInquiry(i) === tab);
}

const inq = {
  id: '1',
  client_name: 'Test',
  outcome_status: 'active',
  lifecycle_status: 'active',
};
let pool = [inq];
assert.strictEqual(filterByTab(pool, 'active').length, 1);
assert.strictEqual(filterByTab(pool, 'pending').length, 0);
inq.lifecycle_status = 'pending';
assert.strictEqual(filterByTab(pool, 'active').length, 0);
assert.strictEqual(filterByTab(pool, 'pending').length, 1);
console.log('lifecycle-queue-filter: ok');
