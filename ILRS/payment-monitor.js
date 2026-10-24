/**
 * Payment due date monitoring + alerts.
 */
const { localDateStr } = require('./alarm');
const { computePaymentSummary, getPayments, effectivePaymentTotal } = require('./payment-manager');

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

function loadPaymentAlerted(db, today) {
  const raw = getSetting(db, 'payment_alerts_sent_date', '');
  if (raw !== today) return new Set();
  try {
    return new Set(JSON.parse(getSetting(db, 'payment_alerts_sent_keys', '[]')));
  } catch {
    return new Set();
  }
}

function savePaymentAlerted(db, today, keys) {
  setSetting(db, 'payment_alerts_sent_date', today);
  setSetting(db, 'payment_alerts_sent_keys', JSON.stringify([...keys]));
}

function shouldAlertPayment(entity, payments, now) {
  if (Number(entity.payment_tracking_enabled) !== 1) return false;
  const summary = computePaymentSummary(entity, payments, now);
  if (summary.balance <= 0) return false;
  const due = summary.nextDue;
  if (!due) return false;
  const today = localDateStr(now);
  return due <= today;
}

function checkPaymentDueAlerts(db, dispatchDue, now = new Date()) {
  if (!db || getSetting(db, 'payment_alerts_enabled', '1') !== '1') {
    return { alerted: 0 };
  }

  const today = localDateStr(now);
  const alerted = loadPaymentAlerted(db, today);
  let count = 0;

  const reminders = db.prepare(`
    SELECT * FROM reminders
    WHERE status = 'active' AND payment_tracking_enabled = 1
    AND next_payment_due_date != ''
  `).all();

  for (const item of reminders) {
    const payments = getPayments(db, 'reminder', item.id);
    if (!shouldAlertPayment(item, payments, now)) continue;
    const key = `payment:reminder:${item.id}:${today}`;
    if (alerted.has(key)) continue;
    const summary = computePaymentSummary(item, payments, now);
    const overdue = summary.nextDue < today;
    dispatchDue({
      ...item,
      _type: 'payment_due',
      title: `Payment due: ${item.title}`,
      why_it_matters: overdue
        ? `Payment of ${summary.nextAmount > 0 ? `₹${summary.nextAmount}` : 'outstanding balance'} was due ${summary.nextDue}`
        : `Payment of ${summary.nextAmount > 0 ? `₹${summary.nextAmount}` : 'outstanding balance'} is due today`,
      priority: overdue ? 'critical' : 'important',
      alert_style: 'sound-popup',
      _paymentSummary: summary,
    }, 'payment_due');
    alerted.add(key);
    count += 1;
  }

  const inquiries = db.prepare(`
    SELECT * FROM inquiries WHERE outcome_status = 'active' AND payment_tracking_enabled = 1
    AND next_payment_due_date != ''
  `).all();

  for (const inq of inquiries) {
    const payments = getPayments(db, 'inquiry', inq.id);
    if (!shouldAlertPayment(inq, payments, now)) continue;
    const key = `payment:inquiry:${inq.id}:${today}`;
    if (alerted.has(key)) continue;
    const summary = computePaymentSummary(inq, payments, now);
    const overdue = summary.nextDue < today;
    dispatchDue({
      ...inq,
      _type: 'payment_due',
      inquiry_id: inq.id,
      title: `Payment due: ${inq.client_name}`,
      why_it_matters: overdue
        ? `Payment was due ${summary.nextDue} — balance ${summary.balance}`
        : `Payment due today — balance ${summary.balance}`,
      priority: overdue ? 'critical' : 'important',
      _paymentSummary: summary,
    }, 'payment_due');
    alerted.add(key);
    count += 1;
  }

  savePaymentAlerted(db, today, alerted);
  return { alerted: count };
}

module.exports = {
  checkPaymentDueAlerts,
};
