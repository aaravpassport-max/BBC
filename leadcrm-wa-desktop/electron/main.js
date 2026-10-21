/**
 * LeadCRM WA Desktop — WordPress + leadcrm-wa-build plugin
 * Bundled MariaDB + PHP + Electron shell
 */
const { app, BrowserWindow, dialog, shell, Menu } = require('electron');
const path = require('path');
const fs = require('fs');
const net = require('net');
const crypto = require('crypto');
const { spawn, spawnSync, execSync } = require('child_process');

const IS_WIN = process.platform === 'win32';
const IS_DEV = !app.isPackaged;

const APP_DIR = IS_DEV ? path.join(__dirname, '..', 'app') : path.join(process.resourcesPath, 'app');
const PHP_DIR = IS_DEV ? path.join(__dirname, '..', 'php') : path.join(process.resourcesPath, 'php');
const MARIADB_DIR = IS_DEV ? path.join(__dirname, '..', 'mariadb') : path.join(process.resourcesPath, 'mariadb');

const DATA_DIR = path.join(app.getPath('userData'), 'LeadCRM-WA');
const DB_DATA_DIR = path.join(DATA_DIR, 'mysql');
const LOG_DIR = path.join(DATA_DIR, 'logs');
const DB_CFG_FILE = path.join(DATA_DIR, 'db.json');
const WP_DIR = path.join(DATA_DIR, 'wordpress');
const INSTALLED_FLAG = path.join(DATA_DIR, 'wordpress-installed.json');
const APP_VERSION_FILE = path.join(DATA_DIR, 'app-version.json');
const LOGIN_FILE = path.join(DATA_DIR, 'LOGIN.txt');
const PHP_INI_FILE = path.join(DATA_DIR, 'php.ini');

const DEFAULT_PHP_PORT = 9780;
const DEFAULT_DB_PORT = 3308;
let phpPort = DEFAULT_PHP_PORT;
let dbPort = DEFAULT_DB_PORT;
let phpProcess = null;
let mariaProcess = null;
let mainWindow = null;
const SESSION_PARTITION = 'persist:leadcrm-wa';

[DATA_DIR, DB_DATA_DIR, LOG_DIR].forEach(d => fs.mkdirSync(d, { recursive: true }));

function resolveBinary(candidates) {
  for (const c of candidates) if (c && fs.existsSync(c)) return c;
  return null;
}

function which(cmd) {
  try {
    return execSync(IS_WIN ? `where ${cmd}` : `which ${cmd}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] })
      .trim().split(/\r?\n/)[0];
  } catch { return null; }
}

function getPhpBinary() {
  return resolveBinary([
    path.join(PHP_DIR, IS_WIN ? 'php.exe' : 'bin/php'),
    path.join(PHP_DIR, 'php.exe'),
    path.join(PHP_DIR, 'php'),
    which('php'),
  ]);
}

function getMysqldBinary() {
  return resolveBinary([
    path.join(MARIADB_DIR, 'bin', IS_WIN ? 'mysqld.exe' : 'mysqld'),
    which('mysqld'),
  ]);
}

function getMysqlBinary() {
  return resolveBinary([
    path.join(MARIADB_DIR, 'bin', IS_WIN ? 'mysql.exe' : 'mysql'),
    which('mysql'),
  ]);
}

function ensurePhpIni() {
  const phpExe = getPhpBinary();
  if (!phpExe) throw new Error('PHP executable not found in the application bundle.');

  const extDir = path.join(PHP_DIR, 'ext');
  if (fs.existsSync(extDir)) {
    const extensionDir = extDir.replace(/\\/g, '/');
    const ini = `; LeadCRM WA desktop — stored in user data (Program Files is read-only)
extension_dir = "${extensionDir}"
extension=pdo_mysql
extension=mysqli
extension=mbstring
extension=openssl
extension=curl
extension=fileinfo
max_execution_time = 300
memory_limit = 256M
`;
    fs.writeFileSync(PHP_INI_FILE, ini);
    return PHP_INI_FILE;
  }

  throw new Error(`PHP extensions folder missing: ${extDir}`);
}

function phpCliArgs(extraArgs) {
  const ini = ensurePhpIni();
  return ini ? ['-c', ini, ...extraArgs] : extraArgs;
}

function appendLog(file, data) {
  try { fs.appendFileSync(file, data); } catch {}
}

function waitForPort(port, ms = 60000, label = 'service') {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const tick = () => {
      const s = net.createConnection({ port, host: '127.0.0.1' }, () => { s.end(); resolve(); });
      s.on('error', () => {
        if (Date.now() - start > ms) {
          reject(new Error(`${label} did not start on port ${port} (timeout). See logs in ${LOG_DIR}`));
        } else setTimeout(tick, 400);
      });
    };
    tick();
  });
}

function portAvailable(port) {
  return new Promise((resolve) => {
    const server = net.createServer();
    server.unref();
    server.once('error', () => resolve(false));
    server.listen({ port, host: '127.0.0.1' }, () => server.close(() => resolve(true)));
  });
}

async function pickPort(preferred, from, to) {
  if (await portAvailable(preferred)) return preferred;
  for (let p = from; p <= to; p += 1) {
    if (await portAvailable(p)) return p;
  }
  throw new Error(
    `No free port (${preferred} or ${from}-${to} busy). Quit other LeadCRM copies, free those ports, or reboot Windows.`,
  );
}

function loadSavedPorts() {
  if (!fs.existsSync(DB_CFG_FILE)) return;
  try {
    const cfg = JSON.parse(fs.readFileSync(DB_CFG_FILE, 'utf8'));
    if (cfg.port) dbPort = cfg.port;
    if (cfg.phpPort) phpPort = cfg.phpPort;
  } catch {}
}

async function resolveRuntimePorts() {
  loadSavedPorts();
  dbPort = await pickPort(dbPort, DEFAULT_DB_PORT, DEFAULT_DB_PORT + 12);
  phpPort = await pickPort(phpPort, DEFAULT_PHP_PORT, DEFAULT_PHP_PORT + 12);
}

function syncBundledBin() {
  const binSrc = path.join(APP_DIR, 'bin');
  const binDest = path.join(DATA_DIR, 'bin');
  if (!fs.existsSync(binSrc)) return;
  fs.mkdirSync(binDest, { recursive: true });
  for (const name of fs.readdirSync(binSrc)) {
    const src = path.join(binSrc, name);
    if (fs.statSync(src).isFile()) fs.copyFileSync(src, path.join(binDest, name));
  }
}

function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    const f = path.join(src, e.name);
    const t = path.join(dest, e.name);
    if (e.isDirectory()) copyDir(f, t);
    else fs.copyFileSync(f, t);
  }
}

function ensureWordPressTree() {
  const bundled = path.join(APP_DIR, 'wordpress');
  if (!fs.existsSync(path.join(bundled, 'wp-includes'))) {
    throw new Error(`Bundled WordPress missing at ${bundled}`);
  }
  if (!fs.existsSync(path.join(WP_DIR, 'wp-includes'))) {
    appendLog(path.join(LOG_DIR, 'init.log'), 'Copying WordPress to user data…\n');
    copyDir(bundled, WP_DIR);
  }
  syncBundledBin();
  syncBundledPluginIfNeeded();
}

function ensureDesktopBootstrapFiles() {
  const muBundled = path.join(APP_DIR, 'wordpress', 'wp-content', 'mu-plugins', 'leadcrm-desktop.php');
  const muLive = path.join(WP_DIR, 'wp-content', 'mu-plugins', 'leadcrm-desktop.php');
  if (fs.existsSync(muBundled)) {
    fs.mkdirSync(path.dirname(muLive), { recursive: true });
    fs.copyFileSync(muBundled, muLive);
  }
  const bootBundled = path.join(APP_DIR, 'wordpress', 'leadcrm-desktop-bootstrap.php');
  const bootLive = path.join(WP_DIR, 'leadcrm-desktop-bootstrap.php');
  if (fs.existsSync(bootBundled)) {
    fs.copyFileSync(bootBundled, bootLive);
  }
  const cfg = path.join(WP_DIR, 'wp-config.php');
  if (fs.existsSync(cfg)) {
    let text = fs.readFileSync(cfg, 'utf8');
    if (!text.includes('LCRM_DESKTOP_APP')) {
      text = text.replace(
        "define('WP_DEBUG'",
        "define('LCRM_DESKTOP_APP', true);\ndefine('WP_DEBUG'",
      );
      fs.writeFileSync(cfg, text);
    }
  }
}

function syncBundledPluginIfNeeded() {
  if (!fs.existsSync(path.join(WP_DIR, 'wp-includes'))) return;
  const version = app.getVersion();
  let prev = '';
  if (fs.existsSync(APP_VERSION_FILE)) {
    try { prev = JSON.parse(fs.readFileSync(APP_VERSION_FILE, 'utf8')).version || ''; } catch {}
  }
  ensureDesktopBootstrapFiles();
  if (prev === version) return;

  const pluginSrc = path.join(APP_DIR, 'wordpress', 'wp-content', 'plugins', 'leadcrm-wa-build');
  const pluginDest = path.join(WP_DIR, 'wp-content', 'plugins', 'leadcrm-wa-build');
  if (fs.existsSync(pluginSrc)) {
    appendLog(path.join(LOG_DIR, 'init.log'), `Syncing LeadCRM plugin for app v${version}…\n`);
    fs.rmSync(pluginDest, { recursive: true, force: true });
    copyDir(pluginSrc, pluginDest);
  }
  fs.writeFileSync(APP_VERSION_FILE, JSON.stringify({ version, syncedAt: new Date().toISOString() }, null, 2));
}

function writeDbConfig(port) {
  let pass = crypto.randomBytes(16).toString('hex');
  if (fs.existsSync(DB_CFG_FILE)) {
    try { pass = JSON.parse(fs.readFileSync(DB_CFG_FILE, 'utf8')).pass; } catch {}
  }
  fs.writeFileSync(
    DB_CFG_FILE,
    JSON.stringify({ port, phpPort, pass, user: 'lcrmuser', name: 'leadcrm_wp' }, null, 2),
  );
  return pass;
}

function writeWpConfig(port, dbPass) {
  const cfg = path.join(WP_DIR, 'wp-config.php');
  if (fs.existsSync(cfg)) return;
  const salts = Array.from({ length: 8 }, () => crypto.randomBytes(32).toString('hex'));
  const content = `<?php
define('DB_NAME', 'leadcrm_wp');
define('DB_USER', 'lcrmuser');
define('DB_PASSWORD', '${dbPass.replace(/'/g, "\\'")}');
define('DB_HOST', '127.0.0.1:${port}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('AUTH_KEY',         '${salts[0]}');
define('SECURE_AUTH_KEY',  '${salts[1]}');
define('LOGGED_IN_KEY',    '${salts[2]}');
define('NONCE_KEY',        '${salts[3]}');
define('AUTH_SALT',        '${salts[4]}');
define('SECURE_AUTH_SALT', '${salts[5]}');
define('LOGGED_IN_SALT',   '${salts[6]}');
define('NONCE_SALT',       '${salts[7]}');
define('LCRM_DESKTOP_APP', true);
define('WP_DEBUG', false);
define('FS_METHOD', 'direct');
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
`;
  fs.writeFileSync(cfg, content);
}

function mariaConfigPath() {
  return path.join(DATA_DIR, IS_WIN ? 'my.ini' : 'my.cnf');
}

function writeMariaConfig(port) {
  const datadir = DB_DATA_DIR.replace(/\\/g, '/');
  const content = IS_WIN
    ? `[mysqld]\nport=${port}\ndatadir=${datadir}\nbind-address=127.0.0.1\nskip-networking=0\n`
    : `[mysqld]\nport=${port}\ndatadir=${datadir}\nbind-address=127.0.0.1\n`;
  fs.writeFileSync(mariaConfigPath(), content);
}

function hasMariaSystemTables() {
  return fs.existsSync(path.join(DB_DATA_DIR, 'mysql'));
}

async function startMariaDB() {
  const mysqld = getMysqldBinary();
  const mysql = getMysqlBinary();
  if (!mysqld) throw new Error('MariaDB not bundled. Run scripts/download-windows-runtime.js');

  writeMariaConfig(dbPort);
  const dbPass = writeDbConfig(dbPort);

  if (!hasMariaSystemTables()) {
    fs.mkdirSync(DB_DATA_DIR, { recursive: true });
    const installDb = path.join(MARIADB_DIR, 'bin', IS_WIN ? 'mysql_install_db.exe' : 'mariadb-install-db');
    if (fs.existsSync(installDb)) {
      spawnSync(installDb, [`--datadir=${DB_DATA_DIR}`], { stdio: 'inherit', windowsHide: true });
    } else {
      spawnSync(mysqld, ['--initialize-insecure', `--datadir=${DB_DATA_DIR}`], { stdio: 'inherit', windowsHide: true });
    }
  }

  mariaProcess = spawn(mysqld, [`--defaults-file=${mariaConfigPath()}`, `--datadir=${DB_DATA_DIR}`], {
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
  });
  const mariaLog = path.join(LOG_DIR, 'mariadb.log');
  mariaProcess.stdout?.on('data', d => appendLog(mariaLog, d.toString()));
  mariaProcess.stderr?.on('data', d => appendLog(mariaLog, d.toString()));
  mariaProcess.on('exit', code => {
    if (code) appendLog(mariaLog, `\n[mysqld exited with code ${code}]\n`);
  });

  await waitForPort(dbPort, 180000, 'MariaDB');

  const esc = dbPass.replace(/'/g, "''");
  const sql = [
    'CREATE DATABASE IF NOT EXISTS leadcrm_wp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
    `CREATE USER IF NOT EXISTS 'lcrmuser'@'127.0.0.1' IDENTIFIED BY '${esc}';`,
    `GRANT ALL PRIVILEGES ON leadcrm_wp.* TO 'lcrmuser'@'127.0.0.1';`,
    'FLUSH PRIVILEGES;',
  ].join(' ');
  try {
    execSync(`"${mysql}" -u root -h127.0.0.1 -P${dbPort} -e "${sql.replace(/"/g, '\\"')}"`, { stdio: 'pipe', timeout: 30000 });
  } catch (e) {
    appendLog(path.join(LOG_DIR, 'init.log'), `DB user setup: ${e.message}\n`);
  }

  writeWpConfig(dbPort, dbPass);
  return dbPass;
}

async function startPhp() {
  const php = getPhpBinary();
  if (!php) throw new Error('PHP not found');
  const router = path.join(WP_DIR, 'router.php');
  if (!fs.existsSync(router)) {
    fs.writeFileSync(router, `<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) return false;
require __DIR__ . '/index.php';
`);
  }

  phpProcess = spawn(php, phpCliArgs(['-S', `127.0.0.1:${phpPort}`, '-t', WP_DIR, router]), {
    cwd: WP_DIR,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
  });
  phpProcess.stderr.on('data', d => appendLog(path.join(LOG_DIR, 'php.log'), d.toString()));
  phpProcess.on('exit', code => {
    if (code) appendLog(path.join(LOG_DIR, 'php.log'), `\n[php exited with code ${code}]\n`);
  });
  await waitForPort(phpPort, 60000, 'PHP');
}

function syncDesktopEnv(dbPass) {
  const php = getPhpBinary();
  const syncScript = path.join(DATA_DIR, 'bin', 'sync-desktop-env.php');
  if (!php || !fs.existsSync(syncScript)) return;

  const siteUrl = `http://127.0.0.1:${phpPort}`;
  const result = spawnSync(php, phpCliArgs([syncScript]), {
    env: {
      ...process.env,
      LCRM_WP_ROOT: WP_DIR,
      LCRM_SITE_URL: siteUrl,
      LCRM_DB_HOST: `127.0.0.1:${dbPort}`,
      LCRM_DB_PASSWORD: dbPass,
    },
    encoding: 'utf8',
    timeout: 120000,
  });
  appendLog(path.join(LOG_DIR, 'init.log'), (result.stdout || '') + (result.stderr || ''));
}

function runWordPressInstall(dbPass) {
  if (fs.existsSync(INSTALLED_FLAG)) return;

  let adminPass = crypto.randomBytes(8).toString('hex') + 'A1!';
  const php = getPhpBinary();
  const installer = path.join(DATA_DIR, 'bin', 'install-wordpress.php');
  if (!fs.existsSync(installer)) throw new Error('install-wordpress.php missing');

  const siteUrl = `http://127.0.0.1:${phpPort}`;
  const result = spawnSync(php, phpCliArgs([installer]), {
    env: {
      ...process.env,
      LCRM_WP_ROOT: WP_DIR,
      LCRM_WP_CONFIG: path.join(WP_DIR, 'wp-config.php'),
      LCRM_SITE_URL: siteUrl,
      LCRM_ADMIN_USER: 'admin',
      LCRM_ADMIN_PASS: adminPass,
      LCRM_LOGIN_FILE: LOGIN_FILE,
    },
    encoding: 'utf8',
    timeout: 300000,
  });
  appendLog(path.join(LOG_DIR, 'install.log'), (result.stdout || '') + (result.stderr || ''));
  if (result.status !== 0) {
    throw new Error(`WordPress install failed:\n${result.stderr || result.stdout}`);
  }
  fs.writeFileSync(INSTALLED_FLAG, JSON.stringify({ at: new Date().toISOString(), siteUrl }, null, 2));
}

function needsAuthReroute(url, title = '') {
  if (typeof url !== 'string') return false;
  if (url.includes('wp-login.php')) return true;
  if (url.includes('/crm') && /login/i.test(title) && !/dashboard/i.test(title)) return true;
  return false;
}

function attachLoginBypass(win) {
  const bootstrap = bootstrapUrl();
  const rerouteLogin = (url, title = '') => {
    if (needsAuthReroute(url, title)) {
      win.loadURL(bootstrap);
      return true;
    }
    return false;
  };
  win.webContents.on('will-navigate', (event, url) => {
    if (rerouteLogin(url)) event.preventDefault();
  });
  win.webContents.on('will-redirect', (event, url) => {
    if (rerouteLogin(url)) event.preventDefault();
  });
  win.webContents.on('did-navigate', (_event, url) => {
    const title = win.webContents.getTitle();
    rerouteLogin(url, title);
  });
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith(`http://127.0.0.1:${phpPort}`)) return { action: 'allow' };
    shell.openExternal(url);
    return { action: 'deny' };
  });
}

function crmUrl() {
  return `http://127.0.0.1:${phpPort}/crm/`;
}

function bootstrapUrl() {
  return `http://127.0.0.1:${phpPort}/leadcrm-desktop-bootstrap.php`;
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1360,
    height: 860,
    minWidth: 1024,
    minHeight: 640,
    title: 'LeadCRM WA',
    webPreferences: {
      partition: SESSION_PARTITION,
      contextIsolation: true,
      nodeIntegration: false,
    },
    show: true,
    backgroundColor: '#0f172a',
  });
  attachLoginBypass(mainWindow);

  const openCrm = () => {
    if (mainWindow && !mainWindow.isDestroyed()) mainWindow.loadURL(crmUrl());
  };

  mainWindow.webContents.on('did-finish-load', () => {
    const url = mainWindow.webContents.getURL();
    const title = mainWindow.webContents.getTitle();
    if (needsAuthReroute(url, title)) {
      mainWindow.loadURL(bootstrapUrl());
    }
  });

  mainWindow.loadURL(crmUrl()).catch(() => mainWindow.loadURL(bootstrapUrl()));

  Menu.setApplicationMenu(Menu.buildFromTemplate([
    {
      label: 'LeadCRM',
      submenu: [
        { label: 'Open CRM', click: () => mainWindow?.loadURL(crmUrl()) },
        { label: 'Sign in again (local)', click: () => mainWindow?.loadURL(bootstrapUrl()) },
        { label: 'Advanced settings', click: () => mainWindow?.loadURL(`http://127.0.0.1:${phpPort}/wp-admin/`) },
        { label: 'Data folder', click: () => shell.openPath(DATA_DIR) },
        { type: 'separator' },
        { role: 'quit' },
      ],
    },
    { label: 'View', submenu: [{ role: 'reload' }, { role: 'toggleDevTools' }] },
  ]));
}

function cleanup() {
  if (phpProcess) try { phpProcess.kill(); } catch {}
  if (mariaProcess) try { mariaProcess.kill(); } catch {}
}

app.on('ready', async () => {
  if (!app.requestSingleInstanceLock()) return app.quit();

  const splash = new BrowserWindow({
    width: 440,
    height: 220,
    frame: false,
    center: true,
    resizable: false,
    alwaysOnTop: true,
    backgroundColor: '#0f172a',
    webPreferences: { contextIsolation: true, nodeIntegration: false },
  });
  splash.loadURL(
    'data:text/html;charset=utf-8,' + encodeURIComponent(
      '<body style="margin:0;background:#0f172a;color:#fff;font-family:Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column">'
      + '<div style="font-size:20px;font-weight:600;margin-bottom:10px">LeadCRM WA</div>'
      + '<div style="color:#94a3b8;font-size:13px">Starting local CRM services…</div></body>',
    ),
  );

  try {
    await resolveRuntimePorts();
    ensureWordPressTree();
    const dbPass = await startMariaDB();
    await startPhp();
    syncDesktopEnv(dbPass);
    runWordPressInstall(dbPass);
    splash.close();
    createWindow();
  } catch (e) {
    appendLog(path.join(LOG_DIR, 'startup.log'), `${e.stack || e.message}\n`);
    splash.close();
    const sessionHint = String(e.message).includes('LeadCRM session')
      ? '\n\nYou may be on an old installer (v1.0.3 or earlier). Install LeadCRM-WA-Setup-1.0.6.exe from GitHub Releases.'
      : '';
    dialog.showErrorBox(
      'LeadCRM failed to start',
      `${e.message}${sessionHint}\n\nData folder:\n${DATA_DIR}\n\nLogs:\n${LOG_DIR}`,
    );
    app.quit();
  }
});

app.on('window-all-closed', () => { cleanup(); app.quit(); });
app.on('before-quit', cleanup);
