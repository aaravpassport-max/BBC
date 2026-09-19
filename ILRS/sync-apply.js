const { createHash } = require('crypto');
const { getEntity } = require('./sync-entities');
const { recordConflict } = require('./sync-conflicts');
const { isEventProcessed, markEventProcessed } = require('./sync-processed');
const { runApplyingInbound } = require('./sync-hook');
const { safeReadEventFile } = require('./sync-processed');
const { listChangeEventFiles } = require('./sync-folder');

const tableColumnsCache = new Map();

function getTableColumns(db, table) {
  if (tableColumnsCache.has(table)) return tableColumnsCache.get(table);
  const cols = db.prepare(`PRAGMA table_info(${table})`).all().map((r) => r.name);
  tableColumnsCache.set(table, cols);
  return cols;
}

function verifyPayloadHash(event) {
  if (!event.payload_hash || !event.payload) return true;
  const payloadJson = JSON.stringify(event.payload);
  const hash = createHash('sha256').update(payloadJson).digest('hex');
  return hash === event.payload_hash;
}

function checkDependencies(db, entityKey, row) {
  const def = getEntity(entityKey);
  if (!def) return { ok: false, reason: 'unknown_entity' };
  for (const dep of def.dependencies || []) {
    if (dep.when && !dep.when(row)) continue;
    const fk = row[dep.column];
    if (!fk) {
      if (dep.optional) continue;
      return { ok: false, reason: 'missing_dependency', column: dep.column };
    }
    const parent = db.prepare(`SELECT 1 FROM ${dep.table} WHERE id = ?`).get(fk);
    if (!parent) return { ok: false, reason: 'waiting_dependency', column: dep.column, parentId: fk };
  }
  return { ok: true };
}

function filterRowForTable(db, table, row) {
  const allowed = new Set(getTableColumns(db, table));
  const out = {};
  for (const [k, v] of Object.entries(row || {})) {
    if (allowed.has(k)) out[k] = v;
  }
  return out;
}

function upsertRow(db, table, idColumn, row) {
  const filtered = filterRowForTable(db, table, row);
  if (!filtered[idColumn]) return { success: false, error: 'missing_id' };
  const cols = Object.keys(filtered);
  const placeholders = cols.map(() => '?').join(', ');
  const updates = cols.filter((c) => c !== idColumn).map((c) => `${c} = excluded.${c}`).join(', ');
  const sql = `
    INSERT INTO ${table} (${cols.join(', ')})
    VALUES (${placeholders})
    ON CONFLICT(${idColumn}) DO UPDATE SET ${updates}
  `;
  try {
    db.prepare(sql).run(...cols.map((c) => filtered[c]));
    return { success: true };
  } catch (err) {
    if (table === 'inquiries' && /UNIQUE constraint failed: inquiries.inquiry_number/i.test(err.message)) {
      const alt = { ...filtered, inquiry_number: `${filtered.inquiry_number || 'INQ'}-${String(filtered.id).slice(0, 8)}` };
      const cols2 = Object.keys(alt);
      const sql2 = `
        INSERT INTO ${table} (${cols2.join(', ')})
        VALUES (${cols2.map(() => '?').join(', ')})
        ON CONFLICT(${idColumn}) DO UPDATE SET ${cols2.filter((c) => c !== idColumn).map((c) => `${c} = excluded.${c}`).join(', ')}
      `;
      db.prepare(sql2).run(...cols2.map((c) => alt[c]));
      return { success: true, inquiry_number_adjusted: true };
    }
    return { success: false, error: err.message };
  }
}

function deleteRow(db, table, idColumn, recordId) {
  if (table === 'inquiries') {
    db.prepare(`UPDATE ${table} SET outcome_status = 'deleted', updated_at = datetime('now') WHERE ${idColumn} = ?`).run(recordId);
    return { success: true };
  }
  if (table === 'work_payments') {
    db.prepare(`DELETE FROM ${table} WHERE ${idColumn} = ?`).run(recordId);
    return { success: true };
  }
  db.prepare(`DELETE FROM ${table} WHERE ${idColumn} = ?`).run(recordId);
  return { success: true };
}

function applySyncEvent(db, event, deviceId) {
  if (!event?.event_id || !event.entity) return { status: 'invalid' };
  if (event.device_id === deviceId) return { status: 'skipped_own' };
  if (isEventProcessed(db, event.event_id)) return { status: 'duplicate' };
  if (!verifyPayloadHash(event)) return { status: 'invalid_hash' };

  const def = getEntity(event.entity);
  if (!def) return { status: 'unknown_entity' };

  const payload = event.payload || {};
  const row = payload.row || payload;
  const recordId = event.record_id || row[def.idColumn];
  const incomingRevision = event.new_revision ?? payload.new_revision ?? 0;
  const baseRevision = event.base_revision ?? payload.base_revision;

  if (event.operation === 'delete') {
    return runApplyingInbound(() => {
      deleteRow(db, def.table, def.idColumn, recordId);
      markEventProcessed(db, {
        eventId: event.event_id,
        sourceDeviceId: event.device_id || '',
        entity: event.entity,
        applyStatus: 'applied',
      });
      return { status: 'applied', operation: 'delete', record_id: recordId };
    });
  }

  const dep = checkDependencies(db, event.entity, row);
  if (!dep.ok) {
    if (dep.reason === 'waiting_dependency' || dep.reason === 'missing_dependency') {
      markEventProcessed(db, {
        eventId: event.event_id,
        sourceDeviceId: event.device_id || '',
        entity: event.entity,
        applyStatus: 'pending_apply',
      });
      return { status: 'pending_dependency', detail: dep };
    }
    return { status: 'rejected', detail: dep };
  }

  const local = db.prepare(`SELECT * FROM ${def.table} WHERE ${def.idColumn} = ?`).get(recordId);
  const localRevision = local?.sync_revision ?? 0;

  if (local && incomingRevision <= localRevision) {
    markEventProcessed(db, {
      eventId: event.event_id,
      sourceDeviceId: event.device_id || '',
      entity: event.entity,
      applyStatus: 'applied',
    });
    return { status: 'skipped_stale', record_id: recordId };
  }

  if (
    local
    && baseRevision != null
    && baseRevision < localRevision
    && incomingRevision > localRevision
  ) {
    recordConflict(db, {
      entity: event.entity,
      recordId,
      eventId: event.event_id,
      localRevision,
      incomingRevision,
      detail: { base_revision: baseRevision, device_id: event.device_id },
    });
    markEventProcessed(db, {
      eventId: event.event_id,
      sourceDeviceId: event.device_id || '',
      entity: event.entity,
      applyStatus: 'conflict',
    });
    return { status: 'conflict', record_id: recordId };
  }

  const rowToWrite = { ...row, sync_revision: incomingRevision };

  return runApplyingInbound(() => {
    const result = upsertRow(db, def.table, def.idColumn, rowToWrite);
    if (!result.success) {
      return { status: 'error', error: result.error };
    }
    markEventProcessed(db, {
      eventId: event.event_id,
      sourceDeviceId: event.device_id || '',
      entity: event.entity,
      applyStatus: 'applied',
    });
    return { status: 'applied', record_id: recordId, entity: event.entity };
  });
}

function retryPendingApply(db, changesDir, deviceId, { maxFiles = 3000 } = {}) {
  const pending = db.prepare(`
    SELECT event_id FROM sync_processed_events WHERE apply_status = 'pending_apply' LIMIT 500
  `).all();
  if (!pending.length) return { retried: 0, applied: 0 };

  const files = listChangeEventFiles(changesDir, { maxFiles });
  const byEventId = new Map();
  for (const filePath of files) {
    const ev = safeReadEventFile(filePath);
    if (ev?.event_id) byEventId.set(ev.event_id, ev);
  }

  let applied = 0;
  for (const row of pending) {
    const ev = byEventId.get(row.event_id);
    if (!ev) continue;
    db.prepare('DELETE FROM sync_processed_events WHERE event_id = ?').run(row.event_id);
    const r = applySyncEvent(db, ev, deviceId);
    if (r.status === 'applied') applied += 1;
  }
  return { retried: pending.length, applied };
}

function applyInboundFromFolder(db, changesDir, deviceId, { maxScan = 3000 } = {}) {
  const files = listChangeEventFiles(changesDir, { maxFiles: maxScan });
  const stats = {
    scanned: files.length,
    applied: 0,
    pending_dependency: 0,
    conflict: 0,
    duplicate: 0,
    skipped_stale: 0,
    invalid: 0,
  };

  const sorted = files.slice().sort();
  for (const filePath of sorted) {
    const event = safeReadEventFile(filePath);
    if (!event) {
      stats.invalid += 1;
      continue;
    }
    const result = applySyncEvent(db, event, deviceId);
    switch (result.status) {
      case 'applied':
        stats.applied += 1;
        break;
      case 'pending_dependency':
        stats.pending_dependency += 1;
        break;
      case 'conflict':
        stats.conflict += 1;
        break;
      case 'duplicate':
      case 'skipped_own':
        stats.duplicate += 1;
        break;
      case 'skipped_stale':
        stats.skipped_stale += 1;
        break;
      default:
        break;
    }
  }

  const retry = retryPendingApply(db, changesDir, deviceId, { maxFiles: maxScan });
  stats.retry_applied = retry.applied;

  return stats;
}

module.exports = {
  applySyncEvent,
  applyInboundFromFolder,
  retryPendingApply,
  getTableColumns,
};
