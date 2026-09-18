/**
 * Mirror reminder/task events onto linked enquiry activity timelines.
 */
const { logActivity } = require('./inquiry-actions');
const { lifecycleLabel } = require('./work-lifecycle');

function inquiryIdFromReminder(reminder) {
  if (!reminder || reminder.source_type !== 'inquiry' || !reminder.source_id) return null;
  return reminder.source_id;
}

function mirrorReminderToInquiryActivity(db, reminder, activityType, title, body = '') {
  const inquiryId = inquiryIdFromReminder(reminder);
  if (!inquiryId) return;
  try {
    logActivity(db, inquiryId, activityType, title, body);
  } catch (err) {
    console.error('mirrorReminderToInquiryActivity:', err.message);
  }
}

function mirrorLifecycleToInquiry(db, inquiryId, lifecycle, { source = 'enquiry' } = {}) {
  if (!inquiryId) return;
  const label = lifecycleLabel(lifecycle);
  logActivity(
    db,
    inquiryId,
    'status_change',
    `Work status → ${label}`,
    source === 'reminder' ? 'Updated from linked reminder/task' : '',
  );
}

module.exports = {
  inquiryIdFromReminder,
  mirrorReminderToInquiryActivity,
  mirrorLifecycleToInquiry,
};
