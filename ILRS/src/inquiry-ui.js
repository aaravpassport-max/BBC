// ILRS — Inquiry capture UI
(function () {
  const P = () => window.ILRSInquiryPipeline;
  const esc = (s) => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');

  function showQuickAddMenu() {
    if (typeof dismissPageModals === 'function') dismissPageModals();
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'quick-add-menu';
    overlay.innerHTML = `
      <div class="capture-sheet" style="max-width:360px">
        <div class="capture-header"><h2>＋ Add</h2>
          <button class="modal-close" onclick="document.getElementById('quick-add-menu').remove()">✕</button>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px">
          <button class="btn btn-primary" style="justify-content:flex-start;padding:14px 16px" onclick="document.getElementById('quick-add-menu').remove();showCaptureSheet()">🔔 Reminder</button>
          <button class="btn btn-ghost" style="justify-content:flex-start;padding:14px 16px" onclick="document.getElementById('quick-add-menu').remove();showCaptureSheet({ task_type: 'task', id: '' })">✅ Task</button>
          <button class="btn btn-ghost" style="justify-content:flex-start;padding:14px 16px;border-color:var(--accent)" onclick="document.getElementById('quick-add-menu').remove();showInquirySheet()">📥 Inquiry</button>
        </div>
      </div>`;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
  }

  function showInquirySheet(existing = null) {
    if (typeof dismissPageModals === 'function') dismissPageModals();
    const pipeline = P();
    const isEdit = !!existing?.id;
    const inq = existing || {};
    const activeStages = pipeline?.getActiveStages() || [];
    const closedStages = pipeline?.getClosedStages() || [];
    const stages = isEdit
      ? [...activeStages, ...closedStages.filter((s) => !activeStages.find((a) => a.key === s.key))]
      : activeStages;
    const sources = pipeline?.SOURCE_CHIPS || [];
    const cap = window.ILRSCapture;
    const whenInfo = isEdit && inq.next_follow_up && cap?.resolveWhenFromExisting
      ? cap.resolveWhenFromExisting({ start_date: inq.next_follow_up })
      : { when: 'tomorrow', startDate: '' };

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay capture-overlay';
    overlay.id = 'inquiry-sheet';
    overlay.innerHTML = `
      <div class="capture-sheet" role="dialog">
        <div class="capture-header">
          <h2>${isEdit ? 'Edit' : 'New'} Inquiry</h2>
          <button type="button" class="modal-close" id="inq-close">✕</button>
        </div>
        ${!isEdit && (App.inquiryTemplates || []).length ? `
        <label class="form-label">Start from template</label>
        <div class="chip-row" id="inq-template-chips" style="margin-bottom:12px">
          ${(App.inquiryTemplates || []).map((t) =>
            `<button type="button" class="chip" data-template="${t.id}">${t.name}</button>`
          ).join('')}
        </div>` : ''}
        <label class="form-label">Client / Contact *</label>
        <input type="text" class="form-input capture-input-lg" id="inq-client" value="${esc(inq.client_name)}" placeholder="Raj Kumar" autocomplete="off" />

        <label class="form-label">What do they need? *</label>
        <input type="text" class="form-input" id="inq-requirement" value="${esc(inq.requirement)}" placeholder="Birth Certificate Retrieval" />

        <label class="form-label">Stage</label>
        <select class="form-select" id="inq-stage">
          ${stages.map((s) => `<option value="${s.key}" ${inq.stage_key === s.key ? 'selected' : ''}>${esc(s.display)}</option>`).join('')}
        </select>

        <label class="form-label">Next action</label>
        <input type="text" class="form-input" id="inq-next-action" value="${esc(inq.next_action || 'Follow up')}" placeholder="Call client" />

        <label class="form-label">Next follow-up</label>
        <div class="chip-row" id="inq-when-chips">
          ${['today', 'tomorrow', 'next-week', 'custom'].map((w) =>
            `<button type="button" class="chip ${whenInfo.when === w ? 'selected' : ''}" data-when="${w}">${w === 'next-week' ? 'Next week' : w === 'custom' ? 'Pick date…' : w.charAt(0).toUpperCase() + w.slice(1)}</button>`
          ).join('')}
        </div>
        <input type="date" class="form-input" id="inq-follow-date" value="${whenInfo.startDate || ''}" style="display:${whenInfo.when === 'custom' ? 'block' : 'none'};margin-top:8px" />
        <input type="time" class="form-input" id="inq-follow-time" value="${inq.next_follow_up_time || '11:00'}" style="margin-top:8px" />

        <div class="form-grid" style="margin-top:12px">
          <div class="form-group">
            <label class="form-label">Work starting date</label>
            <input type="date" class="form-input" id="inq-work-start" value="${inq.work_start_date || (typeof todayStr === 'function' ? todayStr() : '')}" />
          </div>
          <div class="form-group">
            <label class="form-label">Expected completion date</label>
            <input type="date" class="form-input" id="inq-completion-date" value="${inq.expected_completion_date || ''}" />
          </div>
        </div>

        <button type="button" class="capture-more-toggle" id="inq-more-toggle">+ More Details</button>
        <div class="capture-more" id="inq-more" style="display:none">
          <div class="form-grid">
            <div class="form-group"><label class="form-label">Mobile</label><input type="tel" class="form-input" id="inq-mobile" value="${esc(inq.mobile)}" /></div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" class="form-input" id="inq-email" value="${esc(inq.email)}" /></div>
            <div class="form-group full"><label class="form-label">Company</label><input type="text" class="form-input" id="inq-company" value="${esc(inq.company)}" /></div>
            <div class="form-group"><label class="form-label">Service category</label><input type="text" class="form-input" id="inq-category" value="${esc(inq.service_category)}" /></div>
            <div class="form-group"><label class="form-label">Expected value (₹)</label><input type="number" class="form-input" id="inq-value" value="${inq.expected_value || ''}" min="0" /></div>
          </div>
          <label class="form-label">Source</label>
          <div class="chip-row" id="inq-source-chips">
            ${sources.map((s) => `<button type="button" class="chip ${inq.source === s ? 'selected' : ''}" data-source="${s}">${s}</button>`).join('')}
          </div>
          <div class="form-group" style="margin-top:12px"><label class="form-label">Notes</label>
            <textarea class="form-textarea" id="inq-notes" rows="2">${esc(inq.notes)}</textarea></div>
        </div>

        <div class="capture-actions">
          <button type="button" class="btn btn-ghost" id="inq-cancel">Cancel</button>
          <button type="button" class="btn btn-primary capture-create-btn" id="inq-save">${isEdit ? 'Save Changes' : 'Create Inquiry'}</button>
        </div>
        <input type="hidden" id="inq-when" value="${whenInfo.when}" />
        <input type="hidden" id="inq-source" value="${esc(inq.source || '')}" />
      </div>`;

    if (typeof attachModalDismiss === 'function') attachModalDismiss(overlay);
    document.body.appendChild(overlay);

    const resolveWhen = (when) => {
      const now = new Date();
      if (!cap) return typeof todayStr === 'function' ? todayStr() : '';
      switch (when) {
        case 'today': return cap.dateStr(now);
        case 'tomorrow': return cap.dateStr(cap.addDays(now, 1));
        case 'next-week': return cap.dateStr(cap.addDays(now, 7));
        case 'custom': return document.getElementById('inq-follow-date')?.value || cap.dateStr(now);
        default: return cap.dateStr(cap.addDays(now, 1));
      }
    };

    overlay.querySelector('#inq-template-chips')?.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-template]');
      if (!chip) return;
      const t = (App.inquiryTemplates || []).find((x) => x.id === chip.dataset.template);
      if (!t) return;
      document.getElementById('inq-requirement').value = t.requirement || '';
      document.getElementById('inq-stage').value = t.stage_key || 'follow_up';
      document.getElementById('inq-next-action').value = t.next_action || 'Follow up';
      if (t.source) {
        document.getElementById('inq-source').value = t.source;
        overlay.querySelectorAll('#inq-source-chips .chip').forEach((c) =>
          c.classList.toggle('selected', c.dataset.source === t.source));
      }
      if (t.expected_value) document.getElementById('inq-value').value = t.expected_value;
      overlay.querySelectorAll('#inq-template-chips .chip').forEach((c) =>
        c.classList.toggle('selected', c === chip));
      const more = document.getElementById('inq-more');
      if (more && t.notes) {
        more.style.display = 'block';
        document.getElementById('inq-notes').value = t.notes;
      }
    });

    overlay.querySelector('#inq-close')?.addEventListener('click', () => overlay.remove());
    overlay.querySelector('#inq-cancel')?.addEventListener('click', () => overlay.remove());
    overlay.querySelector('#inq-more-toggle')?.addEventListener('click', () => {
      const more = document.getElementById('inq-more');
      const open = more.style.display !== 'block';
      more.style.display = open ? 'block' : 'none';
      document.getElementById('inq-more-toggle').textContent = open ? '▾ Less Details' : '+ More Details';
    });

    overlay.querySelector('#inq-when-chips')?.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-when]');
      if (!chip) return;
      document.getElementById('inq-when').value = chip.dataset.when;
      overlay.querySelectorAll('#inq-when-chips .chip').forEach((c) => c.classList.toggle('selected', c === chip));
      const dateEl = document.getElementById('inq-follow-date');
      dateEl.style.display = chip.dataset.when === 'custom' ? 'block' : 'none';
      if (chip.dataset.when !== 'custom') dateEl.value = resolveWhen(chip.dataset.when);
    });

    overlay.querySelector('#inq-source-chips')?.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-source]');
      if (!chip) return;
      document.getElementById('inq-source').value = chip.dataset.source;
      overlay.querySelectorAll('#inq-source-chips .chip').forEach((c) => c.classList.toggle('selected', c === chip));
    });

    if (!isEdit) {
      const tomorrowChip = overlay.querySelector('[data-when="tomorrow"]');
      if (tomorrowChip) {
        tomorrowChip.classList.add('selected');
        document.getElementById('inq-follow-date').value = resolveWhen('tomorrow');
      }
    } else if (whenInfo.when !== 'custom') {
      document.getElementById('inq-follow-date').value = whenInfo.startDate || resolveWhen(whenInfo.when);
    }

    overlay.querySelector('#inq-save')?.addEventListener('click', async () => {
      const clientName = document.getElementById('inq-client')?.value.trim();
      const requirement = document.getElementById('inq-requirement')?.value.trim();
      if (!clientName || !requirement) {
        if (typeof toast === 'function') toast('Client and requirement are required', 'warning');
        return;
      }
      const when = document.getElementById('inq-when')?.value || 'tomorrow';
      const nextFollowUp = when === 'custom'
        ? (document.getElementById('inq-follow-date')?.value || resolveWhen(when))
        : resolveWhen(when);
      const data = {
        clientName,
        requirement,
        stageKey: document.getElementById('inq-stage')?.value,
        nextAction: document.getElementById('inq-next-action')?.value.trim(),
        nextFollowUp,
        nextFollowUpTime: document.getElementById('inq-follow-time')?.value || '11:00',
        mobile: document.getElementById('inq-mobile')?.value.trim(),
        email: document.getElementById('inq-email')?.value.trim(),
        company: document.getElementById('inq-company')?.value.trim(),
        serviceCategory: document.getElementById('inq-category')?.value.trim(),
        source: document.getElementById('inq-source')?.value,
        expectedValue: document.getElementById('inq-value')?.value,
        workStartDate: document.getElementById('inq-work-start')?.value || '',
        expectedCompletionDate: document.getElementById('inq-completion-date')?.value || '',
        notes: document.getElementById('inq-notes')?.value.trim(),
      };

      if (window.ilrs?.findInquiryDuplicates && !isEdit) {
        const dup = await window.ilrs.findInquiryDuplicates(data);
        if (dup?.matches?.length) {
          const proceed = await showDuplicateConfirm(dup.matches);
          if (!proceed) return;
        }
      }

      const result = isEdit
        ? await window.ilrs?.updateInquiry?.(inq.id, data)
        : await window.ilrs?.createInquiry?.(data);
      if (!result?.success) {
        if (typeof toast === 'function') toast(result?.error || `Could not ${isEdit ? 'update' : 'create'} inquiry`, 'warning');
        return;
      }
      if (!result.inquiry?.id) {
        if (typeof toast === 'function') toast('Save failed: inquiry was not created.', 'critical');
        return;
      }
      if (typeof loadAllData === 'function') await loadAllData();
      const verified = (App.inquiries || []).find((i) => i.id === result.inquiry.id);
      if (!verified) {
        if (typeof toast === 'function') toast('Save failed: inquiry not found after save.', 'critical');
        return;
      }
      overlay.remove();
      if (typeof toast === 'function') toast(isEdit ? '📥 Inquiry updated' : `📥 Inquiry ${result.inquiry.inquiry_number} created`);
      if (typeof navigate === 'function') {
        App.selectedInquiryId = result.inquiry.id;
        navigate('inquiry-detail');
      }
    });

    setTimeout(() => document.getElementById('inq-client')?.focus(), 50);
  }

  function showDuplicateConfirm(matches) {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay';
      overlay.id = 'inq-dup-modal';
      overlay.innerHTML = `
        <div class="capture-sheet" style="max-width:420px">
          <div class="capture-header"><h2>Possible duplicate</h2>
            <button class="modal-close" onclick="document.getElementById('inq-dup-modal').remove();window.__inqDupResolve(false)">✕</button>
          </div>
          <p style="font-size:13px;color:var(--text-secondary);margin:0 0 12px">An active inquiry may already exist for this contact:</p>
          <div class="inquiry-list" style="margin-bottom:16px">
            ${matches.map((m) => `
              <div class="inquiry-card" style="cursor:pointer" onclick="App.selectedInquiryId='${m.id}';document.getElementById('inq-dup-modal').remove();window.__inqDupResolve(false);navigate('inquiry-detail')">
                <strong>${m.client_name}</strong> · ${m.inquiry_number || ''}<br>
                <span style="font-size:12px;color:var(--text-muted)">${m.requirement || ''}</span>
              </div>`).join('')}
          </div>
          <div class="capture-actions">
            <button class="btn btn-ghost" onclick="document.getElementById('inq-dup-modal').remove();window.__inqDupResolve(false)">Cancel</button>
            <button class="btn btn-primary" onclick="document.getElementById('inq-dup-modal').remove();window.__inqDupResolve(true)">Create new anyway</button>
          </div>
        </div>`;
      window.__inqDupResolve = resolve;
      overlay.addEventListener('click', (e) => { if (e.target === overlay) { overlay.remove(); resolve(false); } });
      document.body.appendChild(overlay);
    });
  }

  window.showQuickAddMenu = showQuickAddMenu;
  window.showInquirySheet = showInquirySheet;
})();
