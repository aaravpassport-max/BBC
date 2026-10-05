/**
 * Work / inquiry analytics for reporting dashboard.
 */
const { loadStagesFromDb } = require('./inquiry-stage-store');

function daysBetween(fromStr, toDate = new Date()) {
  if (!fromStr) return 0;
  const from = new Date(String(fromStr).replace(' ', 'T'));
  if (Number.isNaN(from.getTime())) return 0;
  return Math.max(0, Math.floor((toDate - from) / 86400000));
}

function getWorkAnalytics(db, now = new Date()) {
  const stages = loadStagesFromDb(db);
  const inquiries = db.prepare('SELECT * FROM inquiries').all();
  const active = inquiries.filter((i) => i.outcome_status === 'active');
  const closedWon = inquiries.filter((i) => i.outcome_status === 'closed_won');
  const closedLost = inquiries.filter((i) => i.outcome_status === 'closed_lost' || (i.outcome_status !== 'active' && i.outcome_status !== 'closed_won'));

  const byStage = {};
  for (const s of stages) byStage[s.key] = { stage: s, count: 0, inquiries: [] };
  for (const inq of active) {
    const key = inq.stage_key || 'follow_up';
    if (!byStage[key]) byStage[key] = { stage: { key, display: key }, count: 0, inquiries: [] };
    byStage[key].count += 1;
    byStage[key].inquiries.push(inq);
  }

  const stageDurations = active.map((i) => ({
    id: i.id,
    client: i.client_name,
    stage: i.stage_key,
    days: daysBetween(i.stage_changed_at, now),
  })).sort((a, b) => b.days - a.days);

  const healthBreakdown = {
    healthy: active.filter((i) => i.health === 'healthy').length,
    needs_attention: active.filter((i) => i.health === 'needs_attention').length,
    at_risk: active.filter((i) => i.health === 'at_risk').length,
    stale: active.filter((i) => i.health === 'stale').length,
  };

  const totalValue = active.reduce((s, i) => s + (Number(i.quotation_amount) || Number(i.expected_value) || 0), 0);
  const wonValue = closedWon.reduce((s, i) => s + (Number(i.quotation_amount) || 0), 0);

  const today = now.toISOString().slice(0, 10);
  const followUpsOverdue = active.filter((i) => i.next_follow_up && i.next_follow_up < today).length;
  const followUpsToday = active.filter((i) => i.next_follow_up === today).length;

  const createdThisMonth = inquiries.filter((i) => (i.created_at || '').slice(0, 7) === today.slice(0, 7)).length;
  const closedThisMonth = inquiries.filter((i) =>
    i.outcome_status !== 'active' && (i.updated_at || '').slice(0, 7) === today.slice(0, 7)).length;

  const conversionRate = inquiries.length
    ? Math.round((closedWon.length / Math.max(inquiries.length - active.length, 1)) * 100)
    : 0;

  return {
    totals: {
      all: inquiries.length,
      active: active.length,
      closedWon: closedWon.length,
      closedLost: closedLost.length,
      pipelineValue: totalValue,
      wonValue,
      conversionRate,
      createdThisMonth,
      closedThisMonth,
      followUpsOverdue,
      followUpsToday,
    },
    byStage: Object.values(byStage).filter((b) => b.count > 0).sort((a, b) => (a.stage.sort || 0) - (b.stage.sort || 0)),
    healthBreakdown,
    stageDurations: stageDurations.slice(0, 15),
    stages,
  };
}

module.exports = { getWorkAnalytics, daysBetween };
