#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { randomUUID } = require('crypto');
const Database = require('better-sqlite3');

process.env.ILRS_TEST_ATTACHMENTS_DIR = path.join(os.tmpdir(), `ilrs-p5-att-${Date.now()}`);

const {
  runSyncMigrationV20,
  runSyncMigrationV21,
  runSyncMigrationV22,
  runSyncMigrationV23,
  runSyncMigrationV24,
  runSyncMigrationV25,
} = require('../sync-migration');
const { configureSyncFolder, runSyncCycle } = require('../sync-engine');
const { exportBootstrapSnapshot, maybeImportBootstrap } = require('../sync-bootstrap');
const { registerAttachmentFromFile } = require('../attachment-actions');
const { applySyncEvent } = require('../sync-apply');
const { resolveConflictWithStrategy } = require('../sync-conflicts');
const { getDeviceContext } = require('../sync-device');
const { maybeSyncEntity } = require('../sync-hook');

function makeDb(label) {
  const dbPath = path.join(os.tmpdir(), `ilrs-p5-${label}-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('schema_version', '19');
    CREATE TABLE clients (id TEXT PRIMARY KEY, name TEXT, company TEXT, mobile TEXT, email TEXT, notes TEXT, created_at TEXT, sync_revision INTEGER DEFAULT 1);
    CREATE TABLE inquiries (
      id TEXT PRIMARY KEY, client_id TEXT, inquiry_number TEXT, requirement TEXT, service_category TEXT,
      stage_key TEXT, status TEXT, created_at TEXT, updated_at TEXT, sync_revision INTEGER DEFAULT 1
    );
    CREATE TABLE sync_conflicts (
      id TEXT PRIMARY KEY, entity TEXT, record_id TEXT, event_id TEXT,
      local_revision INTEGER, incoming_revision INTEGER, detail_json TEXT, created_at TEXT, resolved INTEGER DEFAULT 0
    );
  `);
  runSyncMigrationV20(db);
  runSyncMigrationV21(db);
  runSyncMigrationV22(db);
  runSyncMigrationV23(db);
  runSyncMigrationV24(db);
  runSyncMigrationV25(db);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '25')").run();
  return { db, dbPath };
}

const syncRoot = path.join(os.tmpdir(), `ilrs-p5-shared-${Date.now()}`);
const dbA = makeDb('A');
const dbB = makeDb('B');

(async () => {
try {
  configureSyncFolder(dbA.db, syncRoot);
  configureSyncFolder(dbB.db, syncRoot);
  for (const d of [dbA.db, dbB.db]) {
    d.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_enabled', '1')").run();
    d.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_folder_path', ?)").run(syncRoot);
  }

  const clientId = randomUUID();
  dbA.db.prepare(`
    INSERT INTO clients (id, name, company, mobile, email, notes, created_at, sync_revision)
    VALUES (?, 'Bootstrap Client', '', '', '', '', datetime('now'), 1)
  `).run(clientId);

  const exportRes = exportBootstrapSnapshot(dbA.db, syncRoot);
  assert.ok(exportRes.success, 'bootstrap export');

  const importRes = maybeImportBootstrap(dbB.db, syncRoot);
  assert.ok(importRes.success, `bootstrap import: ${importRes.error || importRes.reason}`);
  const clientB = dbB.db.prepare('SELECT * FROM clients WHERE id = ?').get(clientId);
  assert.ok(clientB, 'client imported on B');

  const inquiryId = randomUUID();
  dbA.db.prepare(`
    INSERT INTO inquiries (id, client_id, inquiry_number, requirement, service_category, stage_key, status, created_at, updated_at, sync_revision)
    VALUES (?, ?, 'INQ-P5-1', 'Visa', 'Documentation', 'new', 'active', datetime('now'), datetime('now'), 1)
  `).run(inquiryId, clientId);
  maybeSyncEntity(dbA.db, 'inquiry', inquiryId);

  const tmpFile = path.join(os.tmpdir(), `p5-doc-${Date.now()}.txt`);
  fs.writeFileSync(tmpFile, 'phase 5 attachment payload');

  const att = registerAttachmentFromFile(dbA.db, {
    inquiryId,
    entityType: 'inquiry',
    entityId: inquiryId,
    sourcePath: tmpFile,
    fileName: 'proof.txt',
  });
  assert.ok(att.success, att.error);

  runSyncCycle(dbA.db, '1.2.5-test', { force: true });
  runSyncCycle(dbB.db, '1.2.5-test', { force: true });

  const attB = dbB.db.prepare('SELECT * FROM attachments WHERE id = ?').get(att.id);
  assert.ok(attB, 'attachment row on B');
  const localB = path.join(process.env.ILRS_TEST_ATTACHMENTS_DIR, attB.storage_rel_path);
  assert.ok(fs.existsSync(localB), 'attachment file on B');

  const deviceB = getDeviceContext(dbB.db).device_id;
  const conflictClientId = randomUUID();
  dbB.db.prepare(`
    INSERT INTO clients (id, name, company, mobile, email, notes, created_at, sync_revision)
    VALUES (?, 'Local Name B', '', '', '', '', datetime('now'), 3)
  `).run(conflictClientId);

  const incomingRow = {
    id: conflictClientId,
    name: 'Remote Name A',
    company: '',
    mobile: '',
    email: '',
    notes: '',
    created_at: new Date().toISOString(),
    sync_revision: 4,
  };
  const event = {
    event_id: randomUUID(),
    entity: 'client',
    record_id: conflictClientId,
    operation: 'upsert',
    device_id: 'DEVICE-AAAAAAAA',
    new_revision: 4,
    base_revision: 1,
    payload: { row: incomingRow, new_revision: 4, base_revision: 1 },
  };
  const applyResult = applySyncEvent(dbB.db, event, deviceB);
  assert.strictEqual(applyResult.status, 'conflict');

  const open = dbB.db.prepare('SELECT * FROM sync_conflicts WHERE resolved = 0').get();
  assert.ok(open, 'conflict recorded');

  const resolved = resolveConflictWithStrategy(dbB.db, open.id, 'keep_remote');
  assert.ok(resolved.success);
  const after = dbB.db.prepare('SELECT name FROM clients WHERE id = ?').get(conflictClientId);
  assert.strictEqual(after.name, 'Remote Name A');

  console.log('✅ test-sync-phase5 passed');
} finally {
  dbA.db.close();
  dbB.db.close();
  try { fs.unlinkSync(dbA.dbPath); } catch (_) { /* ignore */ }
  try { fs.unlinkSync(dbB.dbPath); } catch (_) { /* ignore */ }
  try { fs.rmSync(syncRoot, { recursive: true, force: true }); } catch (_) { /* ignore */ }
  try { fs.rmSync(process.env.ILRS_TEST_ATTACHMENTS_DIR, { recursive: true, force: true }); } catch (_) { /* ignore */ }
}
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
