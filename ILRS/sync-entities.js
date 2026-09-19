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
  family_member: {
    table: 'family_members',
    idColumn: 'id',
    applyOrder: 50,
    dependencies: [],
  },
  medicine: {
    table: 'medicines',
    idColumn: 'id',
    applyOrder: 51,
    dependencies: [],
  },
  medicine_log: {
    table: 'medicine_logs',
    idColumn: 'id',
    applyOrder: 52,
    dependencies: [{ table: 'medicines', column: 'medicine_id' }],
  },
  bill: {
    table: 'bills',
    idColumn: 'id',
    applyOrder: 53,
    dependencies: [],
  },
  bill_history: {
    table: 'bill_history',
    idColumn: 'id',
    applyOrder: 54,
    dependencies: [{ table: 'bills', column: 'bill_id' }],
  },
  habit: {
    table: 'habits',
    idColumn: 'id',
    applyOrder: 55,
    dependencies: [{ table: 'reminders', column: 'reminder_id', optional: true }],
  },
  habit_log: {
    table: 'habit_logs',
    idColumn: 'id',
    applyOrder: 56,
    dependencies: [{ table: 'habits', column: 'habit_id' }],
  },
  checklist: {
    table: 'checklists',
    idColumn: 'id',
    applyOrder: 57,
    dependencies: [],
  },
  checklist_item: {
    table: 'checklist_items',
    idColumn: 'id',
    applyOrder: 58,
    dependencies: [{ table: 'checklists', column: 'checklist_id' }],
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
