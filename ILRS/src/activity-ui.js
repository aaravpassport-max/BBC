// ILRS — Structured inquiry activity / conversation timeline
(function () {
  const ACTIVITY_TYPES = [
    { id: 'client_contact', label: 'Client contacted us', icon: '📥' },
    { id: 'outbound_call', label: 'We called client', icon: '📞' },
    { id: 'inbound_call', label: 'Client called', icon: '📲' },
    { id: 'whatsapp', label: 'WhatsApp', icon: '💬' },
    { id: 'email', label: 'Email', icon: '✉️' },
    { id: 'documents_requested', label: 'Documents requested', icon: '📄' },
    { id: 'documents_received', label: 'Documents received', icon: '📁' },
    { id: 'vendor_contact', label: 'Vendor contacted', icon: '🏢' },
    { id: 'vendor_update', label: 'Vendor update', icon: '📦' },
    { id: 'quotation_prepared', label: 'Quotation prepared', icon: '📝' },
    { id: 'quotation_sent', label: 'Quotation sent', icon: '📤' },
    { id: 'client_response', label: 'Client responded', icon: '💬' },
    { id: 'follow_up_done', label: 'Follow-up completed', icon: '✓' },
    { id: 'status_change', label: 'Status changed', icon: '🔄' },
    { id: 'stage_change', label: 'Stage changed', icon: '🏷' },
    { id: 'internal_note', label: 'Internal note', icon: '📌' },
    { id: 'note', label: 'Note', icon: '🗒' },
    { id: 'task_created', label: 'Task created', icon: '➕' },
    { id: 'task_completed', label: 'Task completed', icon: '✅' },
    { id: 'reminder_created', label: 'Reminder created', icon: '🔔' },
    { id: 'reminder_completed', label: 'Reminder completed', icon: '🔕' },
    { id: 'payment', label: 'Payment', icon: '💰' },
    { id: 'call', label: 'Call (quick)', icon: '📞' },
  ];

  function typeMeta(typeId) {
    return ACTIVITY_TYPES.find((t) => t.id === typeId) || { id: typeId, label: typeId, icon: '•' };
  }

  function formatActivityTime(createdAt) {
    if (!createdAt) return '';
    const d = String(createdAt).replace(' ', 'T');
    const date = d.slice(0, 10);
    const time = d.slice(11, 16);
    return typeof formatDate === 'function' ? `${formatDate(date)} · ${time}` : `${date} · ${time}`;
  }

  function renderTimeline(activities, { emptyText = 'No activity recorded yet.', compact = false } = {}) {
    if (!activities?.length) {
      return `<p class="activity-empty">${emptyText}</p>`;
    }
    const cls = compact ? 'activity-timeline activity-timeline-compact' : 'activity-timeline';
    return `<div class="${cls}">
      ${activities.map((a) => {
        const meta = typeMeta(a.activity_type);
        return `<article class="activity-item" data-activity-type="${a.activity_type || ''}">
          <div class="activity-marker" aria-hidden="true">${meta.icon}</div>
          <div class="activity-content">
            <div class="activity-time">${formatActivityTime(a.created_at)}</div>
            <div class="activity-title">${a.title || meta.label}</div>
            ${a.body ? `<div class="activity-body">${a.body}</div>` : ''}
            ${a.new_stage_key && a.old_stage_key !== a.new_stage_key
              ? `<div class="activity-meta">Stage: ${a.old_stage_key || '—'} → ${a.new_stage_key}</div>` : ''}
          </div>
        </article>`;
      }).join('')}
    </div>`;
  }

  function latestActivityLine(inq) {
    const summary = String(inq?.last_activity_summary || '').trim();
    if (summary) return summary;
    return '';
  }

  function showLogActivityModal(inquiryId) {
    const existing = document.getElementById('activity-log-modal');
    existing?.remove();
    const options = ACTIVITY_TYPES.filter((t) => !['call'].includes(t.id) || t.id === 'call').map((t) =>
      `<option value="${t.id}">${t.icon} ${t.label}</option>`,
    ).join('');
    const overlay = document.createElement('div');
    overlay.id = 'activity-log-modal';
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal-card activity-log-card" role="dialog" aria-labelledby="activity-log-title">
        <h3 id="activity-log-title">Log activity</h3>
        <p class="form-hint">Record what happened — it appears on the timeline for everyone viewing this enquiry.</p>
        <label class="form-label">Type</label>
        <select id="activity-log-type" class="form-select">${options}</select>
        <label class="form-label">Summary</label>
        <input id="activity-log-title-input" class="form-input" placeholder="Short title" />
        <label class="form-label">Details (optional)</label>
        <textarea id="activity-log-body" class="form-input" rows="4" placeholder="What was said, agreed, or done?"></textarea>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('activity-log-modal')?.remove()">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="ILRSActivity.submitLog('${inquiryId}')">Save to timeline</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
    const typeEl = overlay.querySelector('#activity-log-type');
    typeEl.addEventListener('change', () => {
      const meta = typeMeta(typeEl.value);
      const titleInput = overlay.querySelector('#activity-log-title-input');
      if (titleInput && !titleInput.value.trim()) titleInput.value = meta.label;
    });
    typeEl.dispatchEvent(new Event('change'));
  }

  async function submitLog(inquiryId) {
    const overlay = document.getElementById('activity-log-modal');
    const type = overlay?.querySelector('#activity-log-type')?.value || 'note';
    const title = overlay?.querySelector('#activity-log-title-input')?.value?.trim();
    const body = overlay?.querySelector('#activity-log-body')?.value?.trim() || '';
    const meta = typeMeta(type);
    const finalTitle = title || meta.label;
    const result = await window.ilrs?.logInquiryActivity?.(inquiryId, type, finalTitle, body);
    if (!result?.success) {
      if (typeof toast === 'function') toast(result?.error || 'Could not save activity', 'warning');
      return;
    }
    overlay?.remove();
    if (typeof toast === 'function') toast('Activity recorded');
    if (typeof loadAllData === 'function') await loadAllData();
    if (App.currentPage === 'inquiry-detail' && App.selectedInquiryId === inquiryId) {
      navigate('inquiry-detail');
    }
  }

  async function quickLog(inquiryId, typeId) {
    const meta = typeMeta(typeId);
    const result = await window.ilrs?.logInquiryActivity?.(inquiryId, typeId, meta.label, '');
    if (result?.success) {
      if (typeof toast === 'function') toast('Activity recorded');
      if (typeof loadAllData === 'function') await loadAllData();
      if (App.currentPage === 'inquiry-detail') navigate('inquiry-detail');
    }
  }

  window.ILRSActivity = {
    ACTIVITY_TYPES,
    typeMeta,
    renderTimeline,
    latestActivityLine,
    showLogActivityModal,
    submitLog,
    quickLog,
  };
})();
