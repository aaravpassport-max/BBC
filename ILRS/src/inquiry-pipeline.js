// ILRS — Inquiry pipeline stages (renderer, synced from DB)
(function () {
  const STAGE_CATEGORIES = {
    qualification: 'New / Qualification',
    quotation: 'Quotation',
    documentation: 'Documentation',
    feasibility: 'Feasibility',
    payment: 'Payment',
    fulfilment: 'Fulfilment',
    closed: 'Closed / Lost',
  };

  const DEFAULT_STAGES = [
    { key: 'follow_up', display: 'Follow Up', category: 'qualification', sort: 1, closed: false, fields: [], automation: {} },
    { key: 'high_quality_prospect', display: 'High Quality Prospect', category: 'qualification', sort: 2, closed: false, fields: [], automation: {} },
    { key: 'need_to_work_on_query', display: 'Need to Work on Query', category: 'qualification', sort: 3, closed: false, fields: [], automation: {} },
    { key: 'quotation_to_be_sent', display: 'Quotation to be Sent', category: 'quotation', sort: 4, closed: false, fields: [], automation: {} },
    { key: 'quotation_sent', display: 'Quotation Sent', category: 'quotation', sort: 5, closed: false, fields: [], automation: {} },
    { key: 'awaiting_client_decision', display: 'Awaiting Client Decision – Pending', category: 'quotation', sort: 6, closed: false, fields: [], automation: {} },
    { key: 'waiting_for_docs', display: 'Waiting for Docs', category: 'documentation', sort: 7, closed: false, fields: [], automation: {} },
    { key: 'documents_received', display: 'Documents Received', category: 'documentation', sort: 8, closed: false, fields: [], automation: {} },
    { key: 'under_review_feasibility', display: 'Under Review – Feasibility Check', category: 'feasibility', sort: 9, closed: false, fields: [], automation: {} },
    { key: 'feasibility_check_required', display: 'Feasibility Check Required', category: 'feasibility', sort: 10, closed: false, fields: [], automation: {} },
    { key: 'payment_received', display: 'Payment Received', category: 'payment', sort: 11, closed: false, fields: [], automation: {} },
    { key: 'payment_received_work_not_started', display: 'Payment Received · Work Not Started', category: 'payment', sort: 12, closed: false, fields: [], automation: {} },
    { key: 'assigned_in_progress', display: 'Assigned and In Progress', category: 'fulfilment', sort: 13, closed: false, fields: [], automation: {} },
    { key: 'work_completed', display: 'Work Completed', category: 'fulfilment', sort: 14, closed: false, fields: [], automation: {} },
    { key: 'delivered', display: 'Delivered', category: 'fulfilment', sort: 15, closed: false, fields: [], automation: {} },
    { key: 'closed_no_response', display: 'No Response', category: 'closed', sort: 16, closed: true, fields: [], automation: {} },
    { key: 'closed_high_charges', display: 'High Charges – Lost', category: 'closed', sort: 17, closed: true, fields: [], automation: {} },
    { key: 'closed_not_able_to_serve', display: 'Not Able to Serve', category: 'closed', sort: 18, closed: true, fields: [], automation: {} },
    { key: 'closed_late_response', display: 'Late Response – Lost', category: 'closed', sort: 19, closed: true, fields: [], automation: {} },
    { key: 'closed_service_not_required', display: 'Service Not Required Any More', category: 'closed', sort: 20, closed: true, fields: [], automation: {} },
  ];

  let activeStages = [...DEFAULT_STAGES];

  const SOURCE_CHIPS = ['Phone', 'WhatsApp', 'Website', 'Email', 'Walk-in', 'Referral', 'Existing Client', 'Social Media', 'Other'];

  const STAGE_CATEGORY_COLORS = {
    qualification: { bg: 'rgba(59, 130, 246, 0.16)', border: '#3b82f6' },
    quotation: { bg: 'rgba(168, 85, 247, 0.16)', border: '#a855f7' },
    documentation: { bg: 'rgba(14, 165, 233, 0.16)', border: '#0ea5e9' },
    feasibility: { bg: 'rgba(234, 179, 8, 0.16)', border: '#eab308' },
    payment: { bg: 'rgba(34, 197, 94, 0.16)', border: '#22c55e' },
    fulfilment: { bg: 'rgba(20, 184, 166, 0.16)', border: '#14b8a6' },
    closed: { bg: 'rgba(100, 116, 139, 0.16)', border: '#64748b' },
  };

  function stageCategoryClass(key) {
    const cat = getStage(key)?.category || 'qualification';
    return `stage-cat-${cat}`;
  }

  function getStageStyle(key) {
    const cat = getStage(key)?.category || 'qualification';
    return STAGE_CATEGORY_COLORS[cat] || STAGE_CATEGORY_COLORS.qualification;
  }

  function setStages(stages) {
    if (stages?.length) activeStages = stages;
  }

  function getStage(key) {
    return activeStages.find((s) => s.key === key) || activeStages[0];
  }

  function getActiveStages() {
    return activeStages.filter((s) => !s.closed).sort((a, b) => a.sort - b.sort);
  }

  function getClosedStages() {
    return activeStages.filter((s) => s.closed).sort((a, b) => a.sort - b.sort);
  }

  function isClosedStage(key) {
    return Boolean(getStage(key)?.closed);
  }

  function getStageFields(key) {
    return getStage(key)?.fields || [];
  }

  function getStageAutomation(key) {
    return getStage(key)?.automation || {};
  }

  function healthLabel(health) {
    const map = {
      healthy: 'Healthy',
      needs_attention: 'Needs Attention',
      at_risk: 'At Risk',
      stale: 'Stale',
      closed: 'Closed',
    };
    return map[health] || 'Healthy';
  }

  function healthClass(health) {
    return `health-${health || 'healthy'}`;
  }

  window.ILRSInquiryPipeline = {
    STAGE_CATEGORIES,
    DEFAULT_STAGES,
    SOURCE_CHIPS,
    setStages,
    getStage,
    getActiveStages,
    getClosedStages,
    isClosedStage,
    getStageFields,
    getStageAutomation,
    healthLabel,
    healthClass,
    STAGE_CATEGORY_COLORS,
    stageCategoryClass,
    getStageStyle,
  };
})();
