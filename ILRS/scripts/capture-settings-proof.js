#!/usr/bin/env node
const path = require('path');
const fs = require('fs');
const { app, BrowserWindow } = require('electron');

const OUT = '/opt/cursor/artifacts/ilrs-alarm-settings-proof.png';

app.whenReady().then(async () => {
  const win = new BrowserWindow({ width: 1280, height: 800, show: false, webPreferences: {
    preload: path.join(__dirname, '..', 'preload.js'), contextIsolation: true, nodeIntegration: false,
  }});
  const { ipcMain } = require('electron');
  const Database = require('better-sqlite3');
  const db = new Database(':memory:');
  db.exec(`CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings VALUES ('notification_style','sound-popup'),('reminder_tone','alarm-clock'),('quiet_hours_enabled','0');`);
  ipcMain.handle('db-query', async (_e, { sql, params }) => {
    const stmt = db.prepare(sql);
    if (sql.trim().toUpperCase().startsWith('SELECT')) return { success: true, data: stmt.all(...(params || [])) };
    return { success: true, data: stmt.run(...(params || [])) };
  });
  ipcMain.handle('get-app-path', async () => ({ userData: '/tmp' }));
  ipcMain.handle('test-notification', async () => ({ success: true }));
  ipcMain.handle('schedule-test-alarm', async () => ({ success: true, fireAt: 'test' }));

  await win.loadFile(path.join(__dirname, '..', 'src', 'index.html'));
  await new Promise(r => setTimeout(r, 2500));
  await win.webContents.executeJavaScript(`(async()=>{ if(document.getElementById('onboard-modal')) await closeOnboard('settings'); else navigate('settings'); })()`);
  await new Promise(r => setTimeout(r, 1500));
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, (await win.webContents.capturePage()).toPNG());
  console.log('Screenshot saved:', OUT);
  app.exit(0);
});
app.on('window-all-closed', () => {});
