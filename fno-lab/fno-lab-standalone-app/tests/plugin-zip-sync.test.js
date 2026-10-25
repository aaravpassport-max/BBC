'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const pluginPhp = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
const versionMatch = pluginPhp.match(/define\('FNO_PLUGIN_VERSION',\s*'([^']+)'\)/);
assert.ok(versionMatch, 'FNO_PLUGIN_VERSION defined');
const version = versionMatch[1];

const zipPath = path.join(__dirname, '../../../fno-lab-standalone-app.zip');
assert.ok(fs.existsSync(zipPath), `canonical zip missing at ${zipPath} — run build-plugin-zip.sh from plugin folder`);

const zipHead = spawnSync('bash', ['-c', `unzip -p "${zipPath}" fno-lab-standalone-app/fno-lab.php | head -20`], { encoding: 'utf8' });
assert.strictEqual(zipHead.status, 0, 'could not read fno-lab.php from zip');
const zipHeader = zipHead.stdout.match(/\* Version:\s*([0-9.]+)/);
const zipConst = zipHead.stdout.match(/define\('FNO_PLUGIN_VERSION',\s*'([^']+)'\)/);
assert.ok(zipHeader && zipConst, 'zip contains fno-lab.php with version fields');
assert.strictEqual(zipHeader[1], version, 'zip header Version must match source');
assert.strictEqual(zipConst[1], version, 'zip FNO_PLUGIN_VERSION must match source');

const zipCoreCheck = spawnSync(
  'bash',
  ['-c', `unzip -p "${zipPath}" fno-lab-standalone-app/assets/fno-lab-core.js | grep -q 'renderScalpingSessionReadiness' && unzip -p "${zipPath}" fno-lab-standalone-app/assets/fno-lab-core.js | grep -q 'fnoThemePalette'`],
  { stdio: 'ignore' }
);
assert.strictEqual(zipCoreCheck.status, 0, 'zip core JS includes light-mode readiness fix');

console.log(`Canonical zip OK: v${version}`);
