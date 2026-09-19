/**
 * Google Drive for Desktop folder layout (local path only — no API).
 */
const fs = require('fs');
const path = require('path');
const { SYNC_MANIFEST_VERSION, SYNC_ROOT_SUBDIRS } = require('./sync-constants');

function syncRootPath(userSelectedRoot) {
  if (!userSelectedRoot) return '';
  const base = path.resolve(userSelectedRoot);
  const name = path.basename(base).toLowerCase();
  if (name === 'ilrs') return base;
  return path.join(base, 'ILRS');
}

function pathsForRoot(root) {
  const r = syncRootPath(root);
  return {
    root: r,
    sync: path.join(r, 'Sync'),
    manifest: path.join(r, 'Sync', 'manifest.json'),
    devices: path.join(r, 'Sync', 'Devices'),
    changes: path.join(r, 'Sync', 'Changes'),
    conflicts: path.join(r, 'Sync', 'Conflicts'),
    locks: path.join(r, 'Sync', 'Locks'),
    attachments: path.join(r, 'Attachments'),
    backups: path.join(r, 'Backups'),
  };
}

function ensureDirectory(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

function ensureSyncFolderStructure(userSelectedRoot) {
  const p = pathsForRoot(userSelectedRoot);
  if (!p.root) return { success: false, error: 'Sync folder path is empty' };
  try {
    ensureDirectory(p.root);
    for (const sub of SYNC_ROOT_SUBDIRS) {
      ensureDirectory(path.join(p.root, sub));
    }
    ensureDirectory(p.attachments);
    ensureDirectory(p.backups);
    const manifest = readManifest(p.manifest);
    if (!manifest) {
      writeManifest(p.manifest, {
        manifest_version: SYNC_MANIFEST_VERSION,
        app: 'ILRS',
        created_at: new Date().toISOString(),
      });
    }
    return { success: true, root: p.root, paths: p };
  } catch (err) {
    return { success: false, error: err.message };
  }
}

function readManifest(manifestPath) {
  try {
    if (!fs.existsSync(manifestPath)) return null;
    return JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  } catch (_) {
    return null;
  }
}

function writeManifest(manifestPath, data) {
  const tmp = `${manifestPath}.tmp`;
  fs.writeFileSync(tmp, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
  fs.renameSync(tmp, manifestPath);
}

function verifySyncFolder(userSelectedRoot) {
  const ensured = ensureSyncFolderStructure(userSelectedRoot);
  if (!ensured.success) return ensured;
  const p = ensured.paths;
  const checks = [];
  for (const label of ['sync', 'devices', 'changes', 'manifest']) {
    const exists = fs.existsSync(p[label]);
    checks.push({ name: label, ok: exists });
    if (!exists) {
      return { success: false, error: `Missing ${label}`, checks, root: p.root };
    }
  }
  let writable = false;
  try {
    const probe = path.join(p.sync, `.ilrs-write-test-${Date.now()}`);
    fs.writeFileSync(probe, 'ok');
    fs.unlinkSync(probe);
    writable = true;
  } catch (err) {
    return { success: false, error: `Folder not writable: ${err.message}`, checks, root: p.root };
  }
  return { success: true, root: p.root, manifest: readManifest(p.manifest), writable, checks };
}

function writeDeviceHeartbeat(userSelectedRoot, deviceRecord, appVersion) {
  const p = pathsForRoot(userSelectedRoot);
  if (!p.devices) return { success: false, error: 'No sync folder' };
  ensureDirectory(p.devices);
  const filePath = path.join(p.devices, `${deviceRecord.device_id}.json`);
  const payload = {
    device_id: deviceRecord.device_id,
    device_name: deviceRecord.device_name,
    office_id: deviceRecord.office_id,
    office_label: deviceRecord.office_label,
    app_version: appVersion,
    last_seen_at: new Date().toISOString(),
    last_sync_at: deviceRecord.last_sync_at || null,
    status: deviceRecord.status || 'active',
  };
  const tmp = `${filePath}.tmp`;
  fs.writeFileSync(tmp, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  fs.renameSync(tmp, filePath);
  return { success: true, path: filePath };
}

function listChangeEventFiles(changesDir, { maxFiles = 5000 } = {}) {
  const files = [];
  if (!changesDir || !fs.existsSync(changesDir)) return files;

  function walk(dir, depth) {
    if (files.length >= maxFiles || depth > 8) return;
    let entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (_) {
      return;
    }
    for (const ent of entries) {
      if (files.length >= maxFiles) break;
      const full = path.join(dir, ent.name);
      if (ent.isDirectory()) {
        walk(full, depth + 1);
      } else if (ent.isFile() && ent.name.endsWith('.json') && !ent.name.endsWith('.tmp')) {
        if (/\s\(\d+\)\.json$/.test(ent.name)) continue;
        files.push(full);
      }
    }
  }
  walk(changesDir, 0);
  return files;
}

function changesPathForToday(root) {
  const p = pathsForRoot(root);
  const d = new Date();
  const yyyy = String(d.getUTCFullYear());
  const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(d.getUTCDate()).padStart(2, '0');
  const dir = path.join(p.changes, yyyy, mm, dd);
  ensureDirectory(dir);
  return dir;
}

function writeAtomicJson(filePath, obj) {
  const tmp = `${filePath}.tmp`;
  fs.writeFileSync(tmp, `${JSON.stringify(obj, null, 2)}\n`, 'utf8');
  fs.renameSync(tmp, filePath);
}

module.exports = {
  syncRootPath,
  pathsForRoot,
  ensureSyncFolderStructure,
  verifySyncFolder,
  writeDeviceHeartbeat,
  listChangeEventFiles,
  changesPathForToday,
  writeAtomicJson,
  readManifest,
};
