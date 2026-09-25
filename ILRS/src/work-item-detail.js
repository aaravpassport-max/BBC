// ILRS — Tabbed detail workspace for tasks & reminders (parity with enquiries)
(function () {
  function setWorkItemDetailTab(id, tab) {
    App.selectedWorkItemId = id;
    App.workItemDetailTab = tab;
    navigate('work-item-detail');
  }

  function workItemDetailTabsHtml(id, activeTab) {
    const tabs = [
      ['overview', 'Overview'],
      ['schedule', 'Schedule'],
      ['history', 'History'],
      ['notes', 'Notes'],
    ];
    return `<div class="detail-tabs" role="tablist">
      ${tabs.map(([tid, label]) =>
        `<button type="button" class="detail-tab ${activeTab === tid ? 'active' : ''}"
          onclick="ILRSWorkItemDetail.setTab('${id}','${tid}')">${label}</button>`,
      ).join('')}
    </div>`;
  }

  async function renderWorkItemDetail(el) {
    const id = App.selectedWorkItemId;
    const r = (App.reminders || []).find((x) => x.id === id);
    if (!r) {
      el.innerHTML = '<div class="empty-state"><h3>Item not found</h3></div>';
      return;
    }
    const tab = App.workItemDetailTab || 'overview';
    const isTask = r.task_type === 'task';
    const kind = isTask ? 'Task' : 'Reminder';
    const logs = await db(
      'SELECT * FROM reminder_logs WHERE reminder_id = ? ORDER BY timestamp DESC LIMIT 80',
      [id],
    ) || [];
    const LC = window.ILRSWorkLifecycle;
    const lc = LC?.inferLifecycleFromReminder ? LC.inferLifecycleFromReminder(r) : 'active';
    const linkedInq = r.source_type === 'inquiry' && r.source_id
      ? (App.inquiries || []).find((i) => i.id === r.source_id)
      : null;

    let tabBody = '';
    if (tab === 'overview') {
      tabBody = `
        <div class="card" style="padding:16px">
          <div class="form-grid">
            <div><span class="form-label">Type</span><div>${kind}</div></div>
            <div><span class="form-label">Work status</span><div>${LC?.lifecycleLabel?.(lc) || lc}</div></div>
            <div><span class="form-label">Category</span><div>${r.category || '—'}</div></div>
            <div><span class="form-label">Assignee</span><div>${typeof assigneeLabel === 'function' ? assigneeLabel(r.assigned_to) : r.assigned_to}</div></div>
            <div><span class="form-label">Next fire</span><div>${r.next_fire ? formatDate(r.next_fire.slice(0, 10)) + (r.reminder_time ? ' · ' + formatTime(r.reminder_time) : '') : '—'}</div></div>
          </div>
          ${typeof reminderCardWorkStatus === 'function' ? `<div style="margin-top:12px">${reminderCardWorkStatus(r)}</div>` : ''}
          ${linkedInq ? `<div style="margin-top:12px"><button class="btn btn-ghost btn-sm" onclick="openInquiryDetail('${linkedInq.id}')">Open enquiry: ${linkedInq.client_name}</button></div>` : ''}
        </div>
        <div class="inquiry-detail-actions" style="margin-top:12px">
          ${isTask && r.workflow_status !== 'done' ? `<button class="btn btn-primary btn-sm" onclick="completeReminder('${id}')">Mark complete</button>` : ''}
          <button class="btn btn-ghost btn-sm" onclick="editReminder('${id}')">Edit</button>
          <button class="btn btn-ghost btn-sm" onclick="showPostponeMenu('${id}')">Reschedule</button>
        </div>`;
    } else if (tab === 'schedule') {
      tabBody = `
        <div class="card" style="padding:16px">
          ${window.ILRSWorkScheduling?.scheduleDatesHtml ? window.ILRSWorkScheduling.scheduleDatesHtml(r) : '<p class="activity-empty">No schedule fields.</p>'}
          <p class="form-hint" style="margin-top:8px">Repeat: ${r.repeat_type || 'once'} · Priority: ${r.priority || 'normal'}</p>
        </div>`;
    } else if (tab === 'history') {
      tabBody = `
        <div class="activity-timeline">
          ${logs.length === 0 ? '<p class="activity-empty">No history yet.</p>' : logs.map((log) => `
            <article class="activity-item">
              <div class="activity-marker">•</div>
              <div class="activity-content">
                <div class="activity-time">${log.timestamp || ''}</div>
                <div class="activity-title">${log.action || 'event'}</div>
              </div>
            </article>`).join('')}
        </div>`;
    } else if (tab === 'notes') {
      const safe = (r.notes || '').replace(/</g, '&lt;');
      const why = (r.why_it_matters || '').replace(/</g, '&lt;');
      tabBody = `
        <label class="form-label">Why it matters</label>
        <p class="form-hint">${why || '—'}</p>
        <label class="form-label">Notes</label>
        <textarea id="work-item-notes" class="form-input" rows="6">${safe}</textarea>
        <button class="btn btn-primary" style="margin-top:12px" onclick="ILRSWorkItemDetail.saveNotes('${id}')">Save notes</button>`;
    }

    el.innerHTML = `
      <div class="page-header inquiry-detail-header">
        <div>
          <button class="btn btn-ghost btn-sm" onclick="navigate('${isTask ? 'tasks' : 'reminders'}')">← Back</button>
          <div class="page-title" style="margin-top:8px">${r.title}</div>
          <div class="page-subtitle">${kind} · ${LC?.lifecycleLabel?.(lc) || lc}</div>
        </div>
      </div>
      ${workItemDetailTabsHtml(id, tab)}
      <div class="inquiry-detail-panel">${tabBody}</div>`;
  }

  async function saveNotes(id) {
    const notes = document.getElementById('work-item-notes')?.value ?? '';
    await db('UPDATE reminders SET notes = ?, updated_at = datetime(\'now\') WHERE id = ?', [notes, id]);
    toast('Notes saved');
    await loadAllData();
    navigate('work-item-detail');
  }

  function openWorkItemDetail(id) {
    App.selectedWorkItemId = id;
    App.workItemDetailTab = 'overview';
    navigate('work-item-detail');
  }

  window.ILRSWorkItemDetail = { setTab: setWorkItemDetailTab, saveNotes, openWorkItemDetail };
  window.renderWorkItemDetail = renderWorkItemDetail;
  window.openWorkItemDetail = openWorkItemDetail;
})();
