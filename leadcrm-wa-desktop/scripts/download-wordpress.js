#!/usr/bin/env node
const https = require('https');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const WP_VERSION = '6.7.2';
const URL = `https://wordpress.org/wordpress-${WP_VERSION}.zip`;
const OUT = path.join(__dirname, '..', 'app', 'wordpress');
const CACHE = path.join(__dirname, '..', '.build-cache');
const PLUGIN = path.join(OUT, 'wp-content', 'plugins', 'leadcrm-wa-build');

function download(url, dest) {
  return new Promise((resolve, reject) => {
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    const file = fs.createWriteStream(dest);
    https.get(url, res => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        res.resume();
        return https.get(res.headers.location, r => r.pipe(file).on('finish', () => file.close(resolve)));
      }
      if (res.statusCode !== 200) return reject(new Error(`HTTP ${res.statusCode}`));
      res.pipe(file);
      file.on('finish', () => file.close(resolve));
    }).on('error', reject);
  });
}

async function main() {
  if (fs.existsSync(path.join(OUT, 'wp-includes')) && fs.existsSync(path.join(PLUGIN, 'leadcrm.php'))) {
    console.log('[wordpress] Core + LeadCRM plugin already present.');
    return;
  }
  fs.mkdirSync(CACHE, { recursive: true });
  const zipPath = path.join(CACHE, `wordpress-${WP_VERSION}.zip`);
  if (!fs.existsSync(zipPath)) {
    console.log(`[wordpress] Downloading WordPress ${WP_VERSION}…`);
    await download(URL, zipPath);
  }
  const temp = path.join(CACHE, 'wp-extract');
  fs.rmSync(temp, { recursive: true, force: true });
  execSync(`unzip -q "${zipPath}" -d "${temp}"`, { stdio: 'inherit' });
  const core = path.join(temp, 'wordpress');
  fs.rmSync(OUT, { recursive: true, force: true });
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.renameSync(core, OUT);
  fs.mkdirSync(path.join(OUT, 'wp-content', 'plugins'), { recursive: true });
  const srcPlugin = path.join(__dirname, '..', '..', 'leadcrm-wa-build');
  const zipPlugin = path.join(__dirname, '..', '..', 'leadcrm-wa-build');
  const pluginSource = fs.existsSync(srcPlugin)
    ? srcPlugin
    : path.join(__dirname, '..', 'plugin-src', 'leadcrm-wa-build');
  if (!fs.existsSync(path.join(pluginSource, 'leadcrm.php'))) {
    throw new Error('leadcrm-wa-build plugin not found next to leadcrm-wa-desktop');
  }
  execSync(`cp -a "${pluginSource}" "${PLUGIN}"`, { stdio: 'inherit' });
  const muDir = path.join(OUT, 'wp-content', 'mu-plugins');
  fs.mkdirSync(muDir, { recursive: true });
  const muSrc = path.join(__dirname, '..', 'app', 'wordpress', 'wp-content', 'mu-plugins', 'leadcrm-desktop.php');
  if (fs.existsSync(muSrc)) {
    fs.copyFileSync(muSrc, path.join(muDir, 'leadcrm-desktop.php'));
  }
  const bootSrc = path.join(__dirname, '..', 'app', 'wordpress', 'leadcrm-desktop-bootstrap.php');
  if (fs.existsSync(bootSrc)) {
    fs.copyFileSync(bootSrc, path.join(OUT, 'leadcrm-desktop-bootstrap.php'));
  }
  fs.writeFileSync(
    path.join(OUT, 'router.php'),
    `<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) return false;
require __DIR__ . '/index.php';
`,
  );
  const binDir = path.join(__dirname, '..', 'app', 'bin');
  fs.mkdirSync(binDir, { recursive: true });
  console.log('[wordpress] WordPress + LeadCRM WA plugin ready.');
}

main().catch(err => { console.error(err); process.exit(1); });
