/**
 * Default workflow stages for tasks and reminders.
 */
const TASK_STAGES = [
  { key: 'new', display: 'New', category: 'open', sort: 1, closed: false, color: '#3b82f6' },
  { key: 'in_progress', display: 'In Progress', category: 'active', sort: 2, closed: false, color: '#8b5cf6' },
  { key: 'follow_up', display: 'Follow Up', category: 'active', sort: 3, closed: false, color: '#eab308',
    automation: { reminderEnabled: true, followUpDays: 2, nextAction: 'Follow up on task', reminderPriority: 'important' } },
  { key: 'waiting', display: 'Waiting', category: 'hold', sort: 4, closed: false, color: '#0ea5e9' },
  { key: 'blocked', display: 'Blocked', category: 'hold', sort: 5, closed: false, color: '#ef4444' },
  { key: 'done', display: 'Done', category: 'closed', sort: 6, closed: true, color: '#64748b' },
];

const REMINDER_STAGES = [
  { key: 'scheduled', display: 'Scheduled', category: 'open', sort: 1, closed: false, color: '#3b82f6' },
  { key: 'active', display: 'Active', category: 'active', sort: 2, closed: false, color: '#22c55e' },
  { key: 'follow_up', display: 'Follow Up', category: 'active', sort: 3, closed: false, color: '#eab308',
    automation: { reminderEnabled: true, followUpDays: 1, reminderPriority: 'important' } },
  { key: 'snoozed', display: 'Snoozed', category: 'hold', sort: 4, closed: false, color: '#94a3b8' },
  { key: 'done', display: 'Done', category: 'closed', sort: 6, closed: true, color: '#64748b' },
];

const ENTITY_TYPES = ['task', 'reminder', 'inquiry'];

const CATEGORY_COLORS = {
  open: { bg: 'rgba(59, 130, 246, 0.16)', border: '#3b82f6' },
  active: { bg: 'rgba(34, 197, 94, 0.16)', border: '#22c55e' },
  hold: { bg: 'rgba(234, 179, 8, 0.16)', border: '#eab308' },
  closed: { bg: 'rgba(100, 116, 139, 0.16)', border: '#64748b' },
  general: { bg: 'rgba(148, 163, 184, 0.16)', border: '#94a3b8' },
};

function defaultStages(entityType) {
  if (entityType === 'task') return TASK_STAGES;
  if (entityType === 'reminder') return REMINDER_STAGES;
  return [];
}

function defaultStageKey(entityType) {
  if (entityType === 'task') return 'new';
  if (entityType === 'reminder') return 'scheduled';
  return 'follow_up';
}

module.exports = {
  TASK_STAGES,
  REMINDER_STAGES,
  ENTITY_TYPES,
  CATEGORY_COLORS,
  defaultStages,
  defaultStageKey,
};
