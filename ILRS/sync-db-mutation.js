/**
 * Best-effort sync enqueue after renderer db-query mutations (tasks/reminders UI).
 */
const { isApplyingInbound } = require('./sync-hook');

const SYNC_TABLES = {
  reminders: 'reminder',
  reminder_logs: 'reminder_log',
};

function extractMutationTarget(sql, params) {
  const normalized = sql.replace(/\s+/g, ' ').trim();
  const upper = normalized.toUpperCase();
  const p = params || [];

  let table = null;
  if (upper.startsWith('INSERT INTO ')) {
    table = normalized.match(/^INSERT\s+INTO\s+([a-z_]+)/i)?.[1];
    if (table && SYNC_TABLES[table]) {
      const id = p[0];
      return id ? { entity: SYNC_TABLES[table], recordId: String(id) } : null;
    }
  }

  if (/^UPDATE\s+reminders\b/i.test(normalized)) {
    const id = p[p.length - 1];
    return id ? { entity: 'reminder', recordId: String(id) } : null;
  }

  if (/^UPDATE\s+reminder_logs\b/i.test(normalized)) {
    const id = p[p.length - 1];
    return id ? { entity: 'reminder_log', recordId: String(id) } : null;
  }

  if (upper.startsWith('DELETE FROM REMINDERS')) {
    const id = p[0];
    return id ? { entity: 'reminder', recordId: String(id), operation: 'delete' } : null;
  }

  return null;
}

function maybeSyncAfterDbMutation(db, sql, params) {
  if (!db || isApplyingInbound()) return;
  const target = extractMutationTarget(sql, params);
  if (!target) return;
  const { maybeSyncEntity } = require('./sync-hook');
  const { publishRecordChange } = require('./sync-publish');
  if (target.operation === 'delete') {
    publishRecordChange(db, target.entity, target.recordId, 'delete');
    return;
  }
  maybeSyncEntity(db, target.entity, target.recordId);
}

module.exports = {
  maybeSyncAfterDbMutation,
};
