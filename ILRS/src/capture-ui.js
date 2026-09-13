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

  function timeChipHtml(selected) {
    const chips = ['09:00', '10:00', '14:00', '18:00'];
    return chips.map((t) =>
      `<button type="button" class="chip ${selected === t ? 'selected' : ''}" data-time="${t}">${window.formatTime ? formatTime(t) : t}</button>`
    ).join('') + `<button type="button" class="chip ${selected === 'custom' ? 'selected' : ''}" data-time="custom">Custom</button>`;
  }

  function showCaptureSheet(existing = null) {
    if (typeof dismissPageModals === 'function') dismissPageModals();
    document.getElementById('capture-sheet')?.remove();

    const isEdit = !!existing;
    const r = existing || {};
    const state = {
      id: r.id || (typeof uuid === 'function' ? uuid() : ''),
      kind: r.task_type === 'task' ? 'task' : 'reminder',
      when: 'today',
      startDate: r.start_date || (typeof todayStr === 'function' ? todayStr() : ''),
      time: r.reminder_time || '',
      timeMode: r.reminder_time ? (['09:00', '10:00', '14:00', '18:00'].includes(r.reminder_time) ? r.reminder_time : 'custom') : '',
      category: r.category || 'general',
      priority: r.priority || 'normal',
      repeat: r.repeat_type || 'once',
      why: r.why_it_matters || '',
      notes: r.notes || '',
      tags: JSON.parse(r.tags || '[]').join(', '),
      assigned: r.assigned_to || 'me',
      family: (typeof App !== 'undefined' && App.family) ? App.family : [],
      alert: r.alert_style || 'sound-popup',
      private: Number(r.is_private) === 1,
      endDate: r.end_date || '',
      moreOpen: isEdit,
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

        <div class="kind-toggle">
          <button type="button" class="kind-btn ${state.kind === 'reminder' ? 'active' : ''}" data-kind="reminder">🔔 Reminder</button>
          <button type="button" class="kind-btn ${state.kind === 'task' ? 'active' : ''}" data-kind="task">✅ Task</button>
        </div>

        <label class="form-label">What?</label>
        <input type="text" class="form-input capture-input-lg" id="capture-title" value="${esc(r.title)}" placeholder="Call John about the contract" autocomplete="off" />

        <label class="form-label">When?</label>
        <div class="chip-row" id="capture-when-chips">${whenChipHtml(state.when)}</div>
        <input type="date" class="form-input capture-custom-date" id="capture-date" value="${state.startDate}" style="display:none;margin-top:8px" />

        <label class="form-label">Time <span class="form-hint">(recommended for reminders)</span></label>
        <div class="chip-row" id="capture-time-chips">${timeChipHtml(state.timeMode)}</div>
        <input type="time" class="form-input capture-custom-time" id="capture-time" value="${state.time}" style="display:${state.timeMode === 'custom' ? 'block' : 'none'};margin-top:8px" />

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
              </select></div>
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
      </div>
    `;

    if (typeof attachModalDismiss === 'function') attachModalDismiss(overlay);
    document.body.appendChild(overlay);

    wireCaptureSheet(overlay);
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

  function wireCaptureSheet(overlay) {
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
    const startDate = resolveWhenDate(when);
    const time = document.getElementById('capture-time')?.value || (when === 'evening' ? '18:00' : '');
    const repeatType = document.getElementById('capture-repeat')?.value || 'once';
    const id = document.getElementById('capture-id')?.value;
    const isEdit = document.getElementById('capture-is-edit')?.value === '1';

    let nextFire;
    if (typeof computeNextFireForSave === 'function') {
      nextFire = await computeNextFireForSave(startDate, time || '09:00', repeatType);
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
    if (isEdit) {
      ok = await dbRun(
        `UPDATE reminders SET title=?,task_type=?,category=?,why_it_matters=?,repeat_type=?,reminder_time=?,start_date=?,end_date=?,priority=?,urgency_quadrant=?,alert_style=?,snooze_duration=?,assigned_to=?,is_private=?,notes=?,tags=?,next_fire=?,updated_at=? WHERE id=?`,
        [...params.slice(1), new Date().toISOString(), id]
      );
    } else {
      const workflowStatus = kind === 'task' ? 'pending' : 'pending';
      ok = await dbRun(
        `INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,end_date,priority,urgency_quadrant,alert_style,snooze_duration,assigned_to,is_private,notes,tags,next_fire,status,workflow_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?,datetime('now'),datetime('now'))`,
        [...params, workflowStatus]
      );
    }

    if (!ok) return;
    if (typeof toast === 'function') toast(isEdit ? 'Updated!' : 'Created!');
    onClose();
    if (typeof loadAllData === 'function') await loadAllData();
    if (typeof updateBadges === 'function') updateBadges();
    const page = App?.currentPage || 'today';
    if (typeof navigate === 'function' && PAGES?.[page]) navigate(page);
  }

  window.showCaptureSheet = showCaptureSheet;
})();
