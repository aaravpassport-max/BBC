const { app, BrowserWindow, Tray, Menu, ipcMain, dialog, shell, powerMonitor } = require('electron');
const path = require('path');
const fs = require('fs');
const { showDesktopNotification, getSetting, shouldPlaySound } = require('./notifications');
const { createTrayIcon, ensureWindowsToastSupport, showFatalError } = require('./windows-support');
const { playAlertSound } = require('./sound-player');
const { announceReminder, shouldAnnounceVoice } = require('./voice-announcer');
const {
  toLocalISO,
  localDateStr,
  localTimeStr,
  isDue,
  planAfterFire,
  computeNextFire,
  computeNextFireFromReminder,
  normalizeNextFire,
  isSnoozedFire,
  parseLocalDateTime,
  shouldFireNow,
  syncNextFireWithSystemClock,
  getSystemClockInfo,
} = require('./alarm');

let mainWindow;
let tray;
let db;
let schedulerTimer;
const firedKeys = new Set();
const pendingDueEvents = [];
let backgroundNoticeShown = false;
const startInBackground = process.argv.includes('--background') || process.argv.includes('--hidden');

function allowAppQuit() {
  app.isQuitting = true;
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.removeAllListeners('close');
    mainWindow.close();
  }
  if (tray) {
    try { tray.destroy(); } catch (_) { /* ignore */ }
    tray = null;
  }
  if (schedulerTimer) clearInterval(schedulerTimer);
  app.quit();
}

// Installer/update may pass --quit-for-install to close a running tray instance
if (process.argv.includes('--quit-for-install')) {
  app.whenReady().then(() => allowAppQuit());
}

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', (_event, argv) => {
    if (argv.includes('--quit-for-install')) {
      allowAppQuit();
      return;
    }
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.show();
      mainWindow.focus();
    }
  });
}

if (process.platform === 'win32') {
  process.on('SIGTERM', () => allowAppQuit());
  process.on('SIGINT', () => allowAppQuit());
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    title: 'ILRS — Modern Reminder',
    backgroundColor: '#0B1220',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
    show: false,
  });

  mainWindow.loadFile(path.join(__dirname, 'src', 'index.html'));

  mainWindow.once('ready-to-show', () => {
    const firstRun = db && getSetting(db, 'onboarding_done', '0') !== '1';
    if (!startInBackground || firstRun) mainWindow.show();
    flushPendingDueEvents();
    runSchedulerTick();
  });

  mainWindow.on('close', (e) => {
    if (!app.isQuitting) {
      e.preventDefault();
      mainWindow.hide();
      showBackgroundRunningNotice();
    }
  });
}

function showBackgroundRunningNotice() {
  if (backgroundNoticeShown) return;
  backgroundNoticeShown = true;
  showDesktopNotification(db, {
    title: 'ILRS is running in the background',
    why_it_matters: 'Reminders will still ring and notify. Right-click the tray icon → Quit to stop fully.',
    priority: 'important',
    alert_style: 'popup-only',
  }, { type: 'reminder', force: true, onClick: focusMainWindow });
  if (tray && process.platform === 'win32') {
    try {
      tray.displayBalloon({
        title: 'ILRS still running',
        content: 'Reminders work in the background. Tray → Quit to stop.',
        iconType: 'info',
      });
    } catch (_) { /* balloon optional */ }
  }
}

function applyAutoStart(enable) {
  try {
    const settings = {
      openAtLogin: Boolean(enable),
      path: process.execPath,
    };
    if (enable && (process.platform === 'win32' || process.platform === 'darwin' || process.platform === 'linux')) {
      settings.args = ['--background'];
    }
    app.setLoginItemSettings(settings);
  } catch (err) {
    console.error('applyAutoStart error:', err.message);
  }
}

function createTray() {
  const icon = createTrayIcon();
  if (icon.isEmpty()) {
    console.error('Tray icon missing — check assets/tray-icon.png');
  }

  tray = new Tray(icon);
  tray.setToolTip('ILRS — Life Reminder System (running)');

  const contextMenu = Menu.buildFromTemplate([
    { label: 'Open ILRS', click: () => { mainWindow.show(); mainWindow.focus(); } },
    { label: 'Quick Add Reminder', click: () => { mainWindow.show(); mainWindow.focus(); mainWindow.webContents.send('navigate', 'add'); } },
    { type: 'separator' },
    { label: 'Test Notification + Sound', click: () => sendTestNotification() },
    { label: 'Schedule Test Alarm (1 min)', click: () => scheduleTestAlarmFromTray() },
    { label: 'Pause Alerts (1 hour)', click: () => pauseAlerts(60) },
    { type: 'separator' },
    { label: 'Quit ILRS (stops all reminders)', click: () => { app.isQuitting = true; app.quit(); } },
  ]);

  tray.setContextMenu(contextMenu);
  tray.on('click', () => focusMainWindow());
}

function pauseAlerts(minutes) {
  mainWindow?.webContents.send('pause-alerts', minutes);
}

function focusMainWindow() {
  if (!mainWindow || mainWindow.isDestroyed()) return;

  if (mainWindow.isMinimized()) mainWindow.restore();
  mainWindow.setSkipTaskbar(false);
  mainWindow.show();

  if (process.platform === 'win32') {
    mainWindow.setAlwaysOnTop(true, 'screen-saver');
    mainWindow.focus();
    setTimeout(() => {
      if (mainWindow && !mainWindow.isDestroyed()) mainWindow.setAlwaysOnTop(false);
    }, 600);
  } else {
    mainWindow.focus();
  }

  try {
    app.focus({ steal: true });
  } catch (_) { /* unsupported on some platforms */ }

  if (typeof mainWindow.moveTop === 'function') mainWindow.moveTop();
}

function openReminderFromNotification(item, type = 'reminder') {
  focusMainWindow();
  flushPendingDueEvents();

  const payload = { ...item, _type: type, _fromNotificationClick: true };
  if (mainWindow && !mainWindow.isDestroyed() && mainWindow.webContents && !mainWindow.webContents.isDestroyed()) {
    mainWindow.webContents.send('notification-clicked', payload);
  }
}

function sendTestNotification() {
  showDesktopNotification(db, {
    title: 'ILRS Test Notification',
    why_it_matters: 'Desktop notifications are working correctly.',
    priority: 'important',
    alert_style: 'sound-popup',
    is_private: 0,
  }, { onClick: () => openReminderFromNotification({
    title: 'ILRS Test Notification',
    why_it_matters: 'Desktop notifications are working correctly.',
    priority: 'important',
    id: 'test-notification',
  }, 'reminder'), type: 'reminder', force: true });
  const tone = getSetting(db, 'reminder_tone', 'loud-chime');
  playAlertSound(tone, 2, mainWindow);
}

async function scheduleTestAlarmFromTray() {
  try {
    const id = require('crypto').randomUUID();
    const fireAt = new Date(Date.now() + 60000);
    const start = localDateStr(fireAt);
    const time = `${String(fireAt.getHours()).padStart(2, '0')}:${String(fireAt.getMinutes()).padStart(2, '0')}`;
    const nextFire = toLocalISO(fireAt);
    db.prepare(`
      INSERT INTO reminders (
        id, title, task_type, category, why_it_matters, repeat_type, reminder_time,
        start_date, priority, alert_style, status, next_fire, alarm_rings, created_at, updated_at
      ) VALUES (?, ?, 'reminder', 'general', ?, 'once', ?, ?, 'important', 'sound-popup', 'active', ?, 0, datetime('now'), datetime('now'))
    `).run(id, 'ILRS Test Alarm', 'Tray test alarm — should ring in 1 minute.', time, start, nextFire);
    showDesktopNotification(db, {
      title: 'Test alarm scheduled',
      why_it_matters: `Will ring at ${time}. ILRS can stay hidden in the tray.`,
      priority: 'important',
      alert_style: 'popup-only',
    }, { force: true, onClick: focusMainWindow });
  } catch (err) {
    console.error('scheduleTestAlarmFromTray:', err.message);
  }
}

function dispatchDueItem(item, type = 'reminder') {
  const minuteKey = `${type}:${item.id}:${toLocalISO().slice(0, 16)}`;
  if (firedKeys.has(minuteKey)) return;
  firedKeys.add(minuteKey);
  if (firedKeys.size > 500) firedKeys.clear();

  const payload = { ...item, _type: type };
  if (mainWindow && !mainWindow.isDestroyed() && mainWindow.webContents && !mainWindow.webContents.isDestroyed()) {
    mainWindow.webContents.send('reminder-due', payload);
  } else {
    pendingDueEvents.push(payload);
  }

  showDesktopNotification(db, item, { onClick: () => openReminderFromNotification(item, type), type });

  if (shouldPlaySound(db, item)) {
    const tone = item.alert_tone || getSetting(db, 'reminder_tone', 'loud-chime');
    const repeats = item.priority === 'critical' ? 4 : 3;
    playAlertSound(tone, repeats, mainWindow);
  }

  if (shouldAnnounceVoice(db, item, getSetting)) {
    setTimeout(() => announceReminder(item, type, mainWindow), 1800);
  }

  // Wake/show window for critical alarms even when running in background
  if (item.priority === 'critical' && mainWindow && !mainWindow.isDestroyed()) {
    if (!mainWindow.isVisible()) mainWindow.show();
  }
}

function flushPendingDueEvents() {
  if (!mainWindow || mainWindow.isDestroyed() || pendingDueEvents.length === 0) return;
  while (pendingDueEvents.length > 0) {
    mainWindow.webContents.send('reminder-due', pendingDueEvents.shift());
  }
}

function setupIPC() {
  ipcMain.handle('db-query', async (_event, { sql, params }) => {
    try {
      const stmt = db.prepare(sql);
      if (sql.trim().toUpperCase().startsWith('SELECT')) {
        return { success: true, data: stmt.all(...(params || [])) };
      }
      const result = stmt.run(...(params || []));
      return { success: true, data: result };
    } catch (err) {
      console.error('DB Error:', err.message, sql);
      return { success: false, error: err.message };
    }
  });

  ipcMain.on('send-notification', (_event, payload) => {
    showDesktopNotification(db, payload, {
      onClick: () => openReminderFromNotification(payload, payload.type || 'reminder'),
      type: payload.type || 'reminder',
    });
  });

  ipcMain.handle('test-notification', async () => {
    sendTestNotification();
    return { success: true };
  });

  ipcMain.handle('schedule-test-alarm', async () => {
    try {
      const id = require('crypto').randomUUID();
      const fireAt = new Date(Date.now() + 60000);
      const start = localDateStr(fireAt);
      const time = `${String(fireAt.getHours()).padStart(2, '0')}:${String(fireAt.getMinutes()).padStart(2, '0')}`;
      const nextFire = toLocalISO(fireAt);
      db.prepare(`
        INSERT INTO reminders (
          id, title, task_type, category, why_it_matters, repeat_type, reminder_time,
          start_date, priority, alert_style, status, next_fire, alarm_rings, created_at, updated_at
        ) VALUES (?, ?, 'reminder', 'general', ?, 'once', ?, ?, 'important', 'sound-popup', 'active', ?, 0, datetime('now'), datetime('now'))
      `).run(id, 'ILRS Test Alarm', 'This is a 1-minute test alarm. Mark done when you hear it.', time, start, nextFire);
      return { success: true, fireAt: nextFire, id };
    } catch (err) {
      return { success: false, error: err.message };
    }
  });

  ipcMain.on('open-backup-folder', (_event, folderPath) => {
    shell.openPath(folderPath);
  });

  ipcMain.handle('get-system-clock', async () => getSystemClockInfo());

  ipcMain.handle('compute-next-fire', async (_event, { startDate, time, repeatType }) => {
    const now = new Date();
    const start = startDate && !String(startDate).includes('Z') ? startDate : localDateStr(now);
    return { nextFire: computeNextFire(start, time, repeatType || 'once', now) };
  });

  ipcMain.handle('apply-auto-start', async (_event, enable) => {
    applyAutoStart(Boolean(enable));
    if (db) {
      db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('auto_start', ?)").run(enable ? '1' : '0');
    }
    return { success: true };
  });

  ipcMain.handle('export-data', async (_event, { format, data }) => {
    const { filePath } = await dialog.showSaveDialog(mainWindow, {
      title: 'Export ILRS Data',
      defaultPath: `ILRS-Export-${new Date().toISOString().split('T')[0]}.${format}`,
      filters: [{ name: format.toUpperCase(), extensions: [format] }],
    });
    if (filePath) {
      fs.writeFileSync(filePath, data, 'utf-8');
      return { success: true, path: filePath };
    }
    return { success: false };
  });

  ipcMain.handle('get-app-path', async () => ({
    userData: app.getPath('userData'),
    documents: app.getPath('documents'),
  }));

  ipcMain.on('minimize-to-tray', () => mainWindow.hide());
  ipcMain.on('show-window', focusMainWindow);
}

function initDatabase() {
  let Database;
  try {
    Database = require('better-sqlite3');
  } catch (e) {
    console.error('better-sqlite3 failed to load:', e.message);
    showFatalError(
      'ILRS Database Error',
      `Reminders cannot run because the database module failed to load.\n\n${e.message}\n\nTry reinstalling ILRS or run INSTALL.bat from the source folder.`
    );
    return null;
  }

  const dbPath = path.join(app.getPath('userData'), 'ilrs.db');
  db = new Database(dbPath);
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');

  db.exec(`
    CREATE TABLE IF NOT EXISTS reminders (
      id TEXT PRIMARY KEY,
      title TEXT NOT NULL,
      task_type TEXT DEFAULT 'reminder',
      category TEXT DEFAULT 'general',
      why_it_matters TEXT DEFAULT '',
      repeat_type TEXT DEFAULT 'once',
      repeat_value TEXT DEFAULT '',
      time_mode TEXT DEFAULT 'exact',
      reminder_time TEXT DEFAULT '',
      reminder_times TEXT DEFAULT '[]',
      start_date TEXT DEFAULT '',
      end_date TEXT DEFAULT '',
      priority TEXT DEFAULT 'normal',
      urgency_quadrant TEXT DEFAULT 'important-not-urgent',
      alert_style TEXT DEFAULT 'sound-popup',
      snooze_duration INTEGER DEFAULT 10,
      assigned_to TEXT DEFAULT 'me',
      is_private INTEGER DEFAULT 0,
      notes TEXT DEFAULT '',
      tags TEXT DEFAULT '[]',
      depends_on TEXT DEFAULT '',
      status TEXT DEFAULT 'active',
      snooze_count INTEGER DEFAULT 0,
      completion_count INTEGER DEFAULT 0,
      missed_count INTEGER DEFAULT 0,
      streak INTEGER DEFAULT 0,
      best_streak INTEGER DEFAULT 0,
      last_completed TEXT DEFAULT '',
      last_fired TEXT DEFAULT '',
      next_fire TEXT DEFAULT '',
      created_at TEXT DEFAULT (datetime('now')),
      updated_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS medicines (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      condition TEXT DEFAULT '',
      doses_per_day INTEGER DEFAULT 1,
      dose_times TEXT DEFAULT '["08:00"]',
      food_timing TEXT DEFAULT 'after',
      start_date TEXT DEFAULT (date('now')),
      end_date TEXT DEFAULT '',
      alert_style TEXT DEFAULT 'sound-popup',
      escalate INTEGER DEFAULT 1,
      family_notify TEXT DEFAULT '',
      track_doses INTEGER DEFAULT 1,
      notes TEXT DEFAULT '',
      status TEXT DEFAULT 'active',
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS medicine_logs (
      id TEXT PRIMARY KEY,
      medicine_id TEXT,
      dose_time TEXT,
      scheduled_time TEXT,
      status TEXT DEFAULT 'pending',
      taken_at TEXT DEFAULT '',
      note TEXT DEFAULT '',
      log_date TEXT DEFAULT (date('now')),
      FOREIGN KEY (medicine_id) REFERENCES medicines(id)
    );

    CREATE TABLE IF NOT EXISTS bills (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      bill_type TEXT DEFAULT 'electricity',
      amount REAL DEFAULT 0,
      due_day INTEGER DEFAULT 1,
      warning_days INTEGER DEFAULT 3,
      auto_monthly INTEGER DEFAULT 1,
      payment_status TEXT DEFAULT 'pending',
      account_info TEXT DEFAULT '',
      notes TEXT DEFAULT '',
      status TEXT DEFAULT 'active',
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS bill_history (
      id TEXT PRIMARY KEY,
      bill_id TEXT,
      paid_date TEXT,
      amount REAL DEFAULT 0,
      note TEXT DEFAULT '',
      FOREIGN KEY (bill_id) REFERENCES bills(id)
    );

    CREATE TABLE IF NOT EXISTS family_members (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      role TEXT DEFAULT 'other',
      email TEXT DEFAULT '',
      phone TEXT DEFAULT '',
      is_emergency_contact INTEGER DEFAULT 0,
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS habits (
      id TEXT PRIMARY KEY,
      reminder_id TEXT,
      name TEXT NOT NULL,
      frequency TEXT DEFAULT 'daily',
      target_time TEXT DEFAULT '08:00',
      streak INTEGER DEFAULT 0,
      best_streak INTEGER DEFAULT 0,
      completion_rate REAL DEFAULT 0,
      last_completed TEXT DEFAULT '',
      status TEXT DEFAULT 'active',
      created_at TEXT DEFAULT (datetime('now')),
      FOREIGN KEY (reminder_id) REFERENCES reminders(id)
    );

    CREATE TABLE IF NOT EXISTS habit_logs (
      id TEXT PRIMARY KEY,
      habit_id TEXT,
      log_date TEXT,
      completed INTEGER DEFAULT 0,
      note TEXT DEFAULT '',
      FOREIGN KEY (habit_id) REFERENCES habits(id)
    );

    CREATE TABLE IF NOT EXISTS reminder_logs (
      id TEXT PRIMARY KEY,
      reminder_id TEXT,
      action TEXT,
      timestamp TEXT DEFAULT (datetime('now')),
      note TEXT DEFAULT '',
      FOREIGN KEY (reminder_id) REFERENCES reminders(id)
    );

    CREATE TABLE IF NOT EXISTS settings (
      key TEXT PRIMARY KEY,
      value TEXT
    );

    CREATE TABLE IF NOT EXISTS checklists (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      list_type TEXT DEFAULT 'custom',
      progress INTEGER DEFAULT 0,
      total INTEGER DEFAULT 0,
      status TEXT DEFAULT 'active',
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS checklist_items (
      id TEXT PRIMARY KEY,
      checklist_id TEXT,
      title TEXT NOT NULL,
      done INTEGER DEFAULT 0,
      reminder_time TEXT DEFAULT '',
      sort_order INTEGER DEFAULT 0,
      FOREIGN KEY (checklist_id) REFERENCES checklists(id)
    );

    CREATE INDEX IF NOT EXISTS idx_reminders_next_fire ON reminders(next_fire);
    CREATE INDEX IF NOT EXISTS idx_reminders_status ON reminders(status);
    CREATE INDEX IF NOT EXISTS idx_medicine_logs_date ON medicine_logs(log_date);
    CREATE INDEX IF NOT EXISTS idx_habit_logs_date ON habit_logs(log_date);
  `);

  try { db.exec('ALTER TABLE reminders ADD COLUMN alarm_rings INTEGER DEFAULT 0'); } catch (_) { /* already exists */ }

  const defaultSettings = {
    user_name: 'Friend',
    notification_style: 'sound-popup',
    snooze_duration: '10',
    snooze_limit: '3',
    quiet_hours_start: '23:00',
    quiet_hours_end: '06:00',
    quiet_hours_enabled: '0',
    critical_override: '1',
    reminder_tone: 'loud-chime',
    appearance: 'dark',
    layout_density: 'comfortable',
    language: 'en',
    cloud_backup: '0',
    local_backup: '1',
    data_cleanup_days: '90',
    app_lock: '0',
    rewards_enabled: '1',
    urgency_matrix_widget: '0',
    auto_start: '1',
    voice_announcements: '1',
    onboarding_done: '0',
    app_pin: '',
  };

  const insertSetting = db.prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
  for (const [key, value] of Object.entries(defaultSettings)) {
    insertSetting.run(key, value);
  }

  return db;
}

function repairReminderSchedules() {
  if (!db) return;
  try {
    const version = Number(getSetting(db, 'schema_version', '0'));
    if (version < 5) {
      runTimezoneMigrationV5();
      db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '5')").run();
    }
    if (version < 6) {
      runSystemClockMigrationV6();
      db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '6')").run();
    }
    if (version < 7) {
      runBackgroundAlarmMigrationV7();
      db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '7')").run();
    }
  } catch (err) {
    console.error('repairReminderSchedules error:', err.message);
  }
}

function runBackgroundAlarmMigrationV7() {
  const now = new Date();
  syncAllSchedulesToSystemClock(now);
  // Ensure reminders are not silently blocked by quiet hours after upgrade
  db.prepare("UPDATE settings SET value = '0' WHERE key = 'quiet_hours_enabled' AND value = '1'").run();
  console.log('Background alarm migration v7: resynced schedules and relaxed quiet hours');
}

function runSystemClockMigrationV6() {
  const now = new Date();
  const rows = db.prepare(`
    SELECT id, start_date, reminder_time, repeat_type, next_fire
    FROM reminders WHERE status = 'active' AND reminder_time != ''
  `).all();

  const fix = db.prepare('UPDATE reminders SET next_fire = ?, last_fired = ? WHERE id = ?');
  let fixed = 0;

  for (const row of rows) {
    const normalized = normalizeNextFire(row.next_fire);
    if (normalized && isSnoozedFire(normalized, row.reminder_time, now)) {
      fix.run(normalized, '', row.id);
      fixed += 1;
      continue;
    }
    const synced = syncNextFireWithSystemClock(row, now);
    if (synced) {
      fix.run(synced, '', row.id);
      fixed += 1;
    }
  }

  console.log(`System-clock migration v6: resynced ${fixed} reminder schedule(s) to computer time`);
}

function syncAllSchedulesToSystemClock(now = new Date()) {
  if (!db) return;
  const rows = db.prepare(`
    SELECT id, start_date, reminder_time, repeat_type, next_fire
    FROM reminders WHERE status = 'active' AND reminder_time != ''
  `).all();

  const fix = db.prepare('UPDATE reminders SET next_fire = ? WHERE id = ?');
  for (const row of rows) {
    const normalized = normalizeNextFire(row.next_fire);
    if (normalized && isSnoozedFire(normalized, row.reminder_time, now)) continue;
    const synced = syncNextFireWithSystemClock(row, now);
    if (synced && synced !== normalized) fix.run(synced, row.id);
  }
}

function runTimezoneMigrationV5() {
  const now = new Date();
  const rows = db.prepare(`
    SELECT id, start_date, reminder_time, repeat_type, next_fire
    FROM reminders WHERE status = 'active'
  `).all();

  const fix = db.prepare('UPDATE reminders SET next_fire = ?, start_date = ? WHERE id = ?');
  let fixed = 0;

  for (const row of rows) {
    if (!row.reminder_time) continue;

    let startDate = row.start_date;
    if (!startDate || String(startDate).includes('Z')) {
      startDate = localDateStr(now);
    }

    const normalized = normalizeNextFire(row.next_fire);
    if (normalized && isSnoozedFire(normalized, row.reminder_time, now)) {
      fix.run(normalized, startDate, row.id);
      fixed += 1;
      continue;
    }

    const correct = computeNextFireFromReminder({ ...row, start_date: startDate }, now);
    if (correct) {
      fix.run(correct, startDate, row.id);
      fixed += 1;
    }
  }

  console.log(`Timezone migration v5: recalculated ${fixed} reminder schedule(s) using local time`);
}

function afterReminderFired(reminder, now = new Date()) {
  const plan = planAfterFire(reminder, now);
  const firedAt = toLocalISO(now);
  db.prepare(`
    UPDATE reminders
    SET next_fire = ?, alarm_rings = ?, status = ?, last_fired = ?, updated_at = ?
    WHERE id = ?
  `).run(plan.nextFire || reminder.next_fire, plan.alarmRings, plan.status, firedAt, firedAt, reminder.id);
}

function checkDueReminders(now = new Date()) {
  const today = localDateStr(now);

  // All timing decisions use the computer's local wall clock (shouldFireNow).
  const candidates = db.prepare(`
    SELECT * FROM reminders
    WHERE status = 'active'
    AND (start_date = '' OR start_date <= ?)
    AND (next_fire != '' OR reminder_time != '')
    ORDER BY next_fire ASC
    LIMIT 50
  `).all(today);

  for (const reminder of candidates) {
    if (!shouldFireNow(reminder, now)) continue;
    dispatchDueItem(reminder, 'reminder');
    afterReminderFired(reminder, now);
  }
}

function catchUpOverdueReminders() {
  if (!db) return;
  const now = new Date();
  const rows = db.prepare(`
    SELECT * FROM reminders
    WHERE status = 'active' AND (next_fire != '' OR reminder_time != '')
    ORDER BY next_fire ASC
    LIMIT 20
  `).all();

  for (const reminder of rows) {
    if (!shouldFireNow(reminder, now)) continue;
    dispatchDueItem(reminder, 'reminder');
    afterReminderFired(reminder, now);
  }
}

function checkDueMedicines(now = new Date()) {
  const today = localDateStr(now);
  const timeStr = localTimeStr(now);
  const medicines = db.prepare("SELECT * FROM medicines WHERE status = 'active'").all();

  for (const med of medicines) {
    let doseTimes = [];
    try { doseTimes = JSON.parse(med.dose_times || '[]'); } catch { doseTimes = []; }
    if (!doseTimes.includes(timeStr)) continue;

    const taken = db.prepare(`
      SELECT id FROM medicine_logs
      WHERE medicine_id = ? AND log_date = ? AND dose_time = ? AND status = 'taken'
    `).get(med.id, today, timeStr);
    if (taken) continue;

    dispatchDueItem(med, 'medicine');
  }
}

function checkDueBills(now) {
  const day = now.getDate();
  const bills = db.prepare("SELECT * FROM bills WHERE status = 'active'").all();

  for (const bill of bills) {
    const warningDays = Number(bill.warning_days) || 3;
    const daysUntilDue = bill.due_day >= day ? bill.due_day - day : (30 - day + bill.due_day);
    if (daysUntilDue > warningDays) continue;

    const paidThisMonth = db.prepare(`
      SELECT id FROM bill_history
      WHERE bill_id = ? AND paid_date LIKE ?
    `).get(bill.id, `${now.toISOString().slice(0, 7)}%`);
    if (paidThisMonth) continue;

    const priority = daysUntilDue <= 0 ? 'critical' : daysUntilDue <= 1 ? 'important' : 'normal';
    dispatchDueItem({ ...bill, priority }, 'bill');
  }
}

function runSchedulerTick() {
  if (!db) return;
  try {
    const now = new Date();
    checkDueReminders(now);
    checkDueMedicines(now);
    checkDueBills(now);
  } catch (err) {
    console.error('Scheduler error:', err.message);
  }
}

let syncCounter = 0;

function startScheduler() {
  if (schedulerTimer) clearInterval(schedulerTimer);
  runSchedulerTick();
  // Tick every second so alarms ring on the computer's minute boundary.
  schedulerTimer = setInterval(() => {
    runSchedulerTick();
    syncCounter += 1;
    if (syncCounter >= 300) {
      syncCounter = 0;
      syncAllSchedulesToSystemClock();
    }
  }, 1000);
}

function scheduleBackup() {
  const now = new Date();
  const next2AM = new Date(now);
  next2AM.setHours(2, 0, 0, 0);
  if (next2AM <= now) next2AM.setDate(next2AM.getDate() + 1);
  const msUntil2AM = next2AM - now;

  setTimeout(() => {
    performBackup();
    setInterval(performBackup, 24 * 60 * 60 * 1000);
  }, msUntil2AM);
}

function performBackup() {
  try {
    if (!db) return;
    const backupDir = path.join(app.getPath('userData'), 'backups');
    if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir, { recursive: true });
    const backupFile = path.join(backupDir, `ilrs-backup-${new Date().toISOString().split('T')[0]}.db`);
    db.backup(backupFile);
    console.log('Backup created:', backupFile);
  } catch (err) {
    console.error('Backup error:', err.message);
  }
}

app.whenReady().then(() => {
  if (process.platform === 'win32') {
    ensureWindowsToastSupport();
  }

  db = initDatabase();
  if (!db) {
    showFatalError(
      'ILRS Cannot Start',
      'The reminder database could not be initialized. Reminders, sounds, and notifications will not work.\n\nPlease reinstall ILRS-Setup from the latest release.'
    );
  }

  setupIPC();
  if (db) {
    repairReminderSchedules();
    syncAllSchedulesToSystemClock();
    startScheduler();
    scheduleBackup();
    applyAutoStart(getSetting(db, 'auto_start', '1') === '1');
    setTimeout(catchUpOverdueReminders, 1500);
  }
  createTray();
  createWindow();
  if (startInBackground && db && getSetting(db, 'onboarding_done', '0') === '1') {
    showBackgroundRunningNotice();
  }

  powerMonitor.on('resume', () => {
    console.log('System resumed — resyncing schedules to computer clock');
    syncAllSchedulesToSystemClock();
    catchUpOverdueReminders();
    runSchedulerTick();
  });
});

app.on('window-all-closed', () => {});

app.on('activate', () => {
  if (mainWindow) mainWindow.show();
});

app.on('before-quit', () => {
  app.isQuitting = true;
  if (schedulerTimer) clearInterval(schedulerTimer);
  if (tray) {
    try { tray.destroy(); } catch (_) { /* ignore */ }
    tray = null;
  }
});
