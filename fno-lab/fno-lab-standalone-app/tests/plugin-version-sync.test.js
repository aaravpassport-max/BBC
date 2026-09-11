'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const php = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
const headerMatch = php.match(/\* Version:\s*([0-9.]+)/);
const constMatch = php.match(/define\('FNO_PLUGIN_VERSION',\s*'([^']+)'\)/);

assert.ok(headerMatch, 'plugin header includes * Version:');
assert.ok(constMatch, 'FNO_PLUGIN_VERSION constant is defined');
assert.strictEqual(
  headerMatch[1],
  constMatch[1],
  `header Version (${headerMatch[1]}) must match FNO_PLUGIN_VERSION (${constMatch[1]})`
);

console.log(`Plugin version sync OK: ${constMatch[1]}`);
