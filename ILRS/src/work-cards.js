// ILRS — Operational cards (current state only — history lives in Activity tab)
(function () {
  function operationalStateLabel(inq) {
    const LC = window.ILRSWorkLifecycle;
    const tab = LC?.inferInquiryQueueTab ? LC.inferInquiryQueueTab(inq) : null;
    const lc = LC?.inferLifecycleFromInquiry ? LC.inferLifecycleFromInquiry(inq) : 'active';
    if (tab === 'active' && lc === 'active') return 'New enquiry';
    if (tab === 'in_process') return 'In process';
    if (lc === 'pending') return 'On hold';
    if (lc === 'completed') return 'Done';
    if (lc === 'closed') return 'Closed';
    return 'Active';
  }

  function operationalStateClass(inq) {
    const LC = window.ILRSWorkLifecycle;
    const tab = LC?.inferInquiryQueueTab ? LC.inferInquiryQueueTab(inq) : null;
    const lc = LC?.inferLifecycleFromInquiry ? LC.inferLifecycleFromInquiry(inq) : 'active';
    if (tab === 'in_process') return 'state-in_process';
    if (tab === 'active' && lc === 'active') return 'state-new';
    return `state-${lc}`;
  }

  function latestLineHtml(inq) {
    const A = window.ILRSActivity;
    const line = A?.latestActivityLine ? A.latestActivityLine(inq) : (inq?.last_activity_summary || '');
    if (!line) return '';
    return `<div class="card-latest-update" title="Latest activity">${line}</div>`;
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
    nextActionBlock,
  };
})();
