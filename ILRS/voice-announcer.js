/**
 * Speak reminder purpose in natural Indian English (main process + hidden window).
 */
const path = require('path');
const { BrowserWindow, ipcMain } = require('electron');
const { buildIndianEnglishAnnouncement } = require('./reminder-voice');

let voiceWindow;

function getVoiceWindow() {
  if (voiceWindow && !voiceWindow.isDestroyed()) return voiceWindow;
  voiceWindow = new BrowserWindow({
    show: false,
    width: 1,
    height: 1,
    webPreferences: { nodeIntegration: true, contextIsolation: false },
  });
  voiceWindow.loadFile(path.join(__dirname, 'voice-player.html'));
  return voiceWindow;
}

function shouldAnnounceVoice(db, item, getSetting) {
  if (!getSetting) return true;
  if (getSetting(db, 'voice_announcements', '1') !== '1') return false;
  const style = item.alert_style || '';
  if (style === 'silent') return false;
  const global = getSetting(db, 'notification_style', 'sound-popup');
  if (global === 'silent') return false;
  return true;
}

function announceReminder(item, type = 'reminder', mainWindowRef = null) {
  const text = buildIndianEnglishAnnouncement(item, type);
  if (!text) return Promise.resolve({ ok: false, err: 'empty text' });

  const requestId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

  // Visible main window: use renderer speech (shares user audio context)
  if (mainWindowRef
    && !mainWindowRef.isDestroyed()
    && mainWindowRef.isVisible()
    && mainWindowRef.webContents
    && !mainWindowRef.webContents.isDestroyed()) {
    mainWindowRef.webContents.send('speak-reminder', { text, requestId, type });
    return Promise.resolve({ ok: true, method: 'renderer-ipc', text });
  }

  return new Promise((resolve) => {
    const win = getVoiceWindow();
    const timeout = setTimeout(() => {
      cleanup();
      resolve({ ok: false, err: 'voice timeout', text });
    }, 50000);

    const cleanup = () => {
      clearTimeout(timeout);
      ipcMain.removeListener('voice-announce-result', onResult);
    };

    const onResult = (_e, payload) => {
      if (payload.requestId !== requestId) return;
      cleanup();
      resolve({ ...payload, method: 'hidden-window', text });
    };

    ipcMain.on('voice-announce-result', onResult);

    const send = () => win.webContents.send('speak-text', { text, requestId });
    if (win.webContents.isLoading()) win.webContents.once('did-finish-load', send);
    else send();
  });
}

module.exports = {
  announceReminder,
  shouldAnnounceVoice,
  getVoiceWindow,
};
