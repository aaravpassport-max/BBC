/**
 * Local device and operator identity (no cloud login).
 */
const { randomUUID } = require('crypto');
const os = require('os');

function getSetting(db, key, fallback = '') {
  const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
  return row?.value ?? fallback;
}

function setSetting(db, key, value) {
  db.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)').run(key, String(value));
}

function generateDeviceId() {
  const hex = randomUUID().replace(/-/g, '').slice(0, 8).toUpperCase();
  return `DEVICE-${hex}`;
}

function defaultDeviceName() {
  const host = (os.hostname() || 'Computer').replace(/[^\w\s-]/g, '').trim().slice(0, 48);
  return host || 'ILRS Computer';
}

function ensureDeviceRegistration(db) {
  let deviceId = getSetting(db, 'sync_device_id', '');
  if (!deviceId) {
    deviceId = generateDeviceId();
    setSetting(db, 'sync_device_id', deviceId);
  }
  if (!getSetting(db, 'sync_device_name', '')) {
    setSetting(db, 'sync_device_name', defaultDeviceName());
  }
  if (!getSetting(db, 'sync_office_id', '')) {
    setSetting(db, 'sync_office_id', randomUUID());
  }
  if (!getSetting(db, 'sync_office_label', '')) {
    setSetting(db, 'sync_office_label', 'Main Office');
  }
}

function ensureActiveUser(db) {
  const activeId = getSetting(db, 'sync_active_user_id', '');
  if (activeId) {
    const row = db.prepare('SELECT id, display_name FROM app_users WHERE id = ?').get(activeId);
    if (row) return row;
  }
  const display = getSetting(db, 'user_name', 'Operator') || 'Operator';
  const existing = db.prepare('SELECT id, display_name FROM app_users ORDER BY created_at ASC LIMIT 1').get();
  if (existing) {
    setSetting(db, 'sync_active_user_id', existing.id);
    return existing;
  }
  const id = randomUUID();
  db.prepare('INSERT INTO app_users (id, display_name, created_at, updated_at) VALUES (?, ?, datetime(\'now\'), datetime(\'now\'))').run(
    id,
    display,
  );
  setSetting(db, 'sync_active_user_id', id);
  return { id, display_name: display };
}

function syncUserDisplayFromProfile(db) {
  const user = ensureActiveUser(db);
  const name = getSetting(db, 'user_name', '');
  if (name && name !== user.display_name) {
    db.prepare('UPDATE app_users SET display_name = ?, updated_at = datetime(\'now\') WHERE id = ?').run(name, user.id);
    return { ...user, display_name: name };
  }
  return user;
}

function getDeviceContext(db) {
  ensureDeviceRegistration(db);
  const user = syncUserDisplayFromProfile(db);
  ensureActiveUser(db);
  return {
    device_id: getSetting(db, 'sync_device_id'),
    device_name: getSetting(db, 'sync_device_name', defaultDeviceName()),
    office_id: getSetting(db, 'sync_office_id'),
    office_label: getSetting(db, 'sync_office_label', 'Main Office'),
    user_id: user.id,
    user_display: user.display_name,
  };
}

module.exports = {
  generateDeviceId,
  defaultDeviceName,
  ensureDeviceRegistration,
  ensureActiveUser,
  getDeviceContext,
  getSetting,
  setSetting,
};
