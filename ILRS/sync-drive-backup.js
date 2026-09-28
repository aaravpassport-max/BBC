/**
 * Versioned SQLite backups into the Google Drive sync folder (separate from change events).
 */
const fs = require('fs');
const path = require('path');

async function awaitBackup(db, destPath) {
  await db.backup(destPath);
}
const { pathsForRoot } = require('./sync-folder');
const { getSetting } = require('./sync-device');

function isDriveBackupEnabled(db) {
  return getSetting(db, 'sync_drive_backup', '0') === '1';
}

function retentionCount(db) {
  const n = parseInt(getSetting(db, 'sync_drive_backup_retention', '14'), 10);
  return Math.max(3, Math.min(60, n || 14));
}

function pruneOldBackups(backupDir, keep) {
  if (!fs.existsSync(backupDir)) return;
  const files = fs.readdirSync(backupDir)
    .filter((f) => f.startsWith('ilrs-backup-') && f.endsWith('.db'))
    .sort();
  while (files.length > keep) {
    try {
      fs.unlinkSync(path.join(backupDir, files.shift()));
    } catch (_) { /* ignore */ }
  }
}

async function backupDatabaseToDrive(db, syncFolderRoot) {
  if (!db || !syncFolderRoot) {
    return { success: false, error: 'missing_db_or_folder' };
  }
  const p = pathsForRoot(syncFolderRoot);
  fs.mkdirSync(p.backups, { recursive: true });

  const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const dest = path.join(p.backups, `ilrs-backup-${stamp}.db`);
  try {
    await awaitBackup(db, dest);
  } catch (err) {
    return { success: false, error: err.message };
  }
  if (!fs.existsSync(dest)) {
    return { success: false, error: 'backup_file_not_created' };
  }
  pruneOldBackups(p.backups, retentionCount(db));
  return { success: true, path: dest };
}

async function maybeRunDriveBackup(db) {
  if (!isDriveBackupEnabled(db)) {
    return { success: false, skipped: true, reason: 'drive_backup_disabled' };
  }
  const folder = getSetting(db, 'sync_folder_path', '');
  if (!folder) return { success: false, skipped: true, reason: 'no_sync_folder' };

  const { getOutboxStats } = require('./sync-outbox');
  const { getProcessedStats } = require('./sync-processed');
  const { countOpenConflicts } = require('./sync-conflicts');
  const outbox = getOutboxStats(db);
  const pending = (outbox.pending || 0) + (outbox.failed || 0);
  const processed = getProcessedStats(db);
  const conflicts = countOpenConflicts(db);
  const unsettled = pending > 0 || processed.pending_apply > 0 || conflicts > 0;

  const result = await backupDatabaseToDrive(db, folder);
  return {
    ...result,
    warning: unsettled ? 'sync_not_fully_settled' : null,
    pending_outbox: pending,
    pending_apply: processed.pending_apply,
    open_conflicts: conflicts,
  };
}

module.exports = {
  isDriveBackupEnabled,
  backupDatabaseToDrive,
  maybeRunDriveBackup,
};
