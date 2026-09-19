#!/usr/bin/env node
/** Ensure every main-process require('./foo') is listed in package.json build.files */
const fs = require('fs');
const path = require('path');
const pkg = require('../package.json');

const root = path.join(__dirname, '..');
const filesSet = new Set(pkg.build.files.map((f) => f.replace('/**/*', '').replace('/**', '')));

function isPackaged(rel) {
  if (filesSet.has(rel)) return true;
  if (filesSet.has(`${rel}/**/*`)) return true;
  const dir = rel.includes('/') ? rel.split('/')[0] : null;
  if (dir && filesSet.has(`${dir}/**/*`)) return true;
  return false;
}

const entryFiles = [
  'main.js',
  'sync-engine.js',
  'sync-apply.js',
  'sync-publish.js',
  'inquiry-actions.js',
  'reminder-actions.js',
  'payment-manager.js',
  'inquiry-activity-sync.js',
];

const missing = [];
for (const entry of entryFiles) {
  const content = fs.readFileSync(path.join(root, entry), 'utf8');
  const re = /require\(['"]\.\/([^'"]+)['"]\)/g;
  let m;
  while ((m = re.exec(content)) !== null) {
    const base = m[1].endsWith('.js') ? m[1] : `${m[1]}.js`;
    const rel = base;
    if (!fs.existsSync(path.join(root, rel))) continue;
    if (!isPackaged(rel)) missing.push({ from: entry, module: rel });
  }
}

if (missing.length) {
  console.error('Packaging gap — add to package.json build.files:');
  for (const x of missing) console.error(`  ${x.module} (required from ${x.from})`);
  process.exit(1);
}
console.log('verify-packaged-modules: ok');
