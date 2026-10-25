#!/usr/bin/env node
/**
 * Verify modal overlays are dismissed on navigation (prevents blocked form inputs).
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const appJs = fs.readFileSync(path.join(__dirname, '..', 'src', 'app.js'), 'utf8');

assert.match(appJs, /function dismissPageModals\(\)/, 'dismissPageModals must exist');
assert.match(appJs, /dismissPageModals\(\);\s*\n\s*App\.currentPage = page/, 'navigate must call dismissPageModals');
assert.match(appJs, /function attachModalDismiss\(overlay\)/, 'attachModalDismiss must exist');
assert.match(appJs, /if \(e\.target === overlay\) overlay\.remove\(\)/, 'backdrop click must close modals');

const modalCreates = (appJs.match(/attachModalDismiss\(overlay\)/g) || []).length;
assert.ok(modalCreates >= 5, `expected attachModalDismiss on form modals, found ${modalCreates}`);

console.log('✅ modal dismiss on navigation tests passed');
