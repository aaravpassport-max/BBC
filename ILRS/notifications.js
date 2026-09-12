const { Notification, app } = require('electron');
const path = require('path');
const fs = require('fs');
const notifier = require('node-notifier');

const ICON_PATH = path.join(__dirname, 'assets', 'icon.png');
const TRAY_ICON_PATH = path.join(__dirname, 'assets', 'tray-icon.png');

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
  if (getSetting(db, 'quiet_hours_enabled', '1') !== '1') return false;
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

function showDesktopNotification(db, item, { onClick, type = 'reminder', force = false } = {}) {
  if (!force && !shouldNotify(db, item)) return false;

  const style = getEffectiveStyle(db, item);
  const content = buildContent(item, type);
  const silent = style === 'popup-only';
  const icon = fs.existsSync(ICON_PATH) ? ICON_PATH : undefined;

  if (process.platform === 'win32') {
    app.setAppUserModelId('com.ilrs.app');
  }

  let shown = false;
  if (Notification.isSupported()) {
    try {
      const notification = new Notification({
        title: content.title,
        body: content.body,
        urgency: content.urgency,
        silent,
        icon,
        timeoutType: type === 'reminder' || content.urgency === 'critical' ? 'never' : 'default',
      });
      notification.on('click', () => onClick?.());
      notification.on('action', () => onClick?.());
      notification.show();
      shown = true;
    } catch (err) {
      console.warn('Electron notification failed:', err.message);
    }
  }

  if (shown) return true;

  notifier.notify({
    title: content.title,
    message: content.body,
    icon: fs.existsSync(TRAY_ICON_PATH) ? TRAY_ICON_PATH : icon,
    sound: !silent,
    wait: true,
    appID: 'com.ilrs.app',
  }, (_err, response) => {
    if (response === 'activate' || response === 'click' || response === 'timeout') {
      onClick?.();
    }
  });
  return true;
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
};
