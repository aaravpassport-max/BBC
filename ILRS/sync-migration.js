/**
 * Database migration for multi-computer sync (schema v20).
 */

function runSyncMigrationV20(db) {
  db.exec(`
    CREATE TABLE IF NOT EXISTS sync_outbox (
      id TEXT PRIMARY KEY,
      event_id TEXT NOT NULL UNIQUE,
      entity TEXT NOT NULL,
      record_id TEXT NOT NULL,
      operation TEXT NOT NULL,
      payload_json TEXT NOT NULL DEFAULT '{}',
      status TEXT NOT NULL DEFAULT 'pending',
      error TEXT DEFAULT '',
      attempts INTEGER DEFAULT 0,
      created_at TEXT DEFAULT (datetime('now')),
      published_at TEXT DEFAULT ''
    );

    CREATE INDEX IF NOT EXISTS idx_sync_outbox_status ON sync_outbox(status);
    CREATE INDEX IF NOT EXISTS idx_sync_outbox_created ON sync_outbox(created_at);

    CREATE TABLE IF NOT EXISTS sync_processed_events (
      event_id TEXT PRIMARY KEY,
      source_device_id TEXT DEFAULT '',
      entity TEXT DEFAULT '',
      processed_at TEXT DEFAULT (datetime('now')),
      apply_status TEXT DEFAULT 'recorded'
    );

    CREATE INDEX IF NOT EXISTS idx_sync_processed_apply ON sync_processed_events(apply_status);

    CREATE TABLE IF NOT EXISTS app_users (
      id TEXT PRIMARY KEY,
      display_name TEXT NOT NULL,
      created_at TEXT DEFAULT (datetime('now')),
      updated_at TEXT DEFAULT (datetime('now'))
    );
  `);

  const defaults = {
    sync_enabled: '0',
    sync_auto: '1',
    sync_interval_seconds: '120',
    sync_folder_path: '',
    sync_last_run_at: '',
    sync_last_error: '',
  };
  const ins = db.prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
  for (const [k, v] of Object.entries(defaults)) {
    ins.run(k, v);
  }

  const { ensureDeviceRegistration, ensureActiveUser } = require('./sync-device');
  ensureDeviceRegistration(db);
  ensureActiveUser(db);
}

module.exports = { runSyncMigrationV20 };
