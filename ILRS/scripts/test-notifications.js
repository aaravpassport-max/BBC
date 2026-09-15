#!/usr/bin/env node
/**
 * Smoke test for ILRS notification module (no Electron window required).
 */
const path = require('path');
const Database = require('better-sqlite3');
const { showDesktopNotification, shouldNotify } = require('../notifications');

const dbPath = path.join(process.env.HOME, '.config', 'ilrs', 'ilrs.db');
const db = new Database(dbPath);

const sample = {
  title: 'ILRS Notification Test',
  why_it_matters: 'Desktop notifications are configured correctly.',
  priority: 'normal',
  is_private: 0,
};

if (!shouldNotify(db, sample)) {
  console.error('FAIL: shouldNotify returned false');
  process.exit(1);
}

console.log('OK: notification settings allow alerts');
console.log('Note: run the desktop app and use Settings → Send Test for native popup verification.');
