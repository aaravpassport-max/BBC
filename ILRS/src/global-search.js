// ILRS — Global search across tasks, reminders, inquiries, and life modules
(function () {
  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function norm(s) {
    return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function parseTags(raw) {
    try {
      const arr = JSON.parse(raw || '[]');
      return Array.isArray(arr) ? arr.join(' ') : '';
    } catch {
      return String(raw || '');
    }
  }

  function assigneeName(id) {
    if (!id || id === 'me') return '';
    const member = (typeof App !== 'undefined' && App.family || []).find((f) => f.id === id);
    return member ? member.name : id;
  }

  function reminderStageLabel(r) {
    const entityType = r.task_type === 'task' ? 'task' : 'reminder';
    const key = r.stage_key || '';
    const W = window.ILRSWorkflowPipeline;
    if (W?.stageDisplay) return W.stageDisplay(entityType, key);
    return key;
  }

  function inquiryStageLabel(inq) {
    const P = window.ILRSInquiryPipeline;
    if (P?.getStage) return P.getStage(inq.stage_key)?.display || inq.stage_key || '';
    return inq.stage_key || '';
  }

  function workflowStatusLabel(status) {
    const labels = { pending: 'Pending', in_progress: 'In progress', postponed: 'Postponed', done: 'Done' };
    return labels[status] || status || '';
  }

  function paymentNotes(entityType, entityId) {
    const key = `${entityType}:${entityId}`;
    const payments = (typeof App !== 'undefined' && App.workPayments || {})[key] || [];
    return payments.map((p) => `${p.amount} ${p.received_date} ${p.note || ''}`).join(' ');
  }

  function fieldEntries(obj) {
    const entries = [];
    for (const [key, value] of Object.entries(obj)) {
      if (value == null || value === '') continue;
      entries.push({ field: key, text: norm(value) });
    }
    return entries;
  }

  function reminderFields(r) {
    return fieldEntries({
      title: r.title,
      notes: r.notes,
      description: r.why_it_matters,
      category: r.category,
      tags: parseTags(r.tags),
      status: r.status,
      workflow_status: workflowStatusLabel(r.workflow_status),
      stage: reminderStageLabel(r),
      assignee: assigneeName(r.assigned_to),
      work_start: r.work_start_date,
      completion: r.expected_completion_date,
      alarm: r.next_fire,
      start_date: r.start_date,
      repeat: r.repeat_type,
      payments: paymentNotes('reminder', r.id),
    });
  }

  function inquiryFields(inq) {
    return fieldEntries({
      client: inq.client_name,
      requirement: inq.requirement,
      title: `${inq.client_name} ${inq.requirement}`,
      inquiry_number: inq.inquiry_number,
      company: inq.company,
      mobile: inq.mobile,
      email: inq.email,
      notes: inq.notes,
      internal_notes: inq.internal_notes,
      tags: parseTags(inq.tags),
      stage: inquiryStageLabel(inq),
      next_action: inq.next_action,
      source: inq.source,
      category: inq.service_category,
      payment_status: inq.payment_status,
      payment_track_status: inq.payment_track_status,
      quotation: inq.quotation_amount,
      expected_value: inq.expected_value,
      work_start: inq.work_start_date,
      completion: inq.expected_completion_date,
      follow_up: inq.next_follow_up,
      payments: paymentNotes('inquiry', inq.id),
    });
  }

  function scoreMatch(query, fields) {
    const tokens = norm(query).split(' ').filter(Boolean);
    if (!tokens.length) return null;

    let score = 0;
    let bestField = '';
    let bestSnippet = '';

    for (const { field, text } of fields) {
      if (!text) continue;
      const phraseHit = text.includes(norm(query));
      const tokenHits = tokens.filter((t) => text.includes(t));
      if (!tokenHits.length && !phraseHit) continue;

      let fieldScore = tokenHits.length * 10;
      if (phraseHit) fieldScore += 30;
      if (field === 'title' || field === 'client' || field === 'requirement') fieldScore += 20;
      if (field === 'notes' || field === 'description' || field === 'internal_notes') fieldScore += 8;
      if (field === 'tags' || field === 'stage') fieldScore += 12;

      if (fieldScore > score) {
        score = fieldScore;
        bestField = field;
        const idx = text.indexOf(tokens[0]);
        if (idx >= 0) {
          const start = Math.max(0, idx - 20);
          const end = Math.min(text.length, idx + tokens[0].length + 40);
          bestSnippet = (start > 0 ? '…' : '') + text.slice(start, end) + (end < text.length ? '…' : '');
        } else {
          bestSnippet = text.slice(0, 80);
        }
      }
    }

    if (!score) return null;
    return { score, field: bestField, snippet: bestSnippet };
  }

  function highlightSnippet(snippet, query) {
    const s = esc(snippet);
    const tokens = norm(query).split(' ').filter((t) => t.length >= 2);
    let out = s;
    for (const t of tokens) {
      const re = new RegExp(`(${t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
      out = out.replace(re, '<mark>$1</mark>');
    }
    return out;
  }

  function collectDocuments() {
    const docs = [];

    (App.reminders || []).forEach((r) => {
      if (r.status === 'deleted') return;
      const fields = reminderFields(r);
      const kind = r.task_type === 'task' ? 'Task' : 'Reminder';
      docs.push({
        id: r.id,
        type: r.task_type === 'task' ? 'task' : 'reminder',
        icon: r.task_type === 'task' ? '✅' : '🔔',
        title: r.title,
        subtitle: `${kind} · ${workflowStatusLabel(r.workflow_status) || r.status}`,
        fields,
        action: () => {
          if (typeof editReminder === 'function') editReminder(r.id);
          else navigate(r.task_type === 'task' ? 'tasks' : 'reminders');
        },
      });
    });

    (App.inquiries || []).forEach((inq) => {
      if (inq.outcome_status === 'deleted') return;
      docs.push({
        id: inq.id,
        type: 'inquiry',
        icon: '📥',
        title: `${inq.client_name} — ${inq.requirement}`,
        subtitle: `Inquiry · ${inq.inquiry_number || ''} · ${inquiryStageLabel(inq)}`,
        fields: inquiryFields(inq),
        action: () => {
          App.selectedInquiryId = inq.id;
          navigate('inquiry-detail');
        },
      });
    });

    (App.clients || []).forEach((c) => {
      docs.push({
        id: c.id,
        type: 'client',
        icon: '👤',
        title: c.name,
        subtitle: `Client · ${c.company || c.mobile || 'contact'}`,
        fields: fieldEntries({ name: c.name, company: c.company, mobile: c.mobile, email: c.email, notes: c.notes }),
        action: () => { App.clientFilterId = c.id; navigate('inquiries'); },
      });
    });

    (App.medicines || []).forEach((m) => {
      docs.push({
        id: m.id,
        type: 'medicine',
        icon: '💊',
        title: m.name,
        subtitle: 'Medicine',
        fields: fieldEntries({ name: m.name, condition: m.condition, notes: m.notes }),
        action: () => navigate('medicine'),
      });
    });

    (App.bills || []).forEach((b) => {
      docs.push({
        id: b.id,
        type: 'bill',
        icon: '💸',
        title: b.name,
        subtitle: `Bill · ${b.bill_type || ''}`,
        fields: fieldEntries({ name: b.name, type: b.bill_type, notes: b.notes, account: b.account_info }),
        action: () => navigate('bills'),
      });
    });

    (App.habits || []).forEach((h) => {
      docs.push({
        id: h.id,
        type: 'habit',
        icon: '🔁',
        title: h.name,
        subtitle: `Habit · ${h.streak || 0}d streak`,
        fields: fieldEntries({ name: h.name }),
        action: () => navigate('habits'),
      });
    });

    (App.family || []).forEach((f) => {
      docs.push({
        id: f.id,
        type: 'family',
        icon: '👨‍👩‍👧',
        title: f.name,
        subtitle: f.role || 'Family',
        fields: fieldEntries({ name: f.name, role: f.role, email: f.email, phone: f.phone }),
        action: () => navigate('family'),
      });
    });

    return docs;
  }

  function searchGlobal(query, limit = 30) {
    const q = norm(query);
    if (!q) return [];

    const results = [];
    for (const doc of collectDocuments()) {
      const match = scoreMatch(q, doc.fields);
      if (!match) continue;
      results.push({
        ...doc,
        score: match.score,
        matchField: match.field,
        matchSnippet: match.snippet,
        highlightedSnippet: highlightSnippet(match.snippet, q),
      });
    }

    results.sort((a, b) => b.score - a.score);
    return results.slice(0, limit);
  }

  function matchesReminder(r, query) {
    if (!norm(query)) return true;
    return Boolean(scoreMatch(query, reminderFields(r)));
  }

  function matchesInquiry(inq, query) {
    if (!norm(query)) return true;
    return Boolean(scoreMatch(query, inquiryFields(inq)));
  }

  const FIELD_LABELS = {
    title: 'Title',
    notes: 'Notes',
    description: 'Description',
    client: 'Client',
    requirement: 'Requirement',
    tags: 'Tags',
    stage: 'Stage',
    workflow_status: 'Status',
    status: 'Status',
    payments: 'Payments',
    next_action: 'Next action',
    inquiry_number: 'Inquiry #',
    internal_notes: 'Internal notes',
  };

  window.ILRSGlobalSearch = {
    searchGlobal,
    matchesReminder,
    matchesInquiry,
    fieldLabel: (f) => FIELD_LABELS[f] || f,
  };
})();
