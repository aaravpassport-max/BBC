/**
 * Default inquiry pipeline stages — configurable later via inquiry_stages table.
 */
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
  { key: 'follow_up', display: 'Follow Up', category: 'qualification', sort: 1, closed: false },
  { key: 'high_quality_prospect', display: 'High Quality Prospect', category: 'qualification', sort: 2, closed: false },
  { key: 'need_to_work_on_query', display: 'Need to Work on Query', category: 'qualification', sort: 3, closed: false },
  { key: 'quotation_to_be_sent', display: 'Quotation to be Sent', category: 'quotation', sort: 4, closed: false },
  { key: 'quotation_sent', display: 'Quotation Sent', category: 'quotation', sort: 5, closed: false },
  { key: 'awaiting_client_decision', display: 'Awaiting Client Decision – Pending', category: 'quotation', sort: 6, closed: false },
  { key: 'waiting_for_docs', display: 'Waiting for Docs', category: 'documentation', sort: 7, closed: false },
  { key: 'documents_received', display: 'Documents Received', category: 'documentation', sort: 8, closed: false },
  { key: 'under_review_feasibility', display: 'Under Review – Feasibility Check', category: 'feasibility', sort: 9, closed: false },
  { key: 'feasibility_check_required', display: 'Feasibility Check Required', category: 'feasibility', sort: 10, closed: false },
  { key: 'payment_received', display: 'Payment Received', category: 'payment', sort: 11, closed: false },
  { key: 'payment_received_work_not_started', display: 'Payment Received · Work Not Started', category: 'payment', sort: 12, closed: false },
  { key: 'assigned_in_progress', display: 'Assigned and In Progress', category: 'fulfilment', sort: 13, closed: false },
  { key: 'work_completed', display: 'Work Completed', category: 'fulfilment', sort: 14, closed: false },
  { key: 'delivered', display: 'Delivered', category: 'fulfilment', sort: 15, closed: false },
  { key: 'closed_no_response', display: 'No Response', category: 'closed', sort: 16, closed: true },
  { key: 'closed_high_charges', display: 'High Charges – Lost', category: 'closed', sort: 17, closed: true },
  { key: 'closed_not_able_to_serve', display: 'Not Able to Serve', category: 'closed', sort: 18, closed: true },
  { key: 'closed_late_response', display: 'Late Response – Lost', category: 'closed', sort: 19, closed: true },
  { key: 'closed_service_not_required', display: 'Service Not Required Any More', category: 'closed', sort: 20, closed: true },
];

const STAGE_FOLLOW_UP_DAYS = {
  quotation_sent: 2,
  awaiting_client_decision: 3,
  waiting_for_docs: 3,
  feasibility_check_required: 2,
};

/** Fields shown when entering a stage (stage change modal). */
const STAGE_FIELDS = {
  quotation_to_be_sent: [
    { key: 'quotationAmount', label: 'Quotation amount (₹)', type: 'number', required: false },
  ],
  quotation_sent: [
    { key: 'quotationAmount', label: 'Quotation amount (₹)', type: 'number', required: true },
  ],
  awaiting_client_decision: [
    { key: 'quotationAmount', label: 'Quotation amount (₹)', type: 'number', required: false },
  ],
  payment_received: [
    { key: 'quotationAmount', label: 'Amount received (₹)', type: 'number', required: true },
    { key: 'paymentStatus', label: 'Payment status', type: 'select', options: ['received', 'partial'], required: true },
  ],
  payment_received_work_not_started: [
    { key: 'paymentStatus', label: 'Payment status', type: 'select', options: ['received', 'partial'], required: false },
  ],
  waiting_for_docs: [
    { key: 'nextAction', label: 'Docs requested', type: 'text', required: false, placeholder: 'Ask client for passport copy' },
  ],
  documents_received: [
    { key: 'nextAction', label: 'Next step', type: 'text', required: false },
  ],
  delivered: [
    { key: 'nextAction', label: 'Delivery notes', type: 'text', required: false },
  ],
};

/** Automation rules applied on stage entry. */
const STAGE_AUTOMATION = {
  quotation_sent: { followUpDays: 2, nextAction: 'Follow up on quotation' },
  awaiting_client_decision: { followUpDays: 3, nextAction: 'Check client decision' },
  waiting_for_docs: { followUpDays: 3, nextAction: 'Remind client for documents' },
  feasibility_check_required: { followUpDays: 2, nextAction: 'Complete feasibility check' },
  payment_received: { followUpDays: 1, nextAction: 'Assign work to team' },
  assigned_in_progress: { followUpDays: 2, nextAction: 'Check work progress' },
};

const ACTIVITY_TYPES = [
  'call', 'whatsapp', 'sms', 'email', 'meeting', 'note', 'follow_up',
  'stage_change', 'quotation', 'payment', 'document_received', 'document_requested',
  'assignment', 'delivery', 'inquiry_created', 'other',
];

function getStage(key, stages = DEFAULT_STAGES) {
  return stages.find((s) => s.key === key) || stages[0];
}

function getActiveStages(stages = DEFAULT_STAGES) {
  return stages.filter((s) => !s.closed).sort((a, b) => a.sort - b.sort);
}

function getClosedStages(stages = DEFAULT_STAGES) {
  return stages.filter((s) => s.closed).sort((a, b) => a.sort - b.sort);
}

function getStagesByCategory(stages = DEFAULT_STAGES) {
  const map = {};
  for (const s of stages) {
    if (!map[s.category]) map[s.category] = [];
    map[s.category].push(s);
  }
  return map;
}

function isClosedStage(key, stages = DEFAULT_STAGES) {
  return Boolean(getStage(key, stages)?.closed);
}

function getStageFields(key, stages = DEFAULT_STAGES) {
  const fromStage = stages.find((s) => s.key === key)?.fields;
  if (fromStage?.length) return fromStage;
  return STAGE_FIELDS[key] || [];
}

function getStageAutomation(key, stages = DEFAULT_STAGES) {
  const fromStage = stages.find((s) => s.key === key)?.automation;
  if (fromStage && Object.keys(fromStage).length) return fromStage;
  const days = STAGE_FOLLOW_UP_DAYS[key];
  const base = STAGE_AUTOMATION[key] || {};
  if (days && !base.followUpDays) return { ...base, followUpDays: days };
  return base;
}

module.exports = {
  STAGE_CATEGORIES,
  DEFAULT_STAGES,
  STAGE_FOLLOW_UP_DAYS,
  STAGE_FIELDS,
  STAGE_AUTOMATION,
  ACTIVITY_TYPES,
  getStage,
  getActiveStages,
  getClosedStages,
  getStagesByCategory,
  isClosedStage,
  getStageFields,
  getStageAutomation,
};
