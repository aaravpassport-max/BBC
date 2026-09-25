#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const Database = require('better-sqlite3');

const {
  runSyncMigrationV20,
  runSyncMigrationV21,
  runSyncMigrationV22,
  runSyncMigrationV23,
  runSyncMigrationV24,
} = require('../sync-migration');
const { configureSyncFolder, runSyncCycle } = require('../sync-engine');
const { saveStageToDb } = require('../inquiry-stage-store');
const { createTemplate } = require('../inquiry-templates');
const { backupDatabaseToDrive } = require('../sync-drive-backup');

function makeDb(label) {
  const dbPath = path.join(os.tmpdir(), `ilrs-p4-${label}-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('schema_version', '19');
    CREATE TABLE inquiry_stages (
      key TEXT PRIMARY KEY, display_name TEXT, category TEXT, sort_order INTEGER, is_closed INTEGER,
      color TEXT, fields_json TEXT, automation_json TEXT
    );
    CREATE TABLE inquiry_templates (
      id TEXT PRIMARY KEY, name TEXT, requirement TEXT, service_category TEXT, stage_key TEXT,
      next_action TEXT, source TEXT, expected_value REAL, notes TEXT, sort_order INTEGER, created_at TEXT
    );
    CREATE TABLE workflow_stages (
      key TEXT NOT NULL, entity_type TEXT NOT NULL, display_name TEXT, category TEXT, sort_order INTEGER,
      is_closed INTEGER, color TEXT, fields_json TEXT, automation_json TEXT,
      PRIMARY KEY (key, entity_type)
    );
  `);
  runSyncMigrationV20(db);
  runSyncMigrationV21(db);
  runSyncMigrationV22(db);
  runSyncMigrationV23(db);
  runSyncMigrationV24(db);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '24')").run();
  return { db, dbPath };
}

const syncRoot = path.join(os.tmpdir(), `ilrs-p4-shared-${Date.now()}`);
const dbA = makeDb('A');
const dbB = makeDb('B');

(async () => {
try {
  configureSyncFolder(dbA.db, syncRoot);
  configureSyncFolder(dbB.db, syncRoot);
  dbA.db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_enabled', '1')").run();
  dbB.db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_enabled', '1')").run();
  dbA.db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_folder_path', ?)").run(syncRoot);
  dbB.db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_folder_path', ?)").run(syncRoot);

  saveStageToDb(dbA.db, {
    key: 'custom_stage_p4',
    display: 'Custom Stage P4',
    category: 'active',
    sort: 99,
    closed: false,
    color: '#336699',
    fields: [],
    automation: {},
  });

  createTemplate(dbA.db, {
    name: 'P4 Template',
    requirement: 'Test service',
    serviceCategory: 'Documentation',
    stageKey: 'custom_stage_p4',
    nextAction: 'Call client',
  });

  runSyncCycle(dbA.db, '1.2.4-test', { force: true });
  runSyncCycle(dbB.db, '1.2.4-test', { force: true });

  const stageB = dbB.db.prepare('SELECT * FROM inquiry_stages WHERE key = ?').get('custom_stage_p4');
  assert.ok(stageB, 'inquiry stage on B');
  assert.strictEqual(stageB.display_name, 'Custom Stage P4');

  const tplB = dbB.db.prepare('SELECT * FROM inquiry_templates WHERE name = ?').get('P4 Template');
  assert.ok(tplB, 'template on B');

  const drive = await backupDatabaseToDrive(dbA.db, syncRoot);
  assert.ok(drive.success, `drive backup failed: ${drive.error || 'unknown'}`);
  assert.ok(fs.existsSync(drive.path), 'drive backup file exists');

  console.log('✅ test-sync-phase4 passed');
} finally {
  dbA.db.close();
  dbB.db.close();
  try { fs.unlinkSync(dbA.dbPath); } catch (_) { /* ignore */ }
  try { fs.unlinkSync(dbB.dbPath); } catch (_) { /* ignore */ }
  try { fs.rmSync(syncRoot, { recursive: true, force: true }); } catch (_) { /* ignore */ }
}
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
