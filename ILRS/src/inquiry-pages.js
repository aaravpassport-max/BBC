// ILRS — Inquiry pipeline, list, detail pages
(function () {
  const P = () => window.ILRSInquiryPipeline;

  function stageDisplay(key) {
    return P()?.getStage(key)?.display || key;
  }

  function inquiryCard(inq, compact = false) {
    const pipeline = P();
    const health = pipeline?.healthClass(inq.health) || '';
    const followLabel = inq.next_follow_up
      ? `${formatDate(inq.next_follow_up)}${inq.next_follow_up_time ? ' · ' + formatTime(inq.next_follow_up_time) : ''}`
      : 'No follow-up set';
    const assignee = typeof assigneeLabel === 'function' ? assigneeLabel(inq.assigned_to) : '';
    return `
      <div class="inquiry-card ${health}" onclick="openInquiryDetail('${inq.id}')">
        <div class="inquiry-card-top">
          <strong>${inq.client_name}</strong>
          <span class="inquiry-number">${inq.inquiry_number || ''}</span>
        </div>
        <div class="inquiry-requirement">${inq.requirement}</div>
        <div class="inquiry-stage-badge">${stageDisplay(inq.stage_key)}</div>
        ${inq.quotation_amount > 0 ? `<div class="inquiry-amount">₹${Number(inq.quotation_amount).toLocaleString('en-IN')}</div>` : ''}
        <div class="inquiry-next-action">
          <span class="next-action-label">Next:</span> ${inq.next_action || 'Follow up'}
          <div class="next-action-when">${followLabel}</div>
        </div>
        ${!compact && assignee ? `<div class="inquiry-meta">Assigned: ${assignee}</div>` : ''}
        <div class="inquiry-health-pill ${health}">${pipeline?.healthLabel(inq.health) || ''}</div>
      </div>`;
  }

  function activeInquiries() {
    return (App.inquiries || []).filter((i) => i.outcome_status === 'active');
  }

  function daysInStage(inq) {
    if (!inq.stage_changed_at) return '—';
    const changed = new Date(String(inq.stage_changed_at).replace(' ', 'T'));
    if (Number.isNaN(changed.getTime())) return '—';
    const days = Math.floor((Date.now() - changed.getTime()) / 86400000);
    return days <= 0 ? 'Today' : `${days}d`;
  }

  function followUpCell(inq) {
    if (!inq.next_follow_up) return '<span class="pipeline-muted">—</span>';
    const today = typeof todayStr === 'function' ? todayStr() : '';
    const overdue = inq.next_follow_up < today;
    const dueToday = inq.next_follow_up === today;
    const cls = overdue ? 'pipeline-overdue' : dueToday ? 'pipeline-today' : '';
    const time = inq.next_follow_up_time ? ` · ${formatTime(inq.next_follow_up_time)}` : '';
    return `<span class="${cls}">${formatDate(inq.next_follow_up)}${time}</span>`;
  }

  function pipelineTableRow(inq) {
    const pipeline = P();
    const stage = pipeline?.getStage(inq.stage_key);
    const categories = pipeline?.STAGE_CATEGORIES || {};
    const health = pipeline?.healthClass(inq.health) || '';
    const assignee = typeof assigneeLabel === 'function' ? assigneeLabel(inq.assigned_to) : '';
    const amount = inq.quotation_amount > 0
      ? `₹${Number(inq.quotation_amount).toLocaleString('en-IN')}`
      : (inq.expected_value > 0 ? `~₹${Number(inq.expected_value).toLocaleString('en-IN')}` : '—');
    return `
      <tr class="pipeline-row ${health}" onclick="openInquiryDetail('${inq.id}')">
        <td class="pipeline-id">${inq.inquiry_number || '—'}</td>
        <td class="pipeline-client"><strong>${inq.client_name}</strong></td>
        <td class="pipeline-req">${inq.requirement}</td>
        <td><span class="inquiry-stage-badge">${stageDisplay(inq.stage_key)}</span></td>
        <td class="pipeline-cat">${categories[stage?.category] || stage?.category || '—'}</td>
        <td class="pipeline-action">${inq.next_action || 'Follow up'}</td>
        <td>${followUpCell(inq)}</td>
        <td class="pipeline-amount">${amount}</td>
        <td class="pipeline-assignee">${assignee || 'Me'}</td>
        <td><span class="inquiry-health-pill ${health}">${pipeline?.healthLabel(inq.health) || ''}</span></td>
        <td class="pipeline-days">${daysInStage(inq)}</td>
      </tr>`;
  }

  function setPipelineCategory(cat) {
    App.pipelineCategoryFilter = cat;
    navigate('pipeline');
  }

  async function renderPipeline(el) {
    const pipeline = P();
    const categories = pipeline?.STAGE_CATEGORIES || {};
    const active = activeInquiries();
    const catFilter = App.pipelineCategoryFilter || 'all';
    const stages = (pipeline?.getActiveStages() || []).filter((s) => {
      if (catFilter === 'all') return true;
      return s.category === catFilter;
    });

    const byStage = {};
    for (const inq of active) {
      const stageKey = inq.stage_key || 'follow_up';
      const stage = pipeline?.getStage(stageKey);
      if (catFilter !== 'all' && stage?.category !== catFilter) continue;
      if (!byStage[stageKey]) byStage[stageKey] = [];
      byStage[stageKey].push(inq);
    }

    const visibleCount = Object.values(byStage).reduce((n, rows) => n + rows.length, 0);
    const categoryTabs = [
      ['all', 'All'],
      ...Object.entries(categories).filter(([k]) => k !== 'closed').map(([k, v]) => [k, v]),
    ];

    const tableBody = stages.map((stage) => {
      const rows = (byStage[stage.key] || []).sort((a, b) => {
        const da = a.next_follow_up || '9999-99-99';
        const db = b.next_follow_up || '9999-99-99';
        return da.localeCompare(db);
      });
      if (rows.length === 0) return '';
      return `
        <tr class="pipeline-stage-header">
          <td colspan="11">
            <span class="pipeline-stage-name">${stage.display}</span>
            <span class="nav-badge">${rows.length}</span>
          </td>
        </tr>
        ${rows.map(pipelineTableRow).join('')}`;
    }).join('');

    el.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-title">📊 Inquiry Pipeline</div>
          <div class="page-subtitle">${visibleCount} active · table view by stage</div>
        </div>
        <button class="btn btn-primary" onclick="showInquirySheet()">＋ New Inquiry</button>
      </div>
      <div class="smart-tabs pipeline-filters">
        ${categoryTabs.map(([key, label]) =>
          `<button class="smart-tab ${catFilter === key ? 'active' : ''}" onclick="setPipelineCategory('${key}')">${label}</button>`
        ).join('')}
      </div>
      <div class="pipeline-table-wrap card">
        ${visibleCount === 0
          ? '<div class="empty-state" style="padding:32px"><div class="empty-icon">📊</div><h3>No inquiries in this view</h3><p>Create an inquiry or change the category filter.</p></div>'
          : `<table class="pipeline-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Requirement</th>
                <th>Stage</th>
                <th>Category</th>
                <th>Next Action</th>
                <th>Follow-up</th>
                <th>Amount</th>
                <th>Assigned</th>
                <th>Health</th>
                <th>Days</th>
              </tr>
            </thead>
            <tbody>${tableBody}</tbody>
          </table>`}
      </div>`;
  }

  async function renderInquiries(el) {
    const all = App.inquiries || [];
    const filter = App.inquiryListFilter || 'active';
    let items = all;
    if (filter === 'active') items = all.filter((i) => i.outcome_status === 'active');
    else if (filter === 'closed') items = all.filter((i) => i.outcome_status !== 'active');
    else if (filter === 'mine') items = all.filter((i) => i.assigned_to === 'me' || !i.assigned_to);

    el.innerHTML = `
      <div class="page-header">
        <div><div class="page-title">📥 Inquiries</div>
          <div class="page-subtitle">${items.length} inquiries</div></div>
        <button class="btn btn-primary" onclick="showInquirySheet()">＋ New Inquiry</button>
      </div>
      <div class="smart-tabs">
        <button class="smart-tab ${filter === 'active' ? 'active' : ''}" onclick="setInquiryFilter('active')">Active</button>
        <button class="smart-tab ${filter === 'mine' ? 'active' : ''}" onclick="setInquiryFilter('mine')">My Inquiries</button>
        <button class="smart-tab ${filter === 'closed' ? 'active' : ''}" onclick="setInquiryFilter('closed')">Closed / Lost</button>
      </div>
      <div class="inquiry-list">
        ${items.length === 0
          ? '<div class="empty-state"><div class="empty-icon">📥</div><h3>No inquiries</h3><p>Create your first inquiry in seconds.</p></div>'
          : items.map((i) => inquiryCard(i)).join('')}
      </div>`;
  }

  function setInquiryFilter(f) {
    App.inquiryListFilter = f;
    navigate('inquiries');
  }

  async function renderInquiryFollowups(el) {
    const today = typeof todayStr === 'function' ? todayStr() : '';
    const active = activeInquiries();
    const dueToday = active.filter((i) => i.next_follow_up === today);
    const overdue = active.filter((i) => i.next_follow_up && i.next_follow_up < today);
    const upcoming = active.filter((i) => i.next_follow_up && i.next_follow_up > today);

    el.innerHTML = `
      <div class="page-header">
        <div><div class="page-title">📞 Follow-ups</div>
          <div class="page-subtitle">${dueToday.length} today · ${overdue.length} overdue</div></div>
      </div>
      ${overdue.length ? `<div class="section-header"><div class="section-title">⚠️ Overdue</div></div>
        <div class="inquiry-list">${overdue.map(inquiryCard).join('')}</div>` : ''}
      <div class="section-header"><div class="section-title">☀️ Today</div></div>
      <div class="inquiry-list">${dueToday.length ? dueToday.map(inquiryCard).join('') : '<p class="pipeline-empty">Nothing due today</p>'}</div>
      <div class="section-header"><div class="section-title">📆 Upcoming</div></div>
      <div class="inquiry-list">${upcoming.slice(0, 20).map(inquiryCard).join('') || '<p class="pipeline-empty">—</p>'}</div>`;
  }

  async function renderInquiryDetail(el) {
    const id = App.selectedInquiryId;
    const inq = (App.inquiries || []).find((i) => i.id === id);
    if (!inq) {
      el.innerHTML = '<div class="empty-state"><h3>Inquiry not found</h3></div>';
      return;
    }

    const activities = await db(
      'SELECT * FROM inquiry_activities WHERE inquiry_id = ? ORDER BY created_at DESC LIMIT 50',
      [id],
    ) || [];
    const linkedTasks = await db(
      "SELECT * FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'",
      [id],
    ) || [];
    const pipeline = P();
    const stages = inq.outcome_status === 'active'
      ? pipeline?.getActiveStages() || []
      : [...(pipeline?.getActiveStages() || []), ...(pipeline?.getClosedStages() || [])];

    el.innerHTML = `
      <div class="page-header">
        <div>
          <button class="btn btn-ghost btn-sm" onclick="navigate('inquiries')">← Back</button>
          <div class="page-title" style="margin-top:8px">${inq.client_name}</div>
          <div class="page-subtitle">${inq.inquiry_number} · ${inq.requirement}</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-ghost btn-sm" onclick="logInquiryQuick('${id}','call')">📞 Call</button>
          <button class="btn btn-ghost btn-sm" onclick="logInquiryQuick('${id}','whatsapp')">💬 WhatsApp</button>
          <button class="btn btn-ghost btn-sm" onclick="showCaptureSheet()">＋ Task</button>
          <button class="btn btn-primary btn-sm" onclick="showStageChangeModal('${id}')">Change Stage</button>
        </div>
      </div>

      <div class="inquiry-detail-grid">
        <div class="inquiry-detail-main">
          <div class="next-action-banner">
            <div class="next-action-label">NEXT ACTION</div>
            <div class="next-action-title">${inq.next_action || 'Follow up'}</div>
            <div class="next-action-when">${inq.next_follow_up ? formatDate(inq.next_follow_up) + (inq.next_follow_up_time ? ' · ' + formatTime(inq.next_follow_up_time) : '') : 'Set a follow-up date'}</div>
          </div>

          <div class="card" style="padding:16px;margin-bottom:16px">
            <div class="form-grid">
              <div><span class="form-label">Stage</span><div>${stageDisplay(inq.stage_key)}</div></div>
              <div><span class="form-label">Health</span><div class="inquiry-health-pill ${pipeline?.healthClass(inq.health)}">${pipeline?.healthLabel(inq.health)}</div></div>
              ${inq.mobile ? `<div><span class="form-label">Mobile</span><div>${inq.mobile}</div></div>` : ''}
              ${inq.email ? `<div><span class="form-label">Email</span><div>${inq.email}</div></div>` : ''}
              ${inq.quotation_amount > 0 ? `<div><span class="form-label">Quotation</span><div>₹${Number(inq.quotation_amount).toLocaleString('en-IN')}</div></div>` : ''}
            </div>
            ${inq.notes ? `<p style="margin-top:12px;font-size:13px;color:var(--text-secondary)">${inq.notes}</p>` : ''}
          </div>

          <div class="section-header"><div class="section-title">📋 Linked Tasks & Reminders</div></div>
          <div class="reminder-list" style="margin-bottom:16px">
            ${linkedTasks.length === 0 ? '<p style="color:var(--text-muted);font-size:13px">No linked items yet.</p>' :
              linkedTasks.map((r) => typeof reminderCard === 'function' ? reminderCard(r) : `<div>${r.title}</div>`).join('')}
          </div>

          <div class="section-header">
            <div class="section-title">🕓 Activity Timeline</div>
            <button class="btn btn-ghost btn-sm" onclick="promptInquiryNote('${id}')">+ Log Activity</button>
          </div>
          <div class="activity-timeline">
            ${activities.length === 0 ? '<p style="color:var(--text-muted)">No activity yet.</p>' :
              activities.map((a) => `
                <div class="activity-item">
                  <div class="activity-time">${formatDate(a.created_at?.slice(0, 10))} · ${a.created_at?.slice(11, 16) || ''}</div>
                  <div class="activity-title">${a.title}</div>
                  ${a.body ? `<div class="activity-body">${a.body}</div>` : ''}
                </div>`).join('')}
          </div>
        </div>
      </div>`;
  }

  function openInquiryDetail(id) {
    App.selectedInquiryId = id;
    navigate('inquiry-detail');
  }

  async function logInquiryQuick(id, type) {
    const labels = { call: 'Call logged', whatsapp: 'WhatsApp logged', email: 'Email logged' };
    const result = await window.ilrs?.logInquiryActivity?.(id, type, labels[type] || 'Activity', '');
    if (result?.success) {
      toast('Activity logged');
      await loadAllData();
      navigate('inquiry-detail');
    }
  }

  async function promptInquiryNote(id) {
    const body = prompt('Activity note:');
    if (!body) return;
    await window.ilrs?.logInquiryActivity?.(id, 'note', 'Note added', body);
    toast('Note logged');
    await loadAllData();
    navigate('inquiry-detail');
  }

  function showStageChangeModal(id) {
    const inq = (App.inquiries || []).find((i) => i.id === id);
    if (!inq) return;
    const pipeline = P();
    const stages = [...(pipeline?.getActiveStages() || []), ...(pipeline?.getClosedStages() || [])];
    const stageKey = prompt(
      `New stage for ${inq.client_name}:\n\n` +
      stages.map((s, i) => `${i + 1}. ${s.display}`).join('\n') +
      '\n\nEnter stage number or key:',
      '',
    );
    if (!stageKey) return;
    let key = stageKey;
    const num = parseInt(stageKey, 10);
    if (!Number.isNaN(num) && num >= 1 && num <= stages.length) key = stages[num - 1].key;
    const selected = stages.find((s) => s.key === key || s.display.toLowerCase() === stageKey.toLowerCase());
    if (!selected) {
      toast('Invalid stage', 'warning');
      return;
    }
    changeInquiryStageConfirm(id, selected.key);
  }

  async function changeInquiryStageConfirm(id, stageKey) {
    const result = await window.ilrs?.changeInquiryStage?.(id, stageKey, {});
    if (!result?.success) {
      toast(result?.error || 'Could not change stage', 'warning');
      return;
    }
    toast(`Stage → ${result.stage?.display || stageKey}`);
    await loadAllData();
    navigate('inquiry-detail');
  }

  window.renderPipeline = renderPipeline;
  window.setPipelineCategory = setPipelineCategory;
  window.renderInquiries = renderInquiries;
  window.renderInquiryFollowups = renderInquiryFollowups;
  window.renderInquiryDetail = renderInquiryDetail;
  window.openInquiryDetail = openInquiryDetail;
  window.setInquiryFilter = setInquiryFilter;
  window.showStageChangeModal = showStageChangeModal;
  window.logInquiryQuick = logInquiryQuick;
  window.promptInquiryNote = promptInquiryNote;
})();
