const { randomUUID } = require('crypto');

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

module.exports = {
  recordConflict,
  countOpenConflicts,
  listOpenConflicts,
  markConflictResolved,
};
