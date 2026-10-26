/**
 * LeadCRM Desktop — Electron main process
 * Starts bundled LeadCRM (PostgreSQL + API + Next.js) and opens the login UI.
 */
const { app, BrowserWindow, dialog, Menu, shell } = require('electron');
const path = require('path');
const fs = require('fs');
const net = require('net');
const { spawn } = require('child_process');

const IS_WIN = process.platform === 'win32';
const IS_DEV = !app.isPackaged;

const FRONTEND_PORT = 13100;
const BACKEND_PORT = 13101;

function appRoot() {
  if (IS_DEV) return path.resolve(__dirname, '../../leadcrm');
  return path.join(process.resourcesPath, 'leadcrm');
}

function postgresBinDir() {
  if (IS_DEV) {
    try {
      const { execSync } = require('child_process');
      return execSync('pg_config --bindir', { encoding: 'utf8' }).trim();
    } catch {
      return null;
    }
  }
  const bundled = path.join(process.resourcesPath, 'postgres', 'bin');
  return fs.existsSync(path.join(bundled, IS_WIN ? 'initdb.exe' : 'initdb')) ? bundled : null;
}

function dataDir() {
  return path.join(app.getPath('userData'), 'leadcrm-data');
}

let mainWindow = null;
let launcher = null;

function waitForPort(port, attempts = 120) {
  return new Promise((resolve, reject) => {
    let left = attempts;
    const tick = () => {
      const socket = net.createConnection({ port, host: '127.0.0.1' }, () => {
        socket.end();
        resolve();
      });
      socket.on('error', () => {
        left -= 1;
        if (left <= 0) reject(new Error(`Timed out waiting for port ${port}`));
        else setTimeout(tick, 500);
      });
    };
    tick();
  });
}

function startLauncher() {
  const root = appRoot();
  const launcherScript = path.join(root, 'scripts/desktop-launcher.cjs');
  if (!fs.existsSync(launcherScript)) {
    throw new Error(`LeadCRM launcher not found at ${launcherScript}`);
  }

  const pgBin = postgresBinDir();
  const env = {
    ...process.env,
    LEADCRM_APP_ROOT: root,
    LEADCRM_DATA_DIR: dataDir(),
    LEADCRM_FRONTEND_PORT: String(FRONTEND_PORT),
    LEADCRM_BACKEND_PORT: String(BACKEND_PORT),
    LEADCRM_DESKTOP_QUIET: '1',
    ELECTRON_RUN_AS_NODE: '1',
  };
  if (pgBin) env.POSTGRES_BIN = pgBin;

  launcher = spawn(process.execPath, [launcherScript], {
    env,
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
  });

  const logPath = path.join(dataDir(), 'electron-bridge.log');
  fs.mkdirSync(dataDir(), { recursive: true });
  launcher.stdout.on('data', d => fs.appendFileSync(logPath, d));
  launcher.stderr.on('data', d => fs.appendFileSync(logPath, d));
  launcher.on('exit', code => {
    if (code && code !== 0 && mainWindow) {
      dialog.showErrorBox('LeadCRM stopped', `The application exited unexpectedly (code ${code}). See:\n${logPath}`);
    }
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1400,
    height: 900,
    minWidth: 1024,
    minHeight: 640,
    title: 'LeadCRM',
    icon: path.join(__dirname, 'leadcrm.ico'),
    autoHideMenuBar: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  const menu = Menu.buildFromTemplate([
    {
      label: 'File',
      submenu: [
        {
          label: 'Open data folder',
          click: () => shell.openPath(dataDir()),
        },
        {
          label: 'Show login credentials',
          click: () => {
            const loginFile = path.join(dataDir(), 'LOGIN.txt');
            if (fs.existsSync(loginFile)) {
              dialog.showMessageBox(mainWindow, {
                type: 'info',
                title: 'LeadCRM login',
                message: fs.readFileSync(loginFile, 'utf8'),
              });
            } else {
              dialog.showMessageBox(mainWindow, { type: 'warning', message: 'Login file not created yet. Complete first-run setup.' });
            }
          },
        },
        { type: 'separator' },
        { role: 'quit' },
      ],
    },
    {
      label: 'View',
      submenu: [
        { role: 'reload' },
        { role: 'forceReload' },
        { role: 'toggleDevTools' },
        { type: 'separator' },
        { role: 'resetZoom' },
        { role: 'zoomIn' },
        { role: 'zoomOut' },
      ],
    },
    {
      label: 'Help',
      submenu: [
        {
          label: 'LeadCRM documentation',
          click: () => shell.openExternal('https://github.com/reymarkjpanes/LeadCRM'),
        },
      ],
    },
  ]);
  Menu.setApplicationMenu(menu);

  mainWindow.loadURL(`http://127.0.0.1:${FRONTEND_PORT}/login`);
  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

async function bootstrap() {
  try {
    startLauncher();
    await waitForPort(FRONTEND_PORT);
    createWindow();
  } catch (error) {
    dialog.showErrorBox('LeadCRM failed to start', `${error.message}\n\nData: ${dataDir()}`);
    app.quit();
  }
}

app.whenReady().then(bootstrap);

app.on('window-all-closed', () => {
  if (launcher) {
    try {
      const stopScript = path.join(appRoot(), 'scripts/desktop-launcher.cjs');
      spawn(process.execPath, [stopScript, '--stop'], {
        env: { ...process.env, LEADCRM_DATA_DIR: dataDir(), LEADCRM_APP_ROOT: appRoot() },
        stdio: 'ignore',
        windowsHide: true,
      });
    } catch {}
    launcher.kill();
  }
  if (process.platform !== 'darwin') app.quit();
});

app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) createWindow();
});
