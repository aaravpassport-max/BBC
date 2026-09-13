// ILRS — Pipeline stage admin + inquiry templates
(function () {
  const P = () => window.ILRSInquiryPipeline;
  const cats = () => P()?.STAGE_CATEGORIES || {};

  async function renderPipelineSettings(el) {
    const result = await window.ilrs?.getPipelineStages?.();
    const stages = result?.stages || P()?.DEFAULT_STAGES || [];
    if (result?.stages) P()?.setStages?.(stages);

    el.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-title">⚙️ Pipeline Stages</div>
          <div class="page-subtitle">Configure stage names, order, and categories</div>
        </div>
        <button class="btn btn-ghost" onclick="navigate('pipeline')">← Pipeline</button>
      </div>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
        Edit display names and sort order. Stage keys are fixed; automation rules are defined per stage in the database.
      </p>
      <div class="pipeline-table-wrap card">
        <table class="pipeline-table">
          <thead><tr><th>Order</th><th>Key</th><th>Display name</th><th>Category</th><th>Closed</th><th></th></tr></thead>
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
                <td>${s.closed ? 'Yes' : 'No'}</td>
                <td><button class="btn btn-ghost btn-sm" onclick="savePipelineStage('${s.key}')">Save</button></td>
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
      </div>`;
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
})();
