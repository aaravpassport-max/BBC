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
const LOGIN_FILE = path.join(DATA_DIR, 'LOGIN.txt');
const PHP_INI_FILE = path.join(DATA_DIR, 'php.ini');

let phpPort = 9780;
let dbPort = 3308;
let phpProcess = null;
let mariaProcess = null;
let mainWindow = null;

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

function waitForPort(port, ms = 60000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const tick = () => {
      const s = net.createConnection({ port, host: '127.0.0.1' }, () => { s.end(); resolve(); });
      s.on('error', () => {
        if (Date.now() - start > ms) reject(new Error(`Port ${port} timeout`));
        else setTimeout(tick, 400);
      });
    };
    tick();
  });
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
  const binSrc = path.join(APP_DIR, 'bin');
  const binDest = path.join(DATA_DIR, 'bin');
  if (fs.existsSync(binSrc) && !fs.existsSync(binDest)) copyDir(binSrc, binDest);
  ensureDesktopBootstrapFiles();
}

function ensureDesktopBootstrapFiles() {
  const muBundled = path.join(APP_DIR, 'wordpress', 'wp-content', 'mu-plugins', 'leadcrm-desktop.php');
  const muLive = path.join(WP_DIR, 'wp-content', 'mu-plugins', 'leadcrm-desktop.php');
  if (fs.existsSync(muBundled)) {
    fs.mkdirSync(path.dirname(muLive), { recursive: true });
    fs.copyFileSync(muBundled, muLive);
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

function writeDbConfig(port) {
  let pass = crypto.randomBytes(16).toString('hex');
  if (fs.existsSync(DB_CFG_FILE)) {
    try { pass = JSON.parse(fs.readFileSync(DB_CFG_FILE, 'utf8')).pass; } catch {}
  }
  fs.writeFileSync(DB_CFG_FILE, JSON.stringify({ port, pass, user: 'lcrmuser', name: 'leadcrm_wp' }, null, 2));
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
    stdio: 'ignore',
    windowsHide: true,
  });

  await waitForPort(dbPort, 120000);

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
  await waitForPort(phpPort, 30000);
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

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1360,
    height: 860,
    minWidth: 1024,
    minHeight: 640,
    title: 'LeadCRM WA',
    webPreferences: { contextIsolation: true, nodeIntegration: false },
    show: false,
  });
  mainWindow.loadURL(`http://127.0.0.1:${phpPort}/crm/`);
  mainWindow.once('ready-to-show', () => mainWindow.show());
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith(`http://127.0.0.1:${phpPort}`)) return { action: 'allow' };
    shell.openExternal(url);
    return { action: 'deny' };
  });

  Menu.setApplicationMenu(Menu.buildFromTemplate([
    {
      label: 'LeadCRM',
      submenu: [
        { label: 'Open CRM', click: () => mainWindow?.loadURL(`http://127.0.0.1:${phpPort}/crm/`) },
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
  try {
    ensureWordPressTree();
    const dbPass = await startMariaDB();
    await startPhp();
    runWordPressInstall(dbPass);
    createWindow();
  } catch (e) {
    dialog.showErrorBox('LeadCRM failed to start', `${e.message}\n\nData: ${DATA_DIR}`);
    app.quit();
  }
});

app.on('window-all-closed', () => { cleanup(); app.quit(); });
app.on('before-quit', cleanup);
