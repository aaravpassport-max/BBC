'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const php = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
assert.match(php, /function fno_is_plausible_kite_api_key\(/);
assert.match(php, /function fno_build_kite_connect_login_url\(/);
assert.match(php, /kite\.zerodha\.com\/connect\/login\?v=3&api_key=/);
assert.match(php, /function fno_probe_kite_connect_api_key/);
assert.match(php, /fno_clear_kite_settings_fn/);
assert.match(php, /if \(\$api_secret !== ''\) \{\s*\n\s*\$settings\['api_secret'\]/);
assert.doesNotMatch(php, /\$settings\['api_key'\] = \$api_key;\s*\n\s*\$settings\['api_secret'\] = fno_encrypt_secret\(\$api_secret\);/);

console.log('kite-api-key-validation wiring tests passed');
