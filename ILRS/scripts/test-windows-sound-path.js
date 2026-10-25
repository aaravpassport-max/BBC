#!/usr/bin/env node
/** Regression: sound paths must be copyable from asar-like locations on Windows. */
const fs = require('fs');
const path = require('path');
const os = require('os');

const tmp = path.join(os.tmpdir(), `ilrs-asar-test-${Date.now()}`);
const asarLike = path.join(tmp, 'app.asar', 'assets', 'sounds');
fs.mkdirSync(asarLike, { recursive: true });
const src = path.join(__dirname, '..', 'assets', 'sounds', 'alarm-clock.wav');
fs.copyFileSync(src, path.join(asarLike, 'alarm-clock.wav'));

process.env.ILRS_TEST_USERDATA = path.join(tmp, 'userdata');
const { app } = require('electron');

app.whenReady().then(() => {
  app.setPath('userData', process.env.ILRS_TEST_USERDATA);
  const { resolvePlayableSoundPath } = require('../sound-player');
  const fakeAsarPath = path.join(asarLike, 'alarm-clock.wav');
  const resolved = resolvePlayableSoundPath(fakeAsarPath);
  if (!resolved || resolved.includes('app.asar')) {
    console.error('FAIL: sound not copied out of asar path');
    process.exit(1);
  }
  if (!fs.existsSync(resolved)) {
    console.error('FAIL: resolved path missing');
    process.exit(1);
  }
  console.log('PASS: resolvePlayableSoundPath copies to', resolved);
  app.exit(0);
});

app.on('window-all-closed', () => {});
