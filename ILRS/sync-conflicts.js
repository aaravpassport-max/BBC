const path = require('path');
const { randomUUID } = require('crypto');
const { getEntity } = require('./sync-entities');
const { fetchRow, bumpRevision, upsertEntityRow } = require('./sync-entity-rows');
const { maybeSyncEntity, runApplyingInbound } = require('./sync-hook');
const { ensureLocalAttachmentFromDrive } = require('./sync-attachments');
const { resolveLocalPath, getLocalAttachmentsRoot } = require('./attachment-actions');

function recordConflict(db, { entity, recordId, eventId, localRevision, incomingRevision, detail }) {
  const id = randomUUID();
  db.prepare(`
    INSERT INTO sync_conflicts (
      id, entity, record_id, event_id, local_revision, incoming_revision, detail_json, created_at, resolved
    ) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), 0)
  `).run(
    id,
    entity,
    recordId,
    eventId,
    localRevision ?? 0,
    incomingRevision ?? 0,
    JSON.stringify(detail || {}),
  );
  return id;
}

function countOpenConflicts(db) {
  return db.prepare('SELECT COUNT(*) AS c FROM sync_conflicts WHERE resolved = 0').get()?.c || 0;
}

function listOpenConflicts(db, limit = 50) {
  return db.prepare(`
    SELECT * FROM sync_conflicts WHERE resolved = 0
    ORDER BY created_at DESC LIMIT ?
  `).all(limit);
}

function markConflictResolved(db, conflictId) {
  const row = db.prepare('SELECT * FROM sync_conflicts WHERE id = ?').get(conflictId);
  if (!row) return { success: false, error: 'not_found' };
  db.prepare('UPDATE sync_conflicts SET resolved = 1 WHERE id = ?').run(conflictId);
  return { success: true };
}

function applyIncomingRow(db, entity, recordId, incomingRow, incomingRevision) {
  const def = getEntity(entity);
  if (!def || !incomingRow) return { success: false, error: 'missing_row' };
  const rowToWrite = { ...incomingRow, sync_revision: incomingRevision ?? incomingRow.sync_revision ?? 1 };
  return runApplyingInbound(() => {
    const special = upsertEntityRow(db, entity, rowToWrite);
    if (!special) {
      const cols = Object.keys(rowToWrite);
      const placeholders = cols.map(() => '?').join(', ');
      const updates = cols.filter((c) => c !== def.idColumn).map((c) => `${c} = excluded.${c}`).join(', ');
      const sql = `
        INSERT INTO ${def.table} (${cols.join(', ')})
        VALUES (${placeholders})
        ON CONFLICT(${def.idColumn}) DO UPDATE SET ${updates}
      `;
      db.prepare(sql).run(...cols.map((c) => rowToWrite[c]));
    }
    if (entity === 'attachment') {
      const folder = require('./sync-device').getSetting(db, 'sync_folder_path', '');
      if (folder) {
        const localDest = path.join(getLocalAttachmentsRoot(), rowToWrite.storage_rel_path || path.join(recordId, rowToWrite.file_name));
        ensureLocalAttachmentFromDrive(folder, rowToWrite, localDest);
      }
    }
    return { success: true };
  });
}

function resolveConflictWithStrategy(db, conflictId, strategy = 'mark_resolved') {
  const row = db.prepare('SELECT * FROM sync_conflicts WHERE id = ?').get(conflictId);
  if (!row) return { success: false, error: 'not_found' };
  if (row.resolved) return { success: true, already: true };

  let detail = {};
  try {
    detail = JSON.parse(row.detail_json || '{}');
  } catch (_) { /* ignore */ }

  if (strategy === 'keep_remote' && detail.incoming_row) {
    const applied = applyIncomingRow(
      db,
      row.entity,
      row.record_id,
      detail.incoming_row,
      row.incoming_revision,
    );
    if (!applied?.success) {
      return { success: false, error: applied?.error || 'apply_failed' };
    }
  } else if (strategy === 'keep_local') {
    bumpRevision(db, row.entity, row.record_id);
    maybeSyncEntity(db, row.entity, row.record_id, 'upsert');
  }

  db.prepare('UPDATE sync_conflicts SET resolved = 1 WHERE id = ?').run(conflictId);
  return { success: true, strategy };
}

module.exports = {
  recordConflict,
  countOpenConflicts,
  listOpenConflicts,
  markConflictResolved,
  resolveConflictWithStrategy,
};
