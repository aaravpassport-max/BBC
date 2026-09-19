/**
 * Durable local sync outbox (survives restarts).
 */
const { randomUUID, createHash } = require('crypto');
const path = require('path');
const { OUTBOX_STATUS, SYNC_EVENT_SCHEMA_VERSION } = require('./sync-constants');
const { changesPathForToday, writeAtomicJson } = require('./sync-folder');

function enqueueChange(db, { entity, recordId, operation, payload, deviceContext, appVersion }) {
  const eventId = randomUUID();
  const id = randomUUID();
  const payloadJson = JSON.stringify(payload ?? {});
  db.prepare(`
    INSERT INTO sync_outbox (
      id, event_id, entity, record_id, operation, payload_json, status, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
  `).run(id, eventId, entity, recordId, operation, payloadJson, OUTBOX_STATUS.PENDING);

  return { id, event_id: eventId, entity, record_id: recordId, operation, payload };
}

function getOutboxStats(db) {
  const rows = db.prepare(`
    SELECT status, COUNT(*) AS c FROM sync_outbox GROUP BY status
  `).all();
  const stats = { pending: 0, publishing: 0, published: 0, failed: 0, total: 0 };
  for (const r of rows) {
    const key = r.status in stats ? r.status : 'other';
    if (key in stats) stats[key] = r.c;
    stats.total += r.c;
  }
  return stats;
}

function listPendingOutbox(db, limit = 50) {
  return db.prepare(`
    SELECT * FROM sync_outbox
    WHERE status IN ('pending', 'failed')
    ORDER BY created_at ASC
    LIMIT ?
  `).all(limit);
}

function markOutboxStatus(db, outboxId, status, { error = '', publishedAt = null } = {}) {
  db.prepare(`
    UPDATE sync_outbox
    SET status = ?, error = ?, attempts = attempts + 1,
        published_at = COALESCE(?, published_at)
    WHERE id = ?
  `).run(status, error || '', publishedAt, outboxId);
}

function buildEventFile(row, deviceContext, appVersion) {
  const payload = JSON.parse(row.payload_json || '{}');
  const payloadHash = createHash('sha256').update(row.payload_json || '{}').digest('hex');
  return {
    schema_version: SYNC_EVENT_SCHEMA_VERSION,
    event_id: row.event_id,
    entity: row.entity,
    record_id: row.record_id,
    operation: row.operation,
    device_id: deviceContext.device_id,
    device_name: deviceContext.device_name,
    user_id: deviceContext.user_id,
    user_display: deviceContext.user_display,
    office_id: deviceContext.office_id,
    office_label: deviceContext.office_label,
    client_timestamp: new Date().toISOString(),
    base_revision: payload.base_revision ?? null,
    new_revision: payload.new_revision ?? null,
    payload,
    payload_hash: payloadHash,
    app_version: appVersion,
  };
}

function publishPendingOutbox(db, syncFolderRoot, deviceContext, appVersion, { batchSize = 25 } = {}) {
  if (!syncFolderRoot) {
    return { success: false, error: 'Sync folder not configured', published: 0 };
  }
  const pending = listPendingOutbox(db, batchSize);
  let published = 0;
  const errors = [];

  for (const row of pending) {
    markOutboxStatus(db, row.id, OUTBOX_STATUS.PUBLISHING);
    try {
      if (row.entity === 'attachment') {
        const { fetchRow } = require('./sync-entity-rows');
        const { resolveLocalPath } = require('./attachment-actions');
        const { ensureAttachmentOnDrive } = require('./sync-attachments');
        const attRow = fetchRow(db, 'attachment', row.record_id);
        const localPath = resolveLocalPath(db, row.record_id);
        if (attRow && localPath) {
          ensureAttachmentOnDrive(syncFolderRoot, localPath, attRow);
        }
      }
      const event = buildEventFile(row, deviceContext, appVersion);
      const dir = changesPathForToday(syncFolderRoot);
      const filePath = path.join(dir, `${event.event_id}.json`);
      writeAtomicJson(filePath, event);
      markOutboxStatus(db, row.id, OUTBOX_STATUS.PUBLISHED, {
        publishedAt: new Date().toISOString().replace('T', ' ').slice(0, 19),
      });
      published += 1;
    } catch (err) {
      markOutboxStatus(db, row.id, OUTBOX_STATUS.FAILED, { error: err.message });
      errors.push({ event_id: row.event_id, error: err.message });
    }
  }

  return { success: errors.length === 0, published, errors };
}

module.exports = {
  enqueueChange,
  getOutboxStats,
  listPendingOutbox,
  publishPendingOutbox,
  buildEventFile,
  markOutboxStatus,
};
