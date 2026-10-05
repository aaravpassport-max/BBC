/**
 * Global search scoring logic tests (mirrors global-search.js behaviour)
 */
const assert = require('assert');

function norm(s) {
  return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
}

function fieldEntries(obj) {
  const entries = [];
  for (const [key, value] of Object.entries(obj)) {
    if (value == null || value === '') continue;
    entries.push({ field: key, text: norm(value) });
  }
  return entries;
}

function scoreMatch(query, fields) {
  const tokens = norm(query).split(' ').filter(Boolean);
  if (!tokens.length) return null;
  let score = 0;
  for (const { text } of fields) {
    if (!text) continue;
    const tokenHits = tokens.filter((t) => text.includes(t));
    if (!tokenHits.length) continue;
    score += tokenHits.length * 10;
  }
  return score ? { score } : null;
}

const reminderFields = fieldEntries({
  title: 'Call John about contract',
  notes: 'Discuss renewal terms and pricing',
  tags: 'work urgent',
  stage: 'In Progress',
});

assert.ok(scoreMatch('john', reminderFields), 'title match');
assert.ok(scoreMatch('renewal', reminderFields), 'notes match');
assert.ok(scoreMatch('urgent', reminderFields), 'tags match');
assert.ok(scoreMatch('progress', reminderFields), 'stage match');
assert.ok(!scoreMatch('nonexistent', reminderFields), 'no false positive');

const inquiryFields = fieldEntries({
  client: 'Raj Kumar',
  requirement: 'Birth Certificate',
  notes: 'Needs apostille by next month',
});

assert.ok(scoreMatch('raj', inquiryFields));
assert.ok(scoreMatch('apostille', inquiryFields));
assert.ok(scoreMatch('birth certificate', inquiryFields));

console.log('✅ test-global-search passed');
