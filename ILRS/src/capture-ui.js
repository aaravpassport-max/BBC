// ILRS — Fast capture sheet UI
(function () {
  const C = () => window.ILRSCapture;

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function whenChipHtml(selected) {
    const chips = [
      { id: 'today', label: 'Today' },
      { id: 'tomorrow', label: 'Tomorrow' },
      { id: 'evening', label: 'This evening' },
      { id: 'next-week', label: 'Next week' },
      { id: 'custom', label: 'Pick date…' },
    ];
    return chips.map((c) =>
      `<button type="button" class="chip ${selected === c.id ? 'selected' : ''}" data-when="${c.id}">${c.label}</button>`
    ).join('');
  }

  function parseRepeatValue(repeatType, repeatValue) {
    if (repeatType !== 'custom') return { mode: 'days', interval: 3, days: [1, 2, 3, 4, 5] };
    try {
      const parsed = JSON.parse(repeatValue || '{}');
      if (parsed.mode === 'weekdays' && Array.isArray(parsed.days)) {
        return { mode: 'weekdays', interval: 3, days: parsed.days };
      }
      if (parsed.mode === 'days') {
        return { mode: 'days', interval: parseInt(parsed.interval, 10) || 3, days: [1, 2, 3, 4, 5] };
      }
    } catch (_) {
      const n = parseInt(repeatValue, 10);
      if (!Number.isNaN(n) && n > 0) return { mode: 'days', interval: n, days: [1, 2, 3, 4, 5] };
    }
    return { mode: 'days', interval: 3, days: [1, 2, 3, 4, 5] };
  }

  function customRepeatHtml(repeatType, repeatValue) {
    const cfg = parseRepeatValue(repeatType, repeatValue);
    const weekdays = [
      { id: 0, label: 'Sun' }, { id: 1, label: 'Mon' }, { id: 2, label: 'Tue' },
      { id: 3, label: 'Wed' }, { id: 4, label: 'Thu' }, { id: 5, label: 'Fri' }, { id: 6, label: 'Sat' },
    ];
    return `
      <div id="capture-custom-repeat" style="display:${repeatType === 'custom' ? 'block' : 'none'};margin-top:8px">
        <div class="form-group">
          <label class="form-label">Custom pattern</label>
          <select class="form-select" id="capture-custom-mode">
            <option value="days" ${cfg.mode === 'days' ? 'selected' : ''}>Every N days</option>
            <option value="weekdays" ${cfg.mode === 'weekdays' ? 'selected' : ''}>Specific weekdays</option>
          </select>
        </div>
        <div class="form-group" id="capture-custom-days-wrap" style="display:${cfg.mode === 'days' ? 'block' : 'none'}">
          <label class="form-label">Every</label>
          <input type="number" class="form-input" id="capture-custom-interval" min="2" max="365" value="${cfg.interval}" />
          <span class="form-hint">days</span>
        </div>
        <div class="form-group" id="capture-custom-weekdays-wrap" style="display:${cfg.mode === 'weekdays' ? 'block' : 'none'}">
          <label class="form-label">On days</label>
          <div class="chip-row" id="capture-weekday-chips">
            ${weekdays.map((d) =>
              `<button type="button" class="chip ${cfg.days.includes(d.id) ? 'selected' : ''}" data-weekday="${d.id}">${d.label}</button>`
            ).join('')}
          </div>
        </div>
      </div>`;
  }

  function timeChipHtml(selected) {
    const chips = ['09:00', '10:00', '14:00', '18:00'];
    return chips.map((t) =>
      `<button type="button" class="chip ${selected === t ? 'selected' : ''}" data-time="${t}">${window.formatTime ? formatTime(t) : t}</button>`
    ).join('') + `<button type="button" class="chip ${selected === 'custom' ? 'selected' : ''}" data-time="custom">Custom</button>`;
  }

  function showCaptureSheet(existing = null) {
    if (typeof dismissPageModals === 'function') dismissPageModals();
    document.getElementById('capture-sheet')?.remove();

    const isEdit = !!(existing && existing.id);
    const r = existing || {};
    const whenInfo = isEdit && C()?.resolveWhenFromExisting
      ? C().resolveWhenFromExisting(r)
      : { when: 'today', startDate: r.start_date || (typeof todayStr === 'function' ? todayStr() : '') };
    const state = {
      id: r.id || (typeof uuid === 'function' ? uuid() : ''),
      kind: r.task_type === 'task' ? 'task' : 'reminder',
      when: whenInfo.when,
      startDate: whenInfo.startDate,
      stageKey: r.stage_key || '',
      time: r.reminder_time || '',
      timeMode: r.reminder_time ? (['09:00', '10:00', '14:00', '18:00'].includes(r.reminder_time) ? r.reminder_time : 'custom') : '',
      category: r.category || 'general',
      priority: r.priority || 'normal',
      repeat: r.repeat_type || 'once',
      repeatValue: r.repeat_value || '',
      why: r.why_it_matters || '',
      notes: r.notes || '',
      tags: JSON.parse(r.tags || '[]').join(', '),
      assigned: r.assigned_to || 'me',
      family: (typeof App !== 'undefined' && App.family) ? App.family : [],
      alert: r.alert_style || 'sound-popup',
      private: Number(r.is_private) === 1,
      endDate: r.end_date || '',
      sourceType: r.source_type || '',
      sourceId: r.source_id || '',
      moreOpen: isEdit || !!(r.source_type && r.source_id),
    };

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay capture-overlay';
    overlay.id = 'capture-sheet';
    overlay.innerHTML = `
      <div class="capture-sheet" role="dialog" aria-label="${isEdit ? 'Edit' : 'Create'} reminder">
        <div class="capture-header">
          <h2>${isEdit ? 'Edit' : 'Create'}</h2>
          <button type="button" class="modal-close" id="capture-close" aria-label="Close">✕</button>
        </div>
        ${state.sourceType === 'inquiry' && state.sourceId ? `<div class="capture-linked-banner">📥 Linked to inquiry</div>` : ''}

        <div class="kind-toggle">
          <button type="button" class="kind-btn ${state.kind === 'reminder' ? 'active' : ''}" data-kind="reminder">🔔 Reminder</button>
          <button type="button" class="kind-btn ${state.kind === 'task' ? 'active' : ''}" data-kind="task">✅ Task</button>
        </div>

        <label class="form-label">What?</label>
        <input type="text" class="form-input capture-input-lg" id="capture-title" value="${esc(r.title)}" placeholder="Call John about the contract" autocomplete="off" />

        <label class="form-label">When?</label>
        <div class="chip-row" id="capture-when-chips">${whenChipHtml(state.when)}</div>
        <input type="date" class="form-input capture-custom-date" id="capture-date" value="${state.startDate}" style="display:${state.when === 'custom' ? 'block' : 'none'};margin-top:8px" />

        <label class="form-label">Time <span class="form-hint">(recommended for reminders)</span></label>
        <div class="chip-row" id="capture-time-chips">${timeChipHtml(state.timeMode)}</div>
        <input type="time" class="form-input capture-custom-time" id="capture-time" value="${state.time}" style="display:${state.timeMode === 'custom' ? 'block' : 'none'};margin-top:8px" />

        <label class="form-label">Workflow stage</label>
        <select class="form-select" id="capture-stage"></select>
        <p class="form-hint" style="margin-top:4px">Manage stages in sidebar → <strong>Workflow Stages</strong> (Tasks / Reminders tabs)</p>

        <button type="button" class="capture-more-toggle" id="capture-more-toggle">${state.moreOpen ? '▾ Less options' : '+ More options'}</button>
        <div class="capture-more" id="capture-more" style="display:${state.moreOpen ? 'block' : 'none'}">
          <div class="form-group"><label class="form-label">Why it matters</label>
            <input type="text" class="form-input" id="capture-why" value="${esc(state.why)}" placeholder="Optional context" /></div>
          <div class="form-grid">
            <div class="form-group"><label class="form-label">Category</label>
              <select class="form-select" id="capture-category">
                ${['general', 'medicine', 'bills', 'family', 'work', 'health', 'personal'].map((c) =>
                  `<option value="${c}" ${state.category === c ? 'selected' : ''}>${c}</option>`
                ).join('')}
              </select></div>
            <div class="form-group"><label class="form-label">Repeat</label>
              <select class="form-select" id="capture-repeat">
                <option value="once" ${state.repeat === 'once' ? 'selected' : ''}>One-time</option>
                <option value="daily" ${state.repeat === 'daily' ? 'selected' : ''}>Daily</option>
                <option value="weekly" ${state.repeat === 'weekly' ? 'selected' : ''}>Weekly</option>
                <option value="monthly" ${state.repeat === 'monthly' ? 'selected' : ''}>Monthly</option>
                <option value="custom" ${state.repeat === 'custom' ? 'selected' : ''}>Custom…</option>
              </select>
              ${customRepeatHtml(state.repeat, state.repeatValue)}
            </div>
          </div>
          <div class="form-group"><label class="form-label">Priority</label>
            <div class="chip-row" id="capture-priority-chips">
              ${['normal', 'important', 'critical'].map((p) =>
                `<button type="button" class="chip priority-${p} ${state.priority === p ? 'selected' : ''}" data-priority="${p}">${p === 'normal' ? '✅' : p === 'important' ? '⚠️' : '🚨'} ${p}</button>`
              ).join('')}
            </div>
          </div>
          <div class="form-group"><label class="form-label">Assign to</label>
            <select class="form-select" id="capture-assignee">
              <option value="me" ${state.assigned === 'me' ? 'selected' : ''}>Me</option>
              ${state.family.map((f) =>
                `<option value="${f.id}" ${state.assigned === f.id ? 'selected' : ''}>${esc(f.name)} (${f.role || 'family'})</option>`
              ).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">Notes</label>
            <textarea class="form-textarea" id="capture-notes" rows="2">${esc(state.notes)}</textarea></div>
          <div class="form-group"><label class="form-label">Tags</label>
            <input type="text" class="form-input" id="capture-tags" value="${esc(state.tags)}" placeholder="comma separated" /></div>
          <div class="form-group"><label class="form-label">Alert</label>
            <select class="form-select" id="capture-alert">
              <option value="sound-popup" ${state.alert === 'sound-popup' ? 'selected' : ''}>Sound + Popup</option>
              <option value="popup-only" ${state.alert === 'popup-only' ? 'selected' : ''}>Popup only</option>
              <option value="silent" ${state.alert === 'silent' ? 'selected' : ''}>Silent</option>
            </select></div>
          <div class="setting-row" style="padding:8px 0">
            <span class="form-label">Private notification</span>
            <div class="toggle ${state.private ? 'on' : ''}" id="capture-private-toggle"></div>
          </div>
        </div>

        <div class="capture-actions">
          <button type="button" class="btn btn-ghost" id="capture-cancel">Cancel</button>
          <button type="button" class="btn btn-primary capture-create-btn" id="capture-save">${isEdit ? 'Update' : 'Create'}</button>
        </div>
        <input type="hidden" id="capture-id" value="${state.id}" />
        <input type="hidden" id="capture-is-edit" value="${isEdit ? '1' : '0'}" />
        <input type="hidden" id="capture-when" value="${state.when}" />
        <input type="hidden" id="capture-priority" value="${state.priority}" />
        <input type="hidden" id="capture-private" value="${state.private ? '1' : '0'}" />
        <input type="hidden" id="capture-source-type" value="${esc(state.sourceType)}" />
        <input type="hidden" id="capture-source-id" value="${esc(state.sourceId)}" />
      </div>
    `;

    if (typeof attachModalDismiss === 'function') attachModalDismiss(overlay);
    document.body.appendChild(overlay);

    wireCaptureSheet(overlay, r, isEdit);
    setTimeout(() => document.getElementById('capture-title')?.focus(), 50);
  }

  function resolveWhenDate(whenKey) {
    const now = new Date();
    const cap = C();
    if (!cap) return typeof todayStr === 'function' ? todayStr() : '';
    switch (whenKey) {
      case 'today': return cap.dateStr(now);
      case 'tomorrow': return cap.dateStr(cap.addDays(now, 1));
      case 'evening': return cap.dateStr(now);
      case 'next-week': return cap.dateStr(cap.addDays(now, 7));
      case 'custom': return document.getElementById('capture-date')?.value || cap.dateStr(now);
      default: return cap.dateStr(now);
    }
  }

  function wireCaptureSheet(overlay, existing = {}, isEdit = false) {
    const close = () => overlay.remove();

    overlay.querySelector('#capture-close')?.addEventListener('click', close);
    overlay.querySelector('#capture-cancel')?.addEventListener('click', close);

    overlay.querySelectorAll('.kind-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        overlay.querySelectorAll('.kind-btn').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
      });
    });

    overlay.querySelector('#capture-more-toggle')?.addEventListener('click', () => {
      const more = document.getElementById('capture-more');
      const open = more.style.display !== 'block';
      more.style.display = open ? 'block' : 'none';
      document.getElementById('capture-more-toggle').textContent = open ? '▾ Less options' : '+ More options';
    });

    overlay.querySelector('#capture-when-chips')?.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-when]');
      if (!chip) return;
      const when = chip.dataset.when;
      document.getElementById('capture-when').value = when;
      overlay.querySelectorAll('#capture-when-chips .chip').forEach((c) => c.classList.toggle('selected', c.dataset.when === when));
      const dateEl = document.getElementById('capture-date');
      dateEl.style.display = when === 'custom' ? 'block' : 'none';
      if (when === 'evening') {
        document.getElementById('capture-time').value = '18:00';
        overlay.querySelectorAll('#capture-time-chips .chip').forEach((c) => {
          c.classList.toggle('selected', c.dataset.time === '18:00');
        });
      }
      if (when !== 'custom') dateEl.value = resolveWhenDate(when);
    });

    overlay.querySelector('#capture-time-chips')?.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-time]');
      if (!chip) return;
      const t = chip.dataset.time;
      overlay.querySelectorAll('#capture-time-chips .chip').forEach((c) => c.classList.toggle('selected', c.dataset.time === t));
      const timeEl = document.getElementById('capture-time');
      if (t === 'custom') {
        timeEl.style.display = 'block';
        timeEl.focus();
      } else {
        timeEl.value = t;
        timeEl.style.display = 'none';
      }
    });

    overlay.querySelector('#capture-repeat')?.addEventListener('change', (e) => {
      const custom = document.getElementById('capture-custom-repeat');
      if (custom) custom.style.display = e.target.value === 'custom' ? 'block' : 'none';
    });

    overlay.querySelector('#capture-custom-mode')?.addEventListener('change', (e) => {
      const mode = e.target.value;
      const daysWrap = document.getElementById('capture-custom-days-wrap');
      const weekdaysWrap = document.getElementById('capture-custom-weekdays-wrap');
      if (daysWrap) daysWrap.style.display = mode === 'days' ? 'block' : 'none';
      if (weekdaysWrap) weekdaysWrap.style.display = mode === 'weekdays' ? 'block' : 'none';
    });

    overlay.querySelector('#capture-weekday-chips')?.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-weekday]');
      if (!chip) return;
      chip.classList.toggle('selected');
    });

    overlay.querySelector('#capture-priority-chips')?.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-priority]');
      if (!chip) return;
      document.getElementById('capture-priority').value = chip.dataset.priority;
      overlay.querySelectorAll('#capture-priority-chips .chip').forEach((c) =>
        c.classList.toggle('selected', c.dataset.priority === chip.dataset.priority)
      );
    });

    overlay.querySelector('#capture-private-toggle')?.addEventListener('click', function () {
      this.classList.toggle('on');
      document.getElementById('capture-private').value = this.classList.contains('on') ? '1' : '0';
    });

    overlay.querySelector('#capture-save')?.addEventListener('click', () => saveCaptureSheet(close));

    const refreshStageOptions = async () => {
      const kind = overlay.querySelector('.kind-btn.active')?.dataset.kind || 'reminder';
      const entityType = kind === 'task' ? 'task' : 'reminder';
      let stages = window.ILRSWorkflowPipeline?.getStages?.(entityType) || [];
      if (!stages.length && window.ilrs?.getWorkflowStages) {
        const result = await window.ilrs.getWorkflowStages(entityType);
        if (result?.success && result.stages?.length) {
          stages = result.stages;
          window.ILRSWorkflowPipeline?.setStages?.(entityType, stages);
        }
      }
      const sel = overlay.querySelector('#capture-stage');
      if (!sel) return;
      const defaultKey = entityType === 'task' ? 'new' : 'scheduled';
      const current = existing?.stage_key || defaultKey;
      if (!stages.length) {
        sel.innerHTML = `<option value="${current}">${current}</option>`;
        return;
      }
      const hasCurrent = stages.some((s) => s.key === current);
      sel.innerHTML = stages.map((s) =>
        `<option value="${s.key}" ${s.key === current ? 'selected' : ''}>${s.display}</option>`
      ).join('');
      if (!hasCurrent && current) {
        sel.innerHTML += `<option value="${current}" selected>${current} (current)</option>`;
      }
    };
    overlay.querySelectorAll('.kind-btn').forEach((btn) => {
      btn.addEventListener('click', () => { refreshStageOptions(); });
    });
    refreshStageOptions();

    overlay.querySelector('.capture-sheet')?.addEventListener('click', (e) => e.stopPropagation());
  }

  async function saveCaptureSheet(onClose) {
    const title = document.getElementById('capture-title')?.value.trim();
    if (!title) {
      if (typeof toast === 'function') toast('Please enter what to remember', 'warning');
      return;
    }

    const kind = document.querySelector('.kind-btn.active')?.dataset.kind || 'reminder';
    const when = document.getElementById('capture-when')?.value || 'today';
    const startDate = when === 'custom'
      ? (document.getElementById('capture-date')?.value || resolveWhenDate(when))
      : resolveWhenDate(when);
    const time = document.getElementById('capture-time')?.value || (when === 'evening' ? '18:00' : '');
    const repeatType = document.getElementById('capture-repeat')?.value || 'once';
    let repeatValue = '';
    if (repeatType === 'custom') {
      const mode = document.getElementById('capture-custom-mode')?.value || 'days';
      if (mode === 'weekdays') {
        const days = [...document.querySelectorAll('#capture-weekday-chips .chip.selected')]
          .map((c) => parseInt(c.dataset.weekday, 10))
          .filter((d) => !Number.isNaN(d));
        if (!days.length) {
          if (typeof toast === 'function') toast('Pick at least one weekday', 'warning');
          return;
        }
        repeatValue = JSON.stringify({ mode: 'weekdays', days });
      } else {
        const interval = Math.min(365, Math.max(2, parseInt(document.getElementById('capture-custom-interval')?.value, 10) || 3));
        repeatValue = JSON.stringify({ mode: 'days', interval });
      }
    }
    const id = document.getElementById('capture-id')?.value;
    const isEdit = document.getElementById('capture-is-edit')?.value === '1';

    let nextFire;
    if (typeof computeNextFireForSave === 'function') {
      nextFire = await computeNextFireForSave(startDate, time || '09:00', repeatType, repeatValue);
    } else {
      nextFire = C().parseNextFireLocal(startDate, time || '09:00', repeatType);
    }

    const tags = (document.getElementById('capture-tags')?.value || '').split(',').map((t) => t.trim()).filter(Boolean);
    const params = [
      id,
      title,
      kind,
      document.getElementById('capture-category')?.value || 'general',
      document.getElementById('capture-why')?.value || '',
      repeatType,
      repeatValue,
      time,
      startDate,
      '',
      document.getElementById('capture-priority')?.value || 'normal',
      'important-not-urgent',
      document.getElementById('capture-alert')?.value || 'sound-popup',
      parseInt(App?.settings?.snooze_duration) || 10,
      document.getElementById('capture-assignee')?.value || 'me',
      parseInt(document.getElementById('capture-private')?.value) || 0,
      document.getElementById('capture-notes')?.value || '',
      JSON.stringify(tags),
      nextFire,
    ];

    let ok;
    const stageKey = document.getElementById('capture-stage')?.value || '';
    if (isEdit) {
      const existing = App?.reminders?.find((x) => x.id === id);
      const wasCompleted = existing
        && (existing.status === 'completed' || (existing.workflow_status || '') === 'done');
      const reactivateSql = wasCompleted
        ? ", status='active', workflow_status='pending', last_completed=NULL, snooze_count=0, alarm_rings=0"
        : '';
      ok = await dbRun(
        `UPDATE reminders SET title=?,task_type=?,category=?,why_it_matters=?,repeat_type=?,repeat_value=?,reminder_time=?,start_date=?,end_date=?,priority=?,urgency_quadrant=?,alert_style=?,snooze_duration=?,assigned_to=?,is_private=?,notes=?,tags=?,next_fire=?,stage_key=?,updated_at=?${reactivateSql} WHERE id=?`,
        [...params.slice(1), stageKey, new Date().toISOString(), id]
      );
      if (ok && stageKey && existing && stageKey !== (existing.stage_key || '')) {
        await window.ilrs?.changeReminderStage?.(id, stageKey, {});
      }
    } else {
      const workflowStatus = kind === 'task' ? 'pending' : 'pending';
      const sourceType = document.getElementById('capture-source-type')?.value || '';
      const sourceId = document.getElementById('capture-source-id')?.value || '';
      ok = await dbRun(
        `INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,repeat_value,reminder_time,start_date,end_date,priority,urgency_quadrant,alert_style,snooze_duration,assigned_to,is_private,notes,tags,next_fire,status,workflow_status,source_type,source_id,stage_key,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?,?,?,?,datetime('now'),datetime('now'))`,
        [...params, workflowStatus, sourceType, sourceId, stageKey]
      );
      if (ok && stageKey) {
        await window.ilrs?.setInitialReminderStage?.(id, kind, stageKey);
      } else if (ok) {
        await window.ilrs?.setInitialReminderStage?.(id, kind);
      }
    }

    if (!ok) return;

    const savedId = id;
    const verify = await db(
      'SELECT id FROM reminders WHERE id = ? AND status != ?',
      [savedId, 'deleted']
    );
    if (!verify?.length) {
      if (typeof toast === 'function') toast('Save failed: item was not found after saving. Please try again.', 'critical');
      return;
    }

    const savedKind = document.querySelector('.kind-btn.active')?.dataset.kind || 'reminder';
    const existing = isEdit ? App?.reminders?.find((x) => x.id === savedId) : null;
    const wasCompleted = existing
      && (existing.status === 'completed' || (existing.workflow_status || '') === 'done');
    if (typeof toast === 'function') {
      toast(wasCompleted ? 'Rescheduled — item is active again' : (isEdit ? 'Updated!' : 'Created!'));
    }
    onClose();
    if (typeof loadAllData === 'function') await loadAllData();
    if (typeof updateBadges === 'function') updateBadges();
    if (wasCompleted && App?.currentPage === 'completed' && typeof navigate === 'function') {
      navigate(savedKind === 'task' ? 'tasks' : 'reminders');
    } else if (typeof refreshCurrentView === 'function') {
      refreshCurrentView();
    } else if (typeof navigate === 'function') {
      navigate(savedKind === 'task' ? 'tasks' : (App?.currentPage || 'today'));
    }
  }

  window.showCaptureSheet = showCaptureSheet;
})();
