/**
 * Load/save pipeline stages from inquiry_stages table.
 */
const {
  DEFAULT_STAGES,
  STAGE_FIELDS,
  STAGE_AUTOMATION,
  getStage,
} = require('./inquiry-pipeline');

function rowToStage(row) {
  let fields = [];
  let automation = {};
  try { fields = JSON.parse(row.fields_json || '[]'); } catch (_) { /* use defaults */ }
  try { automation = JSON.parse(row.automation_json || '{}'); } catch (_) { /* ignore */ }
  if (!fields.length && STAGE_FIELDS[row.key]) fields = STAGE_FIELDS[row.key];
  if (!Object.keys(automation).length && STAGE_AUTOMATION[row.key]) automation = STAGE_AUTOMATION[row.key];

  return {
    key: row.key,
    display: row.display_name,
    category: row.category,
    sort: row.sort_order,
    closed: !!row.is_closed,
    color: row.color || '',
    fields,
    automation,
  };
}

function loadStagesFromDb(db) {
  const rows = db.prepare('SELECT * FROM inquiry_stages ORDER BY sort_order ASC, display_name ASC').all();
  if (!rows.length) return DEFAULT_STAGES.map((s) => ({
    ...s,
    fields: STAGE_FIELDS[s.key] || [],
    automation: STAGE_AUTOMATION[s.key] || {},
  }));
  return rows.map(rowToStage);
}

function saveStageToDb(db, stage) {
  db.prepare(`
    INSERT OR REPLACE INTO inquiry_stages
      (key, display_name, category, sort_order, is_closed, color, fields_json, automation_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    stage.key,
    stage.display,
    stage.category,
    stage.sort || 0,
    stage.closed ? 1 : 0,
    stage.color || '',
    JSON.stringify(stage.fields || STAGE_FIELDS[stage.key] || []),
    JSON.stringify(stage.automation || STAGE_AUTOMATION[stage.key] || {}),
  );
}

function saveAllStagesToDb(db, stages) {
  for (const s of stages) saveStageToDb(db, s);
}

function getStageFromDb(db, key) {
  const row = db.prepare('SELECT * FROM inquiry_stages WHERE key = ?').get(key);
  if (!row) return getStage(key, loadStagesFromDb(db));
  return rowToStage(row);
}

module.exports = {
  loadStagesFromDb,
  saveStageToDb,
  saveAllStagesToDb,
  getStageFromDb,
  rowToStage,
};
