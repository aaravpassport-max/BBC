/**
 * Guard against re-entrant sync while applying inbound events.
 */
const { getSetting } = require('./sync-device');

let inboundDepth = 0;

function isApplyingInbound() {
  return inboundDepth > 0;
}

function runApplyingInbound(fn) {
  inboundDepth += 1;
  try {
    return fn();
  } finally {
    inboundDepth -= 1;
  }
}

function maybeSyncEntity(db, entityKey, recordId, operation = 'upsert') {
  if (!db || !recordId || inboundDepth > 0) return;
  if (getSetting(db, 'sync_enabled', '0') !== '1') return;
  const { publishRecordChange } = require('./sync-publish');
  publishRecordChange(db, entityKey, recordId, operation);
}

module.exports = {
  isApplyingInbound,
  runApplyingInbound,
  maybeSyncEntity,
};
