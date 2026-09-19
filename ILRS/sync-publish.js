const { getDeviceContext } = require('./sync-device');
const { getEntity } = require('./sync-entities');
const { enqueueChange } = require('./sync-outbox');

function bumpRevision(db, table, idColumn, recordId) {
  db.prepare(`
    UPDATE ${table}
    SET sync_revision = COALESCE(sync_revision, 0) + 1
    WHERE ${idColumn} = ?
  `).run(recordId);
}

function publishRecordChange(db, entityKey, recordId, operation = 'upsert') {
  const def = getEntity(entityKey);
  if (!def) return null;

  let row = db.prepare(`SELECT * FROM ${def.table} WHERE ${def.idColumn} = ?`).get(recordId);

  if (operation === 'upsert') {
    if (!row) return null;
    bumpRevision(db, def.table, def.idColumn, recordId);
    row = db.prepare(`SELECT * FROM ${def.table} WHERE ${def.idColumn} = ?`).get(recordId);
  }

  if (!row && operation === 'delete') {
    row = { [def.idColumn]: recordId };
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
  bumpRevision,
  publishRecordChange,
};
