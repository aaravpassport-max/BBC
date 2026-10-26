#!/usr/bin/env node
/** Validates LeadCRM production artifacts before electron-builder packaging. */
const fs = require('fs');
const path = require('path');

const leadcrm = path.resolve(__dirname, '../../leadcrm');
const required = [
  'backend/dist/start.js',
  'frontend/.next/standalone/frontend/server.js',
  'frontend/.next/static',
  'scripts/desktop-launcher.cjs',
  'node_modules/prisma/build/index.js',
];

for (const rel of required) {
  const full = path.join(leadcrm, rel);
  if (!fs.existsSync(full)) {
    console.error(`Missing required artifact: ${rel}`);
    console.error('Run: cd leadcrm && npm install && npm run desktop:build');
    process.exit(1);
  }
}
console.log('LeadCRM packaging prerequisites OK.');
