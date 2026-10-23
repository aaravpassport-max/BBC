#!/usr/bin/env node
/** Copy Next.js static assets into the standalone output (required for production). */
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const frontend = path.join(root, 'frontend');
const standalone = path.join(frontend, '.next/standalone');
const staticSrc = path.join(frontend, '.next/static');
const staticDest = path.join(standalone, 'frontend/.next/static');
const publicSrc = path.join(frontend, 'public');
const publicDest = path.join(standalone, 'frontend/public');

function copyRecursive(src, dest) {
  if (!fs.existsSync(src)) throw new Error(`Missing path: ${src}`);
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    if (entry.isDirectory()) copyRecursive(from, to);
    else fs.copyFileSync(from, to);
  }
}

if (!fs.existsSync(path.join(standalone, 'frontend/server.js'))) {
  throw new Error('Run `npm --prefix frontend run build` before prepare-standalone.');
}
copyRecursive(staticSrc, staticDest);
copyRecursive(publicSrc, publicDest);
console.log('Prepared Next.js standalone bundle for desktop/offline use.');
