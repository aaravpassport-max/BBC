'use strict';
const { execSync } = require('child_process');
const path = require('path');
execSync(`node --check ${path.join(__dirname, '../assets/fno-lab-core.js')}`, { stdio: 'inherit' });
console.log('fno-lab-core.js syntax OK');
