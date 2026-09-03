/**
 * Play notification sounds — renderer-first (verified), native OS fallback for tray mode.
 */
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const { BrowserWindow } = require('electron');
const { resolveSoundId } = require('./alarm');

let soundWindow;
let lastPlayback = null;

function getSoundPath(soundId) {
  const file = resolveSoundId(soundId);
  const soundPath = path.join(__dirname, 'assets', 'sounds', `${file}.wav`);
  return fs.existsSync(soundPath) ? soundPath : null;
}

function getSoundWindow() {
  if (soundWindow && !soundWindow.isDestroyed()) return soundWindow;
  soundWindow = new BrowserWindow({
    show: false,
    width: 1,
    height: 1,
    webPreferences: { nodeIntegration: true, contextIsolation: false },
  });
  soundWindow.loadFile(path.join(__dirname, 'sound-player.html'));
  return soundWindow;
}

function playWithNative(soundPath) {
  return new Promise((resolve) => {
    const done = (ok, method, err = null) => resolve({ ok, method, err });

    if (process.platform === 'win32') {
      const escaped = soundPath.replace(/'/g, "''");
      const child = spawn('powershell', [
        '-NoProfile', '-Command',
        `(New-Object System.Media.SoundPlayer '${escaped}').PlaySync()`,
      ], { stdio: 'ignore', windowsHide: true });
      child.on('error', (e) => done(false, 'powershell', e.message));
      child.on('close', (code) => done(code === 0, 'powershell', code === 0 ? null : `exit ${code}`));
      return;
    }

    if (process.platform === 'darwin') {
      const child = spawn('afplay', [soundPath], { stdio: 'ignore' });
      child.on('error', (e) => done(false, 'afplay', e.message));
      child.on('close', (code) => done(code === 0, 'afplay', code === 0 ? null : `exit ${code}`));
      return;
    }

    const players = [
      ['ffplay', ['-nodisp', '-autoexit', '-loglevel', 'quiet', soundPath]],
      ['paplay', [soundPath]],
      ['aplay', ['-q', soundPath]],
    ];

    function tryPlayer(index) {
      if (index >= players.length) return done(false, 'native', 'no native player succeeded');
      const [cmd, args] = players[index];
      const child = spawn(cmd, args, { stdio: 'ignore' });
      child.on('error', () => tryPlayer(index + 1));
      child.on('close', (code) => (code === 0 ? done(true, cmd) : tryPlayer(index + 1)));
    }
    tryPlayer(0);
  });
}

function playWithHiddenWindow(soundPath, repeat = 2) {
  return new Promise((resolve) => {
    const { ipcMain } = require('electron');
    const requestId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const url = `file://${soundPath.replace(/\\/g, '/')}`;
    const win = getSoundWindow();

    const timeout = setTimeout(() => {
      cleanup();
      resolve({ ok: false, method: 'electron-file', err: 'timeout' });
    }, 12000);

    const cleanup = () => {
      clearTimeout(timeout);
      ipcMain.removeListener('sound-playback-result', onResult);
    };

    const onResult = (_e, payload) => {
      if (payload.requestId !== requestId) return;
      cleanup();
      resolve(payload);
    };

    ipcMain.once('sound-playback-result', onResult);

    const send = () => win.webContents.send('play-sound', { url, repeat, requestId });
    if (win.webContents.isLoading()) win.webContents.once('did-finish-load', send);
    else send();
  });
}

/**
 * @param {string} soundId
 * @param {number} repeat
 * @param {import('electron').BrowserWindow|null} mainWindowRef
 */
async function playAlertSound(soundId, repeat = 2, mainWindowRef = null) {
  const soundPath = getSoundPath(soundId);
  if (!soundPath) {
    lastPlayback = { ok: false, err: 'missing file', soundId };
    return false;
  }

  // 1) Renderer in main window — verified working in E2E tests
  if (mainWindowRef && !mainWindowRef.isDestroyed() && mainWindowRef.webContents && !mainWindowRef.webContents.isDestroyed()) {
    mainWindowRef.webContents.send('play-alert-sound', soundId);
    lastPlayback = { ok: true, method: 'renderer-ipc', soundId };
    return true;
  }

  // 2) Native OS player — works when app is in tray with no renderer focus
  const native = await playWithNative(soundPath);
  if (native.ok) {
    lastPlayback = { ...native, soundId, soundPath };
    return true;
  }

  // 3) Hidden helper window
  const hidden = await playWithHiddenWindow(soundPath, repeat);
  lastPlayback = { ...hidden, soundId, soundPath, nativeErr: native.err };
  return hidden.ok;
}

function getLastPlayback() {
  return lastPlayback;
}

module.exports = { playAlertSound, getLastPlayback, getSoundPath, playWithNative, playWithHiddenWindow };
