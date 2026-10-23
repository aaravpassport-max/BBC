#!/usr/bin/env node
/**
 * Downloads portable PostgreSQL Windows binaries for the LeadCRM offline installer.
 * Output: leadcrm-desktop/runtimes/postgres/
 */
const https = require('https');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const OUT_DIR = path.join(__dirname, '..', 'runtimes', 'postgres');
const CACHE = path.join(__dirname, '..', '.build-cache');
const VERSION = '16.6-1';
const URL = `https://get.enterprisedb.com/postgresql/postgresql-${VERSION}-windows-x64-binaries.zip`;

function log(msg) {
  console.log(`[postgres] ${msg}`);
}

function download(url, dest) {
  return new Promise((resolve, reject) => {
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    const file = fs.createWriteStream(dest);
    const request = (current, redirects = 0) => {
      if (redirects > 10) return reject(new Error('Too many redirects'));
      https.get(current, res => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          res.resume();
          return request(res.headers.location, redirects + 1);
        }
        if (res.statusCode !== 200) return reject(new Error(`HTTP ${res.statusCode}`));
        res.pipe(file);
        file.on('finish', () => file.close(resolve));
      }).on('error', reject);
    };
    request(url);
  });
}

function unzip(zipPath, destDir) {
  fs.mkdirSync(destDir, { recursive: true });
  execSync(`unzip -qo "${zipPath}" -d "${destDir}"`, { stdio: 'inherit' });
}

function extractZip(zipPath) {
  const temp = path.join(CACHE, 'pg-extract');
  fs.rmSync(temp, { recursive: true, force: true });
  unzip(zipPath, temp);
  const entries = fs.readdirSync(temp);
  const root = entries.length === 1 ? path.join(temp, entries[0]) : temp;
  fs.rmSync(OUT_DIR, { recursive: true, force: true });
  fs.mkdirSync(path.dirname(OUT_DIR), { recursive: true });
  fs.renameSync(root, OUT_DIR);
  log(`Ready at ${OUT_DIR}`);
}

async function main() {
  const marker = path.join(OUT_DIR, 'bin', 'initdb.exe');
  if (fs.existsSync(marker)) {
    log('Runtime already extracted.');
    return;
  }

  fs.mkdirSync(CACHE, { recursive: true });
  const zipPath = path.join(CACHE, `postgresql-${VERSION}-windows-x64-binaries.zip`);
  if (!fs.existsSync(zipPath)) {
    log(`Downloading PostgreSQL ${VERSION}…`);
    await download(URL, zipPath);
  } else {
    log('Using cached zip…');
  }
  log('Extracting…');
  extractZip(zipPath);
}

main().catch(err => {
  console.error(err);
  process.exit(1);
});
