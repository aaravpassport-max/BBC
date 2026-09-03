/**
 * Play notification sounds from the Electron main process (works when minimized to tray).
 */
const path = require('path');
const fs = require('fs');
const { BrowserWindow } = require('electron');
const { resolveSoundId } = require('./alarm');

let soundWindow;

function getSoundWindow() {
  if (soundWindow && !soundWindow.isDestroyed()) return soundWindow;

  soundWindow = new BrowserWindow({
    show: false,
    width: 1,
    height: 1,
    webPreferences: {
      nodeIntegration: true,
      contextIsolation: false,
    },
  });

  soundWindow.loadURL(`data:text/html,${encodeURIComponent(`
    <!DOCTYPE html><html><body>
    <script>
      const { ipcRenderer } = require('electron');
      let current = null;
      ipcRenderer.on('play-sound', (_, payload) => {
        const { url, repeat } = payload;
        try {
          if (current) { current.pause(); current = null; }
          const audio = new Audio(url);
          audio.volume = 1;
          let plays = 0;
          audio.addEventListener('ended', () => {
            plays += 1;
            if (plays < repeat) {
              audio.currentTime = 0;
              audio.play().catch(() => {});
            }
          });
          current = audio;
          audio.play().catch(() => {});
        } catch (e) {}
      });
    </script>
    </body></html>
  `)}`);

  return soundWindow;
}

function playAlertSound(soundId, repeat = 2) {
  const file = resolveSoundId(soundId);
  const soundPath = path.join(__dirname, 'assets', 'sounds', `${file}.wav`);
  if (!fs.existsSync(soundPath)) return false;

  const url = `file://${soundPath.replace(/\\/g, '/')}`;
  const win = getSoundWindow();
  const send = () => win.webContents.send('play-sound', { url, repeat });

  if (win.webContents.isLoading()) {
    win.webContents.once('did-finish-load', send);
  } else {
    send();
  }
  return true;
}

module.exports = { playAlertSound };
