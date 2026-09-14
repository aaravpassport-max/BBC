/**
 * Inquiry lifecycle actions — create, stage change, activity log, follow-up reminders.
 */
const { randomUUID } = require('crypto');
const { localDateStr, localTimeStr, toLocalISO, computeNextFire } = require('./alarm');
const {
  DEFAULT_STAGES,
  getStage,
  isClosedStage,
  getStageAutomation,
} = require('./inquiry-pipeline');
const { loadStagesFromDb, saveAllStagesToDb } = require('./inquiry-stage-store');

function nextInquiryNumber(db) {
  const row = db.prepare("SELECT value FROM settings WHERE key = 'inquiry_counter'").get();
  const n = (parseInt(row?.value, 10) || 1000) + 1;
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('inquiry_counter', ?)").run(String(n));
  return `INQ-${n}`;
}

function findOrCreateClient(db, { name, company, mobile, email }) {
  const trimmedMobile = (mobile || '').trim();
  const trimmedEmail = (email || '').trim().toLowerCase();
  const trimmedName = (name || '').trim();

  if (trimmedMobile) {
    const byMobile = db.prepare('SELECT * FROM clients WHERE mobile = ? LIMIT 1').get(trimmedMobile);
    if (byMobile) return byMobile;
  }
  if (trimmedEmail) {
    const byEmail = db.prepare('SELECT * FROM clients WHERE lower(email) = ? LIMIT 1').get(trimmedEmail);
    if (byEmail) return byEmail;
  }

  const id = randomUUID();
  db.prepare(`
    INSERT INTO clients (id, name, company, mobile, email) VALUES (?, ?, ?, ?, ?)
  `).run(id, trimmedName, company || '', trimmedMobile, trimmedEmail);
  return { id, name: trimmedName, company: company || '', mobile: trimmedMobile, email: trimmedEmail };
}

function findPossibleDuplicates(db, { name, mobile, email, requirement }) {
  const results = [];
  const trimmedMobile = (mobile || '').trim();
  const trimmedEmail = (email || '').trim().toLowerCase();
  const trimmedName = (name || '').trim().toLowerCase();

  if (trimmedMobile) {
    db.prepare(`
      SELECT i.* FROM inquiries i
      WHERE i.mobile = ? AND i.outcome_status = 'active' LIMIT 5
    `).all(trimmedMobile).forEach((r) => results.push(r));
  }
  if (trimmedEmail) {
    db.prepare(`
      SELECT i.* FROM inquiries i
      WHERE lower(i.email) = ? AND i.outcome_status = 'active' LIMIT 5
    `).all(trimmedEmail).forEach((r) => {
      if (!results.find((x) => x.id === r.id)) results.push(r);
    });
  }
  if (trimmedName.length >= 3) {
    db.prepare(`
      SELECT i.* FROM inquiries i
      WHERE lower(i.client_name) LIKE ? AND i.outcome_status = 'active' LIMIT 5
    `).all(`%${trimmedName}%`).forEach((r) => {
      if (!results.find((x) => x.id === r.id)) results.push(r);
    });
  }
  return results.slice(0, 5);
}

function logActivity(db, inquiryId, activityType, title, body = '', meta = {}) {
  const id = randomUUID();
  db.prepare(`
    INSERT INTO inquiry_activities (id, inquiry_id, activity_type, title, body, old_stage_key, new_stage_key, metadata)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    id,
    inquiryId,
    activityType,
    title,
    body,
    meta.oldStage || '',
    meta.newStage || '',
    JSON.stringify(meta.extra || {}),
  );
  db.prepare(`UPDATE inquiries SET last_activity_at = datetime('now'), updated_at = datetime('now') WHERE id = ?`).run(inquiryId);
  return id;
}

function computeInquiryHealth(inquiry, now = new Date()) {
  if (inquiry.outcome_status !== 'active') return 'closed';
  const followUp = inquiry.next_follow_up;
  const followTime = inquiry.next_follow_up_time || '09:00';
  if (followUp) {
    const due = new Date(`${followUp}T${followTime}:00`);
    if (due.getTime() < now.getTime() - 86400000) return 'at_risk';
    if (due.getTime() < now.getTime()) return 'needs_attention';
  }
  if (inquiry.last_activity_at) {
    const last = new Date(String(inquiry.last_activity_at).replace(' ', 'T'));
    const daysSince = (now - last) / 86400000;
    if (daysSince > 7) return 'stale';
    if (daysSince > 3) return 'needs_attention';
  }
  return 'healthy';
}

function createFollowUpReminder(db, inquiry, { title, date, time, now = new Date() }) {
  const reminderId = randomUUID();
  const fireTime = time || '09:00';
  const startDate = date || localDateStr(now);
  const nextFire = computeNextFire(startDate, fireTime, 'once', now);
  const reminderTitle = title || `Follow up: ${inquiry.client_name}`;
  db.prepare(`
    INSERT INTO reminders (
      id, title, task_type, category, why_it_matters, repeat_type, reminder_time,
      start_date, priority, alert_style, assigned_to, source_type, source_id,
      next_fire, status, workflow_status, created_at, updated_at
    ) VALUES (?, ?, 'reminder', 'work', ?, 'once', ?, ?, 'important', 'sound-popup', ?, 'inquiry', ?, ?, 'active', 'pending', datetime('now'), datetime('now'))
  `).run(
    reminderId,
    reminderTitle,
    inquiry.requirement || '',
    fireTime,
    startDate,
    inquiry.assigned_to || 'me',
    inquiry.id,
    nextFire,
  );
  return reminderId;
}

function createInquiry(db, data, now = new Date()) {
  const client = findOrCreateClient(db, {
    name: data.clientName,
    company: data.company,
    mobile: data.mobile,
    email: data.email,
  });

  const id = randomUUID();
  const inquiryNumber = nextInquiryNumber(db);
  const stageKey = data.stageKey || 'follow_up';
  const today = localDateStr(now);

  db.prepare(`
    INSERT INTO inquiries (
      id, inquiry_number, client_id, client_name, company, mobile, email,
      requirement, service_category, source, stage_key, priority, assigned_to,
      next_action, next_follow_up, next_follow_up_time, expected_value, quotation_amount,
      work_start_date, expected_completion_date,
      outcome_status, health, stage_changed_at, last_activity_at, notes, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'healthy', datetime('now'), datetime('now'), ?, datetime('now'), datetime('now'))
  `).run(
    id,
    inquiryNumber,
    client.id,
    client.name,
    data.company || client.company || '',
    data.mobile || client.mobile || '',
    data.email || client.email || '',
    data.requirement,
    data.serviceCategory || '',
    data.source || '',
    stageKey,
    data.priority || 'normal',
    data.assignedTo || 'me',
    data.nextAction || data.requirement || 'Follow up',
    data.nextFollowUp || '',
    data.nextFollowUpTime || '',
    parseFloat(data.expectedValue) || 0,
    parseFloat(data.quotationAmount) || 0,
    data.workStartDate || today,
    data.expectedCompletionDate || '',
    data.notes || '',
  );

  logActivity(db, id, 'inquiry_created', 'Inquiry created', `${client.name} — ${data.requirement}`, { newStage: stageKey });

  if (data.nextFollowUp) {
    createFollowUpReminder(db, { id, client_name: client.name, requirement: data.requirement, assigned_to: data.assignedTo }, {
      title: data.nextAction ? `${data.nextAction}: ${client.name}` : undefined,
      date: data.nextFollowUp,
      time: data.nextFollowUpTime || '09:00',
      now,
    });
    logActivity(db, id, 'follow_up', 'Follow-up scheduled', `${data.nextFollowUp} ${data.nextFollowUpTime || '09:00'}`, {});
  }

  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(id);
  inquiry.health = computeInquiryHealth(inquiry, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(inquiry.health, id);

  return { success: true, inquiry, client };
}

function changeInquiryStage(db, inquiryId, newStageKey, options = {}, now = new Date()) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };

  const stages = loadStagesFromDb(db);
  const stage = getStage(newStageKey, stages);
  if (!stage) return { success: false, error: 'Invalid stage' };
  const automation = getStageAutomation(newStageKey, stages);

  const oldKey = inquiry.stage_key;
  const outcome = isClosedStage(newStageKey, stages) ? 'closed_lost' : (newStageKey === 'delivered' ? 'closed_won' : 'active');

  // Cancel prior stage follow-up reminders before applying new stage rules
  db.prepare(`
    UPDATE reminders SET status = 'deleted', updated_at = datetime('now')
    WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).run(inquiryId);

  db.prepare(`
    UPDATE inquiries SET stage_key = ?, outcome_status = ?, closed_reason = ?,
      stage_changed_at = datetime('now'), updated_at = datetime('now'),
      quotation_amount = COALESCE(?, quotation_amount),
      payment_status = COALESCE(?, payment_status)
    WHERE id = ?
  `).run(
    newStageKey,
    outcome,
    options.closedReason || (isClosedStage(newStageKey, stages) ? stage.display : ''),
    options.quotationAmount ?? null,
    options.paymentStatus ?? null,
    inquiryId,
  );

  logActivity(db, inquiryId, 'stage_change', `Stage → ${stage.display}`, options.note || '', {
    oldStage: oldKey,
    newStage: newStageKey,
  });

  const followDays = options.followUpDays ?? automation.followUpDays;
  const autoNextAction = options.nextAction || automation.nextAction;
  if (followDays && !isClosedStage(newStageKey, stages)) {
    const d = new Date(now);
    d.setDate(d.getDate() + followDays);
    const dateStr = localDateStr(d);
    const timeStr = options.followUpTime || inquiry.next_follow_up_time || '11:00';
    createFollowUpReminder(db, inquiry, {
      title: `Follow up: ${inquiry.client_name}`,
      date: dateStr,
      time: timeStr,
      now,
    });
    db.prepare(`UPDATE inquiries SET next_follow_up = ?, next_follow_up_time = ?, next_action = ? WHERE id = ?`).run(
      dateStr, timeStr, autoNextAction || 'Follow up with client', inquiryId,
    );
    logActivity(db, inquiryId, 'follow_up', 'Auto follow-up scheduled', `${dateStr} ${timeStr}`, {});
  }

  if (options.nextFollowUp) {
    createFollowUpReminder(db, inquiry, {
      date: options.nextFollowUp,
      time: options.nextFollowUpTime || '11:00',
      now,
    });
    db.prepare(`UPDATE inquiries SET next_follow_up = ?, next_follow_up_time = ? WHERE id = ?`).run(
      options.nextFollowUp, options.nextFollowUpTime || '11:00', inquiryId,
    );
  }

  const updated = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  const health = computeInquiryHealth(updated, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  updated.health = health;

  return { success: true, inquiry: updated, stage };
}

function logInquiryActivity(db, inquiryId, { type, title, body }) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  logActivity(db, inquiryId, type || 'note', title || 'Note', body || '');
  const health = computeInquiryHealth(inquiry, new Date());
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  return { success: true };
}

function updateInquiry(db, inquiryId, data, now = new Date()) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };

  if (data.clientName || data.mobile || data.email) {
    const client = findOrCreateClient(db, {
      name: data.clientName || inquiry.client_name,
      company: data.company || inquiry.company,
      mobile: data.mobile || inquiry.mobile,
      email: data.email || inquiry.email,
    });
    db.prepare(`
      UPDATE clients SET name = ?, company = ?, mobile = ?, email = ? WHERE id = ?
    `).run(client.name, data.company || client.company, data.mobile || client.mobile, data.email || client.email, client.id);
    data.clientId = client.id;
  }

  db.prepare(`
    UPDATE inquiries SET
      client_id = COALESCE(?, client_id),
      client_name = COALESCE(?, client_name),
      company = COALESCE(?, company),
      mobile = COALESCE(?, mobile),
      email = COALESCE(?, email),
      requirement = COALESCE(?, requirement),
      service_category = COALESCE(?, service_category),
      source = COALESCE(?, source),
      stage_key = COALESCE(?, stage_key),
      next_action = COALESCE(?, next_action),
      next_follow_up = COALESCE(?, next_follow_up),
      next_follow_up_time = COALESCE(?, next_follow_up_time),
      expected_value = COALESCE(?, expected_value),
      quotation_amount = COALESCE(?, quotation_amount),
      work_start_date = COALESCE(?, work_start_date),
      expected_completion_date = COALESCE(?, expected_completion_date),
      notes = COALESCE(?, notes),
      updated_at = datetime('now')
    WHERE id = ?
  `).run(
    data.clientId ?? null,
    data.clientName ?? null,
    data.company ?? null,
    data.mobile ?? null,
    data.email ?? null,
    data.requirement ?? null,
    data.serviceCategory ?? null,
    data.source ?? null,
    data.stageKey ?? null,
    data.nextAction ?? null,
    data.nextFollowUp ?? null,
    data.nextFollowUpTime ?? null,
    data.expectedValue != null ? parseFloat(data.expectedValue) : null,
    data.quotationAmount != null ? parseFloat(data.quotationAmount) : null,
    data.workStartDate ?? null,
    data.expectedCompletionDate ?? null,
    data.notes ?? null,
    inquiryId,
  );

  logActivity(db, inquiryId, 'note', 'Inquiry updated', data.requirement || inquiry.requirement, {});

  const wasClosed = inquiry.outcome_status !== 'active' && inquiry.outcome_status !== 'deleted';
  if (wasClosed && data.nextFollowUp) {
    return rescheduleInquiry(db, inquiryId, {
      date: data.nextFollowUp,
      time: data.nextFollowUpTime,
      stageKey: data.stageKey,
      nextAction: data.nextAction,
    }, now);
  }

  const updated = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  const health = computeInquiryHealth(updated, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  updated.health = health;
  return { success: true, inquiry: updated };
}

function deleteInquiry(db, inquiryId) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  if (inquiry.outcome_status === 'deleted') return { success: false, error: 'Already deleted' };
  db.prepare(`
    UPDATE inquiries SET outcome_status = 'deleted', updated_at = datetime('now') WHERE id = ?
  `).run(inquiryId);
  logActivity(db, inquiryId, 'note', 'Inquiry deleted', '', {});
  return { success: true };
}

function bulkDeleteInquiries(db, ids) {
  if (!Array.isArray(ids) || ids.length === 0) {
    return { success: false, error: 'No items selected' };
  }
  let deleted = 0;
  for (const id of ids) {
    const result = deleteInquiry(db, id);
    if (result.success) deleted += 1;
  }
  return { success: true, deleted };
}

function reopenInquiry(db, inquiryId, stageKey = 'follow_up') {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  db.prepare(`
    UPDATE inquiries SET outcome_status = 'active', stage_key = ?, closed_reason = '',
      stage_changed_at = datetime('now'), updated_at = datetime('now'), health = 'healthy'
    WHERE id = ?
  `).run(stageKey, inquiryId);
  logActivity(db, inquiryId, 'stage_change', 'Inquiry reopened', '', { newStage: stageKey });
  const updated = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  return { success: true, inquiry: updated };
}

function rescheduleInquiry(db, inquiryId, options = {}, now = new Date()) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  if (inquiry.outcome_status === 'deleted') return { success: false, error: 'Inquiry deleted' };

  const date = options.date || options.nextFollowUp;
  if (!date) return { success: false, error: 'Follow-up date required' };

  const time = options.time || options.nextFollowUpTime || inquiry.next_follow_up_time || '11:00';
  const stageKey = options.stageKey || (inquiry.outcome_status === 'active' ? inquiry.stage_key : 'follow_up');
  const nextAction = options.nextAction || inquiry.next_action || 'Follow up with client';
  const wasClosed = inquiry.outcome_status !== 'active';

  db.prepare(`
    UPDATE reminders SET status = 'deleted', updated_at = datetime('now')
    WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).run(inquiryId);

  db.prepare(`
    UPDATE inquiries SET
      outcome_status = 'active',
      stage_key = ?,
      closed_reason = '',
      next_follow_up = ?,
      next_follow_up_time = ?,
      next_action = ?,
      stage_changed_at = datetime('now'),
      updated_at = datetime('now')
    WHERE id = ?
  `).run(stageKey, date, time, nextAction, inquiryId);

  const refreshed = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  createFollowUpReminder(db, refreshed, {
    title: `${nextAction}: ${refreshed.client_name}`,
    date,
    time,
    now,
  });

  logActivity(
    db,
    inquiryId,
    wasClosed ? 'stage_change' : 'follow_up',
    wasClosed ? 'Inquiry rescheduled & reopened' : 'Follow-up rescheduled',
    `${date} ${time}`,
    { newStage: stageKey },
  );

  const health = computeInquiryHealth(refreshed, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  refreshed.health = health;
  return { success: true, inquiry: refreshed };
}

function seedInquiryStages(db) {
  const { getStageFields, getStageAutomation } = require('./inquiry-pipeline');
  const stages = DEFAULT_STAGES.map((s) => ({
    ...s,
    fields: getStageFields(s.key),
    automation: getStageAutomation(s.key),
  }));
  saveAllStagesToDb(db, stages);
}

module.exports = {
  createInquiry,
  updateInquiry,
  changeInquiryStage,
  logInquiryActivity,
  findPossibleDuplicates,
  findOrCreateClient,
  computeInquiryHealth,
  createFollowUpReminder,
  reopenInquiry,
  rescheduleInquiry,
  deleteInquiry,
  bulkDeleteInquiries,
  seedInquiryStages,
  logActivity,
};
