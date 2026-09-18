// ILRS — Operational cards (current state only — history lives in Activity tab)
(function () {
  function operationalStateLabel(inq) {
    const op = String(inq?.operational_state || '').trim();
    const map = {
      waiting_us: 'Waiting on us',
      waiting_client: 'Waiting on client',
      waiting_vendor: 'Waiting on vendor',
      follow_up_required: 'Follow-up required',
      in_progress: 'In progress',
      new: 'New',
    };
    if (op && map[op]) return map[op];
    const LC = window.ILRSWorkLifecycle;
    const lc = LC?.inferLifecycleFromInquiry ? LC.inferLifecycleFromInquiry(inq) : 'active';
    if (lc === 'pending') return 'On hold';
    if (lc === 'completed') return 'Done';
    if (lc === 'closed') return 'Closed';
    return 'Active';
  }

  function operationalStateClass(inq) {
    const op = inq?.operational_state || '';
    if (op.startsWith('waiting_')) return 'state-waiting';
    const LC = window.ILRSWorkLifecycle;
    const lc = LC?.inferLifecycleFromInquiry ? LC.inferLifecycleFromInquiry(inq) : 'active';
    return `state-${lc}`;
  }

  function latestLineHtml(inq) {
    const A = window.ILRSActivity;
    const line = A?.latestActivityLine ? A.latestActivityLine(inq) : (inq?.last_activity_summary || '');
    if (!line) return '';
    return `<div class="card-latest-update" title="Latest activity">${line}</div>`;
  }

  function currentNoteHtml(inq) {
    const note = String(inq?.notes || '').trim();
    if (!note) return '';
    const short = note.length > 120 ? `${note.slice(0, 117)}…` : note;
    return `<div class="card-current-note" title="${note.replace(/"/g, '&quot;')}">${short}</div>`;
  }

  function nextActionBlock(inq) {
    const when = inq.next_follow_up
      ? `${typeof formatDate === 'function' ? formatDate(inq.next_follow_up) : inq.next_follow_up}${inq.next_follow_up_time ? ' · ' + formatTime(inq.next_follow_up_time) : ''}`
      : 'No follow-up scheduled';
    return `<div class="card-next-block">
      <div class="card-next-action">${inq.next_action || 'Follow up'}</div>
      <div class="card-next-when">${when}</div>
    </div>`;
  }

  window.ILRSWorkCards = {
    operationalStateLabel,
    operationalStateClass,
    latestLineHtml,
    currentNoteHtml,
    nextActionBlock,
  };
})();
