// ILRS — Work scheduling UI helpers (frontend mirror of work-scheduling.js)
(function () {
  function dateOnly(value) {
    if (!value) return '';
    const s = String(value).trim();
    return s.length >= 10 ? s.slice(0, 10) : '';
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

  /** Canonical tab-classification date for inquiries (saved follow-up, not created_at). */
  function effectiveInquiryScheduleDate(inq) {
    return dateOnly(inq?.next_follow_up);
  }

  function isActiveInquiry(inq) {
    const LC = window.ILRSWorkLifecycle;
    if (LC?.isInquiryActiveForWorkQueue) return LC.isInquiryActiveForWorkQueue(inq);
    return Boolean(inq && inq.outcome_status === 'active');
  }

  function tomorrowStr(now = new Date()) {
    const cap = window.ILRSCapture;
    return cap?.dateStr?.(cap.addDays(now, 1)) || todayStr(now);
  }

  function isInquiryDueToday(inq, now = new Date()) {
    if (!isActiveInquiry(inq)) return false;
    const d = effectiveInquiryScheduleDate(inq);
    return Boolean(d && d === todayStr(now));
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
    const tomorrow = tomorrowStr(now);
    return d > tomorrow;
  }

  function isInquiryFollowUpOverdue(inq, now = new Date()) {
    if (!isActiveInquiry(inq)) return false;
    const d = effectiveInquiryScheduleDate(inq);
    return Boolean(d && d < todayStr(now));
  }

  function isWorkItemActive(item) {
    if (!item) return false;
    const LC = window.ILRSWorkLifecycle;
    if (item.outcome_status) {
      if (LC?.isInquiryActiveForWorkQueue) return LC.isInquiryActiveForWorkQueue(item);
      return item.outcome_status === 'active';
    }
    if (item.status === 'deleted') return false;
    if (LC?.isReminderOpenForWork && !LC.isReminderOpenForWork(item)) return false;
    if (item.status === 'completed') return false;
    if (item.workflow_status === 'done') return false;
    return item.status === 'active' || !item.status;
  }

  function todayStr(now = new Date()) {
    const cap = window.ILRSCapture;
    return cap?.dateStr ? cap.dateStr(now) : now.toISOString().slice(0, 10);
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
    return due < todayStr(now);
  }

  function isCompletionDueToday(item, now = new Date()) {
    if (!isWorkItemActive(item)) return false;
    const due = effectiveCompletionDate(item);
    return due && due === todayStr(now);
  }

  function isCompletionApproaching(item, now = new Date(), withinDays = 3) {
    if (!isWorkItemActive(item)) return false;
    const due = effectiveCompletionDate(item);
    if (!due) return false;
    const diff = daysBetween(todayStr(now), due);
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
    return { start: todayStr(start), end: todayStr(end) };
  }

  function getDateFilterRange(filter, now = new Date(), custom = {}) {
    const today = todayStr(now);
    switch (filter) {
      case 'today':
        return { start: today, end: today };
      case 'this_week': {
        const s = startOfWeek(now);
        const e = endOfWeek(now);
        return { start: todayStr(s), end: todayStr(e) };
      }
      case 'this_month':
        return monthRange(now.getFullYear(), now.getMonth());
      case 'next_month': {
        const d = new Date(now.getFullYear(), now.getMonth() + 1, 1);
        return monthRange(d.getFullYear(), d.getMonth());
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
    if (range.mode === 'upcoming') return isWorkItemActive(item) && due >= todayStr(now);
    if (!range.start && !range.end) return true;
    return dateInRange(due, range.start, range.end);
  }

  function workItemKind(item) {
    if (item.inquiry_number || item.requirement) return 'inquiry';
    if (item.task_type === 'task') return 'task';
    return 'reminder';
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

  function formatScheduleDate(dateStr) {
    if (!dateStr) return '—';
    if (typeof formatDate === 'function') return formatDate(dateStr);
    const d = new Date(`${dateStr}T00:00:00`);
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function completionBadge(item, now = new Date()) {
    if (!isWorkItemActive(item)) return '';
    const due = effectiveCompletionDate(item);
    if (!due) return '';
    if (isCompletionOverdue(item, now)) {
      return '<span class="schedule-badge overdue">Overdue</span>';
    }
    if (isCompletionDueToday(item, now)) {
      return '<span class="schedule-badge due-today">Due today</span>';
    }
    if (isCompletionApproaching(item, now)) {
      const days = daysBetween(todayStr(now), due);
      return `<span class="schedule-badge approaching">Due in ${days}d</span>`;
    }
    return '';
  }

  function scheduleDatesHtml(item, compact = false) {
    const start = effectiveWorkStart(item);
    const due = effectiveCompletionDate(item);
    const follow = effectiveFollowUpDate(item);
    if (!start && !due && !follow) return '';
    const badge = completionBadge(item);
    const cls = compact ? 'card-schedule compact' : 'card-schedule';
    return `
      <div class="${cls}">
        ${start ? `<span class="schedule-date" title="Work start">▶ ${formatScheduleDate(start)}</span>` : ''}
        ${due ? `<span class="schedule-date ${isCompletionOverdue(item) ? 'overdue' : ''}" title="Expected completion">◎ ${formatScheduleDate(due)}</span>` : ''}
        ${follow ? `<span class="schedule-date follow" title="Follow-up / alarm">↪ ${formatScheduleDate(follow)}</span>` : ''}
        ${badge}
      </div>`;
  }

  function notePreviewHtml(item, idPrefix) {
    const note = String(item.notes || '').trim();
    if (!note) return '';
    const short = truncateNote(note, 100);
    const needsExpand = note.length > 100;
    const uid = `${idPrefix}-note-${item.id}`;
    return `
      <div class="card-note-preview" id="${uid}" data-full="${needsExpand ? '1' : '0'}">
        <span class="note-label">📝</span>
        <span class="note-text ${needsExpand ? 'truncated' : ''}" id="${uid}-text">${short}</span>
        ${needsExpand ? `<button type="button" class="note-expand-btn" onclick="event.stopPropagation();toggleCardNote('${uid}')">more</button>` : ''}
      </div>`;
  }

  const DATE_FILTERS = [
    { id: 'today', label: 'Today' },
    { id: 'this_week', label: 'This Week' },
    { id: 'this_month', label: 'This Month' },
    { id: 'next_month', label: 'Next Month' },
    { id: 'upcoming', label: 'Upcoming' },
    { id: 'overdue', label: 'Overdue' },
    { id: 'custom', label: 'Custom Range' },
    { id: 'all', label: 'All' },
  ];

  window.ILRSWorkScheduling = {
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
    workItemKind,
    sortByCompletionDate,
    truncateNote,
    formatScheduleDate,
    completionBadge,
    scheduleDatesHtml,
    notePreviewHtml,
    DATE_FILTERS,
    daysBetween,
  };

  window.toggleCardNote = function (uid) {
    const wrap = document.getElementById(uid);
    const text = document.getElementById(`${uid}-text`);
    const btn = wrap?.querySelector('.note-expand-btn');
    if (!wrap || !text) return;
    const full = wrap.dataset.fullNote || text.textContent;
    if (!wrap.dataset.fullNote) wrap.dataset.fullNote = full;
    const expanded = wrap.classList.toggle('expanded');
    if (expanded) {
      text.textContent = wrap.dataset.fullNote;
      text.classList.remove('truncated');
      if (btn) btn.textContent = 'less';
    } else {
      text.textContent = truncateNote(wrap.dataset.fullNote, 100);
      text.classList.add('truncated');
      if (btn) btn.textContent = 'more';
    }
  };
})();
