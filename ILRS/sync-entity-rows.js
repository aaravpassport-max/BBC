/**
 * Row fetch / revision bump for entities with non-standard primary keys.
 */
const { getEntity } = require('./sync-entities');

function parseWorkflowStageRecordId(recordId) {
  const idx = recordId.indexOf(':');
  if (idx <= 0) return null;
  return { entity_type: recordId.slice(0, idx), key: recordId.slice(idx + 1) };
}

function fetchRow(db, entityKey, recordId) {
  const def = getEntity(entityKey);
  if (!def) return null;
  if (entityKey === 'workflow_stage') {
    const parts = parseWorkflowStageRecordId(recordId);
    if (!parts) return null;
    return db.prepare('SELECT * FROM workflow_stages WHERE entity_type = ? AND key = ?').get(parts.entity_type, parts.key);
  }
  return db.prepare(`SELECT * FROM ${def.table} WHERE ${def.idColumn} = ?`).get(recordId);
}

function bumpRevision(db, entityKey, recordId) {
  const def = getEntity(entityKey);
  if (!def) return;
  if (entityKey === 'workflow_stage') {
    const parts = parseWorkflowStageRecordId(recordId);
    if (!parts) return;
    db.prepare(`
      UPDATE workflow_stages SET sync_revision = COALESCE(sync_revision, 0) + 1
      WHERE entity_type = ? AND key = ?
    `).run(parts.entity_type, parts.key);
    return;
  }
  db.prepare(`
    UPDATE ${def.table} SET sync_revision = COALESCE(sync_revision, 0) + 1
    WHERE ${def.idColumn} = ?
  `).run(recordId);
}

function deleteEntityRow(db, entityKey, recordId) {
  const def = getEntity(entityKey);
  if (!def) return { success: false };
  if (entityKey === 'workflow_stage') {
    const parts = parseWorkflowStageRecordId(recordId);
    if (!parts) return { success: false };
    db.prepare('DELETE FROM workflow_stages WHERE entity_type = ? AND key = ?').run(parts.entity_type, parts.key);
    return { success: true };
  }
  if (def.table === 'inquiry_stages') {
    db.prepare(`DELETE FROM ${def.table} WHERE ${def.idColumn} = ?`).run(recordId);
    return { success: true };
  }
  if (def.table === 'inquiry_templates') {
    db.prepare(`DELETE FROM ${def.table} WHERE ${def.idColumn} = ?`).run(recordId);
    return { success: true };
  }
  return null;
}

function upsertEntityRow(db, entityKey, row) {
  const def = getEntity(entityKey);
  if (!def) return { success: false, error: 'unknown_entity' };
  if (entityKey === 'workflow_stage') {
    const cols = Object.keys(row);
    const placeholders = cols.map(() => '?').join(', ');
    const updates = cols.filter((c) => c !== 'key' && c !== 'entity_type').map((c) => `${c} = excluded.${c}`).join(', ');
    const sql = `
      INSERT INTO workflow_stages (${cols.join(', ')})
      VALUES (${placeholders})
      ON CONFLICT(key, entity_type) DO UPDATE SET ${updates}
    `;
    db.prepare(sql).run(...cols.map((c) => row[c]));
    return { success: true };
  }
  return null;
}

module.exports = {
  fetchRow,
  bumpRevision,
  deleteEntityRow,
  upsertEntityRow,
  parseWorkflowStageRecordId,
};
