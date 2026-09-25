// ILRS — Pipeline stage admin + inquiry templates
(function () {
  const P = () => window.ILRSInquiryPipeline;
  const cats = () => P()?.STAGE_CATEGORIES || {};

  const W = () => window.ILRSWorkflowPipeline;

  const WF_CATEGORIES = {
    open: 'Open',
    active: 'Active',
    hold: 'On hold',
    closed: 'Closed',
    general: 'General',
  };

  function workflowStageHelp(entityType) {
    const label = entityType === 'task' ? 'tasks' : 'reminders';
    return `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <div style="font-weight:600;margin-bottom:8px">How workflow stages work</div>
        <ul style="margin:0;padding-left:18px;font-size:13px;color:var(--text-secondary);line-height:1.6">
          <li>Every ${label.slice(0, -1)} shows a <strong>stage badge</strong> — click it (or 🏷) to assign/change stage</li>
          <li>Pick a stage when creating or editing (✏️) an item</li>
          <li>Create custom stages below; stages can auto-schedule follow-up reminders (days)</li>
        </ul>
      </div>`;
  }

  function workflowStageTable(entityType, stages) {
    return `
      ${workflowStageHelp(entityType)}
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
        <div class="section-title">${entityType === 'task' ? '✅ Task' : '🔔 Reminder'} stages (${stages.length})</div>
        <button class="btn btn-primary btn-sm" onclick="showWorkflowStageForm('${entityType}')">＋ Add stage</button>
      </div>
      <div class="pipeline-table-wrap card" style="margin-bottom:20px">
        <table class="pipeline-table">
          <thead><tr><th>Order</th><th>Key</th><th>Name</th><th>Category</th><th>Auto-remind</th><th>Closed</th><th></th></tr></thead>
          <tbody>
            ${stages.length === 0 ? '<tr><td colspan="7" style="padding:24px;text-align:center;color:var(--text-muted)">No stages yet — click Add stage</td></tr>' : stages.map((s) => `
              <tr class="${W()?.stageClass?.(entityType, s.key) || ''}">
                <td><input type="number" class="form-input wf-stage-sort" data-type="${entityType}" data-key="${s.key}" value="${s.sort}" style="width:60px"/></td>
                <td class="pipeline-id">${s.key}</td>
                <td><input type="text" class="form-input wf-stage-display" data-type="${entityType}" data-key="${s.key}" value="${s.display}"/></td>
                <td>
                  <select class="form-select wf-stage-category" data-type="${entityType}" data-key="${s.key}" style="min-width:110px">
                    ${Object.entries(WF_CATEGORIES).map(([k, v]) =>
                      `<option value="${k}" ${s.category === k ? 'selected' : ''}>${v}</option>`
                    ).join('')}
                  </select>
                </td>
                <td><input type="number" class="form-input wf-stage-follow-days" data-type="${entityType}" data-key="${s.key}" value="${s.automation?.followUpDays || 0}" min="0" max="365" style="width:70px" title="Days until auto-reminder" /></td>
                <td>
                  <input type="checkbox" class="wf-stage-closed" data-type="${entityType}" data-key="${s.key}" ${s.closed ? 'checked' : ''} />
                </td>
                <td style="white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" onclick="showWorkflowStageForm('${entityType}','${s.key}')">Edit</button>
                  <button class="btn btn-ghost btn-sm" onclick="saveWorkflowStageRow('${entityType}','${s.key}')">Save</button>
                  <button class="btn btn-ghost btn-sm" onclick="deleteWorkflowStageRow('${entityType}','${s.key}')" title="Delete">🗑</button>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  }

  async function renderPipelineSettings(el) {
    const tab = App.workflowSettingsTab || 'inquiry';
    const result = await window.ilrs?.getPipelineStages?.();
    const stages = result?.stages || P()?.DEFAULT_STAGES || [];
    if (result?.stages) P()?.setStages?.(stages);
    const taskStages = (await window.ilrs?.getWorkflowStages?.('task'))?.stages || W()?.getStages?.('task') || [];
    const reminderStages = (await window.ilrs?.getWorkflowStages?.('reminder'))?.stages || W()?.getStages?.('reminder') || [];
    W()?.setStages?.('task', taskStages);
    W()?.setStages?.('reminder', reminderStages);

    el.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-title">🏷 Workflow Stages</div>
          <div class="page-subtitle">Create stages & assign them to inquiries, tasks, and reminders</div>
        </div>
        <button class="btn btn-ghost" onclick="navigate('pipeline')">← Pipeline</button>
      </div>
      <div class="smart-tabs" style="margin-bottom:16px">
        <button class="smart-tab ${tab === 'inquiry' ? 'active' : ''}" onclick="setWorkflowSettingsTab('inquiry')">Inquiries</button>
        <button class="smart-tab ${tab === 'task' ? 'active' : ''}" onclick="setWorkflowSettingsTab('task')">Tasks</button>
        <button class="smart-tab ${tab === 'reminder' ? 'active' : ''}" onclick="setWorkflowSettingsTab('reminder')">Reminders</button>
      </div>
      ${tab === 'inquiry' ? `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <div style="font-weight:600;margin-bottom:8px">Inquiry pipeline stages</div>
        <ul style="margin:0;padding-left:18px;font-size:13px;color:var(--text-secondary);line-height:1.6">
          <li>Create custom stages for your sales/service pipeline</li>
          <li>Changes appear in inquiry forms, pipeline view, and stage-change modal</li>
          <li>Closed stages mark inquiries as won/lost when selected</li>
        </ul>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
        <div class="section-title">📥 Inquiry stages (${stages.length})</div>
        <button class="btn btn-primary btn-sm" onclick="showInquiryStageForm()">＋ Add stage</button>
      </div>
      <div class="pipeline-table-wrap card">
        <table class="pipeline-table">
          <thead><tr><th>Order</th><th>Key</th><th>Display name</th><th>Category</th><th>Follow-up (days)</th><th>Closed</th><th></th></tr></thead>
          <tbody>
            ${stages.map((s) => `
              <tr>
                <td><input type="number" class="form-input stage-sort" data-key="${s.key}" value="${s.sort}" style="width:60px" /></td>
                <td class="pipeline-id">${s.key}</td>
                <td><input type="text" class="form-input stage-display" data-key="${s.key}" value="${s.display}" /></td>
                <td>
                  <select class="form-select stage-category" data-key="${s.key}">
                    ${Object.entries(cats()).map(([k, v]) =>
                      `<option value="${k}" ${s.category === k ? 'selected' : ''}>${v}</option>`
                    ).join('')}
                  </select>
                </td>
                <td><input type="number" class="form-input stage-follow-days" data-key="${s.key}" value="${s.automation?.followUpDays || 0}" min="0" max="365" style="width:70px" /></td>
                <td><input type="checkbox" class="stage-closed" data-key="${s.key}" ${s.closed ? 'checked' : ''} /></td>
                <td style="white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" onclick="showInquiryStageForm('${s.key}')">Edit</button>
                  <button class="btn btn-ghost btn-sm" onclick="savePipelineStage('${s.key}')">Save</button>
                  <button class="btn btn-ghost btn-sm" onclick="deleteInquiryStageRow('${s.key}')">🗑</button>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>

      <div class="page-header" style="margin-top:32px">
        <div>
          <div class="page-title">📋 Inquiry Templates</div>
          <div class="page-subtitle">Quick-start presets for new inquiries</div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="showTemplateForm()">＋ New Template</button>
      </div>
      <div class="inquiry-list" id="template-list">
        ${(App.inquiryTemplates || []).map(templateCard).join('') || '<p class="pipeline-empty">No templates yet.</p>'}
      </div>` : ''}
      ${tab === 'task' ? workflowStageTable('task', taskStages) : ''}
      ${tab === 'reminder' ? workflowStageTable('reminder', reminderStages) : ''}`;
  }

  function setWorkflowSettingsTab(tab) {
    App.workflowSettingsTab = tab;
    navigate('pipeline-settings');
  }

  async function saveWorkflowStageRow(entityType, key) {
    const stages = (await window.ilrs?.getWorkflowStages?.(entityType))?.stages || [];
    const stage = stages.find((s) => s.key === key);
    if (!stage) return;
    stage.display = document.querySelector(`.wf-stage-display[data-type="${entityType}"][data-key="${key}"]`)?.value || stage.display;
    stage.sort = parseInt(document.querySelector(`.wf-stage-sort[data-type="${entityType}"][data-key="${key}"]`)?.value, 10) || stage.sort;
    stage.category = document.querySelector(`.wf-stage-category[data-type="${entityType}"][data-key="${key}"]`)?.value || stage.category;
    stage.closed = document.querySelector(`.wf-stage-closed[data-type="${entityType}"][data-key="${key}"]`)?.checked || false;
    const followDays = parseInt(document.querySelector(`.wf-stage-follow-days[data-type="${entityType}"][data-key="${key}"]`)?.value, 10) || 0;
    stage.automation = { ...(stage.automation || {}), followUpDays: followDays, reminderEnabled: followDays > 0 };
    stage.entityType = entityType;
    const result = await window.ilrs?.saveWorkflowStage?.(stage);
    if (!result?.success) { toast(result?.error || 'Could not save', 'warning'); return; }
    W()?.setStages?.(entityType, result.stages);
    toast('Stage saved');
    await loadAllData();
    navigate('pipeline-settings');
  }

  async function showWorkflowStageForm(entityType, existingKey = null) {
    document.getElementById('wf-stage-form-modal')?.remove();
    const isEdit = !!existingKey;
    const stages = (await window.ilrs?.getWorkflowStages?.(entityType))?.stages || [];
    const existing = isEdit ? stages.find((s) => s.key === existingKey) : null;
    if (isEdit && !existing) {
      toast('Stage not found', 'warning');
      return;
    }
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'wf-stage-form-modal';
    overlay.innerHTML = `
      <div class="capture-sheet" style="width:min(420px,94vw)">
        <div class="capture-header">
          <h2>${isEdit ? 'Edit' : 'Add'} ${entityType === 'task' ? 'task' : 'reminder'} stage</h2>
          <button class="modal-close" onclick="document.getElementById('wf-stage-form-modal').remove()">✕</button>
        </div>
        <label class="form-label">Stage key (unique id)</label>
        <input type="text" class="form-input" id="wf-form-key" value="${existing?.key || ''}" ${isEdit ? 'readonly style="opacity:0.7"' : ''} placeholder="e.g. review_pending" />
        <label class="form-label" style="margin-top:8px">Display name</label>
        <input type="text" class="form-input" id="wf-form-display" value="${existing?.display || ''}" placeholder="e.g. Review Pending" />
        <label class="form-label" style="margin-top:8px">Sort order</label>
        <input type="number" class="form-input" id="wf-form-sort" value="${existing?.sort ?? (stages.reduce((m, s) => Math.max(m, s.sort || 0), 0) + 1)}" min="0" />
        <label class="form-label" style="margin-top:8px">Category</label>
        <select class="form-select" id="wf-form-category">
          ${Object.entries(WF_CATEGORIES).map(([k, v]) =>
            `<option value="${k}" ${(existing?.category || 'general') === k ? 'selected' : ''}>${v}</option>`
          ).join('')}
        </select>
        <label class="form-label" style="margin-top:8px">Auto-reminder after (days, 0 = off)</label>
        <input type="number" class="form-input" id="wf-form-follow-days" value="${existing?.automation?.followUpDays || 0}" min="0" max="365" />
        <label class="setting-row" style="margin-top:12px;padding:8px 0">
          <span class="form-label">Closed stage (marks item done)</span>
          <input type="checkbox" id="wf-form-closed" ${existing?.closed ? 'checked' : ''} />
        </label>
        <div class="capture-actions">
          <button class="btn btn-ghost" onclick="document.getElementById('wf-stage-form-modal').remove()">Cancel</button>
          <button class="btn btn-primary" id="wf-form-save">${isEdit ? 'Save changes' : 'Create stage'}</button>
        </div>
      </div>`;
    if (typeof attachModalDismiss === 'function') attachModalDismiss(overlay);
    document.body.appendChild(overlay);
    overlay.querySelector('#wf-form-save')?.addEventListener('click', async () => {
      const rawKey = (overlay.querySelector('#wf-form-key')?.value || '').trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
      const display = overlay.querySelector('#wf-form-display')?.value.trim();
      if (!rawKey || !display) {
        toast('Key and display name are required', 'warning');
        return;
      }
      const followDays = parseInt(overlay.querySelector('#wf-form-follow-days')?.value, 10) || 0;
      const result = await window.ilrs?.saveWorkflowStage?.({
        key: rawKey,
        entityType,
        display,
        category: overlay.querySelector('#wf-form-category')?.value || 'general',
        sort: parseInt(overlay.querySelector('#wf-form-sort')?.value, 10) || 0,
        closed: overlay.querySelector('#wf-form-closed')?.checked || false,
        automation: { followUpDays: followDays, reminderEnabled: followDays > 0 },
      });
      if (!result?.success) {
        toast(result?.error || 'Could not save stage', 'warning');
        return;
      }
      overlay.remove();
      W()?.setStages?.(entityType, result.stages);
      toast(isEdit ? 'Stage updated' : 'Stage created');
      App.workflowSettingsTab = entityType;
      await loadAllData();
      navigate('pipeline-settings');
    });
  }

  async function showInquiryStageForm(existingKey = null) {
    document.getElementById('inq-stage-form-modal')?.remove();
    const isEdit = !!existingKey;
    const stages = (await window.ilrs?.getPipelineStages?.())?.stages || P()?.getStages?.() || [];
    const existing = isEdit ? stages.find((s) => s.key === existingKey) : null;
    if (isEdit && !existing) {
      toast('Stage not found', 'warning');
      return;
    }
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'inq-stage-form-modal';
    overlay.innerHTML = `
      <div class="capture-sheet" style="width:min(420px,94vw)">
        <div class="capture-header">
          <h2>${isEdit ? 'Edit' : 'Add'} inquiry stage</h2>
          <button class="modal-close" onclick="document.getElementById('inq-stage-form-modal').remove()">✕</button>
        </div>
        <label class="form-label">Stage key (unique id)</label>
        <input type="text" class="form-input" id="inq-form-key" value="${existing?.key || ''}" ${isEdit ? 'readonly style="opacity:0.7"' : ''} placeholder="e.g. custom_review" />
        <label class="form-label" style="margin-top:8px">Display name</label>
        <input type="text" class="form-input" id="inq-form-display" value="${existing?.display || ''}" />
        <label class="form-label" style="margin-top:8px">Sort order</label>
        <input type="number" class="form-input" id="inq-form-sort" value="${existing?.sort ?? (stages.reduce((m, s) => Math.max(m, s.sort || 0), 0) + 1)}" min="0" />
        <label class="form-label" style="margin-top:8px">Category</label>
        <select class="form-select" id="inq-form-category">
          ${Object.entries(cats()).map(([k, v]) =>
            `<option value="${k}" ${(existing?.category || 'qualification') === k ? 'selected' : ''}>${v}</option>`
          ).join('')}
        </select>
        <label class="form-label" style="margin-top:8px">Auto follow-up after (days, 0 = off)</label>
        <input type="number" class="form-input" id="inq-form-follow-days" value="${existing?.automation?.followUpDays || 0}" min="0" max="365" />
        <label class="setting-row" style="margin-top:12px;padding:8px 0">
          <span class="form-label">Closed / lost stage</span>
          <input type="checkbox" id="inq-form-closed" ${existing?.closed ? 'checked' : ''} />
        </label>
        <div class="capture-actions">
          <button class="btn btn-ghost" onclick="document.getElementById('inq-stage-form-modal').remove()">Cancel</button>
          <button class="btn btn-primary" id="inq-form-save">${isEdit ? 'Save changes' : 'Create stage'}</button>
        </div>
      </div>`;
    if (typeof attachModalDismiss === 'function') attachModalDismiss(overlay);
    document.body.appendChild(overlay);
    overlay.querySelector('#inq-form-save')?.addEventListener('click', async () => {
      const rawKey = (overlay.querySelector('#inq-form-key')?.value || '').trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
      const display = overlay.querySelector('#inq-form-display')?.value.trim();
      if (!rawKey || !display) {
        toast('Key and display name are required', 'warning');
        return;
      }
      const followDays = parseInt(overlay.querySelector('#inq-form-follow-days')?.value, 10) || 0;
      const result = await window.ilrs?.savePipelineStage?.({
        key: rawKey,
        display,
        category: overlay.querySelector('#inq-form-category')?.value || 'qualification',
        sort: parseInt(overlay.querySelector('#inq-form-sort')?.value, 10) || 0,
        closed: overlay.querySelector('#inq-form-closed')?.checked || false,
        automation: { followUpDays: followDays, reminderEnabled: followDays > 0 },
      });
      if (!result?.success) {
        toast(result?.error || 'Could not save stage', 'warning');
        return;
      }
      overlay.remove();
      P()?.setStages?.(result.stages);
      toast(isEdit ? 'Inquiry stage updated' : 'Inquiry stage created');
      App.workflowSettingsTab = 'inquiry';
      await loadAllData();
      navigate('pipeline-settings');
    });
  }

  async function deleteInquiryStageRow(key) {
    if (!confirm(`Delete inquiry stage "${key}"?`)) return;
    let reassignTo = null;
    const tryDelete = async () => {
      const result = await window.ilrs?.deleteInquiryStage?.(key, reassignTo);
      if (result?.error === 'stage_in_use') {
        const stages = (await window.ilrs?.getPipelineStages?.())?.stages || [];
        const options = stages.filter((s) => s.key !== key).map((s) => s.key).join(', ');
        reassignTo = prompt(`${result.count} inquiry(ies) use this stage. Reassign to which stage?\nOptions: ${options}`);
        if (!reassignTo) return;
        await tryDelete();
        return;
      }
      if (!result?.success) {
        toast(result?.error || 'Could not delete', 'warning');
        return;
      }
      toast('Inquiry stage deleted');
      P()?.setStages?.(result.stages || []);
      await loadAllData();
      navigate('pipeline-settings');
    };
    await tryDelete();
  }

  async function deleteWorkflowStageRow(entityType, key) {
    if (!confirm(`Delete stage "${key}"?`)) return;
    let reassignTo = null;
    const tryDelete = async () => {
      const result = await window.ilrs?.deleteWorkflowStage?.(entityType, key, reassignTo);
      if (result?.error === 'stage_in_use') {
        const stages = (await window.ilrs?.getWorkflowStages?.(entityType))?.stages || [];
        const options = stages.filter((s) => s.key !== key).map((s) => s.key).join(', ');
        reassignTo = prompt(`${result.count} item(s) use this stage. Reassign them to which stage?\nOptions: ${options}`);
        if (!reassignTo) return;
        await tryDelete();
        return;
      }
      if (!result?.success) {
        toast(result?.error || 'Could not delete', 'warning');
        return;
      }
      toast('Stage deleted');
      W()?.setStages?.(entityType, (await window.ilrs?.getWorkflowStages?.(entityType))?.stages || []);
      await loadAllData();
      navigate('pipeline-settings');
    };
    await tryDelete();
  }

  function templateCard(t) {
    return `
      <div class="inquiry-card">
        <div class="inquiry-card-top"><strong>${t.name}</strong></div>
        <div class="inquiry-requirement">${t.requirement}</div>
        <div class="inquiry-meta">${t.service_category || ''} · Stage: ${t.stage_key}</div>
        <div style="display:flex;gap:8px;margin-top:10px">
          <button class="btn btn-primary btn-sm" onclick="useInquiryTemplate('${t.id}')">Use template</button>
          <button class="btn btn-ghost btn-sm" onclick="deleteInquiryTemplate('${t.id}')">Delete</button>
        </div>
      </div>`;
  }

  async function savePipelineStage(key) {
    const stages = (await window.ilrs?.getPipelineStages?.())?.stages || [];
    const stage = stages.find((s) => s.key === key);
    if (!stage) return;
    stage.display = document.querySelector(`.stage-display[data-key="${key}"]`)?.value || stage.display;
    stage.sort = parseInt(document.querySelector(`.stage-sort[data-key="${key}"]`)?.value, 10) || stage.sort;
    stage.category = document.querySelector(`.stage-category[data-key="${key}"]`)?.value || stage.category;
    stage.closed = document.querySelector(`.stage-closed[data-key="${key}"]`)?.checked || false;
    const followDays = parseInt(document.querySelector(`.stage-follow-days[data-key="${key}"]`)?.value, 10) || 0;
    stage.automation = { ...(stage.automation || {}), followUpDays: followDays, reminderEnabled: followDays > 0 };
    const result = await window.ilrs?.savePipelineStage?.(stage);
    if (!result?.success) {
      toast(result?.error || 'Could not save stage', 'warning');
      return;
    }
    P()?.setStages?.(result.stages);
    toast('Stage saved');
    await loadAllData();
    navigate('pipeline-settings');
  }

  function showTemplateForm() {
    const name = prompt('Template name:');
    if (!name) return;
    const requirement = prompt('Default requirement:', name) || name;
    window.ilrs?.createInquiryTemplate?.({ name, requirement }).then(async (r) => {
      if (!r?.success) { toast(r?.error || 'Failed', 'warning'); return; }
      toast('Template created');
      await loadAllData();
      navigate('pipeline-settings');
    });
  }

  function useInquiryTemplate(id) {
    const t = (App.inquiryTemplates || []).find((x) => x.id === id);
    if (!t) return;
    showInquirySheet({
      requirement: t.requirement,
      service_category: t.service_category,
      stage_key: t.stage_key,
      next_action: t.next_action,
      source: t.source,
      expected_value: t.expected_value,
      notes: t.notes,
    });
  }

  async function deleteInquiryTemplate(id) {
    if (!confirm('Delete this template?')) return;
    await window.ilrs?.deleteInquiryTemplate?.(id);
    toast('Template deleted');
    await loadAllData();
    navigate('pipeline-settings');
  }

  window.renderPipelineSettings = renderPipelineSettings;
  window.savePipelineStage = savePipelineStage;
  window.showTemplateForm = showTemplateForm;
  window.useInquiryTemplate = useInquiryTemplate;
  window.deleteInquiryTemplate = deleteInquiryTemplate;
  window.setWorkflowSettingsTab = setWorkflowSettingsTab;
  window.saveWorkflowStageRow = saveWorkflowStageRow;
  window.showWorkflowStageForm = showWorkflowStageForm;
  window.showInquiryStageForm = showInquiryStageForm;
  window.deleteWorkflowStageRow = deleteWorkflowStageRow;
  window.deleteInquiryStageRow = deleteInquiryStageRow;
})();
