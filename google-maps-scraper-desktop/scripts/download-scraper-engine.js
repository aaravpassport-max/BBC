#!/usr/bin/env node
/**
 * Downloads gosom/google-maps-scraper release binaries into src-tauri/binaries/
 * with Tauri sidecar naming: scraper-engine-<target-triple>[.exe]
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import https from 'https';

const VERSION = '1.18.1';
const TAG = `v${VERSION}`;
const ROOT = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR = path.join(ROOT, '..', 'src-tauri', 'binaries');

const ASSETS = {
  'x86_64-pc-windows-msvc': `google_maps_scraper-${VERSION}-windows-amd64.exe`,
  'x86_64-unknown-linux-gnu': `google_maps_scraper-${VERSION}-linux-amd64`,
  'aarch64-apple-darwin': `google_maps_scraper-${VERSION}-darwin-arm64`,
  'x86_64-apple-darwin': `google_maps_scraper-${VERSION}-darwin-amd64`,
};

function download(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);
    https
      .get(url, { headers: { 'User-Agent': 'google-maps-scraper-desktop-build' } }, (res) => {
        if (res.statusCode === 302 || res.statusCode === 301) {
          file.close();
          fs.unlinkSync(dest);
          return download(res.headers.location, dest).then(resolve, reject);
        }
        if (res.statusCode !== 200) {
          reject(new Error(`HTTP ${res.statusCode} for ${url}`));
          return;
        }
        res.pipe(file);
        file.on('finish', () => {
          file.close();
          resolve();
        });
      })
      .on('error', reject);
  });
}

async function main() {
  const targets = process.argv.slice(2);
  const toFetch = targets.length ? targets : Object.keys(ASSETS);
  fs.mkdirSync(OUT_DIR, { recursive: true });

  for (const triple of toFetch) {
    const asset = ASSETS[triple];
    if (!asset) {
      console.warn(`Unknown triple: ${triple}`);
      continue;
    }
    const ext = triple.includes('windows') ? '.exe' : '';
    const outName = `scraper-engine-${triple}${ext}`;
    const outPath = path.join(OUT_DIR, outName);
    const url = `https://github.com/gosom/google-maps-scraper/releases/download/${TAG}/${asset}`;
    console.log(`Downloading ${url} -> ${outName}`);
    await download(url, outPath);
    if (!triple.includes('windows')) {
      fs.chmodSync(outPath, 0o755);
    }
    console.log(`  OK (${fs.statSync(outPath).size} bytes)`);
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
