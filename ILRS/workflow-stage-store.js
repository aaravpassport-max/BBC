/**
 * Workflow stages for tasks, reminders (workflow_stages table).
 * Inquiries continue using inquiry_stages via inquiry-stage-store.
 */
const { defaultStages } = require('./workflow-stage-pipeline');

function rowToStage(row) {
  let fields = [];
  let automation = {};
  try { fields = JSON.parse(row.fields_json || '[]'); } catch (_) { /* ignore */ }
  try { automation = JSON.parse(row.automation_json || '{}'); } catch (_) { /* ignore */ }
  return {
    key: row.key,
    entityType: row.entity_type,
    display: row.display_name,
    category: row.category,
    sort: row.sort_order,
    closed: !!row.is_closed,
    color: row.color || '',
    fields,
    automation,
  };
}

function loadWorkflowStages(db, entityType) {
  const rows = db.prepare(
    'SELECT * FROM workflow_stages WHERE entity_type = ? ORDER BY sort_order ASC, display_name ASC',
  ).all(entityType);
  if (!rows.length) {
    return defaultStages(entityType).map((s) => ({
      ...s,
      entityType,
      fields: s.fields || [],
      automation: s.automation || {},
    }));
  }
  return rows.map(rowToStage);
}

function saveWorkflowStage(db, stage) {
  if (!stage?.key || !stage?.entityType) {
    throw new Error('Stage key and entityType required');
  }
  db.prepare(`
    INSERT OR REPLACE INTO workflow_stages
      (key, entity_type, display_name, category, sort_order, is_closed, color, fields_json, automation_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    stage.key,
    stage.entityType,
    stage.display,
    stage.category || 'general',
    stage.sort || 0,
    stage.closed ? 1 : 0,
    stage.color || '',
    JSON.stringify(stage.fields || []),
    JSON.stringify(stage.automation || {}),
  );
}

function deleteWorkflowStage(db, entityType, key, reassignTo = null) {
  const inUse = db.prepare(
    'SELECT COUNT(*) as c FROM reminders WHERE stage_key = ? AND task_type = ? AND status != ?',
  ).get(key, entityType, 'deleted');
  if (inUse.c > 0 && !reassignTo) {
    return { success: false, error: 'stage_in_use', count: inUse.c };
  }
  if (inUse.c > 0 && reassignTo) {
    db.prepare(
      'UPDATE reminders SET stage_key = ?, updated_at = datetime(\'now\') WHERE stage_key = ? AND task_type = ? AND status != ?',
    ).run(reassignTo, key, entityType, 'deleted');
  }
  db.prepare('DELETE FROM workflow_stages WHERE key = ? AND entity_type = ?').run(key, entityType);
  return { success: true };
}

function seedWorkflowStages(db) {
  for (const entityType of ['task', 'reminder']) {
    for (const s of defaultStages(entityType)) {
      saveWorkflowStage(db, { ...s, entityType });
    }
  }
}

function getWorkflowStage(db, entityType, key) {
  const row = db.prepare(
    'SELECT * FROM workflow_stages WHERE entity_type = ? AND key = ?',
  ).get(entityType, key);
  if (!row) {
    const fallback = defaultStages(entityType).find((s) => s.key === key);
    if (!fallback) return null;
    return { ...fallback, entityType, fields: [], automation: fallback.automation || {} };
  }
  return rowToStage(row);
}

function countStageUsage(db, entityType, key) {
  const row = db.prepare(
    'SELECT COUNT(*) as c FROM reminders WHERE stage_key = ? AND task_type = ? AND status != ?',
  ).get(key, entityType, 'deleted');
  return row?.c || 0;
}

module.exports = {
  loadWorkflowStages,
  saveWorkflowStage,
  deleteWorkflowStage,
  seedWorkflowStages,
  getWorkflowStage,
  countStageUsage,
  rowToStage,
};
