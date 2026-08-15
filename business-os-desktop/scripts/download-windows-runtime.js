#!/usr/bin/env node
/**
 * Downloads and configures portable Windows runtimes required for the
 * Business OS offline installer (PHP 8.2 NTS + MariaDB 10.11).
 *
 * Usage: node scripts/download-windows-runtime.js
 * Output: business-os-win/php/ and business-os-win/mariadb/
 */

const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const ROOT = path.join(__dirname, '..', 'business-os-win');
const PHP_DIR = path.join(ROOT, 'php');
const MARIADB_DIR = path.join(ROOT, 'mariadb');
const CACHE_DIR = path.join(ROOT, '.build-cache');

const PHP_VERSION = '8.2.33';
const PHP_URL = `https://downloads.php.net/~windows/releases/php-${PHP_VERSION}-nts-Win32-vs16-x64.zip`;

const MARIADB_VERSION = '10.11.10';
const MARIADB_URL = `https://downloads.mariadb.com/MariaDB/mariadb-${MARIADB_VERSION}/winx64-packages/mariadb-${MARIADB_VERSION}-winx64.zip`;

function log(msg) {
  console.log(`[build] ${msg}`);
}

function download(url, dest) {
  return new Promise((resolve, reject) => {
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    const file = fs.createWriteStream(dest);
    const client = url.startsWith('https') ? https : http;

    const request = (currentUrl, redirects = 0) => {
      if (redirects > 10) {
        reject(new Error(`Too many redirects: ${url}`));
        return;
      }
      client.get(currentUrl, (res) => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          const next = new URL(res.headers.location, currentUrl).href;
          res.resume();
          request(next, redirects + 1);
          return;
        }
        if (res.statusCode !== 200) {
          reject(new Error(`Download failed (${res.statusCode}): ${currentUrl}`));
          return;
        }
        const total = parseInt(res.headers['content-length'] || '0', 10);
        let downloaded = 0;
        res.on('data', (chunk) => {
          downloaded += chunk.length;
          if (total > 0 && downloaded % (5 * 1024 * 1024) < chunk.length) {
            process.stdout.write(`\r[build]   ${Math.round((downloaded / total) * 100)}%`);
          }
        });
        res.pipe(file);
        file.on('finish', () => {
          file.close(() => {
            process.stdout.write('\n');
            const size = fs.statSync(dest).size;
            if (size < 1024 * 1024) {
              reject(new Error(`Download too small (${size} bytes) — likely an error page: ${currentUrl}`));
              return;
            }
            resolve();
          });
        });
      }).on('error', reject);
    };

    request(url);
  });
}

function unzip(zipPath, destDir) {
  fs.mkdirSync(destDir, { recursive: true });
  if (process.platform === 'win32') {
    execSync(
      `powershell -NoProfile -Command "Expand-Archive -Path '${zipPath}' -DestinationPath '${destDir}' -Force"`,
      { stdio: 'inherit' },
    );
    return;
  }
  execSync(`unzip -o -q "${zipPath}" -d "${destDir}"`, { stdio: 'inherit' });
}

function copyDirContents(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    if (entry.isDirectory()) copyDirContents(from, to);
    else fs.copyFileSync(from, to);
  }
}

function configurePhp(phpDir) {
  const iniDev = path.join(phpDir, 'php.ini-development');
  const iniPath = path.join(phpDir, 'php.ini');

  // Always use relative extension_dir so php.ini works on any machine after install.
  const ini = `; Business OS — portable PHP configuration
extension_dir = "ext"
extension=pdo_mysql
extension=mysqli
extension=mbstring
extension=openssl
extension=curl
extension=fileinfo
`;

  if (fs.existsSync(iniDev)) {
    let devIni = fs.readFileSync(iniDev, 'utf8');
    const replacements = [
      [';extension_dir = "ext"', 'extension_dir = "ext"'],
      [';extension=pdo_mysql', 'extension=pdo_mysql'],
      [';extension=mysqli', 'extension=mysqli'],
      [';extension=mbstring', 'extension=mbstring'],
      [';extension=openssl', 'extension=openssl'],
      [';extension=curl', 'extension=curl'],
      [';extension=fileinfo', 'extension=fileinfo'],
    ];
    for (const [from, to] of replacements) {
      devIni = devIni.replace(from, to);
    }
    if (!devIni.includes('extension=pdo_mysql')) {
      devIni += `\n${ini}`;
    }
    fs.writeFileSync(iniPath, devIni);
  } else {
    fs.writeFileSync(iniPath, ini);
  }

  log('Configured php.ini with relative extension_dir and required extensions');
}

async function setupPhp() {
  if (fs.existsSync(path.join(PHP_DIR, 'php.exe'))) {
    log('PHP runtime already present — ensuring php.ini');
    configurePhp(PHP_DIR);
    return;
  }

  fs.mkdirSync(CACHE_DIR, { recursive: true });
  const zipPath = path.join(CACHE_DIR, `php-${PHP_VERSION}.zip`);
  const extractDir = path.join(CACHE_DIR, 'php-extract');

  log(`Downloading PHP ${PHP_VERSION} NTS x64…`);
  if (!fs.existsSync(zipPath)) await download(PHP_URL, zipPath);

  if (fs.existsSync(extractDir)) fs.rmSync(extractDir, { recursive: true, force: true });
  unzip(zipPath, extractDir);

  if (fs.existsSync(PHP_DIR)) fs.rmSync(PHP_DIR, { recursive: true, force: true });
  fs.mkdirSync(PHP_DIR, { recursive: true });

  const entries = fs.readdirSync(extractDir);
  const phpRoot = entries.length === 1
    ? path.join(extractDir, entries[0])
    : extractDir;
  copyDirContents(phpRoot, PHP_DIR);
  configurePhp(PHP_DIR);
  log(`PHP ready at ${PHP_DIR}`);
}

async function setupMariaDb() {
  if (fs.existsSync(path.join(MARIADB_DIR, 'bin', 'mysqld.exe'))) {
    log('MariaDB runtime already present — skipping download');
    return;
  }

  fs.mkdirSync(CACHE_DIR, { recursive: true });
  const zipPath = path.join(CACHE_DIR, `mariadb-${MARIADB_VERSION}.zip`);
  const extractDir = path.join(CACHE_DIR, 'mariadb-extract');

  log(`Downloading MariaDB ${MARIADB_VERSION} portable x64…`);
  if (!fs.existsSync(zipPath)) await download(MARIADB_URL, zipPath);

  if (fs.existsSync(extractDir)) fs.rmSync(extractDir, { recursive: true, force: true });
  unzip(zipPath, extractDir);

  const findMariaRoot = (dir) => {
    if (fs.existsSync(path.join(dir, 'bin', 'mysqld.exe'))) return dir;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        const found = findMariaRoot(path.join(dir, entry.name));
        if (found) return found;
      }
    }
    return null;
  };

  const mariaRoot = findMariaRoot(extractDir);
  if (!mariaRoot) throw new Error('mysqld.exe not found in MariaDB archive');

  if (fs.existsSync(MARIADB_DIR)) fs.rmSync(MARIADB_DIR, { recursive: true, force: true });
  copyDirContents(mariaRoot, MARIADB_DIR);
  log(`MariaDB ready at ${MARIADB_DIR}`);
}

async function main() {
  log('Preparing Windows runtimes for offline installer…');
  await setupPhp();
  await setupMariaDb();
  log('All Windows runtimes ready. Run "npm run build" in electron/ to create the installer.');
}

main().catch((err) => {
  console.error('[build] ERROR:', err.message);
  process.exit(1);
});
