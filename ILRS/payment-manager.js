/**
 * Optional payment tracking for tasks, reminders, and inquiries.
 */
const { randomUUID } = require('crypto');
const { localDateStr, computeNextFire } = require('./alarm');
const { createFollowUpReminder, logActivity } = require('./inquiry-actions');

const STATUS_LABELS = {
  none: 'No Payment',
  partial: 'Partially Paid',
  paid: 'Fully Paid',
  due: 'Payment Due',
  overdue: 'Payment Overdue',
};

function roundMoney(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function formatRupee(amount) {
  const n = roundMoney(amount);
  return `₹${n.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
}

function entityTable(entityType) {
  return entityType === 'inquiry' ? 'inquiries' : 'reminders';
}

function getPayments(db, entityType, entityId) {
  return db.prepare(`
    SELECT * FROM work_payments
    WHERE entity_type = ? AND entity_id = ?
    ORDER BY received_date ASC, created_at ASC
  `).all(entityType, entityId);
}

function getAllPayments(db) {
  return db.prepare('SELECT * FROM work_payments ORDER BY received_date DESC').all();
}

function effectivePaymentTotal(entity) {
  const total = roundMoney(entity?.payment_total);
  if (total > 0) return total;
  if (entity?.quotation_amount > 0) return roundMoney(entity.quotation_amount);
  if (entity?.expected_value > 0) return roundMoney(entity.expected_value);
  return 0;
}

function computePaymentSummary(entity, payments = [], now = new Date()) {
  const enabled = Number(entity?.payment_tracking_enabled) === 1;
  const total = effectivePaymentTotal(entity);
  const received = roundMoney((payments || []).reduce((s, p) => s + (Number(p.amount) || 0), 0));
  const balance = roundMoney(Math.max(0, total - received));
  const today = localDateStr(now);
  const nextDue = String(entity?.next_payment_due_date || '').slice(0, 10);
  const nextAmount = roundMoney(entity?.next_payment_amount);

  let status = 'none';
  if (!enabled) {
    return {
      enabled: false,
      total,
      received,
      balance,
      nextDue,
      nextAmount,
      status: 'none',
      statusLabel: STATUS_LABELS.none,
      payments: payments || [],
    };
  }

  if (total > 0 && received >= total) {
    status = 'paid';
  } else if (received > 0) {
    status = 'partial';
  } else {
    status = 'none';
  }

  if (balance > 0 && nextDue) {
    if (nextDue < today) status = 'overdue';
    else if (nextDue === today) status = 'due';
    else if (status === 'none' || status === 'partial') status = received > 0 ? 'partial' : 'due';
  }

  return {
    enabled: true,
    total,
    received,
    balance,
    nextDue,
    nextAmount,
    status,
    statusLabel: STATUS_LABELS[status] || status,
    payments: payments || [],
  };
}

function syncEntityPaymentStatus(db, entityType, entityId, now = new Date()) {
  const table = entityTable(entityType);
  const entity = db.prepare(`SELECT * FROM ${table} WHERE id = ?`).get(entityId);
  if (!entity) return null;
  const payments = getPayments(db, entityType, entityId);
  const summary = computePaymentSummary(entity, payments, now);
  if (Number(entity.payment_tracking_enabled) === 1) {
    db.prepare(`
      UPDATE ${table} SET payment_track_status = ?, updated_at = datetime('now') WHERE id = ?
    `).run(summary.status, entityId);
  }
  return summary;
}

function updatePaymentSettings(db, { entityType, entityId, enabled, total, nextPaymentDueDate, nextPaymentAmount }, now = new Date()) {
  const table = entityTable(entityType);
  const entity = db.prepare(`SELECT * FROM ${table} WHERE id = ?`).get(entityId);
  if (!entity) return { success: false, error: 'Item not found' };

  db.prepare(`
    UPDATE ${table} SET
      payment_tracking_enabled = ?,
      payment_total = ?,
      next_payment_due_date = ?,
      next_payment_amount = ?,
      updated_at = datetime('now')
    WHERE id = ?
  `).run(
    enabled ? 1 : 0,
    roundMoney(total),
    nextPaymentDueDate || '',
    roundMoney(nextPaymentAmount),
    entityId,
  );

  const summary = syncEntityPaymentStatus(db, entityType, entityId, now);

  if (entityType === 'inquiry') {
    logActivity(db, entityId, 'payment', enabled ? 'Payment tracking enabled' : 'Payment tracking disabled',
      total ? `Total: ${formatRupee(total)}` : '', {});
    const { maybeSyncEntity } = require('./sync-hook');
    maybeSyncEntity(db, 'inquiry', entityId);
  }

  return { success: true, summary };
}

function recordPayment(db, { entityType, entityId, amount, receivedDate, note }, now = new Date()) {
  if (!entityType || !entityId) return { success: false, error: 'Missing entity' };
  const amt = roundMoney(amount);
  if (amt <= 0) return { success: false, error: 'Amount must be greater than zero' };
  const date = receivedDate || localDateStr(now);

  const table = entityTable(entityType);
  const entity = db.prepare(`SELECT * FROM ${table} WHERE id = ?`).get(entityId);
  if (!entity) return { success: false, error: 'Item not found' };

  if (!Number(entity.payment_tracking_enabled)) {
    db.prepare(`UPDATE ${table} SET payment_tracking_enabled = 1, updated_at = datetime('now') WHERE id = ?`).run(entityId);
  }

  const id = randomUUID();
  db.prepare(`
    INSERT INTO work_payments (id, entity_type, entity_id, amount, received_date, note, created_at)
    VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
  `).run(id, entityType, entityId, amt, date, note || '');

  const summary = syncEntityPaymentStatus(db, entityType, entityId, now);

  if (entityType === 'inquiry') {
    logActivity(db, entityId, 'payment', `Payment received ${formatRupee(amt)}`,
      `${date}${note ? ` — ${note}` : ''}`, {});
  } else {
    db.prepare(`
      INSERT INTO reminder_logs (id, reminder_id, action, timestamp, note)
      VALUES (?, ?, 'payment_received', datetime('now'), ?)
    `).run(randomUUID(), entityId, `${formatRupee(amt)} on ${date}${note ? ` — ${note}` : ''}`);
  }

  const { maybeSyncEntity } = require('./sync-hook');
  maybeSyncEntity(db, 'work_payment', id);
  if (entityType === 'inquiry') maybeSyncEntity(db, 'inquiry', entityId);

  return { success: true, paymentId: id, summary };
}

function deletePayment(db, paymentId, now = new Date()) {
  const row = db.prepare('SELECT * FROM work_payments WHERE id = ?').get(paymentId);
  if (!row) return { success: false, error: 'Payment not found' };
  const { maybeSyncEntity } = require('./sync-hook');
  const { publishRecordChange } = require('./sync-publish');
  publishRecordChange(db, 'work_payment', paymentId, 'delete');
  db.prepare('DELETE FROM work_payments WHERE id = ?').run(paymentId);
  const summary = syncEntityPaymentStatus(db, row.entity_type, row.entity_id, now);
  if (row.entity_type === 'inquiry') maybeSyncEntity(db, 'inquiry', row.entity_id);
  return { success: true, summary };
}

function schedulePaymentReminder(db, entity, entityType, now = new Date()) {
  if (!entity?.next_payment_due_date) return null;
  const title = entityType === 'inquiry'
    ? `Payment due: ${entity.client_name}`
    : `Payment due: ${entity.title}`;
  const amount = roundMoney(entity.next_payment_amount);
  const body = amount > 0 ? `${formatRupee(amount)} due` : 'Payment follow-up';

  if (entityType === 'inquiry') {
    createFollowUpReminder(db, entity, {
      title,
      date: entity.next_payment_due_date,
      time: '10:00',
      now,
    });
    return true;
  }

  const reminderId = randomUUID();
  const nextFire = computeNextFire(entity.next_payment_due_date, '10:00', 'once', now);
  db.prepare(`
    INSERT INTO reminders (
      id, title, task_type, category, why_it_matters, repeat_type, reminder_time,
      start_date, priority, alert_style, source_type, source_id,
      next_fire, status, workflow_status, created_at, updated_at
    ) VALUES (?, ?, 'reminder', 'work', ?, 'once', '10:00', ?, 'important', 'sound-popup', ?, ?, ?, 'active', 'pending', datetime('now'), datetime('now'))
  `).run(
    reminderId,
    title,
    body,
    entity.next_payment_due_date,
    'payment',
    entity.id,
    nextFire,
  );
  return reminderId;
}

module.exports = {
  STATUS_LABELS,
  formatRupee,
  roundMoney,
  getPayments,
  getAllPayments,
  effectivePaymentTotal,
  computePaymentSummary,
  syncEntityPaymentStatus,
  updatePaymentSettings,
  recordPayment,
  deletePayment,
  schedulePaymentReminder,
};
