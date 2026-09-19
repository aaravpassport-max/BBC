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

  const QUEUE_TAB_IN_PROCESS = 'in_process';
  const INQUIRY_NEW_STAGE_KEYS = new Set([
    'follow_up',
    'high_quality_prospect',
    'need_to_work_on_query',
  ]);

  function inferLifecycleFromInquiry(inquiry) {
    if (!inquiry) return LIFECYCLE_ACTIVE;
    const stored = String(inquiry.lifecycle_status ?? '').trim();
    if (stored) return normalizeLifecycle(stored);
    if (inquiry.outcome_status === 'deleted') return LIFECYCLE_CLOSED;
    if (inquiry.outcome_status === 'closed_lost') return LIFECYCLE_CLOSED;
    if (inquiry.outcome_status === 'closed_won') return LIFECYCLE_COMPLETED;
    if (inquiry.outcome_status && inquiry.outcome_status !== 'active') return LIFECYCLE_CLOSED;
    return LIFECYCLE_ACTIVE;
  }

  function isInquiryInProcess(inquiry) {
    if (!inquiry) return false;
    const phase = String(inquiry.work_phase || '').trim().toLowerCase();
    if (phase === 'in_process') return true;
    if (String(inquiry.work_start_date || '').trim()) return true;
    const op = String(inquiry.operational_state || '').trim();
    if (op && op !== 'new') return true;
    const stageKey = inquiry.stage_key || 'follow_up';
    if (!INQUIRY_NEW_STAGE_KEYS.has(stageKey)) return true;
    return false;
  }

  function inferInquiryQueueTab(inquiry) {
    const lc = inferLifecycleFromInquiry(inquiry);
    if (lc !== LIFECYCLE_ACTIVE) return lc;
    return isInquiryInProcess(inquiry) ? QUEUE_TAB_IN_PROCESS : LIFECYCLE_ACTIVE;
  }

  function inferReminderQueueTab(reminder) {
    const lc = inferLifecycleFromReminder(reminder);
    if (lc !== LIFECYCLE_ACTIVE) return lc;
    if ((reminder.workflow_status || '') === 'in_progress') return QUEUE_TAB_IN_PROCESS;
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
    const sel = normalizeLifecycle(selected);
    return `<select class="form-select lifecycle-status-select" id="${id}" name="${id}">${opts.map((s) =>
      `<option value="${s.id}" ${sel === s.id ? 'selected' : ''}>${s.label}</option>`
    ).join('')}</select>`;
  }

  /** Always returns a status &lt;select&gt; (never empty) for forms. */
  function lifecycleSelectField(id, selected, options = {}) {
    return lifecycleSelectHtml(id, selected, options);
  }

  function lifecycleBadge(lifecycle) {
    const lc = normalizeLifecycle(lifecycle);
    const cls = `lifecycle-badge lifecycle-${lc}`;
    return `<span class="${cls}" title="Status">${lifecycleLabel(lc, true)}</span>`;
  }

  /** Inline work-status control for list cards (no Edit sheet). */
  function lifecycleCardControl(item, entityType = 'reminder') {
    if (!item?.id) return '';
    const isInquiry = entityType === 'inquiry';
    const lc = isInquiry ? inferLifecycleFromInquiry(item) : inferLifecycleFromReminder(item);
    const extraClass = isInquiry ? 'inquiry-lifecycle-card-select' : 'reminder-lifecycle-card-select';
    const dataAttr = isInquiry
      ? `data-inquiry-id="${item.id}"`
      : `data-reminder-id="${item.id}"`;
    const opts = LIFECYCLE_STATUSES.map((s) =>
      `<option value="${s.id}" ${lc === s.id ? 'selected' : ''}>${s.short} — ${s.label}</option>`,
    ).join('');
    const typeHint = isInquiry ? 'inquiry' : (item.task_type === 'task' ? 'task' : 'reminder');
    return `<div class="card-work-status" onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
      <span class="card-work-status-label">Work status</span>
      <select class="lifecycle-quick-select lifecycle-card-select ${extraClass}" ${dataAttr}
        aria-label="Change work status for this ${typeHint}"
        title="Change work status without opening Edit">${opts}</select>
    </div>`;
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
        const scheduleOpt = scheduleCb.closest('.lifecycle-schedule-opt');
        if (scheduleOpt) scheduleOpt.style.display = showCb ? 'block' : 'none';
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
    QUEUE_TAB_IN_PROCESS,
    LIFECYCLE_STATUSES,
    normalizeLifecycle,
    inferLifecycleFromReminder,
    inferLifecycleFromInquiry,
    isInquiryInProcess,
    inferInquiryQueueTab,
    inferReminderQueueTab,
    lifecycleLabel,
    isLifecycleSchedulable,
    requiresNextReminderDate,
    blocksNextReminder,
    lifecycleSelectHtml,
    lifecycleSelectField,
    lifecycleBadge,
    lifecycleCardControl,
    wireLifecycleFollowUpToggle,
    isInquiryActiveForWorkQueue,
    isReminderOpenForWork,
    isReminderSchedulableByLifecycle,
    hasScheduledNextActionReminder,
    hasScheduledInquiryFollowUp,
  };
})();
