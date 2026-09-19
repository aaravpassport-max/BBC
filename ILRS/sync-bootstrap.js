/**
 * Bootstrap snapshot for new computers (bulk import from Sync/Snapshots).
 */
const fs = require('fs');
const path = require('path');
const { pathsForRoot, writeAtomicJson } = require('./sync-folder');
const { getSetting, setSetting, getDeviceContext } = require('./sync-device');
const { listEntitiesByApplyOrder } = require('./sync-entities');
const { upsertEntityRow } = require('./sync-entity-rows');
const { runApplyingInbound } = require('./sync-hook');

const SNAPSHOT_FILENAME = 'bootstrap-latest.json';

function snapshotPath(syncFolderRoot) {
  const p = pathsForRoot(syncFolderRoot);
  return path.join(p.root, 'Sync', 'Snapshots', SNAPSHOT_FILENAME);
}

function exportTableRows(db, entityKey) {
  const def = require('./sync-entities').getEntity(entityKey);
  if (!def) return [];
  try {
    if (entityKey === 'workflow_stage') {
      return db.prepare('SELECT * FROM workflow_stages').all();
    }
    return db.prepare(`SELECT * FROM ${def.table}`).all();
  } catch (_) {
    return [];
  }
}

function exportBootstrapSnapshot(db, syncFolderRoot) {
  if (!syncFolderRoot) return { success: false, error: 'no_folder' };
  const filePath = snapshotPath(syncFolderRoot);
  fs.mkdirSync(path.dirname(filePath), { recursive: true });

  const tables = {};
  const counts = {};
  for (const { key } of listEntitiesByApplyOrder()) {
    const rows = exportTableRows(db, key);
    tables[key] = rows;
    counts[key] = rows.length;
  }

  const doc = {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    device_id: getDeviceContext(db).device_id,
    tables,
  };
  writeAtomicJson(filePath, doc);
  setSetting(db, 'sync_bootstrap_exported_at', doc.generated_at);
  return { success: true, path: filePath, table_counts: counts };
}

function isEligibleForBootstrapImport(db) {
  if (getSetting(db, 'sync_bootstrap_imported', '0') === '1') return false;
  let clients = 0;
  let inquiries = 0;
  try {
    clients = db.prepare('SELECT COUNT(*) AS c FROM clients').get()?.c || 0;
    inquiries = db.prepare('SELECT COUNT(*) AS c FROM inquiries').get()?.c || 0;
  } catch (_) {
    return false;
  }
  return clients === 0 && inquiries === 0;
}

function upsertBootstrapRow(db, entityKey, row) {
  const def = require('./sync-entities').getEntity(entityKey);
  if (!def || !row) return;
  runApplyingInbound(() => {
    try {
      const special = upsertEntityRow(db, entityKey, row);
      if (!special) {
        const cols = Object.keys(row);
        const placeholders = cols.map(() => '?').join(', ');
        const updates = cols.filter((c) => c !== def.idColumn).map((c) => `${c} = excluded.${c}`).join(', ');
        const sql = `
          INSERT INTO ${def.table} (${cols.join(', ')})
          VALUES (${placeholders})
          ON CONFLICT(${def.idColumn}) DO UPDATE SET ${updates}
        `;
        db.prepare(sql).run(...cols.map((c) => row[c]));
      }
    } catch (_) { /* table missing in minimal DB */ }
  });
}

function importBootstrapSnapshot(db, syncFolderRoot) {
  const filePath = snapshotPath(syncFolderRoot);
  if (!fs.existsSync(filePath)) {
    return { success: false, error: 'snapshot_not_found' };
  }
  let doc;
  try {
    doc = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (err) {
    return { success: false, error: err.message };
  }
  if (!doc?.tables) return { success: false, error: 'invalid_snapshot' };

  let imported = 0;
  for (const { key } of listEntitiesByApplyOrder()) {
    const rows = doc.tables[key] || [];
    for (const row of rows) {
      if (key === 'attachment' && row.deleted_at) continue;
      upsertBootstrapRow(db, key, row);
      imported += 1;
    }
  }

  setSetting(db, 'sync_bootstrap_imported', '1');
  setSetting(db, 'sync_bootstrap_imported_at', new Date().toISOString());
  setSetting(db, 'sync_bootstrap_source_device', doc.device_id || '');
  return { success: true, imported, snapshot_at: doc.generated_at };
}

function maybeImportBootstrap(db, syncFolderRoot) {
  if (!syncFolderRoot || !isEligibleForBootstrapImport(db)) {
    return { skipped: true, reason: 'not_eligible' };
  }
  return importBootstrapSnapshot(db, syncFolderRoot);
}

function maybePublishBootstrapSnapshot(db, syncFolderRoot) {
  if (!syncFolderRoot) return { skipped: true };
  if (getSetting(db, 'sync_bootstrap_publish', '0') !== '1') {
    return { skipped: true, reason: 'publish_disabled' };
  }
  let clients = 0;
  try {
    clients = db.prepare('SELECT COUNT(*) AS c FROM clients').get()?.c || 0;
  } catch (_) {
    return { skipped: true };
  }
  if (clients === 0) return { skipped: true, reason: 'no_data' };
  return exportBootstrapSnapshot(db, syncFolderRoot);
}

module.exports = {
  SNAPSHOT_FILENAME,
  snapshotPath,
  exportBootstrapSnapshot,
  importBootstrapSnapshot,
  maybeImportBootstrap,
  maybePublishBootstrapSnapshot,
  isEligibleForBootstrapImport,
};
