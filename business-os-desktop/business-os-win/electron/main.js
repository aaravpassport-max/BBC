/**
 * Business OS Desktop — Electron Main Process
 *
 * Lifecycle:
 *   1. Find free ports for PHP server and MariaDB
 *   2. Start MariaDB (mysqld) — wait for it to accept connections
 *   3. Start PHP built-in server pointing at server/public/
 *   4. Open BrowserWindow at http://127.0.0.1:{phpPort}/business/
 *   5. On quit: kill PHP → kill MariaDB → exit
 */

const { app, BrowserWindow, dialog, shell, Menu } = require('electron');
const path = require('path');
const fs = require('fs');
const net = require('net');
const crypto = require('crypto');
const { spawn, spawnSync, execSync } = require('child_process');

// ── Platform helpers (inlined to ensure packaging) ────────────────────────
const IS_WIN = process.platform === 'win32';

function resolveBinary(candidates) {
  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate)) return candidate;
  }
  return null;
}

function which(cmd) {
  try {
    return execSync(IS_WIN ? `where ${cmd}` : `which ${cmd}`, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim().split(/\r?\n/)[0];
  } catch {
    return null;
  }
}

function getPhpBinary(phpDir) {
  return resolveBinary([
    path.join(phpDir, IS_WIN ? 'php.exe' : 'bin/php'),
    path.join(phpDir, 'php.exe'),
    path.join(phpDir, 'php'),
    which('php'),
  ]);
}

function getMysqldBinary(mariaDir) {
  return resolveBinary([
    path.join(mariaDir, 'bin', IS_WIN ? 'mysqld.exe' : 'mysqld'),
    path.join(mariaDir, IS_WIN ? 'mysqld.exe' : 'mysqld'),
    which('mysqld'),
    which('mariadbd'),
  ]);
}

function getMysqlBinary(mariaDir) {
  return resolveBinary([
    path.join(mariaDir, 'bin', IS_WIN ? 'mysql.exe' : 'mysql'),
    path.join(mariaDir, 'mysql.exe'),
    which('mysql'),
  ]);
}

function getMysqlAdminBinary(mariaDir) {
  return resolveBinary([
    path.join(mariaDir, 'bin', IS_WIN ? 'mysqladmin.exe' : 'mysqladmin'),
    path.join(mariaDir, 'mysqladmin.exe'),
    which('mysqladmin'),
  ]);
}

function getMariaBasedir(mariaDir, mysqld) {
  if (fs.existsSync(path.join(mariaDir, 'bin', IS_WIN ? 'mysqld.exe' : 'mysqld'))) {
    return mariaDir;
  }
  if (IS_WIN) return mariaDir;
  try {
    const help = execSync(`"${mysqld}" --verbose --help 2>/dev/null | head -1`, {
      encoding: 'utf8',
      shell: true,
    });
    const match = help.match(/Ver ([\d.]+)/);
    if (match) return '/usr';
  } catch {}
  return '/usr';
}

function hasMariaSystemTables(dbDataDir) {
  const mysqlDir = path.join(dbDataDir, 'mysql');
  if (!fs.existsSync(mysqlDir)) return false;
  const markers = [
    'global_priv.MAD', 'global_priv.frm',
    'db.frm', 'db.MAD',
    'plugin.frm', 'plugin.MAD',
    'user.frm',
  ];
  return markers.some((name) => fs.existsSync(path.join(mysqlDir, name)));
}

function isDatadirReady(dbDataDir) {
  return hasMariaSystemTables(dbDataDir);
}

function wipeDatadir(dbDataDir) {
  if (fs.existsSync(dbDataDir)) {
    fs.rmSync(dbDataDir, { recursive: true, force: true });
  }
  fs.mkdirSync(dbDataDir, { recursive: true });
}

function initMariaDataDir(mysqld, basedir, dbDataDir, logDir) {
  if (isDatadirReady(dbDataDir)) return;

  wipeDatadir(dbDataDir);
  appendLog(path.join(logDir, 'init.log'), 'Initializing fresh MariaDB data directory…\n');

  const installDb = resolveBinary([
    path.join(basedir, 'bin', IS_WIN ? 'mariadb-install-db.exe' : 'mariadb-install-db'),
    path.join(basedir, 'bin', IS_WIN ? 'mysql_install_db.exe' : 'mysql_install_db'),
    path.join(basedir, 'scripts', IS_WIN ? 'mariadb-install-db.exe' : 'mariadb-install-db'),
    which(IS_WIN ? 'mariadb-install-db.exe' : 'mariadb-install-db'),
    which(IS_WIN ? 'mysql_install_db.exe' : 'mysql_install_db'),
  ]);

  if (installDb) {
    const args = ['--datadir', dbDataDir, '--basedir', basedir];
    if (IS_WIN) {
      args.push('--password=');
    } else {
      args.push('--auth-root-authentication-method=normal');
    }
    const result = spawnSync(installDb, args, {
      timeout: 180000,
      encoding: 'utf8',
      windowsHide: true,
      cwd: basedir,
    });
    const output = `${result.stdout || ''}\n${result.stderr || ''}`.trim();
    if (output) appendLog(path.join(logDir, 'init.log'), output + '\n');
    if (result.status !== 0) {
      throw new Error(`mariadb-install-db failed (code ${result.status})`);
    }
    if (!isDatadirReady(dbDataDir)) {
      throw new Error('MariaDB system tables were not created');
    }
    return;
  }

  const result = spawnSync(mysqld, [
    '--initialize-insecure',
    `--datadir=${dbDataDir}`,
    `--basedir=${basedir}`,
  ], { timeout: 180000, encoding: 'utf8', windowsHide: true, cwd: basedir });
  if (result.status !== 0) {
    throw new Error(`mysqld --initialize-insecure failed (code ${result.status})`);
  }
  if (!isDatadirReady(dbDataDir)) {
    throw new Error('MariaDB system tables were not created after initialize');
  }
}

function resetDatadirIfCorrupt(dbDataDir, logDir) {
  if (!fs.existsSync(dbDataDir)) return;
  const entries = fs.readdirSync(dbDataDir).filter((e) => e !== '.' && e !== '..');
  if (entries.length === 0) return;
  if (!isDatadirReady(dbDataDir)) {
    appendLog(path.join(logDir, 'init.log'), 'Detected incomplete database — wiping and reinitializing.\n');
    wipeDatadir(dbDataDir);
  }
}

function appendLog(file, data) {
  try { fs.appendFileSync(file, data); } catch {}
}

function tailLog(file, lines = 8) {
  try {
    if (!fs.existsSync(file)) return '';
    return fs.readFileSync(file, 'utf8').split('\n').filter(Boolean).slice(-lines).join('\n');
  } catch {
    return '';
  }
}

function spawnMysqld(mysqld, cfgFile, basedir, datadir, logDir) {
  const args = [
    `--defaults-file=${cfgFile}`,
    `--basedir=${basedir}`,
    `--datadir=${datadir}`,
  ];
  if (IS_WIN) args.push('--standalone', '--console');

  const logFile = path.join(logDir, 'mariadb.log');
  const proc = spawn(mysqld, args, {
    stdio: ['ignore', 'pipe', 'pipe'],
    detached: false,
    windowsHide: true,
    cwd: basedir,
  });

  proc.stdout.on('data', (d) => appendLog(logFile, d.toString()));
  proc.stderr.on('data', (d) => appendLog(logFile, d.toString()));
  proc.on('exit', (code) => appendLog(logFile, `mysqld exited with code ${code}\n`));
  return proc;
}

function sleep(seconds) {
  return new Promise((resolve) => setTimeout(resolve, seconds * 1000));
}

function mariaConfigPath(dataDir) {
  return path.join(dataDir, IS_WIN ? 'my.ini' : 'my.cnf');
}

function writeMariaConfig(dataDir, port, logDir, dbDataDir, basedir) {
  const cfgPath = mariaConfigPath(dataDir);
  const datadir = dbDataDir.replace(/\\/g, '/');
  const base = (basedir || '').replace(/\\/g, '/');
  const logError = path.join(logDir, 'mariadb.err').replace(/\\/g, '/');

  const content = IS_WIN
    ? `[mysqld]
port=${port}
basedir="${base}"
datadir="${datadir}"
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
bind-address=127.0.0.1
skip-networking=0
skip-name-resolve
max_allowed_packet=64M
innodb_buffer_pool_size=64M
log_error="${logError}"
slow_query_log=0
general_log=0
[client]
port=${port}
host=127.0.0.1
protocol=TCP`
    : `[mysqld]
port=${port}
datadir=${datadir}
socket=${path.join(dataDir, 'mysql.sock')}
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
bind-address=127.0.0.1
max_allowed_packet=64M
innodb_buffer_pool_size=128M
log_error=${logError}
slow_query_log=0
general_log=0
[client]
port=${port}
socket=${path.join(dataDir, 'mysql.sock')}`;

  fs.writeFileSync(cfgPath, content.trim() + '\n');
  return cfgPath;
}

// ── Paths ─────────────────────────────────────────────────────────────────
const IS_DEV = !app.isPackaged;
const APP_DIR = IS_DEV ? path.join(__dirname, '..') : path.join(process.resourcesPath, 'app');
const PHP_DIR = IS_DEV
  ? path.join(__dirname, '..', 'php')
  : path.join(process.resourcesPath, 'php');
const MARIADB_DIR = IS_DEV
  ? path.join(__dirname, '..', 'mariadb')
  : path.join(process.resourcesPath, 'mariadb');
const DATA_DIR = path.join(app.getPath('userData'), 'BusinessOS');
const DB_DATA_DIR = path.join(DATA_DIR, 'mysql');
const LOG_DIR = path.join(DATA_DIR, 'logs');
const DB_CFG_FILE = path.join(DATA_DIR, 'db.json');

[DATA_DIR, DB_DATA_DIR, LOG_DIR].forEach((dir) => {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
});

// ── Process handles ───────────────────────────────────────────────────────
let phpProcess = null;
let mariaProcess = null;
let mainWindow = null;
let phpPort = 9753;
let dbPort = 3307;
let appReady = false;

function findFreePort(preferred) {
  return new Promise((resolve) => {
    const server = net.createServer();
    server.listen(preferred, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(() => resolve(port));
    });
    server.on('error', () => {
      const fallback = net.createServer();
      fallback.listen(0, '127.0.0.1', () => {
        const port = fallback.address().port;
        fallback.close(() => resolve(port));
      });
    });
  });
}

function waitForPort(port, timeout = 30000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    function attempt() {
      const sock = new net.Socket();
      sock.setTimeout(1000);
      sock.connect(port, '127.0.0.1', () => {
        sock.destroy();
        resolve();
      });
      sock.on('error', () => {
        sock.destroy();
        if (Date.now() - start > timeout) {
          reject(new Error(`Port ${port} did not open within ${timeout}ms`));
        } else {
          setTimeout(attempt, 500);
        }
      });
      sock.on('timeout', () => {
        sock.destroy();
        setTimeout(attempt, 500);
      });
    }
    attempt();
  });
}

function writeDbConfig(port) {
  const pass = readOrGenerateDbPassword();
  fs.writeFileSync(DB_CFG_FILE, JSON.stringify({
    host: '127.0.0.1',
    port: String(port),
    name: 'businessos',
    user: 'bosuser',
    pass,
  }, null, 2));
  return pass;
}

function readOrGenerateDbPassword() {
  if (fs.existsSync(DB_CFG_FILE)) {
    try {
      const cfg = JSON.parse(fs.readFileSync(DB_CFG_FILE, 'utf8'));
      if (cfg.pass && cfg.pass.length > 8) return cfg.pass;
    } catch {}
  }
  return 'bos_' + crypto.randomBytes(16).toString('hex');
}

async function initMariaDbIfNeeded(mysqld, mysql, basedir, port, dbPass) {
  resetDatadirIfCorrupt(DB_DATA_DIR, LOG_DIR);
  if (isDatadirReady(DB_DATA_DIR)) return;

  const cfgFile = mariaConfigPath(DATA_DIR);
  writeMariaConfig(DATA_DIR, port, LOG_DIR, DB_DATA_DIR, basedir);

  try {
    initMariaDataDir(mysqld, basedir, DB_DATA_DIR, LOG_DIR);
  } catch (e) {
    appendLog(path.join(LOG_DIR, 'init.log'), 'Init error: ' + e.message + '\n');
    if (!isDatadirReady(DB_DATA_DIR)) throw e;
  }

  const tempProc = spawnMysqld(mysqld, cfgFile, basedir, DB_DATA_DIR, LOG_DIR);

  try {
    await waitForPort(port, 90000);
    const sql = [
      'CREATE DATABASE IF NOT EXISTS businessos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
      `CREATE USER IF NOT EXISTS 'bosuser'@'127.0.0.1' IDENTIFIED BY '${dbPass}';`,
      `CREATE USER IF NOT EXISTS 'bosuser'@'localhost' IDENTIFIED BY '${dbPass}';`,
      "GRANT ALL PRIVILEGES ON businessos.* TO 'bosuser'@'127.0.0.1';",
      "GRANT ALL PRIVILEGES ON businessos.* TO 'bosuser'@'localhost';",
      'FLUSH PRIVILEGES;',
    ].join(' ');
    const proto = IS_WIN ? ' --protocol=TCP' : '';
    execSync(`"${mysql}" -u root -P${port} -h127.0.0.1${proto} -e "${sql.replace(/"/g, '\\"')}"`, {
      timeout: 30000,
      stdio: 'pipe',
    });
  } catch (e) {
    appendLog(path.join(LOG_DIR, 'init.log'), 'User setup: ' + e.message + '\n');
  } finally {
    try { tempProc.kill('SIGTERM'); } catch {}
    await sleep(2);
  }
}

async function startMariaDB(port) {
  const mysqld = getMysqldBinary(MARIADB_DIR);
  if (!mysqld) {
    showFatalError('MariaDB not found', `Expected bundled MariaDB in:\n${MARIADB_DIR}`);
    return false;
  }

  const basedir = getMariaBasedir(MARIADB_DIR, mysqld);
  const mysql = getMysqlBinary(MARIADB_DIR);
  writeMariaConfig(DATA_DIR, port, LOG_DIR, DB_DATA_DIR, basedir);
  const dbPass = writeDbConfig(port);

  try {
    await initMariaDbIfNeeded(mysqld, mysql, basedir, port, dbPass);
  } catch (e) {
    appendLog(path.join(LOG_DIR, 'init.log'), 'Init error: ' + e.message + '\n');
  }

  const cfgFile = mariaConfigPath(DATA_DIR);
  mariaProcess = spawnMysqld(mysqld, cfgFile, basedir, DB_DATA_DIR, LOG_DIR);

  try {
    await waitForPort(port, 120000);
    return true;
  } catch (e) {
    const errLog = path.join(LOG_DIR, 'mariadb.err');
    const logHint = tailLog(errLog) || tailLog(path.join(LOG_DIR, 'mariadb.log'));
    showFatalError(
      'Database failed to start',
      `MariaDB did not respond in time.\n\nTry: delete this folder and relaunch:\n${DATA_DIR}\n\nLog:\n${logHint}`,
    );
    return false;
  }
}

async function startPhpServer(port) {
  const php = getPhpBinary(PHP_DIR);
  if (!php) {
    showFatalError('PHP not found', 'Install PHP 8.1+ or bundle it in the php/ folder.');
    return false;
  }

  const serverRoot = path.join(APP_DIR, 'server', 'public');
  const appDir = path.join(APP_DIR, 'app');

  phpProcess = spawn(php, [
    '-S', `127.0.0.1:${port}`,
    '-t', serverRoot,
    path.join(serverRoot, 'index.php'),
  ], {
    env: {
      ...process.env,
      BOS_PORT: String(port),
      BOS_DATA_DIR: DATA_DIR,
      BOS_DB_PORT: String(dbPort),
      BOS_DB_PASS: JSON.parse(fs.readFileSync(DB_CFG_FILE, 'utf8')).pass,
      BOS_APP_DIR: appDir,
    },
    stdio: ['ignore', 'pipe', 'pipe'],
    detached: false,
  });

  phpProcess.stderr.on('data', (d) => {
    const msg = d.toString();
    if (msg.includes('Fatal') || msg.includes('Parse error')) {
      fs.appendFileSync(path.join(LOG_DIR, 'php.log'), msg);
    }
  });

  phpProcess.on('exit', (code) => {
    fs.appendFileSync(path.join(LOG_DIR, 'php.log'), `PHP exited with code ${code}\n`);
  });

  try {
    await waitForPort(port, 15000);
    return true;
  } catch (e) {
    showFatalError(
      'Server failed to start',
      `PHP did not respond within 15 seconds.\n\nCheck: ${path.join(LOG_DIR, 'php.log')}`,
    );
    return false;
  }
}

function createWindow() {
  const iconPath = path.join(APP_DIR, 'app', IS_WIN ? 'favicon.ico' : 'favicon.svg');
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    title: 'Business OS',
    icon: fs.existsSync(iconPath) ? iconPath : undefined,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      webSecurity: true,
    },
    show: false,
    backgroundColor: '#0f172a',
  });

  mainWindow.loadURL(`http://127.0.0.1:${phpPort}/business/`);

  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
    appReady = true;
  });

  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('http://127.0.0.1')) return { action: 'allow' };
    shell.openExternal(url);
    return { action: 'deny' };
  });

  mainWindow.on('closed', () => { mainWindow = null; });

  const menu = Menu.buildFromTemplate([
    {
      label: 'Business OS',
      submenu: [
        { label: 'Open Data Folder', click: () => shell.openPath(DATA_DIR) },
        { label: 'Open Log Folder', click: () => shell.openPath(LOG_DIR) },
        { type: 'separator' },
        { label: 'Reload', accelerator: 'CmdOrCtrl+R', click: () => mainWindow?.reload() },
        { type: 'separator' },
        { label: 'Quit', accelerator: 'CmdOrCtrl+Q', role: 'quit' },
      ],
    },
    {
      label: 'View',
      submenu: [
        { label: 'Zoom In', accelerator: 'CmdOrCtrl+Plus', role: 'zoomIn' },
        { label: 'Zoom Out', accelerator: 'CmdOrCtrl+-', role: 'zoomOut' },
        { label: 'Reset Zoom', accelerator: 'CmdOrCtrl+0', role: 'resetZoom' },
        { type: 'separator' },
        ...(IS_DEV ? [{ label: 'DevTools', accelerator: 'F12', role: 'toggleDevTools' }] : []),
      ],
    },
  ]);
  Menu.setApplicationMenu(menu);
}

function showFatalError(title, detail) {
  dialog.showErrorBox(`Business OS — ${title}`, detail);
}

function cleanup() {
  if (phpProcess) {
    try { phpProcess.kill('SIGTERM'); } catch {}
    phpProcess = null;
  }
  if (mariaProcess) {
    try {
      const mysqladmin = getMysqlAdminBinary(MARIADB_DIR);
      if (mysqladmin && fs.existsSync(DB_CFG_FILE)) {
        const cfg = JSON.parse(fs.readFileSync(DB_CFG_FILE, 'utf8'));
        execSync(
          `"${mysqladmin}" -u bosuser -p${cfg.pass} -P${dbPort} -h127.0.0.1 shutdown`,
          { timeout: 5000, stdio: 'ignore' },
        );
      }
    } catch {}
    try { mariaProcess.kill('SIGTERM'); } catch {}
    mariaProcess = null;
  }
}

app.on('ready', async () => {
  if (!app.requestSingleInstanceLock()) {
    app.quit();
    return;
  }

  phpPort = await findFreePort(9753);
  dbPort = await findFreePort(3307);

  const splash = new BrowserWindow({
    width: 400,
    height: 220,
    frame: false,
    center: true,
    resizable: false,
    alwaysOnTop: true,
    backgroundColor: '#0f172a',
    webPreferences: { contextIsolation: true, nodeIntegration: false },
  });
  splash.loadURL(
    'data:text/html,<body style="margin:0;background:#0f172a;display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;font-family:sans-serif;color:#fff"><div style="font-size:22px;font-weight:700;margin-bottom:12px">Business OS</div><div style="color:#94a3b8;font-size:13px">Starting services…</div></body>',
  );

  const dbOk = await startMariaDB(dbPort);
  if (!dbOk) { splash.close(); app.quit(); return; }

  const phpOk = await startPhpServer(phpPort);
  if (!phpOk) { splash.close(); app.quit(); return; }

  splash.close();
  createWindow();
});

app.on('second-instance', () => {
  if (mainWindow) {
    if (mainWindow.isMinimized()) mainWindow.restore();
    mainWindow.focus();
  }
});

app.on('window-all-closed', () => {
  cleanup();
  app.quit();
});

app.on('before-quit', cleanup);

process.on('exit', cleanup);
process.on('SIGINT', () => { cleanup(); process.exit(0); });
process.on('SIGTERM', () => { cleanup(); process.exit(0); });
