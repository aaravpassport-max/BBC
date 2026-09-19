/**
 * Idempotent tracking of inbound sync event files.
 */
const fs = require('fs');

function isEventProcessed(db, eventId) {
  const row = db.prepare('SELECT event_id FROM sync_processed_events WHERE event_id = ?').get(eventId);
  return Boolean(row);
}

function markEventProcessed(db, { eventId, sourceDeviceId = '', entity = '', applyStatus = 'recorded' }) {
  db.prepare(`
    INSERT OR IGNORE INTO sync_processed_events (event_id, source_device_id, entity, processed_at, apply_status)
    VALUES (?, ?, ?, datetime('now'), ?)
  `).run(eventId, sourceDeviceId, entity, applyStatus);
}

function getProcessedStats(db) {
  const total = db.prepare('SELECT COUNT(*) AS c FROM sync_processed_events').get()?.c || 0;
  const pendingApply = db.prepare(`
    SELECT COUNT(*) AS c FROM sync_processed_events WHERE apply_status = 'pending_apply'
  `).get()?.c || 0;
  return { total, pending_apply: pendingApply };
}

function safeReadEventFile(filePath) {
  try {
    const raw = fs.readFileSync(filePath, 'utf8');
    const data = JSON.parse(raw);
    if (!data || typeof data.event_id !== 'string') return null;
    if (!/^[0-9a-f-]{36}$/i.test(data.event_id)) return null;
    return data;
  } catch (_) {
    return null;
  }
}

function scanInboundEventFiles(db, changesDir, deviceId, { maxScan = 2000 } = {}) {
  const { listChangeEventFiles } = require('./sync-folder');
  const files = listChangeEventFiles(changesDir, { maxFiles: maxScan });
  let discovered = 0;
  let recorded = 0;
  let skippedOwn = 0;

  for (const filePath of files) {
    const event = safeReadEventFile(filePath);
    if (!event) continue;
    discovered += 1;
    if (event.device_id === deviceId) {
      skippedOwn += 1;
      continue;
    }
    if (isEventProcessed(db, event.event_id)) continue;
    markEventProcessed(db, {
      eventId: event.event_id,
      sourceDeviceId: event.device_id || '',
      entity: event.entity || '',
      applyStatus: 'pending_apply',
    });
    recorded += 1;
  }

  return { discovered, recorded, skipped_own: skippedOwn, files_scanned: files.length };
}

module.exports = {
  isEventProcessed,
  markEventProcessed,
  getProcessedStats,
  scanInboundEventFiles,
  safeReadEventFile,
};
