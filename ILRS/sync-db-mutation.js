/**
 * Best-effort sync enqueue after renderer db-query mutations.
 */
const { isApplyingInbound } = require('./sync-hook');

const SYNC_TABLES = {
  reminders: 'reminder',
  reminder_logs: 'reminder_log',
  medicines: 'medicine',
  medicine_logs: 'medicine_log',
  bills: 'bill',
  bill_history: 'bill_history',
  habits: 'habit',
  habit_logs: 'habit_log',
  family_members: 'family_member',
  checklists: 'checklist',
  checklist_items: 'checklist_item',
  inquiry_stages: 'inquiry_stage',
  inquiry_templates: 'inquiry_template',
};

function extractMutationTarget(sql, params) {
  const normalized = sql.replace(/\s+/g, ' ').trim();
  const p = params || [];

  const insertMatch = normalized.match(/^INSERT\s+(?:OR\s+REPLACE\s+)?INTO\s+([a-z_]+)\b/i);
  if (insertMatch) {
    const table = insertMatch[1];
    if (SYNC_TABLES[table] && p[0]) {
      return { entity: SYNC_TABLES[table], recordId: String(p[0]) };
    }
  }

  const updateMatch = normalized.match(/^UPDATE\s+([a-z_]+)\b/i);
  if (updateMatch) {
    const table = updateMatch[1];
    if (SYNC_TABLES[table] && p.length > 0) {
      const id = p[p.length - 1];
      return id ? { entity: SYNC_TABLES[table], recordId: String(id) } : null;
    }
  }

  const deleteMatch = normalized.match(/^DELETE\s+FROM\s+([a-z_]+)\b/i);
  if (deleteMatch) {
    const table = deleteMatch[1];
    if (SYNC_TABLES[table] && p[0]) {
      return { entity: SYNC_TABLES[table], recordId: String(p[0]), operation: 'delete' };
    }
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
  SYNC_TABLES,
};
