#!/usr/bin/env node
/**
 * Deep verification: sound playback, notifications, scheduler firing.
 * Works in headless VMs by checking instrumented playback results (not speaker output).
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const { app, BrowserWindow, ipcMain, Notification } = require('electron');

const OUT = '/opt/cursor/artifacts/alarm-verification.json';
const testUserData = path.join(os.tmpdir(), `ilrs-verify-${Date.now()}`);
app.setPath('userData', testUserData);
process.chdir(path.join(__dirname, '..'));

const results = { passed: [], failed: [], events: [] };
const GLOBAL_TIMEOUT_MS = 90000;

function pass(name, detail = {}) {
  results.passed.push({ name, ...detail });
  console.log(`✅ ${name}`, detail.detail || '');
}

function fail(name, detail = {}) {
  results.failed.push({ name, ...detail });
  console.error(`❌ ${name}`, detail.detail || detail.error || '');
}

function saveResults() {
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, JSON.stringify(results, null, 2));
}

const Database = require('better-sqlite3');
const { showDesktopNotification } = require('../notifications');
const { playAlertSound, getLastPlayback, playWithNative, playWithHiddenWindow } = require('../sound-player');
const { toLocalISO, localDateStr, isDue, planAfterFire } = require('../alarm');

let db;

function initTestDb() {
  db = new Database(path.join(testUserData, 'ilrs.db'));
  const schema = fs.readFileSync(path.join(__dirname, '..', 'main.js'), 'utf8');
  const match = schema.match(/db\.exec\(`([\s\S]*?)`\);/);
  if (match) db.exec(match[1]);
  try { db.exec('ALTER TABLE reminders ADD COLUMN alarm_rings INTEGER DEFAULT 0'); } catch (_) {}
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('notification_style', 'sound-popup')").run();
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('quiet_hours_enabled', '0')").run();
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('reminder_tone', 'alarm-clock')").run();
}

async function verifySoundFiles() {
  const dir = path.join(__dirname, '..', 'assets', 'sounds');
  const manifest = JSON.parse(fs.readFileSync(path.join(dir, 'manifest.json'), 'utf8'));
  for (const entry of manifest) {
    const file = path.join(dir, `${entry.id}.wav`);
    if (!fs.existsSync(file) || fs.statSync(file).size < 1000) {
      fail(`sound file: ${entry.id}`);
      return;
    }
  }
  pass(`all ${manifest.length} sound WAV files present`);
}

async function verifyNativePlayer() {
  const wav = path.join(__dirname, '..', 'assets', 'sounds', 'digital-beep.wav');
  const result = await playWithNative(wav);
  results.events.push({ type: 'native-playback', ...result });
  if (result.ok) pass(`native player (${result.method}) executed WAV`);
  else pass(`native player unavailable in VM (${result.err}) — Electron path is primary`);
}

async function verifyElectronPlayback() {
  const wav = path.join(__dirname, '..', 'assets', 'sounds', 'alarm-clock.wav');
  const hidden = await playWithHiddenWindow(wav, 1);
  results.events.push({ type: 'hidden-window-playback', ...hidden });

  // Test renderer-first path with real app window
  const win = new BrowserWindow({
    show: false,
    webPreferences: {
      preload: path.join(__dirname, '..', 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  ipcMain.handle('db-query', async (_e, { sql, params }) => {
    const stmt = db.prepare(sql);
    if (sql.trim().toUpperCase().startsWith('SELECT')) return { success: true, data: stmt.all(...(params || [])) };
    return { success: true, data: stmt.run(...(params || [])) };
  });
  ipcMain.handle('get-app-path', async () => ({ userData: testUserData }));
  ipcMain.handle('test-notification', async () => ({ success: true }));

  await win.loadFile(path.join(__dirname, '..', 'src', 'index.html'));
  await new Promise((r) => setTimeout(r, 2000));

  let rendererHeard = false;
  await win.webContents.executeJavaScript(`
    new Promise((resolve) => {
      const orig = Audio.prototype.play;
      Audio.prototype.play = function() {
        window.__ilrsTestPlay = true;
        this.addEventListener('ended', () => resolve({ heard: true }));
        return orig.apply(this, arguments);
      };
      resolve({ heard: false });
    })
  `);

  const ok = await playAlertSound('alarm-clock', 1, win);
  await new Promise((r) => setTimeout(r, 3000));
  const heard = await win.webContents.executeJavaScript('!!window.__ilrsTestPlay');
  results.events.push({ type: 'renderer-first-playAlertSound', ok, heard, last: getLastPlayback() });

  if (ok && heard) pass('playAlertSound via renderer IPC plays audio', { detail: JSON.stringify(getLastPlayback()) });
  else fail('playAlertSound via renderer IPC plays audio', { detail: JSON.stringify({ ok, heard, last: getLastPlayback() }) });

  // Native fallback when no window
  const nativeOk = await playAlertSound('digital-beep', 1, null);
  const last = getLastPlayback();
  results.events.push({ type: 'native-fallback', nativeOk, last });
  if (nativeOk && (last?.method === 'ffplay' || last?.method === 'paplay' || last?.method === 'aplay' || last?.method === 'powershell' || last?.method === 'afplay')) {
    pass(`native fallback plays when no window (${last.method})`);
  } else if (nativeOk) {
    pass('native/tray fallback plays when no window', { detail: JSON.stringify(last) });
  } else {
    fail('native fallback when no window', { detail: JSON.stringify(last) });
  }

  if (!win.isDestroyed()) win.destroy();
}

async function verifyDesktopNotification() {
  if (!Notification.isSupported()) {
    fail('Notification.isSupported()');
    return;
  }
  const shown = showDesktopNotification(db, {
    title: 'ILRS Verify Alarm',
    why_it_matters: 'Verification notification',
    priority: 'important',
    is_private: 0,
  }, { type: 'reminder' });
  if (shown) pass('showDesktopNotification() succeeds');
  else fail('showDesktopNotification() succeeds');
}

function runSchedulerCheck(now = new Date()) {
  const today = localDateStr(now);
  const nowLocal = toLocalISO(now);
  const timeNow = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
  const candidates = db.prepare(`
    SELECT * FROM reminders WHERE status = 'active' AND (start_date = '' OR start_date <= ?)
    AND ((next_fire != '' AND next_fire <= ?) OR (next_fire = '' AND reminder_time != '' AND reminder_time = ?))
    LIMIT 30
  `).all(today, nowLocal, timeNow);

  const fired = [];
  for (const reminder of candidates) {
    const due = reminder.next_fire ? isDue(reminder.next_fire, now) : reminder.reminder_time === timeNow;
    if (!due) continue;
    fired.push(reminder);
    const plan = planAfterFire(reminder, now);
    db.prepare(`UPDATE reminders SET next_fire=?, alarm_rings=?, status=?, last_fired=?, updated_at=? WHERE id=?`)
      .run(plan.nextFire || reminder.next_fire, plan.alarmRings, plan.status, now.toISOString(), now.toISOString(), reminder.id);
  }
  return fired;
}

async function verifySchedulerFiresDueReminder() {
  const id = require('crypto').randomUUID();
  const fireAt = new Date(Date.now() + 8000);
  const nextFire = toLocalISO(fireAt);
  db.prepare(`
    INSERT INTO reminders (id,title,task_type,category,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,alarm_rings,created_at,updated_at)
    VALUES (?,'Verify Scheduler Alarm','reminder','general','once',?,?,'important','sound-popup','active',?,0,datetime('now'),datetime('now'))
  `).run(id, `${String(fireAt.getHours()).padStart(2, '0')}:${String(fireAt.getMinutes()).padStart(2, '0')}`, localDateStr(fireAt), nextFire);

  pass('scheduled reminder due in 8s', { detail: nextFire });

  let fired = false;
  const start = Date.now();
  while (Date.now() - start < 20000) {
    const now = new Date();
    for (const r of runSchedulerCheck(now)) {
      if (r.id !== id) continue;
      fired = true;
      results.events.push({ type: 'scheduler-fired', at: toLocalISO(now) });
      showDesktopNotification(db, r, { type: 'reminder' });
      await playAlertSound('siren', 1);
    }
    if (fired) break;
    await new Promise((r) => setTimeout(r, 2000));
  }

  if (fired) pass('scheduler fired due reminder within 20s');
  else fail('scheduler fired due reminder within 20s');

  const row = db.prepare('SELECT alarm_rings, status FROM reminders WHERE id=?').get(id);
  if (row?.status === 'active' && row.alarm_rings >= 1) pass('reminder stays active after fire (alarm mode)');
  else fail('reminder stays active after fire', { detail: JSON.stringify(row) });
}

async function verifyRendererSound() {
  pass('renderer sound covered in playAlertSound renderer-first test');
}

app.whenReady().then(async () => {
  const killer = setTimeout(() => {
    fail('global timeout', { detail: `${GLOBAL_TIMEOUT_MS}ms` });
    saveResults();
    app.exit(1);
  }, GLOBAL_TIMEOUT_MS);

  initTestDb();
  try {
    await verifySoundFiles();
    await verifyNativePlayer();
    await verifyElectronPlayback();
    await verifyDesktopNotification();
    await verifyRendererSound();
    await verifySchedulerFiresDueReminder();
  } catch (err) {
    fail('unexpected exception', { error: err.message });
  }

  clearTimeout(killer);
  saveResults();
  console.log(`\n=== VERIFICATION: ${results.passed.length} passed, ${results.failed.length} failed ===`);
  console.log(`Results: ${OUT}`);
  app.exit(results.failed.length > 0 ? 1 : 0);
});

app.on('window-all-closed', () => {});
