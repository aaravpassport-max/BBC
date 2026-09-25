// ILRS — Inquiry pipeline, list, detail pages
(function () {
  const P = () => window.ILRSInquiryPipeline;

  function stageDisplay(key) {
    return P()?.getStage(key)?.display || key;
  }

  function inquiryCardWorkStatus(inq) {
    if (!inq?.id) return '';
    const LC = window.ILRSWorkLifecycle;
    if (LC?.lifecycleCardControl) {
      try {
        return LC.lifecycleCardControl(inq, 'inquiry');
      } catch (err) {
        console.error('inquiryCardWorkStatus:', err);
      }
    }
    const stored = String(inq.lifecycle_status || 'active').toLowerCase();
    const labels = {
      act_now: 'Act now',
      in_process: 'In process',
      pending: 'On hold — Waiting',
      completed: 'Done — Finished',
      closed: 'Closed',
    };
    const opts = ['act_now', 'in_process', 'pending', 'completed', 'closed'].map((v) =>
      `<option value="${v}" ${stored === v ? 'selected' : ''}>${labels[v] || v}</option>`,
    ).join('');
    return `<div class="card-work-status inquiry-card-work-status" onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
      <span class="card-work-status-label">Work status</span>
      <select class="lifecycle-quick-select lifecycle-card-select inquiry-lifecycle-card-select" data-inquiry-id="${inq.id}"
        aria-label="Inquiry work status">${opts}</select>
    </div>`;
  }

  function inquiryLifecycleQuickSelect(inq) {
    return inquiryCardWorkStatus(inq);
  }

  async function setInquiryLifecycleFromCard(id, lifecycle) {
    const LC = window.ILRSWorkLifecycle;
    const inq = (App.inquiries || []).find((i) => i.id === id);
    const raw = String(lifecycle || '').trim().toLowerCase();
    const lc = LC?.normalizeInquiryWorkStatus
      ? LC.normalizeInquiryWorkStatus(raw, inq)
      : (['act_now', 'in_process', 'pending', 'completed', 'closed'].includes(raw) ? raw : 'act_now');
    const data = { lifecycleStatus: lc, scheduleNext: false };
    const followLc = LC?.lifecycleForFollowUpRules ? LC.lifecycleForFollowUpRules(lc) : lc;
    if (LC?.blocksNextReminder(followLc) || followLc === LC.LIFECYCLE_COMPLETED) {
      data.nextFollowUp = '';
      data.nextFollowUpTime = '';
    }
    const Q = window.ILRSLifecycleQueue;
    let tab = Q?.lifecycleToQueueTab ? Q.lifecycleToQueueTab(lc, inq) : lc;
    const prevFilters = App.lifecycleQueueFilters ? { ...App.lifecycleQueueFilters } : null;
    App._lifecycleQueueRefreshLock = true;
    try {
      Q?.applyGlobalFilter?.(tab);
      if (typeof patchInquiryLifecycleInMemory === 'function') patchInquiryLifecycleInMemory(id, lc);
      if (typeof softRefreshCurrentPage === 'function') await softRefreshCurrentPage();
      let result;
      try {
        result = await window.ilrs?.updateInquiry?.(id, data);
      } catch (err) {
        result = { success: false, error: err?.message || String(err) };
      }
      if (!result?.success) {
        if (prevFilters) App.lifecycleQueueFilters = prevFilters;
        if (typeof toast === 'function') toast(result?.error || 'Could not update status', 'warning');
        console.error('setInquiryLifecycleFromCard failed', id, data, result);
        if (typeof loadAllData === 'function') await loadAllData();
        if (typeof softRefreshCurrentPage === 'function') await softRefreshCurrentPage();
        return;
      }
      if (result.inquiry) {
        const idx = (App.inquiries || []).findIndex((i) => i.id === id);
        if (idx >= 0) App.inquiries[idx] = result.inquiry;
        if (Q?.inferInquiryQueueTab) tab = Q.inferInquiryQueueTab(result.inquiry);
      } else if (typeof patchInquiryLifecycleInMemory === 'function') {
        patchInquiryLifecycleInMemory(id, lc);
      }
      Q?.applyGlobalFilter?.(tab);
      const tabLabel = tab === 'in_process' ? 'In process' : tab === 'active' ? 'Act now' : (LC?.lifecycleLabel(lc, true) || lc);
      if (typeof toast === 'function') toast(`Moved to ${tabLabel}`);
      if (typeof loadAllData === 'function') await loadAllData();
      if (typeof updateBadges === 'function') updateBadges();
      if (typeof softRefreshCurrentPage === 'function') {
        await softRefreshCurrentPage();
      } else if (App.currentPage === 'inquiry-detail' && App.selectedInquiryId === id) {
        navigate('inquiry-detail');
      }
    } finally {
      App._lifecycleQueueRefreshLock = false;
    }
  }

  function inquiryCard(inq, compact = false) {
    const pipeline = P();
    const WS = window.ILRSWorkScheduling;
    const WC = window.ILRSWorkCards;
    const health = pipeline?.healthClass(inq.health) || '';
    const stageCls = pipeline?.stageCategoryClass?.(inq.stage_key) || '';
    const completionOverdue = WS?.isCompletionOverdue?.(inq);
    const selected = App.selectedInquiryIds?.has(inq.id);
    const assignee = typeof assigneeLabel === 'function' ? assigneeLabel(inq.assigned_to) : '';
    const LCw = window.ILRSWorkLifecycle;
    const isNew = LCw?.inferInquiryQueueTab?.(inq) === 'active' && LCw?.inferLifecycleFromInquiry?.(inq) === 'active';
    const stateLabel = isNew ? 'New enquiry' : (WC?.operationalStateLabel ? WC.operationalStateLabel(inq) : 'Active');
    const stateCls = WC?.operationalStateClass ? WC.operationalStateClass(inq) : '';
    const nextBlock = WC?.nextActionBlock ? WC.nextActionBlock(inq) : '';
    const latest = WC?.latestLineHtml ? WC.latestLineHtml(inq) : '';
    return `
      <div class="inquiry-card inquiry-card-operational ${health} ${stageCls} ${completionOverdue ? 'completion-overdue' : ''}" onclick="if(event.target.closest('.card-work-status,.lifecycle-quick-select,.item-select-checkbox,.action-btn')) return; openInquiryDetail('${inq.id}')">
        <input type="checkbox" class="item-select-checkbox" ${selected ? 'checked' : ''}
          onclick="event.stopPropagation();toggleInquirySelection('${inq.id}', this.checked)" title="Select" />
        <div class="inquiry-card-top">
          <strong>${inq.client_name}</strong>
          <span class="inquiry-number">${inq.inquiry_number || ''}</span>
          <span class="operational-state-pill ${stateCls}" title="Current state">${stateLabel}</span>
          <button class="action-btn delete btn-sm" onclick="event.stopPropagation();deleteInquiryItem('${inq.id}')" title="Delete">🗑</button>
        </div>
        <div class="inquiry-requirement">${inq.requirement}</div>
        <div class="inquiry-card-status-row">
          <span class="inquiry-stage-badge ${stageCls}">${stageDisplay(inq.stage_key)}</span>
          ${inquiryCardWorkStatus(inq)}
        </div>
        ${nextBlock}
        ${isNew ? `<button type="button" class="btn btn-primary btn-sm inquiry-start-work-btn" onclick="event.stopPropagation();startInquiryWorkFromCard('${inq.id}')">Start processing</button>` : ''}
        ${latest ? `<div class="card-conversation-preview">${latest}</div>` : ''}
        <button type="button" class="btn btn-ghost btn-sm inquiry-add-conversation-btn" onclick="event.stopPropagation();openInquiryConversation('${inq.id}')">+ Conversation</button>
        ${!compact && assignee ? `<div class="inquiry-meta">Owner: ${assignee}</div>` : ''}
        <div class="inquiry-health-pill ${health}" title="Health">${pipeline?.healthLabel(inq.health) || ''}</div>
      </div>`;
  }

  function inquiryDetailTabsHtml(inquiryId, activeTab) {
    const tabs = [
      ['overview', 'Overview'],
      ['tasks', 'Tasks'],
      ['followups', 'Follow-ups'],
      ['conversation', 'Conversation'],
    ];
    return `<div class="detail-tabs" role="tablist">
      ${tabs.map(([id, label]) =>
        `<button type="button" class="detail-tab ${activeTab === id ? 'active' : ''}"
          onclick="setInquiryDetailTab('${inquiryId}','${id}')">${label}</button>`,
      ).join('')}
    </div>`;
  }

  function setInquiryDetailTab(inquiryId, tab) {
    App.inquiryDetailTab = tab;
    App.selectedInquiryId = inquiryId;
    navigate('inquiry-detail');
  }

  function activeInquiries() {
    const LC = window.ILRSWorkLifecycle;
    return (App.inquiries || []).filter((i) => {
      if (i.outcome_status === 'deleted') return false;
      if (LC?.isInquiryActiveForWorkQueue) return LC.isInquiryActiveForWorkQueue(i);
      return i.outcome_status === 'active';
    });
  }

  function lifecycleFilterOptions(selected) {
    const LC = window.ILRSWorkLifecycle;
    const statuses = LC?.LIFECYCLE_STATUSES || [];
    return `<option value="all" ${selected === 'all' ? 'selected' : ''}>All statuses</option>`
      + statuses.map((s) =>
        `<option value="${s.id}" ${selected === s.id ? 'selected' : ''}>${s.label}</option>`
      ).join('')
      + `<option value="has_followup" ${selected === 'has_followup' ? 'selected' : ''}>Has follow-up date</option>`
      + `<option value="no_followup" ${selected === 'no_followup' ? 'selected' : ''}>No follow-up scheduled</option>`;
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
    const stageCls = pipeline?.stageCategoryClass?.(inq.stage_key) || '';
    const selected = App.selectedInquiryIds?.has(inq.id);
    return `
      <tr class="pipeline-row ${health} ${stageCls}" onclick="openInquiryDetail('${inq.id}')">
        <td class="pipeline-select" onclick="event.stopPropagation()">
          <input type="checkbox" class="item-select-checkbox" ${selected ? 'checked' : ''}
            onclick="event.stopPropagation();toggleInquirySelection('${inq.id}', this.checked)" />
        </td>
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
        <td class="pipeline-actions" onclick="event.stopPropagation()">
          <button class="action-btn delete btn-sm" onclick="deleteInquiryItem('${inq.id}')" title="Delete">🗑</button>
        </td>
      </tr>`;
  }

  function setPipelineCategory(cat) {
    App.pipelineCategoryFilter = cat;
    navigate('pipeline');
  }

  function setPipelineStageFilter(stageKey) {
    App.pipelineStageFilter = stageKey;
    navigate('pipeline');
  }

  function setInquiryStageFilter(stageKey) {
    App.inquiryStageFilter = stageKey;
    navigate('inquiries');
  }

  function stageFilterOptions(selected) {
    const pipeline = P();
    const stages = [...(pipeline?.getActiveStages() || []), ...(pipeline?.getClosedStages() || [])];
    return `<option value="all" ${selected === 'all' ? 'selected' : ''}>All stages</option>`
      + stages.map((s) =>
        `<option value="${s.key}" ${selected === s.key ? 'selected' : ''}>${s.display}</option>`
      ).join('');
  }

  async function deleteInquiryItem(id) {
    if (!confirm('Delete this inquiry?')) return;
    const result = await window.ilrs?.deleteInquiry?.(id);
    if (result && !result.success) {
      toast(result?.error || 'Could not delete', 'warning');
      return;
    }
    App.selectedInquiryIds?.delete(id);
    toast('Inquiry deleted', 'warning');
    await loadAllData();
    refreshCurrentView?.();
    if (App.currentPage === 'inquiry-detail' && App.selectedInquiryId === id) {
      navigate('inquiries');
    } else if (PAGES[App.currentPage]) {
      navigate(App.currentPage);
    }
  }

  function inquiryHubViewForPage(page) {
    if (page === 'pipeline') return 'pipeline';
    if (page === 'inquiry-followups') return 'followups';
    if (page === 'work-schedule') return 'schedule';
    return App.inquiryHubView || 'list';
  }

  function inquiryHubTabsHtml(activeView) {
    const tabs = [
      ['list', 'List'],
      ['pipeline', 'Pipeline'],
      ['followups', 'Follow-ups'],
      ['schedule', 'Schedule'],
    ];
    return `<div class="hub-tabs detail-tabs" role="tablist" aria-label="Inquiry views">
      ${tabs.map(([id, label]) =>
        `<button type="button" class="detail-tab ${activeView === id ? 'active' : ''}"
          onclick="setInquiryHubView('${id}')">${label}</button>`,
      ).join('')}
    </div>`;
  }

  function setInquiryHubView(view) {
    App.inquiryHubView = view;
    window.ILRSAppNav?.closeMobileDrawer?.();
    const routes = { list: 'inquiries', pipeline: 'pipeline', followups: 'inquiry-followups', schedule: 'work-schedule' };
    navigate(routes[view] || 'inquiries');
  }

  async function renderInquiryHub(el) {
    const view = inquiryHubViewForPage(App.currentPage);
    App.inquiryHubView = view;
    const subtitles = {
      list: 'Card list with work queues',
      pipeline: 'Grouped by pipeline stage',
      followups: 'Due today, overdue, and upcoming',
      schedule: 'Start dates and expected completion',
    };
    el.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-title">Inquiries</div>
          <div class="page-subtitle">${subtitles[view] || ''}</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-ghost" onclick="showSearchPalette()" title="Ctrl+K">Search</button>
          <button class="btn btn-ghost" onclick="navigate('pipeline-settings')">Stages</button>
          <button class="btn btn-primary" onclick="showInquirySheet()">＋ New inquiry</button>
        </div>
      </div>
      ${inquiryHubTabsHtml(view)}
      <div id="inquiry-hub-panel" class="inquiry-hub-panel"></div>`;
    const panel = el.querySelector('#inquiry-hub-panel');
    if (view === 'list') await renderInquiryList(panel, { embedded: true });
    else if (view === 'pipeline') await renderPipeline(panel, { embedded: true });
    else if (view === 'followups') await renderInquiryFollowups(panel, { embedded: true });
    else if (view === 'schedule' && typeof renderWorkSchedule === 'function') {
      await renderWorkSchedule(panel, { embedded: true });
    }
  }

  async function renderPipeline(el, opts = {}) {
    const pipeline = P();
    const categories = pipeline?.STAGE_CATEGORIES || {};
    const Q = window.ILRSLifecycleQueue;
    const pool = (App.inquiries || []).filter((i) => i.outcome_status !== 'deleted');
    const tab = Q?.getFilter('inquiry') || 'active';
    const active = Q?.filterItems ? Q.filterItems(pool, 'inquiry', tab) : activeInquiries();
    const catFilter = App.pipelineCategoryFilter || 'all';
    const stageFilter = App.pipelineStageFilter || 'all';
    const stages = (pipeline?.getActiveStages() || []).filter((s) => {
      if (catFilter !== 'all' && s.category !== catFilter) return false;
      if (stageFilter !== 'all' && s.key !== stageFilter) return false;
      return true;
    });

    const byStage = {};
    for (const inq of active) {
      const stageKey = inq.stage_key || 'follow_up';
      const stage = pipeline?.getStage(stageKey);
      if (catFilter !== 'all' && stage?.category !== catFilter) continue;
      if (stageFilter !== 'all' && stageKey !== stageFilter) continue;
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
        <tr class="pipeline-stage-header ${pipeline?.stageCategoryClass?.(stage.key) || ''}">
          <td colspan="12">
            <span class="pipeline-stage-name">${stage.display}</span>
            <span class="nav-badge">${rows.length}</span>
          </td>
        </tr>
        ${rows.map(pipelineTableRow).join('')}`;
    }).join('');

    const header = opts.embedded ? '' : `
      <div class="page-header">
        <div>
          <div class="page-title">📊 Inquiry Pipeline</div>
          <div class="page-subtitle">${visibleCount} in this status queue · grouped by pipeline stage</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-ghost" onclick="navigate('pipeline-settings')">⚙️ Setup</button>
          <button class="btn btn-ghost" onclick="navigate('work-reports')">📈 Analytics</button>
          <button class="btn btn-primary" onclick="showInquirySheet()">＋ New Inquiry</button>
        </div>
      </div>`;
    el.innerHTML = `
      ${header}
      ${Q?.tabsHtml ? Q.tabsHtml('inquiry', pool) : ''}
      <div class="smart-tabs pipeline-filters">
        ${categoryTabs.map(([key, label]) =>
          `<button class="smart-tab ${catFilter === key ? 'active' : ''}" onclick="setPipelineCategory('${key}')">${label}</button>`
        ).join('')}
      </div>
      <div class="pipeline-stage-filter" style="margin-bottom:12px">
        <label class="form-label" style="display:inline;margin-right:8px">Stage:</label>
        <select class="form-select" style="width:auto;min-width:220px" onchange="setPipelineStageFilter(this.value)">
          ${stageFilterOptions(stageFilter)}
        </select>
      </div>
      ${typeof renderBulkSelectionBar === 'function' ? renderBulkSelectionBar('inquiry') : ''}
      <div class="pipeline-table-wrap card">
        ${visibleCount === 0
          ? '<div class="empty-state" style="padding:32px"><div class="empty-icon">📊</div><h3>No inquiries in this view</h3><p>Create an inquiry or change the category filter.</p></div>'
          : `<table class="pipeline-table">
            <thead>
              <tr>
                <th style="width:36px"></th>
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
                <th></th>
              </tr>
            </thead>
            <tbody>${tableBody}</tbody>
          </table>`}
      </div>`;
  }

  async function renderInquiryList(el, opts = {}) {
    const Q = window.ILRSLifecycleQueue;
    const all = (App.inquiries || []).filter((i) => i.outcome_status !== 'deleted');
    let pool = all;
    if (App.clientFilterId) pool = pool.filter((i) => i.client_id === App.clientFilterId);
    if (App.inquiryListFilter === 'mine') {
      pool = pool.filter((i) => i.assigned_to === 'me' || !i.assigned_to);
    }
    const stageFilter = App.inquiryStageFilter || 'all';
    const queuePool = pool;
    if (stageFilter !== 'all') pool = pool.filter((i) => i.stage_key === stageFilter);
    const tab = Q?.getFilter('inquiry') || 'active';
    let items = Q?.filterItems ? Q.filterItems(pool, 'inquiry', tab) : pool;
    const searchQ = App.inquirySearchQuery || '';
    const GS = window.ILRSGlobalSearch;
    if (searchQ.trim() && GS?.matchesInquiry) {
      items = items.filter((i) => GS.matchesInquiry(i, searchQ));
    }
    const clientFilter = App.clientFilterId ? (App.clients || []).find((c) => c.id === App.clientFilterId) : null;

    const header = opts.embedded ? '' : `
      <div class="page-header">
        <div><div class="page-title">📥 Inquiries</div>
          <div class="page-subtitle">${clientFilter ? `${clientFilter.name} · ` : ''}${items.length} inquiries</div></div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-ghost" onclick="showSearchPalette()" title="Ctrl+K">🔍 Search</button>
          <button class="btn btn-primary" onclick="showInquirySheet()">＋ New Inquiry</button>
        </div>
      </div>`;
    el.innerHTML = `
      ${header}
      <div class="filter-bar" style="margin-bottom:12px">
        <input type="text" class="form-input" style="max-width:360px" placeholder="🔍 Search client, notes, stage, tags…"
          value="${searchQ.replace(/"/g, '&quot;')}"
          oninput="App.inquirySearchQuery=this.value;navigate('inquiries')" />
      </div>
      ${clientFilter ? `<div style="margin-bottom:12px"><button class="btn btn-ghost btn-sm" onclick="App.clientFilterId=null;navigate('inquiries')">✕ Clear client filter</button></div>` : ''}
      ${Q?.tabsHtml ? Q.tabsHtml('inquiry', queuePool) : ''}
      <div class="smart-tabs" style="margin-top:8px">
        <button class="smart-tab ${App.inquiryListFilter !== 'mine' ? 'active' : ''}" onclick="setInquiryFilter('all')">Everyone</button>
        <button class="smart-tab ${App.inquiryListFilter === 'mine' ? 'active' : ''}" onclick="setInquiryFilter('mine')">My inquiries</button>
      </div>
      <div class="pipeline-stage-filter" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:12px;align-items:center">
        <label class="form-label" style="display:inline;margin-right:8px">Pipeline stage:</label>
        <select class="form-select" style="width:auto;min-width:220px" onchange="setInquiryStageFilter(this.value)">
          ${stageFilterOptions(stageFilter)}
        </select>
      </div>
      ${typeof renderBulkSelectionBar === 'function' ? renderBulkSelectionBar('inquiry') : ''}
      <div class="inquiry-list">
        ${items.length === 0
          ? '<div class="empty-state"><div class="empty-icon">📥</div><h3>No inquiries</h3><p>Create your first inquiry in seconds.</p></div>'
          : items.map((i) => inquiryCard(i)).join('')}
      </div>`;
  }

  async function startInquiryWorkFromCard(id) {
    const result = await window.ilrs?.startInquiryWork?.(id);
    if (!result?.success) {
      toast(result?.error || 'Could not start work', 'warning');
      return;
    }
    const Q = window.ILRSLifecycleQueue;
    Q?.applyGlobalFilter?.('in_process');
    toast('Moved to In process');
    await loadAllData();
    if (typeof softRefreshCurrentPage === 'function') await softRefreshCurrentPage();
    else navigate(App.currentPage);
  }

  function setInquiryFilter(f) {
    App.inquiryListFilter = f === 'mine' ? 'mine' : 'all';
    navigate('inquiries');
  }

  async function renderInquiries(el) {
    return renderInquiryHub(el);
  }

  async function renderInquiryFollowups(el, opts = {}) {
    const today = typeof todayStr === 'function' ? todayStr() : '';
    const active = activeInquiries();
    const dueToday = active.filter((i) => i.next_follow_up === today);
    const overdue = active.filter((i) => i.next_follow_up && i.next_follow_up < today);
    const upcoming = active.filter((i) => i.next_follow_up && i.next_follow_up > today);

    const header = opts.embedded ? '' : `
      <div class="page-header">
        <div><div class="page-title">📞 Follow-ups</div>
          <div class="page-subtitle">${dueToday.length} today · ${overdue.length} overdue</div></div>
      </div>`;
    el.innerHTML = `
      ${header}
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

    let tab = App.inquiryDetailTab || 'overview';
    if (tab === 'activity' || tab === 'notes') tab = 'conversation';
    App.inquiryDetailTab = tab;
    const activities = await db(
      'SELECT * FROM inquiry_activities WHERE inquiry_id = ? ORDER BY created_at ASC LIMIT 500',
      [id],
    ) || [];
    const linkedTasks = await db(
      "SELECT * FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'",
      [id],
    ) || [];
    const pipeline = P();
    const WC = window.ILRSWorkCards;
    const stateLabel = WC?.operationalStateLabel ? WC.operationalStateLabel(inq) : '';

    let tabBody = '';
    if (tab === 'overview') {
      tabBody = `
        <div class="next-action-banner">
          <div class="next-action-label">Next action</div>
          <div class="next-action-title">${inq.next_action || 'Follow up'}</div>
          <div class="next-action-when">${inq.next_follow_up ? formatDate(inq.next_follow_up) + (inq.next_follow_up_time ? ' · ' + formatTime(inq.next_follow_up_time) : '') : 'Set a follow-up date'}</div>
        </div>
        <div class="card inquiry-overview-card">
          <div class="form-grid">
            <div><span class="form-label">State</span><div class="operational-state-pill">${stateLabel}</div></div>
            <div><span class="form-label">Stage</span><div>${stageDisplay(inq.stage_key)}</div></div>
            <div class="full" style="grid-column:1/-1">${inquiryLifecycleQuickSelect(inq)}</div>
            <div><span class="form-label">Health</span><div class="inquiry-health-pill ${pipeline?.healthClass(inq.health)}">${pipeline?.healthLabel(inq.health)}</div></div>
            ${inq.mobile ? `<div><span class="form-label">Mobile</span><div>${inq.mobile}</div></div>` : ''}
            ${inq.email ? `<div><span class="form-label">Email</span><div>${inq.email}</div></div>` : ''}
          </div>
          ${window.ILRSWorkScheduling?.scheduleDatesHtml ? window.ILRSWorkScheduling.scheduleDatesHtml(inq) : ''}
        </div>
        ${window.ILRSPayment?.paymentCardHtml ? `
        <div class="section-header"><div class="section-title">Payments</div>
          <button class="btn btn-ghost btn-sm" onclick="showRecordPaymentModal('inquiry','${id}')">Record payment</button>
        </div>
        <div class="card" style="margin-bottom:16px">${window.ILRSPayment.paymentCardHtml(inq, 'inquiry', 'inq-detail')}</div>` : ''}
        <div class="section-header" style="margin-top:16px">
          <div class="section-title">Recent conversation</div>
          <button type="button" class="btn btn-ghost btn-sm" onclick="setInquiryDetailTab('${id}','conversation')">View all</button>
        </div>
        ${(window.ILRSActivity?.renderTimeline)(activities.slice(-3), { compact: true, emptyText: 'No conversation yet — open the Conversation tab to add the first entry.' }) || ''}`;
    } else if (tab === 'tasks') {
      tabBody = `
        <div class="section-header">
          <div class="section-title">Tasks linked to this enquiry</div>
          <button class="btn btn-primary btn-sm" onclick="showInquiryLinkedTask('${id}')">+ Task</button>
        </div>
        <div class="reminder-list">${linkedTasks.filter((r) => r.task_type === 'task').length
          ? linkedTasks.filter((r) => r.task_type === 'task').map((r) => reminderCard(r)).join('')
          : '<p class="activity-empty">No tasks yet.</p>'}</div>`;
    } else if (tab === 'followups') {
      const followReminders = linkedTasks.filter((r) => r.task_type !== 'task');
      tabBody = `
        <div class="section-header">
          <div class="section-title">Follow-up schedule</div>
          <button class="btn btn-ghost btn-sm" onclick="showInquiryRescheduleMenu('${id}')">Reschedule</button>
        </div>
        <div class="card" style="margin-bottom:16px;padding:16px">
          <div class="form-label">Enquiry work status</div>
          <p class="form-hint" style="margin:0 0 10px">Controls Act now / In process / On hold queues for this enquiry (not just the reminder below).</p>
          ${inquiryLifecycleQuickSelect(inq)}
          <div class="form-label" style="margin-top:16px">Next follow-up</div>
          <div>${inq.next_follow_up ? formatDate(inq.next_follow_up) + (inq.next_follow_up_time ? ' · ' + formatTime(inq.next_follow_up_time) : '') : 'Not scheduled'}</div>
        </div>
        <div class="reminder-list">${followReminders.length
          ? followReminders.map((r) => reminderCard(r)).join('')
          : '<p class="activity-empty">No reminder fires linked.</p>'}</div>`;
    } else if (tab === 'conversation') {
      tabBody = `
        <div class="section-header">
          <div class="section-title">Client conversation history</div>
        </div>
        <p class="form-hint" style="margin-bottom:12px">Every call, message, and update is stored here in time order for this enquiry.</p>
        ${window.ILRSActivity?.renderConversationPanel(id, activities) || ''}`;
    }

    el.innerHTML = `
      <div class="page-header inquiry-detail-header">
        <div>
          <button class="btn btn-ghost btn-sm" onclick="navigate('inquiries')">← Inquiries</button>
          <div class="page-title" style="margin-top:8px">${inq.client_name}</div>
          <div class="page-subtitle">${inq.inquiry_number || ''} · ${inq.requirement}</div>
        </div>
        <div class="inquiry-detail-actions">
          <button class="btn btn-ghost btn-sm" onclick="ILRSActivity.quickLog('${id}','outbound_call')">Call</button>
          <button class="btn btn-ghost btn-sm" onclick="ILRSActivity.quickLog('${id}','whatsapp')">WhatsApp</button>
          <button class="btn btn-primary btn-sm" onclick="setInquiryDetailTab('${id}','conversation')">+ Conversation</button>
          <button class="btn btn-ghost btn-sm" onclick="editInquiry('${id}')">Edit</button>
          ${inq.outcome_status === 'active'
            ? `<button class="btn btn-primary btn-sm" onclick="showStageChangeModal('${id}')">Change stage</button>`
            : `<button class="btn btn-ghost btn-sm" onclick="reopenInquiryConfirm('${id}')">Reopen</button>`}
        </div>
      </div>
      ${inquiryDetailTabsHtml(id, tab)}
      <div class="inquiry-detail-panel">${tabBody}</div>`;
  }

  function openInquiryConversation(id) {
    App.selectedInquiryId = id;
    App.inquiryDetailTab = 'conversation';
    navigate('inquiry-detail');
  }

  function openInquiryDetail(id) {
    App.selectedInquiryId = id;
    navigate('inquiry-detail');
  }

  async function logInquiryQuick(id, type) {
    if (window.ILRSActivity?.quickLog) {
      await window.ILRSActivity.quickLog(id, type === 'call' ? 'outbound_call' : type);
      return;
    }
  }

  async function promptInquiryNote(id) {
    if (window.ILRSActivity?.showLogActivityModal) {
      window.ILRSActivity.showLogActivityModal(id);
    }
  }

  function editInquiry(id) {
    const lookupId = id || App.selectedInquiryId;
    const inq = (App.inquiries || []).find((i) => i.id === lookupId);
    if (!inq) {
      if (typeof toast === 'function') toast('Inquiry not found — try refreshing the list', 'warning');
      return;
    }
    if (typeof window.showInquirySheet !== 'function') {
      if (typeof toast === 'function') toast('Edit form failed to load — please restart the app', 'critical');
      return;
    }
    try {
      window.showInquirySheet(inq);
    } catch (err) {
      console.error('editInquiry:', err);
      if (typeof toast === 'function') toast('Could not open edit form', 'critical');
    }
  }

  function showInquiryLinkedTask(id) {
    const inq = (App.inquiries || []).find((i) => i.id === id);
    if (!inq) return;
    if (typeof showCaptureSheet === 'function') {
      showCaptureSheet({
        task_type: 'task',
        category: 'work',
        title: inq.next_action ? `${inq.next_action}: ${inq.client_name}` : `Follow up: ${inq.client_name}`,
        source_type: 'inquiry',
        source_id: inq.id,
        assigned_to: inq.assigned_to || 'me',
      });
    }
  }

  function canConvertReminderToInquiry(r) {
    if (!r || r.source_type) return false;
    if (['medicine', 'bills', 'habit'].includes(r.category)) return false;
    return true;
  }

  function reminderToInquiryPrefill(r) {
    const cap = window.ILRSCapture;
    const followDate = cap?.nextFireDateStr?.(r.next_fire)
      || String(r.start_date || '').slice(0, 10);
    const title = String(r.title || '').trim();
    let clientName = '';
    let requirement = title;
    const callMatch = title.match(
      /^(?:call|follow up with|contact|meet)\s+([^—\-:]+?)(?:\s+(?:about|re:|regarding|for)\s+(.+))?$/i,
    );
    if (callMatch) {
      clientName = callMatch[1].trim();
      if (callMatch[2]) requirement = callMatch[2].trim();
    }
    const notes = [r.notes, r.why_it_matters ? `Context: ${r.why_it_matters}` : '']
      .filter(Boolean)
      .join('\n\n');
    return {
      client_name: clientName,
      requirement,
      next_action: r.why_it_matters || (r.task_type === 'task' ? 'Complete task' : 'Follow up'),
      next_follow_up: followDate,
      next_follow_up_time: r.reminder_time || '11:00',
      work_start_date: r.work_start_date || r.start_date || '',
      expected_completion_date: r.expected_completion_date || r.end_date || '',
      assigned_to: r.assigned_to || 'me',
      priority: r.priority || 'normal',
      service_category: r.category === 'work' ? 'work' : '',
      notes,
      _convertFromReminderId: r.id,
      _convertKind: r.task_type === 'task' ? 'task' : 'reminder',
    };
  }

  function convertReminderToInquiry(id) {
    const r = (App.reminders || []).find((x) => x.id === id);
    if (!r) {
      if (typeof toast === 'function') toast('Reminder not found', 'warning');
      return;
    }
    if (!canConvertReminderToInquiry(r)) {
      if (typeof toast === 'function') toast('This item cannot be converted to an inquiry', 'warning');
      return;
    }
    if (typeof showInquirySheet !== 'function') {
      if (typeof toast === 'function') toast('Inquiry form is not available', 'critical');
      return;
    }
    showInquirySheet(reminderToInquiryPrefill(r));
  }

  function renderStageFieldsHtml(stageKey, inq) {
    const fields = P()?.getStageFields?.(stageKey) || [];
    if (!fields.length) return '';
    return fields.map((f) => {
      const val = f.key === 'quotationAmount' ? inq.quotation_amount
        : f.key === 'paymentStatus' ? inq.payment_status
        : f.key === 'nextAction' ? inq.next_action : '';
      if (f.type === 'select') {
        return `<label class="form-label">${f.label}${f.required ? ' *' : ''}</label>
          <select class="form-select stage-field" data-field="${f.key}">
            ${(f.options || []).map((o) => `<option value="${o}" ${val === o ? 'selected' : ''}>${o}</option>`).join('')}
          </select>`;
      }
      return `<label class="form-label">${f.label}${f.required ? ' *' : ''}</label>
        <input type="${f.type || 'text'}" class="form-input stage-field" data-field="${f.key}"
          value="${val || ''}" placeholder="${f.placeholder || ''}" />`;
    }).join('');
  }

  function showStageChangeModal(id) {
    const inq = (App.inquiries || []).find((i) => i.id === id);
    if (!inq) return;
    const pipeline = P();
    const stages = [...(pipeline?.getActiveStages() || []), ...(pipeline?.getClosedStages() || [])];
    if (typeof dismissPageModals === 'function') dismissPageModals();

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'stage-change-modal';
    overlay.innerHTML = `
      <div class="capture-sheet" style="max-width:480px">
        <div class="capture-header">
          <h2>Change stage</h2>
          <button class="modal-close" onclick="document.getElementById('stage-change-modal').remove()">✕</button>
        </div>
        <p style="font-size:13px;color:var(--text-secondary);margin:0 0 12px">${inq.client_name} · ${inq.inquiry_number || ''}</p>
        <label class="form-label">New stage</label>
        <select class="form-select" id="stage-change-select" style="margin-bottom:12px">
          ${stages.map((s) => `<option value="${s.key}" ${s.key === inq.stage_key ? 'selected' : ''}>${s.display}</option>`).join('')}
        </select>
        <div id="stage-change-fields">${renderStageFieldsHtml(inq.stage_key, inq)}</div>
        <div id="stage-automation-hint" class="stage-automation-hint"></div>
        <label class="form-label">Note (optional)</label>
        <textarea class="form-textarea" id="stage-change-note" rows="2" placeholder="Reason or context"></textarea>
        <div class="capture-actions">
          <button class="btn btn-ghost" onclick="document.getElementById('stage-change-modal').remove()">Cancel</button>
          <button class="btn btn-primary" id="stage-change-save">Update stage</button>
        </div>
      </div>`;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);

    const updateFields = () => {
      const key = document.getElementById('stage-change-select')?.value;
      const fieldsEl = document.getElementById('stage-change-fields');
      const hintEl = document.getElementById('stage-automation-hint');
      if (fieldsEl) fieldsEl.innerHTML = renderStageFieldsHtml(key, inq);
      const auto = pipeline?.getStageAutomation?.(key) || {};
      if (hintEl) {
        hintEl.innerHTML = auto.followUpDays
          ? `<span class="chip selected">Auto: follow-up in ${auto.followUpDays} day(s)${auto.nextAction ? ' · ' + auto.nextAction : ''}</span>`
          : '';
      }
    };
    overlay.querySelector('#stage-change-select')?.addEventListener('change', updateFields);
    updateFields();

    overlay.querySelector('#stage-change-save')?.addEventListener('click', async () => {
      const key = document.getElementById('stage-change-select')?.value;
      const note = document.getElementById('stage-change-note')?.value.trim();
      const options = { note };
      overlay.querySelectorAll('.stage-field').forEach((el) => {
        const k = el.dataset.field;
        const v = el.value;
        if (k === 'quotationAmount') options.quotationAmount = parseFloat(v) || 0;
        else if (k === 'paymentStatus') options.paymentStatus = v;
        else if (k === 'nextAction') options.nextAction = v;
      });
      overlay.remove();
      const result = await window.ilrs?.changeInquiryStage?.(id, key, options);
      if (!result?.success) {
        toast(result?.error || 'Could not change stage', 'warning');
        return;
      }
      toast(`Stage → ${result.stage?.display || key}`);
      await loadAllData();
      navigate('inquiry-detail');
    });
  }

  async function reopenInquiryConfirm(id) {
    const inq = (App.inquiries || []).find((i) => i.id === id);
    if (!inq) return;
    if (!confirm(`Reopen inquiry for ${inq.client_name}?`)) return;
    const result = await window.ilrs?.reopenInquiry?.(id, 'follow_up');
    if (!result?.success) {
      toast(result?.error || 'Could not reopen', 'warning');
      return;
    }
    toast('Inquiry reopened');
    await loadAllData();
    navigate('inquiry-detail');
  }

  function showInquiryRescheduleMenu(id) {
    const inq = (App.inquiries || []).find((i) => i.id === id);
    if (!inq) return;
    document.getElementById('inq-reschedule-menu')?.remove();
    const defaultTime = inq.next_follow_up_time || '11:00';
    const wasClosed = inq.outcome_status !== 'active';
    const menu = document.createElement('div');
    menu.id = 'inq-reschedule-menu';
    menu.className = 'modal-overlay';
    menu.style.zIndex = '1050';
    menu.innerHTML = `
      <div class="capture-sheet" style="width:min(400px,94vw)">
        <div class="capture-header">
          <h2>${wasClosed ? 'Reschedule & reopen' : 'Reschedule follow-up'}</h2>
          <button class="modal-close" onclick="document.getElementById('inq-reschedule-menu').remove()">✕</button>
        </div>
        <p style="font-size:13px;color:var(--text-secondary);margin:0 0 12px">${inq.client_name} · ${inq.requirement}</p>
        <label class="form-label">Follow-up date</label>
        <input type="date" class="form-input" id="inq-reschedule-date" value="${inq.next_follow_up || ''}" />
        <label class="form-label" style="margin-top:8px">Time</label>
        <input type="time" class="form-input" id="inq-reschedule-time" value="${defaultTime}" />
        <label class="form-label" style="margin-top:8px">Next action</label>
        <input type="text" class="form-input" id="inq-reschedule-action" value="${inq.next_action || 'Follow up with client'}" />
        <div class="capture-actions" style="margin-top:16px">
          <button class="btn btn-ghost" onclick="document.getElementById('inq-reschedule-menu').remove()">Cancel</button>
          <button class="btn btn-primary" id="inq-reschedule-confirm">Confirm</button>
        </div>
      </div>`;
    if (typeof attachModalDismiss === 'function') attachModalDismiss(menu);
    menu.querySelector('.capture-sheet')?.addEventListener('click', (e) => e.stopPropagation());
    document.body.appendChild(menu);

    menu.querySelector('#inq-reschedule-confirm')?.addEventListener('click', async () => {
      const dateStr = menu.querySelector('#inq-reschedule-date')?.value;
      const timeStr = menu.querySelector('#inq-reschedule-time')?.value || defaultTime;
      const nextAction = menu.querySelector('#inq-reschedule-action')?.value?.trim() || 'Follow up with client';
      if (!dateStr) {
        toast('Pick a follow-up date', 'warning');
        return;
      }
      menu.remove();
      const result = await window.ilrs?.rescheduleInquiry?.(id, dateStr, timeStr, 'follow_up', nextAction);
      if (!result?.success) {
        toast(result?.error || 'Could not reschedule', 'warning');
        return;
      }
      toast(wasClosed ? 'Inquiry rescheduled and reopened' : 'Follow-up rescheduled');
      await loadAllData();
      if (wasClosed && App.inquiryListFilter === 'closed') {
        App.inquiryListFilter = 'active';
        navigate('inquiries');
      } else {
        App.selectedInquiryId = id;
        navigate('inquiry-detail');
      }
    });
  }

  async function renderClients(el) {
    const clients = App.clients || [];
    const inquiries = App.inquiries || [];
    el.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-title">👤 Clients</div>
          <div class="page-subtitle">${clients.length} contacts</div>
        </div>
        <button class="btn btn-primary" onclick="showInquirySheet()">＋ New Inquiry</button>
      </div>
      <div class="pipeline-table-wrap card">
        ${clients.length === 0
          ? '<div class="empty-state" style="padding:32px"><div class="empty-icon">👤</div><h3>No clients yet</h3><p>Clients are created when you add inquiries.</p></div>'
          : `<table class="pipeline-table">
            <thead><tr><th>Name</th><th>Company</th><th>Mobile</th><th>Email</th><th>Inquiries</th></tr></thead>
            <tbody>
              ${clients.map((c) => {
                const count = inquiries.filter((i) => i.client_id === c.id).length;
                const active = inquiries.filter((i) => i.client_id === c.id && i.outcome_status === 'active').length;
                return `<tr class="pipeline-row" onclick="openClientInquiries('${c.id}')">
                  <td><strong>${c.name}</strong></td>
                  <td>${c.company || '—'}</td>
                  <td>${c.mobile || '—'}</td>
                  <td>${c.email || '—'}</td>
                  <td>${active} active / ${count} total</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>`}
      </div>`;
  }

  function openClientInquiries(clientId) {
    const client = (App.clients || []).find((c) => c.id === clientId);
    if (!client) return;
    App.inquiryListFilter = 'active';
    App.clientFilterId = clientId;
    navigate('inquiries');
  }

  async function changeInquiryStageConfirm(id, stageKey) {
    const result = await window.ilrs?.changeInquiryStage?.(id, stageKey, {});
    if (!result?.success) {
      toast(result?.error || 'Could not change stage', 'warning');
      return;
    }
    toast(`Stage → ${result.stage?.display || stageKey}`);
    await loadAllData();
    const inq = (App.inquiries || []).find((i) => i.id === id);
    const Q = window.ILRSLifecycleQueue;
    if (inq && Q?.inferInquiryQueueTab) {
      const tab = Q.inferInquiryQueueTab(inq);
      if (tab === 'in_process') Q.applyGlobalFilter?.('in_process');
    }
    navigate('inquiry-detail');
  }

  window.inquiryCard = inquiryCard;
  window.renderPipeline = renderPipeline;
  window.setPipelineCategory = setPipelineCategory;
  window.setPipelineStageFilter = setPipelineStageFilter;
  window.setInquiryStageFilter = setInquiryStageFilter;
  window.deleteInquiryItem = deleteInquiryItem;
  window.renderInquiries = renderInquiries;
  window.renderInquiryHub = renderInquiryHub;
  window.setInquiryHubView = setInquiryHubView;
  window.renderInquiryFollowups = renderInquiryFollowups;
  window.renderInquiryDetail = renderInquiryDetail;
  window.renderClients = renderClients;
  window.openInquiryDetail = openInquiryDetail;
  window.openClientInquiries = openClientInquiries;
  window.setInquiryFilter = setInquiryFilter;
  window.editInquiry = editInquiry;
  window.setInquiryLifecycleFromCard = setInquiryLifecycleFromCard;
  window.showInquiryLinkedTask = showInquiryLinkedTask;
  window.canConvertReminderToInquiry = canConvertReminderToInquiry;
  window.convertReminderToInquiry = convertReminderToInquiry;
  window.showStageChangeModal = showStageChangeModal;
  window.reopenInquiryConfirm = reopenInquiryConfirm;
  window.showInquiryRescheduleMenu = showInquiryRescheduleMenu;
  window.logInquiryQuick = logInquiryQuick;
  window.promptInquiryNote = promptInquiryNote;
  window.setInquiryDetailTab = setInquiryDetailTab;
  window.openInquiryConversation = openInquiryConversation;
  window.startInquiryWorkFromCard = startInquiryWorkFromCard;
})();
