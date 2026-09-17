// ILRS — Work lifecycle UI (frontend mirror of work-lifecycle.js)
(function () {
  const LIFECYCLE_ACTIVE = 'active';
  const LIFECYCLE_PENDING = 'pending';
  const LIFECYCLE_COMPLETED = 'completed';
  const LIFECYCLE_CLOSED = 'closed';

  const LIFECYCLE_STATUSES = [
    { id: LIFECYCLE_ACTIVE, label: 'Active / In Progress', short: 'Active' },
    { id: LIFECYCLE_PENDING, label: 'Pending / On Hold', short: 'On hold' },
    { id: LIFECYCLE_COMPLETED, label: 'Completed / Done', short: 'Done' },
    { id: LIFECYCLE_CLOSED, label: 'Closed', short: 'Closed' },
  ];

  function normalizeLifecycle(value) {
    const v = String(value || '').trim().toLowerCase();
    if ([LIFECYCLE_ACTIVE, LIFECYCLE_PENDING, LIFECYCLE_COMPLETED, LIFECYCLE_CLOSED].includes(v)) return v;
    return LIFECYCLE_ACTIVE;
  }

  function inferLifecycleFromReminder(reminder) {
    if (!reminder) return LIFECYCLE_ACTIVE;
    if (reminder.lifecycle_status) return normalizeLifecycle(reminder.lifecycle_status);
    if (reminder.status === 'deleted') return LIFECYCLE_CLOSED;
    if (reminder.status === 'completed' || (reminder.workflow_status || '') === 'done') {
      return LIFECYCLE_COMPLETED;
    }
    if ((reminder.workflow_status || '') === 'postponed') return LIFECYCLE_PENDING;
    if ((reminder.workflow_status || '') === 'in_progress') return LIFECYCLE_ACTIVE;
    return LIFECYCLE_ACTIVE;
  }

  function inferLifecycleFromInquiry(inquiry) {
    if (!inquiry) return LIFECYCLE_ACTIVE;
    if (inquiry.lifecycle_status) return normalizeLifecycle(inquiry.lifecycle_status);
    if (inquiry.outcome_status === 'deleted') return LIFECYCLE_CLOSED;
    if (inquiry.outcome_status === 'closed_lost') return LIFECYCLE_CLOSED;
    if (inquiry.outcome_status === 'closed_won') return LIFECYCLE_COMPLETED;
    if (inquiry.outcome_status && inquiry.outcome_status !== 'active') return LIFECYCLE_CLOSED;
    return LIFECYCLE_ACTIVE;
  }

  function isInquiryActiveForWorkQueue(inquiry) {
    const lc = inferLifecycleFromInquiry(inquiry);
    return lc === LIFECYCLE_ACTIVE || lc === LIFECYCLE_PENDING;
  }

  function isReminderOpenForWork(reminder) {
    const lc = inferLifecycleFromReminder(reminder);
    return lc === LIFECYCLE_ACTIVE || lc === LIFECYCLE_PENDING;
  }

  function isReminderSchedulableByLifecycle(reminder) {
    const lc = inferLifecycleFromReminder(reminder);
    if (lc === LIFECYCLE_CLOSED) return false;
    if (lc === LIFECYCLE_COMPLETED) {
      return Boolean(String(reminder.next_fire || '').trim());
    }
    return true;
  }

  function hasScheduledNextActionReminder(reminder) {
    return Boolean(String(reminder?.next_fire || '').trim());
  }

  function hasScheduledInquiryFollowUp(inquiry) {
    return Boolean(String(inquiry?.next_follow_up || '').trim());
  }

  function lifecycleLabel(id, short = false) {
    const row = LIFECYCLE_STATUSES.find((s) => s.id === id);
    if (!row) return id || '';
    return short ? row.short : row.label;
  }

  function isLifecycleSchedulable(lifecycle) {
    return lifecycle === LIFECYCLE_ACTIVE || lifecycle === LIFECYCLE_PENDING;
  }

  function requiresNextReminderDate(lifecycle, { scheduleNext = false } = {}) {
    if (lifecycle === LIFECYCLE_ACTIVE) return true;
    if (lifecycle === LIFECYCLE_PENDING) return false;
    if (lifecycle === LIFECYCLE_COMPLETED) return Boolean(scheduleNext);
    return false;
  }

  function blocksNextReminder(lifecycle) {
    return lifecycle === LIFECYCLE_CLOSED;
  }

  function lifecycleSelectHtml(id, selected, { includeClosed = true } = {}) {
    const opts = LIFECYCLE_STATUSES.filter((s) => includeClosed || s.id !== LIFECYCLE_CLOSED);
    return `<select class="form-select" id="${id}">${opts.map((s) =>
      `<option value="${s.id}" ${selected === s.id ? 'selected' : ''}>${s.label}</option>`
    ).join('')}</select>`;
  }

  function lifecycleBadge(lifecycle) {
    const lc = normalizeLifecycle(lifecycle);
    const cls = `lifecycle-badge lifecycle-${lc}`;
    return `<span class="${cls}" title="Status">${lifecycleLabel(lc, true)}</span>`;
  }

  function wireLifecycleFollowUpToggle(overlay, {
    statusSelId,
    followWrapId,
    scheduleCheckboxId,
    onChange,
  }) {
    const statusEl = overlay.querySelector(`#${statusSelId}`);
    const wrap = overlay.querySelector(`#${followWrapId}`);
    const scheduleCb = scheduleCheckboxId ? overlay.querySelector(`#${scheduleCheckboxId}`) : null;

    const refresh = () => {
      const lc = normalizeLifecycle(statusEl?.value);
      const scheduleNext = scheduleCb?.checked;
      const show = !blocksNextReminder(lc)
        && (requiresNextReminderDate(lc, { scheduleNext })
          || lc === LIFECYCLE_PENDING
          || (lc === LIFECYCLE_COMPLETED && scheduleNext));
      if (wrap) wrap.style.display = show ? 'block' : 'none';
      if (scheduleCb) {
        const showCb = lc === LIFECYCLE_COMPLETED;
        scheduleCb.closest('.lifecycle-schedule-opt')?.style.display = showCb ? 'block' : 'none';
        if (!showCb) scheduleCb.checked = false;
      }
      if (typeof onChange === 'function') onChange(lc, scheduleNext);
    };

    statusEl?.addEventListener('change', refresh);
    scheduleCb?.addEventListener('change', refresh);
    refresh();
    return refresh;
  }

  window.ILRSWorkLifecycle = {
    LIFECYCLE_ACTIVE,
    LIFECYCLE_PENDING,
    LIFECYCLE_COMPLETED,
    LIFECYCLE_CLOSED,
    LIFECYCLE_STATUSES,
    normalizeLifecycle,
    inferLifecycleFromReminder,
    inferLifecycleFromInquiry,
    lifecycleLabel,
    isLifecycleSchedulable,
    requiresNextReminderDate,
    blocksNextReminder,
    lifecycleSelectHtml,
    lifecycleBadge,
    wireLifecycleFollowUpToggle,
    isInquiryActiveForWorkQueue,
    isReminderOpenForWork,
    isReminderSchedulableByLifecycle,
    hasScheduledNextActionReminder,
    hasScheduledInquiryFollowUp,
  };
})();
