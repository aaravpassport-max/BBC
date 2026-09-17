/**
 * Work scheduling helpers — start/completion dates, filters, overdue detection.
 */
const { localDateStr } = require('./alarm');
const { isInquiryActiveForWorkQueue, isReminderOpenForWork } = require('./work-lifecycle');

function dateOnly(value) {
  if (!value) return '';
  const s = String(value).trim();
  if (s.length >= 10) return s.slice(0, 10);
  return '';
}

function effectiveWorkStart(item) {
  return dateOnly(item?.work_start_date) || dateOnly(item?.start_date) || '';
}

function effectiveCompletionDate(item) {
  return dateOnly(item?.expected_completion_date) || dateOnly(item?.end_date) || '';
}

function effectiveFollowUpDate(item) {
  if (item?.next_follow_up) return dateOnly(item.next_follow_up);
  if (item?.next_fire) return dateOnly(item.next_fire);
  return '';
}

function effectiveInquiryScheduleDate(inq) {
  return dateOnly(inq?.next_follow_up);
}

function isActiveInquiry(inq) {
  return isInquiryActiveForWorkQueue(inq);
}

function tomorrowStr(now = new Date()) {
  const d = new Date(now);
  d.setDate(d.getDate() + 1);
  return localDateStr(d);
}

function isInquiryDueToday(inq, now = new Date()) {
  if (!isActiveInquiry(inq)) return false;
  const d = effectiveInquiryScheduleDate(inq);
  return Boolean(d && d === localDateStr(now));
}

function isInquiryDueTomorrow(inq, now = new Date()) {
  if (!isActiveInquiry(inq)) return false;
  const d = effectiveInquiryScheduleDate(inq);
  return Boolean(d && d === tomorrowStr(now));
}

function isInquiryUpcoming(inq, now = new Date()) {
  if (!isActiveInquiry(inq)) return false;
  const d = effectiveInquiryScheduleDate(inq);
  if (!d) return false;
  return d > tomorrowStr(now);
}

function isInquiryFollowUpOverdue(inq, now = new Date()) {
  if (!isActiveInquiry(inq)) return false;
  const d = effectiveInquiryScheduleDate(inq);
  return Boolean(d && d < localDateStr(now));
}

function isWorkItemActive(item) {
  if (!item) return false;
  if (item.outcome_status) return isInquiryActiveForWorkQueue(item);
  if (item.status === 'deleted') return false;
  if (!isReminderOpenForWork(item)) return false;
  if (item.status === 'completed') return false;
  if (item.workflow_status === 'done') return false;
  return item.status === 'active' || !item.status;
}

function parseDateAtMidnight(dateStr) {
  if (!dateStr || dateStr.length < 10) return null;
  const d = new Date(`${dateStr}T00:00:00`);
  return Number.isNaN(d.getTime()) ? null : d;
}

function daysBetween(fromDate, toDate) {
  const a = parseDateAtMidnight(fromDate);
  const b = parseDateAtMidnight(toDate);
  if (!a || !b) return null;
  return Math.round((b.getTime() - a.getTime()) / 86400000);
}

function isCompletionOverdue(item, now = new Date()) {
  if (!isWorkItemActive(item)) return false;
  const due = effectiveCompletionDate(item);
  if (!due) return false;
  const today = localDateStr(now);
  return due < today;
}

function isCompletionDueToday(item, now = new Date()) {
  if (!isWorkItemActive(item)) return false;
  const due = effectiveCompletionDate(item);
  return due && due === localDateStr(now);
}

function isCompletionApproaching(item, now = new Date(), withinDays = 3) {
  if (!isWorkItemActive(item)) return false;
  const due = effectiveCompletionDate(item);
  if (!due) return false;
  const diff = daysBetween(localDateStr(now), due);
  return diff !== null && diff >= 0 && diff <= withinDays;
}

function startOfWeek(d) {
  const x = new Date(d);
  const day = x.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  x.setDate(x.getDate() + diff);
  x.setHours(0, 0, 0, 0);
  return x;
}

function endOfWeek(d) {
  const s = startOfWeek(d);
  const e = new Date(s);
  e.setDate(e.getDate() + 6);
  return e;
}

function monthRange(year, monthIndex) {
  const start = new Date(year, monthIndex, 1);
  const end = new Date(year, monthIndex + 1, 0);
  return { start: localDateStr(start), end: localDateStr(end) };
}

function getDateFilterRange(filter, now = new Date(), custom = {}) {
  const today = localDateStr(now);
  switch (filter) {
    case 'today':
      return { start: today, end: today };
    case 'this_week': {
      const s = startOfWeek(now);
      const e = endOfWeek(now);
      return { start: localDateStr(s), end: localDateStr(e) };
    }
    case 'this_month': {
      const r = monthRange(now.getFullYear(), now.getMonth());
      return { start: r.start, end: r.end };
    }
    case 'next_month': {
      const d = new Date(now.getFullYear(), now.getMonth() + 1, 1);
      const r = monthRange(d.getFullYear(), d.getMonth());
      return { start: r.start, end: r.end };
    }
    case 'custom':
      return { start: custom.start || today, end: custom.end || custom.start || today };
    case 'overdue':
      return { start: '', end: today, mode: 'overdue' };
    case 'upcoming':
      return { start: today, end: '', mode: 'upcoming' };
    default:
      return { start: '', end: '' };
  }
}

function dateInRange(dateStr, start, end) {
  if (!dateStr) return false;
  if (start && dateStr < start) return false;
  if (end && dateStr > end) return false;
  return true;
}

function matchesCompletionFilter(item, filter, now = new Date(), custom = {}) {
  const due = effectiveCompletionDate(item);
  if (!due) return filter === 'all' || filter === '';
  const range = getDateFilterRange(filter, now, custom);
  if (range.mode === 'overdue') return isCompletionOverdue(item, now);
  if (range.mode === 'upcoming') return isWorkItemActive(item) && due >= localDateStr(now);
  if (!range.start && !range.end) return true;
  return dateInRange(due, range.start, range.end);
}

function matchesScheduleFilter(item, filter, now = new Date(), custom = {}) {
  if (matchesCompletionFilter(item, filter, now, custom)) return true;
  const alarm = dateOnly(item?.next_fire) || dateOnly(item?.start_date);
  const follow = effectiveFollowUpDate(item);
  const range = getDateFilterRange(filter, now, custom);
  if (range.mode === 'overdue') {
    return isCompletionOverdue(item, now)
      || (isWorkItemActive(item) && alarm && alarm < localDateStr(now))
      || (item.next_follow_up && item.next_follow_up < localDateStr(now));
  }
  if (range.mode === 'upcoming') {
    const today = localDateStr(now);
    const due = effectiveCompletionDate(item);
    return (alarm && alarm >= today) || (follow && follow >= today) || (due && due >= today);
  }
  const dates = [effectiveCompletionDate(item), effectiveWorkStart(item), alarm, follow].filter(Boolean);
  return dates.some((d) => dateInRange(d, range.start, range.end));
}

function workItemKind(item) {
  if (item.inquiry_number || item.requirement) return 'inquiry';
  if (item.task_type === 'task') return 'task';
  return 'reminder';
}

function normalizeWorkItem(item) {
  const kind = workItemKind(item);
  return {
    ...item,
    _workKind: kind,
    _workStart: effectiveWorkStart(item),
    _completionDue: effectiveCompletionDate(item),
    _followUp: effectiveFollowUpDate(item),
    _overdue: isCompletionOverdue(item),
    _dueToday: isCompletionDueToday(item),
    _approaching: isCompletionApproaching(item),
  };
}

function sortByCompletionDate(items) {
  return [...items].sort((a, b) => {
    const ad = effectiveCompletionDate(a) || '9999-12-31';
    const bd = effectiveCompletionDate(b) || '9999-12-31';
    return ad.localeCompare(bd);
  });
}

function truncateNote(text, max = 120) {
  const s = String(text || '').trim();
  if (!s) return '';
  if (s.length <= max) return s;
  return `${s.slice(0, max - 1)}…`;
}

module.exports = {
  dateOnly,
  effectiveWorkStart,
  effectiveCompletionDate,
  effectiveFollowUpDate,
  effectiveInquiryScheduleDate,
  isActiveInquiry,
  isInquiryDueToday,
  isInquiryDueTomorrow,
  isInquiryUpcoming,
  isInquiryFollowUpOverdue,
  isWorkItemActive,
  isCompletionOverdue,
  isCompletionDueToday,
  isCompletionApproaching,
  getDateFilterRange,
  matchesCompletionFilter,
  matchesScheduleFilter,
  workItemKind,
  normalizeWorkItem,
  sortByCompletionDate,
  truncateNote,
  daysBetween,
};
