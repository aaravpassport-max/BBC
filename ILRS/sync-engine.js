/**
 * Phase 0 sync engine: folder setup, device heartbeat, outbox publish, inbound discovery.
 * Record apply arrives in phase 1+.
 */
const { getDeviceContext, getSetting, setSetting, ensureDeviceRegistration } = require('./sync-device');
const {
  pathsForRoot,
  verifySyncFolder,
  ensureSyncFolderStructure,
  writeDeviceHeartbeat,
} = require('./sync-folder');
const { publishPendingOutbox, getOutboxStats, enqueueChange } = require('./sync-outbox');
const { scanInboundEventFiles, getProcessedStats } = require('./sync-processed');

let syncIntervalTimer = null;

function getSyncSettings(db) {
  return {
    enabled: getSetting(db, 'sync_enabled', '0') === '1',
    auto: getSetting(db, 'sync_auto', '1') === '1',
    interval_seconds: Math.max(30, parseInt(getSetting(db, 'sync_interval_seconds', '120'), 10) || 120),
    folder_path: getSetting(db, 'sync_folder_path', ''),
    device_name: getSetting(db, 'sync_device_name', ''),
    office_label: getSetting(db, 'sync_office_label', ''),
    last_run_at: getSetting(db, 'sync_last_run_at', ''),
    last_error: getSetting(db, 'sync_last_error', ''),
  };
}

function saveSyncSettings(db, partial) {
  const map = {
    enabled: 'sync_enabled',
    auto: 'sync_auto',
    interval_seconds: 'sync_interval_seconds',
    folder_path: 'sync_folder_path',
    device_name: 'sync_device_name',
    office_label: 'sync_office_label',
    office_id: 'sync_office_id',
  };
  for (const [key, settingKey] of Object.entries(map)) {
    if (partial[key] !== undefined) {
      setSetting(db, settingKey, partial[key]);
    }
  }
  if (partial.office_label !== undefined) {
    ensureDeviceRegistration(db);
  }
}

function getSyncStatus(db, appVersion) {
  ensureDeviceRegistration(db);
  const settings = getSyncSettings(db);
  const device = getDeviceContext(db);
  const outbox = getOutboxStats(db);
  const processed = getProcessedStats(db);

  let folderOk = false;
  let syncRoot = '';
  let inboundPendingApply = processed.pending_apply;

  if (settings.folder_path) {
    const v = verifySyncFolder(settings.folder_path);
    folderOk = v.success;
    syncRoot = v.root || '';
  }

  const online = folderOk && settings.enabled;
  let statusLabel = 'Not configured';
  if (!settings.folder_path) statusLabel = 'Choose a sync folder';
  else if (!settings.enabled) statusLabel = 'Disabled';
  else if (!folderOk) statusLabel = 'Folder problem';
  else if (outbox.pending > 0 || outbox.failed > 0) statusLabel = 'Pending upload';
  else if (inboundPendingApply > 0) statusLabel = 'Incoming (apply in phase 1)';
  else statusLabel = 'Up to date';

  return {
    settings,
    device,
    app_version: appVersion,
    folder_ok: folderOk,
    sync_root: syncRoot,
    outbox,
    processed,
    inbound_pending_apply: inboundPendingApply,
    online,
    status_label: statusLabel,
  };
}

function runSyncCycle(db, appVersion, { force = false } = {}) {
  const settings = getSyncSettings(db);
  if (!settings.enabled && !force) {
    return { success: false, skipped: true, reason: 'sync_disabled' };
  }
  if (!settings.folder_path) {
    return { success: false, error: 'Sync folder not set' };
  }

  const verified = verifySyncFolder(settings.folder_path);
  if (!verified.success) {
    setSetting(db, 'sync_last_error', verified.error || 'verify failed');
    return { success: false, error: verified.error };
  }

  const device = getDeviceContext(db);
  const paths = pathsForRoot(settings.folder_path);

  const publish = publishPendingOutbox(db, settings.folder_path, device, appVersion);
  const inbound = scanInboundEventFiles(db, paths.changes, device.device_id);

  const lastSyncIso = new Date().toISOString();
  writeDeviceHeartbeat(settings.folder_path, {
    ...device,
    last_sync_at: lastSyncIso,
    status: 'active',
  }, appVersion);

  setSetting(db, 'sync_last_run_at', lastSyncIso);
  setSetting(db, 'sync_last_error', publish.errors?.length ? publish.errors[0].error : '');

  return {
    success: true,
    published: publish.published,
    publish_errors: publish.errors,
    inbound,
    last_run_at: lastSyncIso,
  };
}

function resetSyncState(db, backupFn) {
  const backup = typeof backupFn === 'function' ? backupFn(true) : { success: false };
  const pendingPublished = db.prepare(`
    SELECT COUNT(*) AS c FROM sync_outbox WHERE status = 'published'
  `).get()?.c || 0;

  db.exec('DELETE FROM sync_processed_events');
  db.prepare(`
    UPDATE sync_outbox SET status = 'pending', error = '', published_at = ''
    WHERE status = 'published'
  `).run();

  setSetting(db, 'sync_last_error', '');
  return {
    success: true,
    backup,
    republished_candidates: pendingPublished,
    message: 'Local sync metadata reset. Business data preserved. Run Sync Now to rescan the folder.',
  };
}

function configureSyncFolder(db, folderPath) {
  if (!folderPath) return { success: false, error: 'No folder selected' };
  const ensured = ensureSyncFolderStructure(folderPath);
  if (!ensured.success) return ensured;
  setSetting(db, 'sync_folder_path', folderPath);
  setSetting(db, 'sync_enabled', '1');
  return { success: true, root: ensured.root };
}

function stopSyncScheduler() {
  if (syncIntervalTimer) {
    clearInterval(syncIntervalTimer);
    syncIntervalTimer = null;
  }
}

function startSyncScheduler(db, appVersion) {
  stopSyncScheduler();
  const settings = getSyncSettings(db);
  if (!settings.enabled || !settings.auto || !settings.folder_path) return;

  const ms = settings.interval_seconds * 1000;
  syncIntervalTimer = setInterval(() => {
    try {
      runSyncCycle(db, appVersion);
    } catch (err) {
      console.error('sync scheduler error:', err.message);
      setSetting(db, 'sync_last_error', err.message);
    }
  }, ms);
}

module.exports = {
  getSyncSettings,
  saveSyncSettings,
  getSyncStatus,
  runSyncCycle,
  resetSyncState,
  configureSyncFolder,
  startSyncScheduler,
  stopSyncScheduler,
  enqueueChange,
};
