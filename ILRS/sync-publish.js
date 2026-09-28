const { getDeviceContext } = require('./sync-device');
const { getEntity } = require('./sync-entities');
const { enqueueChange } = require('./sync-outbox');
const { fetchRow, bumpRevision } = require('./sync-entity-rows');

function publishRecordChange(db, entityKey, recordId, operation = 'upsert') {
  const def = getEntity(entityKey);
  if (!def) return null;

  let row = fetchRow(db, entityKey, recordId);

  if (operation === 'upsert') {
    if (!row) return null;
    bumpRevision(db, entityKey, recordId);
    row = fetchRow(db, entityKey, recordId);
  }

  if (!row && operation === 'delete') {
    if (entityKey === 'workflow_stage') {
      const parts = require('./sync-entity-rows').parseWorkflowStageRecordId(recordId);
      row = parts ? { key: parts.key, entity_type: parts.entity_type } : null;
    } else {
      row = { [def.idColumn]: recordId };
    }
  }
  if (!row) return null;

  const newRevision = operation === 'delete'
    ? (row.sync_revision ?? 0) + 1
    : (row.sync_revision ?? 0);
  const payload = {
    row: row || { [def.idColumn]: recordId },
    new_revision: newRevision,
    base_revision: Math.max(0, newRevision - 1),
  };

  const deviceContext = getDeviceContext(db);
  return enqueueChange(db, {
    entity: entityKey,
    recordId,
    operation,
    payload,
    deviceContext,
    appVersion: process.env.ILRS_APP_VERSION || '',
  });
}

module.exports = {
  publishRecordChange,
};
