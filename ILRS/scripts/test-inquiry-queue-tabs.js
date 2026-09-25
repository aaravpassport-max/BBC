#!/usr/bin/env node
const assert = require('assert');
const {
  inferInquiryQueueTab,
  QUEUE_TAB_IN_PROCESS,
  LIFECYCLE_ACTIVE,
  LIFECYCLE_PENDING,
} = require('../work-lifecycle');

const newInq = {
  lifecycle_status: 'active',
  outcome_status: 'active',
  stage_key: 'follow_up',
  work_phase: 'new',
};
assert.strictEqual(inferInquiryQueueTab(newInq), LIFECYCLE_ACTIVE, 'new enquiry → Act now');

const inProcess = {
  ...newInq,
  work_phase: 'in_process',
  work_start_date: '2026-09-18',
};
assert.strictEqual(inferInquiryQueueTab(inProcess), QUEUE_TAB_IN_PROCESS);

const byStage = {
  ...newInq,
  stage_key: 'quotation_sent',
  work_phase: 'new',
};
assert.strictEqual(inferInquiryQueueTab(byStage), QUEUE_TAB_IN_PROCESS, 'past qualification → In process');

const waiting = { ...newInq, lifecycle_status: 'pending' };
assert.strictEqual(inferInquiryQueueTab(waiting), LIFECYCLE_PENDING);

const explicitActNow = {
  ...byStage,
  work_phase: 'act_now',
  work_start_date: '2026-09-01',
};
assert.strictEqual(inferInquiryQueueTab(explicitActNow), LIFECYCLE_ACTIVE, 'act_now phase → Act now tab');

console.log('inquiry-queue-tabs: ok');
