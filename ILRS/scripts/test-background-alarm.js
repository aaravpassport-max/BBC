#!/usr/bin/env node
/**
 * Verify alarms fire with sound when main window is hidden (tray/background mode).
 */
const path = require('path');
const os = require('os');
const { app, BrowserWindow } = require('electron');

const testUserData = path.join(os.tmpdir(), `ilrs-bg-${Date.now()}`);
app.setPath('userData', testUserData);
process.chdir(path.join(__dirname, '..'));

const Database = require('better-sqlite3');
const { playAlertSound } = require('../sound-player');
const { toLocalISO, localDateStr, shouldFireNow, planAfterFire } = require('../alarm');

let db;
let mainWindow;

function initDb() {
  db = new Database(path.join(testUserData, 'ilrs.db'));
  const schema = require('fs').readFileSync(path.join(__dirname, '..', 'main.js'), 'utf8');
  const match = schema.match(/db\.exec\(`([\s\S]*?)`\);/);
  if (match) db.exec(match[1]);
  try { db.exec('ALTER TABLE reminders ADD COLUMN alarm_rings INTEGER DEFAULT 0'); } catch (_) {}
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('notification_style', 'sound-popup')").run();
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('quiet_hours_enabled', '0')").run();
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('reminder_tone', 'alarm-clock')").run();
}

function runSchedulerCheck(now = new Date()) {
  const today = localDateStr(now);
  const rows = db.prepare(`
    SELECT * FROM reminders WHERE status = 'active' AND (start_date = '' OR start_date <= ?)
    AND (next_fire != '' OR reminder_time != '')
  `).all(today);
  const fired = [];
  for (const reminder of rows) {
    if (!shouldFireNow(reminder, now)) continue;
    fired.push(reminder);
    const plan = planAfterFire(reminder, now);
    const firedAt = toLocalISO(now);
    db.prepare(`UPDATE reminders SET next_fire=?, alarm_rings=?, status=?, last_fired=?, updated_at=? WHERE id=?`)
      .run(plan.nextFire || reminder.next_fire, plan.alarmRings, plan.status, firedAt, firedAt, reminder.id);
  }
  return fired;
}

app.whenReady().then(async () => {
  initDb();
  mainWindow = new BrowserWindow({ show: false, width: 400, height: 300, webPreferences: { nodeIntegration: false } });
  await mainWindow.loadURL('data:text/html,<html><body>hidden</body></html>');
  mainWindow.hide();

  const id = require('crypto').randomUUID();
  const fireAt = new Date(Date.now() + 3000);
  const nextFire = toLocalISO(fireAt);
  const time = `${String(fireAt.getHours()).padStart(2, '0')}:${String(fireAt.getMinutes()).padStart(2, '0')}`;
  db.prepare(`
    INSERT INTO reminders (id,title,task_type,category,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,alarm_rings,created_at,updated_at)
    VALUES (?,'Background Test','reminder','general','once',?,?,'important','sound-popup','active',?,0,datetime('now'),datetime('now'))
  `).run(id, time, localDateStr(fireAt), nextFire);

  console.log('Window hidden — waiting for alarm at', nextFire);
  let fired = false;
  const start = Date.now();
  while (Date.now() - start < 15000) {
    const now = new Date();
    for (const r of runSchedulerCheck(now)) {
      if (r.id !== id) continue;
      fired = true;
      const ok = await playAlertSound('alarm-clock', 1, mainWindow);
      console.log('Background sound playback:', ok ? 'OK' : 'FAILED');
      if (!ok) process.exitCode = 1;
    }
    if (fired) break;
    await new Promise((r) => setTimeout(r, 500));
  }

  if (!fired) {
    console.error('FAIL: alarm did not fire while window hidden');
    process.exitCode = 1;
  } else {
    console.log('PASS: background alarm fired with sound');
  }
  app.exit(process.exitCode || 0);
});

app.on('window-all-closed', () => {});
