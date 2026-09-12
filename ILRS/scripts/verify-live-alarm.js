#!/usr/bin/env node
/**
 * Live test against running ILRS userData DB + log tail.
 * Run while `npm start` is active.
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const { toLocalISO, localDateStr } = require('../alarm');

const userData = process.env.ILRS_USER_DATA || path.join(os.homedir(), '.config', 'ilrs');
const dbPath = path.join(userData, 'ilrs.db');
const OUT = '/opt/cursor/artifacts/live-alarm-test.json';

if (!fs.existsSync(dbPath)) {
  console.error(`❌ No ILRS DB at ${dbPath} — start the app first (npm start)`);
  process.exit(1);
}

const db = new Database(dbPath);
try { db.exec('ALTER TABLE reminders ADD COLUMN alarm_rings INTEGER DEFAULT 0'); } catch (_) {}

const id = require('crypto').randomUUID();
const fireAt = new Date(Date.now() + 12000);
const nextFire = toLocalISO(fireAt);
const start = localDateStr(fireAt);
const time = `${String(fireAt.getHours()).padStart(2, '0')}:${String(fireAt.getMinutes()).padStart(2, '0')}`;

db.prepare(`
  INSERT INTO reminders (id,title,task_type,category,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,alarm_rings,created_at,updated_at)
  VALUES (?,'LIVE TEST ALARM','reminder','general','once',?,?,'critical','sound-popup','active',?,0,datetime('now'),datetime('now'))
`).run(id, time, start, nextFire);

console.log(`⏰ Inserted live alarm id=${id} due at ${nextFire}`);
console.log('Waiting 20 seconds for scheduler...');

setTimeout(() => {
  const row = db.prepare('SELECT alarm_rings, status, last_fired, next_fire FROM reminders WHERE id=?').get(id);
  const result = {
    id,
    scheduledFor: nextFire,
    checkedAt: toLocalISO(),
    row,
    passed: !!(row && row.alarm_rings >= 1 && row.status === 'active'),
  };
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, JSON.stringify(result, null, 2));
  if (result.passed) {
    console.log('✅ LIVE TEST PASSED — scheduler fired alarm:', JSON.stringify(row));
    process.exit(0);
  } else {
    console.error('❌ LIVE TEST FAILED — alarm not fired:', JSON.stringify(row));
    process.exit(1);
  }
}, 20000);
