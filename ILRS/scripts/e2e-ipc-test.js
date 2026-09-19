#!/usr/bin/env node
/**
 * E2E via Electron IPC — mirrors renderer save flows.
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const { app, BrowserWindow } = require('electron');

const testUserData = path.join(os.tmpdir(), `ilrs-e2e-${Date.now()}`);
app.setPath('userData', testUserData);
process.chdir(path.join(__dirname, '..'));

let passed = 0;
let failed = 0;

function assert(name, cond, detail = '') {
  if (cond) {
    console.log(`✅ ${name}`);
    passed += 1;
  } else {
    console.error(`❌ ${name}${detail ? `: ${detail}` : ''}`);
    failed += 1;
  }
}

async function query(win, sql, params = []) {
  return win.webContents.executeJavaScript(`
    window.ilrs.query(${JSON.stringify(sql)}, ${JSON.stringify(params)})
  `);
}

app.whenReady().then(async () => {
  // Bootstrap DB via main process by requiring main - but main auto-starts app.
  // Instead create window with preload and load page.
  const win = new BrowserWindow({
    width: 1200,
    height: 800,
    show: false,
    webPreferences: {
      preload: path.join(__dirname, '..', 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  // Manually init DB like main.js
  const Database = require('better-sqlite3');
  const dbPath = path.join(testUserData, 'ilrs.db');
  const db = new Database(dbPath);

  // Register IPC handler
  const { ipcMain } = require('electron');
  ipcMain.handle('db-query', async (_event, { sql, params }) => {
    try {
      const stmt = db.prepare(sql);
      if (sql.trim().toUpperCase().startsWith('SELECT')) {
        return { success: true, data: stmt.all(...(params || [])) };
      }
      const result = stmt.run(...(params || []));
      return { success: true, data: result };
    } catch (err) {
      return { success: false, error: err.message };
    }
  });

  ipcMain.handle('get-app-path', async () => ({ userData: testUserData, documents: testUserData }));
  ipcMain.handle('test-notification', async () => ({ success: true }));

  // Create schema
  const schema = fs.readFileSync(path.join(__dirname, '..', 'main.js'), 'utf8');
  const schemaMatch = schema.match(/db\.exec\(`([\s\S]*?)`\);/);
  if (schemaMatch) db.exec(schemaMatch[1]);

  await win.loadFile(path.join(__dirname, '..', 'src', 'index.html'));
  await new Promise((r) => setTimeout(r, 3500));

  // Dismiss onboarding if present
  await win.webContents.executeJavaScript(`
    (async () => {
      if (document.getElementById('onboard-modal')) {
        await closeOnboard('dashboard');
      }
    })()
  `).catch(() => {});
  await new Promise((r) => setTimeout(r, 1500));

  const today = new Date().toISOString().split('T')[0];
  const id = 'test-' + Date.now();

  // Test saveReminder INSERT (the bug fix)
  const saveResult = await query(win,
    `INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,end_date,priority,urgency_quadrant,alert_style,snooze_duration,assigned_to,is_private,notes,tags,next_fire,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',datetime('now'),datetime('now'))`,
    [id, 'E2E Dashboard Test', 'reminder', 'general', 'Testing', 'once', '15:00', today, '', 'normal', 'important-not-urgent', 'sound-popup', 10, 'me', 0, '', '[]', `${today}T15:00:00`]
  );
  assert('saveReminder INSERT via IPC', saveResult.success === true, saveResult.error);

  const rows = await query(win, "SELECT * FROM reminders WHERE status != 'deleted' AND title=?", ['E2E Dashboard Test']);
  assert('reminder persisted in DB', rows.success && rows.data?.length === 1);

  const dashFilter = await query(win, "SELECT COUNT(*) as c FROM reminders WHERE status='active' AND (start_date='' OR start_date <= ?)", [today]);
  assert('dashboard filter finds active reminders', dashFilter.success && dashFilter.data?.[0]?.c >= 1);

  // Test quick-add UI flow
  const quickResult = await win.webContents.executeJavaScript(`
    (async () => {
      const input = document.getElementById('quick-input');
      input.value = 'Quick add E2E test daily';
      await handleQuickAdd();
      await new Promise(r => setTimeout(r, 800));
      const found = await window.ilrs.query("SELECT title FROM reminders WHERE title LIKE '%Quick add E2E%' AND status != 'deleted'");
      return { ok: found.success && found.data.length > 0, error: found.error };
    })()
  `);
  assert('quick-add saves reminder', quickResult.ok, quickResult.error);

  // Navigate to reminders and capture UI
  await win.webContents.executeJavaScript(`navigate('reminders')`);
  await new Promise((r) => setTimeout(r, 1500));

  const listText = await win.webContents.executeJavaScript(`document.getElementById('reminders-list')?.innerText || ''`);
  assert('reminders page shows E2E test', listText.includes('E2E Dashboard Test') || listText.includes('Quick add E2E'), listText.slice(0, 200));

  const outDir = '/opt/cursor/artifacts';
  fs.mkdirSync(outDir, { recursive: true });
  const img = await win.webContents.capturePage();
  fs.writeFileSync(path.join(outDir, 'ilrs-reminders-e2e.png'), img.toPNG());
  console.log('📸 Screenshot: /opt/cursor/artifacts/ilrs-reminders-e2e.png');

  await win.webContents.executeJavaScript(`navigate('settings')`);
  await new Promise((r) => setTimeout(r, 1200));
  const settingsHtml = await win.webContents.executeJavaScript(`document.getElementById('s-tone')?.innerHTML || ''`);
  assert('settings has old telephone ring sound', settingsHtml.includes('old-telephone-ring'));
  assert('settings has 10+ loud sounds', (settingsHtml.match(/<option/g) || []).length >= 10);

  const settingsShot = await win.webContents.capturePage();
  fs.writeFileSync(path.join(outDir, 'ilrs-settings-sounds.png'), settingsShot.toPNG());
  console.log('📸 Screenshot: /opt/cursor/artifacts/ilrs-settings-sounds.png');

  console.log(`\nE2E results: ${passed} passed, ${failed} failed`);
  app.exit(failed > 0 ? 1 : 0);
});

app.on('window-all-closed', () => {});
