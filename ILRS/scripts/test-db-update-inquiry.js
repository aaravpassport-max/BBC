const Database = require('better-sqlite3');
const path = require('path');
const os = require('os');
const { updateInquiry } = require('../inquiry-actions');

const dbPath = path.join(os.homedir(), '.config/ilrs', 'ilrs.db');
const db = new Database(dbPath);
const cols = db.prepare('PRAGMA table_info(inquiries)').all().map((c) => c.name);
console.log('lifecycle column:', cols.includes('lifecycle_status'));
const row = db.prepare(
  "SELECT id, client_name, lifecycle_status FROM inquiries WHERE outcome_status != 'deleted' ORDER BY updated_at DESC LIMIT 1",
).get();
console.log('sample', row);
if (row) {
  const r = updateInquiry(db, row.id, { lifecycleStatus: 'pending', scheduleNext: false });
  console.log('update success', r.success, r.error || '', 'returned', r.inquiry?.lifecycle_status);
  const after = db.prepare('SELECT lifecycle_status FROM inquiries WHERE id = ?').get(row.id);
  console.log('db after', after.lifecycle_status);
  updateInquiry(db, row.id, { lifecycleStatus: 'active', scheduleNext: false });
}
db.close();
