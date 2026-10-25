#!/usr/bin/env node
/**
 * Ensures capture sheet reminder INSERT has one bound value per non-timestamp column.
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '..', 'src', 'capture-ui.js'), 'utf8');
const colMatch = src.match(/const REMINDER_INSERT_COLUMNS = (\[[\s\S]*?\]);/);
assert(colMatch, 'REMINDER_INSERT_COLUMNS not found in capture-ui.js');
const columns = eval(colMatch[1]);
assert.equal(columns.length, 29, 'expected 29 reminder INSERT columns');

const boundCols = columns.filter((c) => c !== 'created_at' && c !== 'updated_at');
assert.equal(boundCols.length, 27, 'expected 27 bound INSERT columns');

const valueExprs = columns.map((col) =>
  (col === 'created_at' || col === 'updated_at') ? "datetime('now')" : '?'
);
const qCount = valueExprs.filter((v) => v === '?').length;
const dtCount = valueExprs.filter((v) => v.includes('datetime')).length;
assert.equal(qCount, boundCols.length, 'placeholder count must match bound columns');
assert.equal(qCount + dtCount, columns.length, 'SQL value count must match column count');

assert.ok(
  src.includes('insertParams.length !== boundCols'),
  'runtime guard for INSERT param count should exist'
);

console.log('✅ capture reminder INSERT SQL alignment OK');
