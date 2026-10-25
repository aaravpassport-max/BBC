'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const phpSrc = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');
const start = phpSrc.indexOf('<div id="settingsModalOverlay"');
const end = phpSrc.indexOf('<div id="fno-root">', start);
assert.ok(start >= 0 && end > start, 'settings modal block must exist');
const modalBlock = phpSrc.slice(start, end);

const opens = (modalBlock.match(/<div[\s>]/g) || []).length;
const closes = (modalBlock.match(/<\/div>/g) || []).length;
assert.strictEqual(opens, closes, `settings modal div tags must balance (opens=${opens}, closes=${closes})`);

assert.ok(modalBlock.indexOf('id="closeSettingsBtn"') < modalBlock.lastIndexOf('</div>'),
  'Close button must stay inside the modal inner container');
assert.ok(/settingScalpingBracketButtons/.test(modalBlock));
assert.ok(/settingTypeIntraday/.test(modalBlock));
assert.ok(modalBlock.indexOf('settingTypeIntraday') > modalBlock.indexOf('settingScalpingBracketButtons'),
  'Trading Types section must follow bracket setup inside the same modal');

console.log('All settings-modal-html tests passed.');
