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

module.exports = {
  recordConflict,
  countOpenConflicts,
};
