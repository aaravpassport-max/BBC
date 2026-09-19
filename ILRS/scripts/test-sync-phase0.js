#!/usr/bin/env node
/**
 * Phase 0 sync: device, folder, outbox, publish, inbound discovery.
 */
const assert = require('assert');
const { createHash } = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const Database = require('better-sqlite3');

const { runSyncMigrationV20, runSyncMigrationV21 } = require('../sync-migration');
const { configureSyncFolder, runSyncCycle, resetSyncState, getSyncStatus } = require('../sync-engine');
const { enqueueChange } = require('../sync-outbox');
const { getDeviceContext } = require('../sync-device');
const { listChangeEventFiles } = require('../sync-folder');

function makeDb() {
  const dbPath = path.join(os.tmpdir(), `ilrs-sync-p0-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('schema_version', '19');
    INSERT INTO settings (key, value) VALUES ('user_name', 'Test Operator');
  `);
  db.exec(`
    CREATE TABLE clients (id TEXT PRIMARY KEY, name TEXT, company TEXT, mobile TEXT, email TEXT, notes TEXT, created_at TEXT);
  `);
  runSyncMigrationV20(db);
  runSyncMigrationV21(db);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '21')").run();
  return { db, dbPath };
}

async function test(name, fn) {
  try {
    await fn();
    console.log(`  ✓ ${name}`);
  } catch (err) {
    console.error(`  ✗ ${name}`);
    throw err;
  }
}

const { db, dbPath } = makeDb();
const syncTmp = path.join(os.tmpdir(), `ilrs-sync-root-${Date.now()}`);

(async () => {
try {
  await test('device id is generated', () => {
    const ctx = getDeviceContext(db);
    assert.match(ctx.device_id, /^DEVICE-[A-F0-9]{8}$/);
    assert.ok(ctx.user_id);
  });

  await test('configure folder creates ILRS structure', () => {
    const r = configureSyncFolder(db, syncTmp);
    assert.strictEqual(r.success, true);
    assert.ok(fs.existsSync(path.join(r.root, 'Sync', 'manifest.json')));
    assert.ok(fs.existsSync(path.join(r.root, 'Sync', 'Changes')));
  });

  await test('enqueue and publish writes change file', () => {
    db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_enabled', '1')").run();
    const ctx = getDeviceContext(db);
    enqueueChange(db, {
      entity: 'test_entity',
      recordId: '00000000-0000-4000-8000-000000000001',
      operation: 'create',
      payload: { hello: 'phase0' },
      deviceContext: ctx,
      appVersion: '1.2.0-test',
    });
    const cycle = runSyncCycle(db, '1.2.0-test', { force: true });
    assert.strictEqual(cycle.success, true);
    assert.ok(cycle.published >= 1);
    const root = configureSyncFolder(db, syncTmp).root;
    const files = listChangeEventFiles(path.join(root, 'Sync', 'Changes'));
    assert.ok(files.length >= 1);
  });

  await test('inbound applies foreign client events', () => {
    const root = configureSyncFolder(db, syncTmp).root;
    const foreignDir = path.join(root, 'Sync', 'Changes', '2026', '09', '19');
    fs.mkdirSync(foreignDir, { recursive: true });
    const recordId = '22222222-2222-4222-8222-222222222222';
    const payload = {
      row: {
        id: recordId,
        name: 'Raj Kumar',
        company: '',
        mobile: '',
        email: '',
        notes: '',
        sync_revision: 1,
      },
      new_revision: 1,
      base_revision: 0,
    };
    const payloadHash = createHash('sha256').update(JSON.stringify(payload)).digest('hex');
    const foreignEvent = {
      schema_version: 1,
      event_id: '11111111-1111-4111-8111-111111111111',
      entity: 'client',
      record_id: recordId,
      operation: 'upsert',
      device_id: 'DEVICE-FFFFFFFF',
      user_id: '33333333-3333-4333-8333-333333333333',
      office_id: '44444444-4444-4444-8444-444444444444',
      client_timestamp: new Date().toISOString(),
      new_revision: 1,
      base_revision: 0,
      payload,
      payload_hash: payloadHash,
      app_version: '1.2.0-test',
    };
    fs.writeFileSync(path.join(foreignDir, `${foreignEvent.event_id}.json`), JSON.stringify(foreignEvent));

    const cycle = runSyncCycle(db, '1.2.0-test', { force: true });
    assert.ok(cycle.inbound.applied >= 1);
    const client = db.prepare('SELECT * FROM clients WHERE id = ?').get(foreignEvent.record_id);
    assert.ok(client);
    assert.strictEqual(client.name, 'Raj Kumar');
  });

  await test('reset sync state preserves outbox and clears processed', async () => {
    const before = db.prepare('SELECT COUNT(*) AS c FROM sync_outbox').get().c;
    const reset = await resetSyncState(db, () => ({ success: true }));
    assert.strictEqual(reset.success, true);
    const after = db.prepare('SELECT COUNT(*) AS c FROM sync_outbox').get().c;
    assert.strictEqual(after, before);
    const processed = db.prepare('SELECT COUNT(*) AS c FROM sync_processed_events').get().c;
    assert.strictEqual(processed, 0);
  });

  console.log('\n✅ test-sync-phase0 passed');
} finally {
  db.close();
  try { fs.unlinkSync(dbPath); } catch (_) { /* ignore */ }
  try { fs.rmSync(syncTmp, { recursive: true, force: true }); } catch (_) { /* ignore */ }
}
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
