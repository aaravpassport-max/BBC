// ILRS — Client conversation history (chronological thread on inquiry_activities)
(function () {
  const CONVERSATION_TYPES = [
    { id: 'conversation', label: 'Conversation note', icon: '💬' },
    { id: 'outbound_call', label: 'We called client', icon: '📞' },
    { id: 'inbound_call', label: 'Client called', icon: '📲' },
    { id: 'whatsapp', label: 'WhatsApp', icon: '💬' },
    { id: 'email', label: 'Email', icon: '✉️' },
    { id: 'client_contact', label: 'Client contacted us', icon: '📥' },
    { id: 'client_response', label: 'Client responded', icon: '↩️' },
    { id: 'meeting', label: 'Meeting / visit', icon: '🤝' },
    { id: 'documents_requested', label: 'Documents requested', icon: '📄' },
    { id: 'documents_received', label: 'Documents received', icon: '📁' },
    { id: 'quotation_sent', label: 'Quotation / proposal sent', icon: '📤' },
    { id: 'vendor_contact', label: 'Vendor / third party', icon: '🏢' },
    { id: 'follow_up_done', label: 'Follow-up done', icon: '✓' },
  ];

  const SYSTEM_TYPES = new Set([
    'stage_change', 'status_change', 'reminder_created', 'reminder_completed',
    'task_created', 'task_completed', 'payment', 'inquiry_created', 'follow_up', 'note',
  ]);

  const ACTIVITY_TYPES = [
    ...CONVERSATION_TYPES,
    { id: 'internal_note', label: 'Internal only', icon: '📌' },
    { id: 'stage_change', label: 'Stage changed', icon: '🏷' },
    { id: 'status_change', label: 'Status changed', icon: '🔄' },
    { id: 'note', label: 'Note', icon: '🗒' },
    { id: 'call', label: 'Call (quick)', icon: '📞' },
  ];

  function typeMeta(typeId) {
    return ACTIVITY_TYPES.find((t) => t.id === typeId)
      || CONVERSATION_TYPES.find((t) => t.id === typeId)
      || { id: typeId, label: typeId.replace(/_/g, ' '), icon: '•' };
  }

  function formatActivityTime(createdAt) {
    if (!createdAt) return '';
    const d = String(createdAt).replace(' ', 'T');
    const date = d.slice(0, 10);
    const time = d.slice(11, 16);
    const dateLabel = typeof formatDate === 'function' ? formatDate(date) : date;
    return `${dateLabel} · ${time}`;
  }

  function isSystemEvent(activity) {
    const t = activity?.activity_type || '';
    if (SYSTEM_TYPES.has(t)) return true;
    if (t === 'stage_change' || t === 'status_change') return true;
    return false;
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  /** Chronological thread (oldest → newest), chat-style. */
  function renderConversationThread(activities, { emptyText } = {}) {
    const list = (activities || []).slice().sort((a, b) => {
      const ta = String(a.created_at || '');
      const tb = String(b.created_at || '');
      return ta.localeCompare(tb);
    });
    if (!list.length) {
      return `<div class="conversation-empty">
        <p>${emptyText || 'No conversation yet.'}</p>
        <p class="form-hint">Record each call, message, or update below — the full history stays here for the whole team.</p>
      </div>`;
    }
    return `<div class="conversation-thread" role="log" aria-label="Client conversation history">
      ${list.map((a) => {
        const meta = typeMeta(a.activity_type);
        const system = isSystemEvent(a);
        const body = a.body ? escapeHtml(a.body) : '';
        const title = escapeHtml(a.title || meta.label);
        const cls = system ? 'conversation-entry conversation-entry-system' : 'conversation-entry conversation-entry-client';
        return `<article class="${cls}" data-activity-type="${escapeHtml(a.activity_type || '')}">
          <div class="conversation-entry-meta">
            <span class="conversation-entry-icon" aria-hidden="true">${meta.icon}</span>
            <span class="conversation-entry-type">${escapeHtml(meta.label)}</span>
            <time class="conversation-entry-time" datetime="${escapeHtml(a.created_at || '')}">${formatActivityTime(a.created_at)}</time>
          </div>
          <div class="conversation-bubble">
            ${!system || title !== meta.label ? `<div class="conversation-bubble-title">${title}</div>` : ''}
            ${body ? `<div class="conversation-bubble-body">${body}</div>` : ''}
            ${a.new_stage_key && a.old_stage_key !== a.new_stage_key
              ? `<div class="conversation-bubble-meta">Stage: ${escapeHtml(a.old_stage_key)} → ${escapeHtml(a.new_stage_key)}</div>` : ''}
          </div>
        </article>`;
      }).join('')}
    </div>`;
  }

  function renderConversationComposer(inquiryId) {
    const options = CONVERSATION_TYPES.map((t) =>
      `<option value="${t.id}">${t.icon} ${t.label}</option>`,
    ).join('');
    return `<div class="conversation-composer card">
      <div class="conversation-composer-title">Add to conversation</div>
      <p class="form-hint">What was said or agreed? This is saved with date and time for everyone on this enquiry.</p>
      <label class="form-label">Interaction type</label>
      <select id="conversation-entry-type" class="form-select">${options}</select>
      <label class="form-label">Message</label>
      <textarea id="conversation-entry-body" class="form-input conversation-entry-input" rows="4"
        placeholder="e.g. Client asked for revised quotation. Promised to send by Friday 5pm."></textarea>
      <div class="conversation-composer-actions">
        <button type="button" class="btn btn-primary" onclick="ILRSActivity.submitConversation('${inquiryId}')">Post to conversation</button>
      </div>
    </div>`;
  }

  function renderConversationPanel(inquiryId, activities) {
    return `${renderConversationThread(activities)}
      ${renderConversationComposer(inquiryId)}`;
  }

  function renderTimeline(activities, opts = {}) {
    if (opts.compact) {
      const recent = (activities || []).slice().sort((a, b) =>
        String(b.created_at || '').localeCompare(String(a.created_at || '')),
      ).slice(0, 3);
      return renderConversationThread(recent.reverse(), {
        emptyText: opts.emptyText || '',
      });
    }
    return renderConversationThread(activities, opts);
  }

  function latestActivityLine(inq) {
    const summary = String(inq?.last_activity_summary || '').trim();
    if (summary) return summary;
    return '';
  }

  function showLogActivityModal(inquiryId) {
    showAddConversationModal(inquiryId);
  }

  function showAddConversationModal(inquiryId) {
    const existing = document.getElementById('activity-log-modal');
    existing?.remove();
    const options = CONVERSATION_TYPES.map((t) =>
      `<option value="${t.id}">${t.icon} ${t.label}</option>`,
    ).join('');
    const overlay = document.createElement('div');
    overlay.id = 'activity-log-modal';
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal-card activity-log-card conversation-modal" role="dialog">
        <h3>Add conversation entry</h3>
        <label class="form-label">Type</label>
        <select id="activity-log-type" class="form-select">${options}</select>
        <label class="form-label">Message</label>
        <textarea id="activity-log-body" class="form-input" rows="5" placeholder="What was discussed or agreed?"></textarea>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('activity-log-modal')?.remove()">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="ILRSActivity.submitConversation('${inquiryId}', true)">Post</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  }

  async function submitConversation(inquiryId, fromModal = false) {
    const type = fromModal
      ? (document.getElementById('activity-log-type')?.value || 'conversation')
      : (document.getElementById('conversation-entry-type')?.value || 'conversation');
    const body = fromModal
      ? (document.getElementById('activity-log-body')?.value?.trim() || '')
      : (document.getElementById('conversation-entry-body')?.value?.trim() || '');
    if (!body) {
      if (typeof toast === 'function') toast('Please enter what was said or done', 'warning');
      return;
    }
    const meta = typeMeta(type);
    const title = meta.label;
    const result = await window.ilrs?.logInquiryActivity?.(inquiryId, type, title, body);
    if (!result?.success) {
      if (typeof toast === 'function') toast(result?.error || 'Could not save', 'warning');
      return;
    }
    document.getElementById('activity-log-modal')?.remove();
    const input = document.getElementById('conversation-entry-body');
    if (input) input.value = '';
    if (typeof toast === 'function') toast('Conversation saved');
    App.inquiryDetailTab = 'conversation';
    if (typeof loadAllData === 'function') await loadAllData();
    if (App.currentPage === 'inquiry-detail' && App.selectedInquiryId === inquiryId) {
      navigate('inquiry-detail');
    }
  }

  async function submitLog(inquiryId) {
    return submitConversation(inquiryId, true);
  }

  async function quickLog(inquiryId, typeId) {
    showAddConversationModal(inquiryId);
    const typeEl = document.getElementById('activity-log-type');
    if (typeEl) typeEl.value = typeId;
  }

  window.ILRSActivity = {
    ACTIVITY_TYPES,
    CONVERSATION_TYPES,
    typeMeta,
    renderTimeline,
    renderConversationThread,
    renderConversationPanel,
    renderConversationComposer,
    latestActivityLine,
    showLogActivityModal,
    showAddConversationModal,
    submitLog,
    submitConversation,
    quickLog,
  };
})();
