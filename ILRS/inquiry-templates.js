/**
 * Inquiry templates — quick-start presets for common services.
 */
const { randomUUID } = require('crypto');

const DEFAULT_TEMPLATES = [
  { name: 'Birth Certificate', requirement: 'Birth Certificate Retrieval', serviceCategory: 'Documentation', stageKey: 'follow_up', nextAction: 'Collect client details', source: 'Phone' },
  { name: 'Passport Service', requirement: 'Passport Application / Renewal', serviceCategory: 'Documentation', stageKey: 'follow_up', nextAction: 'Share document checklist', source: 'Walk-in' },
  { name: 'Visa Application', requirement: 'Visa Processing', serviceCategory: 'Documentation', stageKey: 'quotation_to_be_sent', nextAction: 'Send quotation', source: 'Website' },
  { name: 'Company Registration', requirement: 'Business Registration', serviceCategory: 'Compliance', stageKey: 'high_quality_prospect', nextAction: 'Schedule consultation', source: 'Referral' },
];

function seedDefaultTemplates(db) {
  const count = db.prepare('SELECT COUNT(*) as c FROM inquiry_templates').get()?.c || 0;
  if (count > 0) return;
  const insert = db.prepare(`
    INSERT INTO inquiry_templates (id, name, requirement, service_category, stage_key, next_action, source, expected_value, notes, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `);
  DEFAULT_TEMPLATES.forEach((t, i) => {
    insert.run(randomUUID(), t.name, t.requirement, t.serviceCategory, t.stageKey, t.nextAction, t.source, 0, '', i + 1);
  });
}

function listTemplates(db) {
  return db.prepare('SELECT * FROM inquiry_templates ORDER BY sort_order, name').all();
}

function createTemplate(db, data) {
  const id = randomUUID();
  db.prepare(`
    INSERT INTO inquiry_templates (id, name, requirement, service_category, stage_key, next_action, source, expected_value, notes, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    id,
    data.name,
    data.requirement || '',
    data.serviceCategory || '',
    data.stageKey || 'follow_up',
    data.nextAction || 'Follow up',
    data.source || '',
    parseFloat(data.expectedValue) || 0,
    data.notes || '',
    parseInt(data.sortOrder, 10) || 99,
  );
  return { success: true, template: db.prepare('SELECT * FROM inquiry_templates WHERE id = ?').get(id) };
}

function deleteTemplate(db, id) {
  db.prepare('DELETE FROM inquiry_templates WHERE id = ?').run(id);
  return { success: true };
}

module.exports = {
  seedDefaultTemplates,
  listTemplates,
  createTemplate,
  deleteTemplate,
  DEFAULT_TEMPLATES,
};
