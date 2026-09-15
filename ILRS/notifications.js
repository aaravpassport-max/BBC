const { Notification, app } = require('electron');
const path = require('path');
const fs = require('fs');
const notifier = require('node-notifier');
const { APP_ID, getTrayIconPath } = require('./windows-support');

const ICON_PATH = path.join(__dirname, 'assets', 'icon.png');
const TRAY_ICON_PATH = path.join(__dirname, 'assets', 'tray-icon.png');

/** @type {Map<string, () => void>} */
const pendingClickHandlers = new Map();
/** @type {Map<string, (action: string) => void>} */
const pendingActionHandlers = new Map();
/** @type {Map<string, Set<{ notification?: object, clickKey: string }>>} */
const activeByItemId = new Map();
let notifierHooksReady = false;

function trackNotification(itemId, entry) {
  if (!itemId || !entry?.clickKey) return;
  if (!activeByItemId.has(itemId)) activeByItemId.set(itemId, new Set());
  activeByItemId.get(itemId).add(entry);
}

function untrackNotification(itemId, clickKey) {
  if (!itemId || !activeByItemId.has(itemId)) return;
  const set = activeByItemId.get(itemId);
  for (const entry of set) {
    if (entry.clickKey === clickKey) set.delete(entry);
  }
  if (set.size === 0) activeByItemId.delete(itemId);
}

function dismissNotificationsForItem(itemId) {
  if (!itemId || !activeByItemId.has(itemId)) return 0;
  const set = activeByItemId.get(itemId);
  let count = 0;
  for (const entry of [...set]) {
    try {
      entry.notification?.close?.();
    } catch (_) { /* ignore */ }
    pendingClickHandlers.delete(entry.clickKey);
    pendingActionHandlers.delete(entry.clickKey);
    set.delete(entry);
    count += 1;
  }
  activeByItemId.delete(itemId);
  return count;
}

function dismissAllNotifications() {
  let total = 0;
  for (const itemId of [...activeByItemId.keys()]) {
    total += dismissNotificationsForItem(itemId);
  }
  return total;
}

const REMINDER_TOAST_ACTIONS = [
  { type: 'button', text: 'Done' },
  { type: 'button', text: 'Snooze' },
];

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

function registerActionHandler(key, onAction) {
  if (!onAction || !key) return;
  pendingActionHandlers.set(key, onAction);
  setTimeout(() => pendingActionHandlers.delete(key), 10 * 60 * 1000);
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

function fireActionHandler(key, action) {
  const handler = pendingActionHandlers.get(key);
  if (!handler || !action) return false;
  pendingActionHandlers.delete(key);
  pendingClickHandlers.delete(key);
  try {
    handler(action);
  } catch (err) {
    console.error('Notification action handler error:', err.message);
  }
  return true;
}

function isActionResponse(response) {
  if (!response) return false;
  const value = String(response).toLowerCase();
  return value.includes('done')
    || value.includes('snooze')
    || value.includes('tomorrow')
    || value === 'buttonclicked';
}

function ensureNotifierClickHooks() {
  if (notifierHooksReady) return;
  notifierHooksReady = true;

  const resolveKey = (options) => options?.toastTag || options?.tag || options?.id
    || `${options?.title || ''}|${options?.message || options?.subtitle || ''}`;

  const onNotifierActivate = (_notifierObject, options) => {
    fireClickHandler(resolveKey(options));
  };

  const onNotifierAction = (_notifierObject, options, action) => {
    const key = resolveKey(options);
    const actionName = action || options?.activationType || options?.button;
    if (actionName && fireActionHandler(key, String(actionName))) return;
    fireClickHandler(key);
  };

  notifier.on('click', onNotifierActivate);
  notifier.on('activate', onNotifierActivate);
  notifier.on('action', onNotifierAction);
}

function showWithNodeNotifier(content, { onClick, onAction, silent, clickKey, actions }) {
  ensureNotifierClickHooks();
  const key = clickKey || `${content.title}|${content.body}`;
  registerClickHandler(key, onClick);
  if (onAction) registerActionHandler(key, onAction);

  return new Promise((resolve) => {
    const icon = getNotificationIcon();
    const notifyOptions = {
      title: content.title,
      message: content.body,
      icon,
      sound: !silent,
      wait: true,
      appID: APP_ID,
      toastTag: key,
      tag: key,
      id: key,
    };
    if (actions?.length) {
      notifyOptions.actions = actions.map((a) => a.text || a);
    }

    notifier.notify(notifyOptions, (err, response, metadata) => {
      if (!err) {
        const actionText = metadata?.activationType || metadata?.button
          || (typeof response === 'object' ? (response.activationType || response.button) : null);
        if (actionText && fireActionHandler(key, String(actionText))) {
          resolve(true);
          return;
        }
        if (isActivateResponse(response) || isActivateResponse(actionText)) {
          fireClickHandler(key);
        }
      }
      resolve(!err);
    });
  });
}

function showWithElectronNotification(content, {
  onClick, onAction, onDismiss, silent, type, icon, clickKey, actions, itemId,
}) {
  if (!Notification.isSupported()) return false;

  const key = clickKey || `${content.title}|${content.body}`;
  registerClickHandler(key, onClick);
  if (onAction) registerActionHandler(key, onAction);

  try {
    const notification = new Notification({
      title: content.title,
      body: content.body,
      urgency: content.urgency,
      silent,
      icon,
      timeoutType: type === 'reminder' || content.urgency === 'critical' ? 'never' : 'default',
      closeButtonText: 'Dismiss',
      actions: actions?.length ? actions : undefined,
    });

    const handleClick = () => {
      fireClickHandler(key);
      try { notification.close(); } catch (_) { /* ignore */ }
    };

    const handleAction = (_event, index) => {
      const label = actions?.[index]?.text || actions?.[index] || '';
      if (label && fireActionHandler(key, label)) {
        try { notification.close(); } catch (_) { /* ignore */ }
        return;
      }
      handleClick();
    };

    notification.on('click', handleClick);
    notification.on('action', handleAction);
    notification.on('close', () => {
      pendingClickHandlers.delete(key);
      pendingActionHandlers.delete(key);
      untrackNotification(itemId, key);
      if (onDismiss) {
        try { onDismiss(); } catch (err) {
          console.error('Notification dismiss handler error:', err.message);
        }
      }
    });
    if (itemId) trackNotification(itemId, { notification, clickKey: key });
    notification.show();
    return true;
  } catch (err) {
    console.warn('Electron notification failed:', err.message);
    pendingClickHandlers.delete(key);
    pendingActionHandlers.delete(key);
    return false;
  }
}

async function showDesktopNotification(db, item, {
  onClick,
  onAction,
  onDismiss,
  type = 'reminder',
  force = false,
} = {}) {
  if (!force && !shouldNotify(db, item)) return false;

  const style = getEffectiveStyle(db, item);
  const content = buildContent(item, type);
  const silent = style === 'popup-only';
  const icon = getNotificationIcon();
  const clickKey = `ilrs-${item?.id || 'general'}-${Date.now()}`;
  const itemId = item?.id || '';
  let actions;
  if (item?.id && onAction) {
    if (type === 'reminder') actions = REMINDER_TOAST_ACTIONS;
    else if (type === 'medicine' || type === 'bill' || type === 'habit') {
      actions = [{ type: 'button', text: 'Done' }];
    }
  }

  if (process.platform === 'win32') {
    app.setAppUserModelId(APP_ID);
  }

  const opts = {
    onClick, onAction, onDismiss, silent, type, icon, clickKey, actions, itemId,
  };

  // Electron native notifications — reliable click-to-focus when app is in tray
  const electronOk = showWithElectronNotification(content, opts);

  // Linux Electron toasts often fail silently — always try node-notifier too
  if (process.platform === 'linux') {
    const notifierOk = await showWithNodeNotifier(content, opts);
    return electronOk || notifierOk;
  }

  if (electronOk) return true;

  const notifierOk = await showWithNodeNotifier(content, opts);
  return notifierOk;
}

module.exports = {
  showDesktopNotification,
  dismissNotificationsForItem,
  dismissAllNotifications,
  shouldNotify,
  shouldPlaySound,
  getEffectiveStyle,
  buildContent,
  isQuietHours,
  getSetting,
  ICON_PATH,
  TRAY_ICON_PATH,
  isActivateResponse,
  isActionResponse,
  registerClickHandler,
  registerActionHandler,
  fireClickHandler,
  fireActionHandler,
  REMINDER_TOAST_ACTIONS,
};
