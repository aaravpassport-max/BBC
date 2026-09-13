/**
 * Inquiry health refresh + proactive desktop alerts for stale/at-risk/overdue follow-ups.
 */
const { localDateStr } = require('./alarm');
const { computeInquiryHealth } = require('./inquiry-actions');

function getSetting(db, key, defaultValue = '') {
  try {
    const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
    return row ? row.value : defaultValue;
  } catch {
    return defaultValue;
  }
}

function setSetting(db, key, value) {
  db.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)').run(key, String(value));
}

function refreshAllInquiryHealth(db, now = new Date()) {
  const inquiries = db.prepare("SELECT * FROM inquiries WHERE outcome_status = 'active'").all();
  let updated = 0;
  for (const inq of inquiries) {
    const health = computeInquiryHealth(inq, now);
    if (health !== inq.health) {
      db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inq.id);
      updated += 1;
    }
  }
  return updated;
}

function loadAlertedKeys(db, today) {
  const raw = getSetting(db, 'inquiry_alerts_sent_date', '');
  if (raw !== today) return new Set();
  try {
    return new Set(JSON.parse(getSetting(db, 'inquiry_alerts_sent_keys', '[]')));
  } catch {
    return new Set();
  }
}

function saveAlertedKeys(db, today, keys) {
  setSetting(db, 'inquiry_alerts_sent_date', today);
  setSetting(db, 'inquiry_alerts_sent_keys', JSON.stringify([...keys]));
}

function checkInquiryAlerts(db, showNotification, now = new Date()) {
  if (!db || getSetting(db, 'inquiry_alerts_enabled', '1') !== '1') {
    return { alerted: 0 };
  }

  refreshAllInquiryHealth(db, now);
  const today = localDateStr(now);
  const alerted = loadAlertedKeys(db, today);
  let count = 0;

  const inquiries = db.prepare("SELECT * FROM inquiries WHERE outcome_status = 'active'").all();
  for (const inq of inquiries) {
    const reasons = [];
    if (inq.health === 'at_risk') reasons.push('at risk');
    if (inq.health === 'stale') reasons.push('stale');
    if (inq.next_follow_up && inq.next_follow_up < today) reasons.push('overdue follow-up');

    if (!reasons.length) continue;

    const key = `${inq.id}:${inq.health}:${inq.next_follow_up || ''}`;
    if (alerted.has(key)) continue;

    const title = `📥 ${inq.client_name} — ${inq.inquiry_number || 'Inquiry'}`;
    const body = `${reasons.join(' · ')} · ${inq.requirement || ''} · Next: ${inq.next_action || 'Follow up'}`;

    showNotification({
      title,
      why_it_matters: body,
      priority: inq.health === 'at_risk' ? 'critical' : 'important',
      alert_style: 'sound-popup',
      inquiry_id: inq.id,
    }, { type: 'inquiry', inquiryId: inq.id });

    alerted.add(key);
    count += 1;
  }

  saveAlertedKeys(db, today, alerted);
  return { alerted: count };
}

module.exports = {
  refreshAllInquiryHealth,
  checkInquiryAlerts,
};
