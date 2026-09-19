/**
 * Phase 1 synced entities (business records).
 */
const ENTITIES = {
  client: {
    table: 'clients',
    idColumn: 'id',
    applyOrder: 10,
    dependencies: [],
  },
  inquiry: {
    table: 'inquiries',
    idColumn: 'id',
    applyOrder: 20,
    dependencies: [{ table: 'clients', column: 'client_id', optional: true }],
  },
  reminder: {
    table: 'reminders',
    idColumn: 'id',
    applyOrder: 25,
    dependencies: [{ table: 'inquiries', column: 'source_id', when: (row) => row.source_type === 'inquiry' }],
  },
  inquiry_activity: {
    table: 'inquiry_activities',
    idColumn: 'id',
    applyOrder: 30,
    dependencies: [{ table: 'inquiries', column: 'inquiry_id' }],
  },
  reminder_log: {
    table: 'reminder_logs',
    idColumn: 'id',
    applyOrder: 35,
    dependencies: [{ table: 'reminders', column: 'reminder_id' }],
  },
  work_payment: {
    table: 'work_payments',
    idColumn: 'id',
    applyOrder: 40,
    dependencies: [
      { table: 'inquiries', column: 'entity_id', when: (row) => row.entity_type === 'inquiry' },
      { table: 'reminders', column: 'entity_id', when: (row) => row.entity_type === 'reminder' },
    ],
  },
};

function getEntity(key) {
  return ENTITIES[key] || null;
}

function listEntitiesByApplyOrder() {
  return Object.entries(ENTITIES)
    .map(([key, def]) => ({ key, ...def }))
    .sort((a, b) => a.applyOrder - b.applyOrder);
}

module.exports = {
  ENTITIES,
  getEntity,
  listEntitiesByApplyOrder,
};
