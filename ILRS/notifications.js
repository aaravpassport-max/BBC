const { Notification, app } = require('electron');
const path = require('path');
const fs = require('fs');
const notifier = require('node-notifier');
const { APP_ID, getTrayIconPath } = require('./windows-support');

const ICON_PATH = path.join(__dirname, 'assets', 'icon.png');
const TRAY_ICON_PATH = path.join(__dirname, 'assets', 'tray-icon.png');

/** @type {Map<string, () => void>} */
const pendingClickHandlers = new Map();
let notifierHooksReady = false;

function getSetting(db, key, defaultValue = '') {
  if (!db) return defaultValue;
  try {
    const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
    return row ? row.value : defaultValue;
  } catch {
    return defaultValue;
  }
}

function isQuietHours(db, now = new Date()) {
  if (getSetting(db, 'quiet_hours_enabled', '0') !== '1') return false;
  const start = getSetting(db, 'quiet_hours_start', '23:00');
  const end = getSetting(db, 'quiet_hours_end', '06:00');
  const cur = now.toTimeString().slice(0, 5);
  if (start > end) return cur >= start || cur < end;
  return cur >= start && cur < end;
}

function getEffectiveStyle(db, item) {
  const global = getSetting(db, 'notification_style', 'sound-popup');
  const itemStyle = item.alert_style || '';
  if (itemStyle === 'silent' || itemStyle === 'popup-only') return itemStyle;
  return global;
}

function shouldNotify(db, item) {
  const style = getEffectiveStyle(db, item);
  if (style === 'silent') return false;

  const priority = item.priority || 'normal';
  if (isQuietHours(db)) {
    if (priority === 'critical') return getSetting(db, 'critical_override', '1') === '1';
    if (priority === 'important') return true;
    return false;
  }
  return true;
}

function shouldPlaySound(db, item) {
  const style = getEffectiveStyle(db, item);
  const global = getSetting(db, 'notification_style', 'sound-popup');
  if (style === 'silent' || style === 'popup-only') return false;
  if (global === 'silent' || global === 'popup-only') return false;
  return true;
}

function buildContent(item, type = 'reminder') {
  const isPrivate = Number(item.is_private) === 1;
  const priority = item.priority || 'normal';
  const prefix = priority === 'critical' ? '🚨 ' : '🔔 ';

  if (isPrivate) {
    return {
      title: `${prefix}Private Reminder`,
      body: 'You have a scheduled reminder.',
      urgency: priority === 'critical' ? 'critical' : 'normal',
    };
  }

  if (type === 'medicine') {
    return {
      title: `💊 Medicine: ${item.name}`,
      body: item.food_timing ? `Take ${item.food_timing.replace('_', ' ')} food` : 'Time for your dose',
      urgency: 'normal',
    };
  }

  if (type === 'bill') {
    return {
      title: `💸 Bill Due: ${item.name}`,
      body: item.amount ? `Amount: ₹${item.amount}` : 'Payment due soon',
      urgency: priority === 'critical' ? 'critical' : 'normal',
    };
  }

  return {
    title: `${prefix}${item.title}`,
    body: item.why_it_matters || 'Time to take action!',
    urgency: priority === 'critical' ? 'critical' : 'normal',
  };
}

function getNotificationIcon() {
  const tray = getTrayIconPath();
  if (tray && fs.existsSync(tray)) return tray;
  if (fs.existsSync(TRAY_ICON_PATH)) return TRAY_ICON_PATH;
  if (fs.existsSync(ICON_PATH)) return ICON_PATH;
  return undefined;
}

function isActivateResponse(response) {
  if (!response) return false;
  const value = String(response).toLowerCase();
  return value === 'activate'
    || value === 'activated'
    || value === 'click'
    || value === 'clicked'
    || value === 'useraction'
    || value === 'action';
}

function registerClickHandler(key, onClick) {
  if (!onClick || !key) return;
  pendingClickHandlers.set(key, onClick);
  setTimeout(() => pendingClickHandlers.delete(key), 10 * 60 * 1000);
}

function fireClickHandler(key) {
  const handler = pendingClickHandlers.get(key);
  if (!handler) return false;
  pendingClickHandlers.delete(key);
  try {
    handler();
  } catch (err) {
    console.error('Notification click handler error:', err.message);
  }
  return true;
}

function ensureNotifierClickHooks() {
  if (notifierHooksReady) return;
  notifierHooksReady = true;

  const onNotifierActivate = (_notifierObject, options) => {
    const key = options?.toastTag || options?.tag || options?.id
      || `${options?.title || ''}|${options?.message || options?.subtitle || ''}`;
    fireClickHandler(key);
  };

  notifier.on('click', onNotifierActivate);
  notifier.on('activate', onNotifierActivate);
  notifier.on('action', onNotifierActivate);
}

function showWithNodeNotifier(content, { onClick, silent, clickKey }) {
  ensureNotifierClickHooks();
  const key = clickKey || `${content.title}|${content.body}`;
  registerClickHandler(key, onClick);

  return new Promise((resolve) => {
    const icon = getNotificationIcon();
    notifier.notify({
      title: content.title,
      message: content.body,
      icon,
      sound: !silent,
      wait: true,
      appID: APP_ID,
      toastTag: key,
      tag: key,
      id: key,
    }, (err, response) => {
      if (!err && isActivateResponse(response)) {
        fireClickHandler(key);
      }
      resolve(!err);
    });
  });
}

function showWithElectronNotification(content, { onClick, silent, type, icon, clickKey }) {
  if (!Notification.isSupported()) return false;

  const key = clickKey || `${content.title}|${content.body}`;
  registerClickHandler(key, onClick);

  try {
    const notification = new Notification({
      title: content.title,
      body: content.body,
      urgency: content.urgency,
      silent,
      icon,
      timeoutType: type === 'reminder' || content.urgency === 'critical' ? 'never' : 'default',
      closeButtonText: 'Dismiss',
    });

    const handleClick = () => {
      fireClickHandler(key);
      try { notification.close(); } catch (_) { /* ignore */ }
    };

    notification.on('click', handleClick);
    notification.on('action', handleClick);
    notification.on('close', () => pendingClickHandlers.delete(key));
    notification.show();
    return true;
  } catch (err) {
    console.warn('Electron notification failed:', err.message);
    pendingClickHandlers.delete(key);
    return false;
  }
}

async function showDesktopNotification(db, item, { onClick, type = 'reminder', force = false } = {}) {
  if (!force && !shouldNotify(db, item)) return false;

  const style = getEffectiveStyle(db, item);
  const content = buildContent(item, type);
  const silent = style === 'popup-only';
  const icon = getNotificationIcon();
  const clickKey = `ilrs-${item?.id || 'general'}-${Date.now()}`;

  if (process.platform === 'win32') {
    app.setAppUserModelId(APP_ID);
  }

  // Electron native notifications — reliable click-to-focus when app is in tray
  if (showWithElectronNotification(content, { onClick, silent, type, icon, clickKey })) {
    return true;
  }

  const notifierOk = await showWithNodeNotifier(content, { onClick, silent, clickKey });
  return notifierOk;
}

module.exports = {
  showDesktopNotification,
  shouldNotify,
  shouldPlaySound,
  getEffectiveStyle,
  buildContent,
  isQuietHours,
  getSetting,
  ICON_PATH,
  TRAY_ICON_PATH,
  isActivateResponse,
  registerClickHandler,
  fireClickHandler,
};
