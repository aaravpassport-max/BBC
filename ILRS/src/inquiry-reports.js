// ILRS — Work / inquiry analytics dashboard
(function () {
  function barRow(label, count, max, color = 'var(--accent)') {
    const pct = max > 0 ? Math.round((count / max) * 100) : 0;
    return `
      <div class="analytics-row">
        <div class="analytics-label">${label}</div>
        <div class="analytics-bar-wrap"><div class="analytics-bar" style="width:${pct}%;background:${color}"></div></div>
        <div class="analytics-count">${count}</div>
      </div>`;
  }

  async function renderWorkReports(el) {
    const result = await window.ilrs?.getWorkAnalytics?.();
    const a = result?.analytics;
    if (!a) {
      el.innerHTML = '<div class="empty-state"><h3>Could not load analytics</h3></div>';
      return;
    }
    const t = a.totals;
    const maxStage = Math.max(...a.byStage.map((b) => b.count), 1);
    const health = a.healthBreakdown;

    el.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-title">📈 Work Analytics</div>
          <div class="page-subtitle">Pipeline funnel, health, and performance</div>
        </div>
        <button class="btn btn-ghost" onclick="navigate('pipeline')">Pipeline</button>
      </div>

      <div class="stats-bar" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
        <div class="stat-card"><div class="stat-value">${t.active}</div><div class="stat-label">Active</div></div>
        <div class="stat-card green"><div class="stat-value">${t.closedWon}</div><div class="stat-label">Won</div></div>
        <div class="stat-card red"><div class="stat-value">${t.closedLost}</div><div class="stat-label">Lost</div></div>
        <div class="stat-card orange"><div class="stat-value">${t.conversionRate}%</div><div class="stat-label">Win rate</div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="card">
          <div class="section-title">📊 Pipeline funnel (by stage)</div>
          <div style="margin-top:12px">
            ${a.byStage.length ? a.byStage.map((b) =>
              barRow(b.stage.display || b.stage.key, b.count, maxStage)
            ).join('') : '<p class="pipeline-empty">No active inquiries</p>'}
          </div>
        </div>

        <div class="card">
          <div class="section-title">❤️ Inquiry health</div>
          <div style="margin-top:12px">
            ${barRow('Healthy', health.healthy, t.active, 'var(--success)')}
            ${barRow('Needs attention', health.needs_attention, t.active, 'var(--warning)')}
            ${barRow('At risk', health.at_risk, t.active, 'var(--critical)')}
            ${barRow('Stale', health.stale, t.active, '#94a3b8')}
          </div>
        </div>

        <div class="card">
          <div class="section-title">💰 Pipeline value</div>
          <div style="font-size:36px;font-weight:700;font-family:var(--font-mono);margin:12px 0">
            ₹${Number(t.pipelineValue).toLocaleString('en-IN')}
          </div>
          <div style="font-size:13px;color:var(--text-muted)">Won value: ₹${Number(t.wonValue).toLocaleString('en-IN')}</div>
          <div style="font-size:13px;color:var(--text-muted);margin-top:8px">
            ${t.createdThisMonth} new this month · ${t.closedThisMonth} closed this month
          </div>
        </div>

        <div class="card">
          <div class="section-title">📞 Follow-ups</div>
          <div style="margin-top:12px">
            <div class="analytics-stat-line"><span>Overdue</span><strong style="color:var(--critical)">${t.followUpsOverdue}</strong></div>
            <div class="analytics-stat-line"><span>Due today</span><strong style="color:var(--warning)">${t.followUpsToday}</strong></div>
          </div>
        </div>
      </div>

      ${a.stageDurations.length ? `
      <div class="card" style="margin-top:16px">
        <div class="section-title">⏱ Longest in current stage</div>
        <div class="pipeline-table-wrap" style="margin-top:12px;border:none">
          <table class="pipeline-table">
            <thead><tr><th>Client</th><th>Stage</th><th>Days</th></tr></thead>
            <tbody>
              ${a.stageDurations.map((d) => `
                <tr class="pipeline-row" onclick="openInquiryDetail('${d.id}')">
                  <td>${d.client}</td>
                  <td>${d.stage}</td>
                  <td>${d.days}d</td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>` : ''}`;
  }

  window.renderWorkReports = renderWorkReports;
})();
