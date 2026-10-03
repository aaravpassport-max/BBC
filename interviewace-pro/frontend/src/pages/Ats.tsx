import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { atsApi, type AtsScore } from '@/api/ats';
import { LoadingBlock, ErrorBlock, EmptyBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

export default function Ats() {
  const [data, setData] = useState<AtsScore | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(true);

  function load() {
    setLoading(true);
    setError(null);
    atsApi
      .score()
      .then(setData)
      .catch((err) => setError(err as ApiError))
      .finally(() => setLoading(false));
  }
  useEffect(load, []);

  if (loading) return <div className="ia-page-wide"><LoadingBlock label="Analyzing your resume…" /></div>;
  if (error) {
    if (error.code === 'ia_missing') {
      return (
        <div className="ia-page-wide">
          <EmptyBlock
            title="Upload your resume first"
            hint={error.message}
            action={<Link to="/settings" className="ia-btn ia-btn-primary">Go to settings</Link>}
          />
        </div>
      );
    }
    return <div className="ia-page-wide"><ErrorBlock error={error} onRetry={load} /></div>;
  }
  if (!data) return null;

  return (
    <div className="ia-page-wide">
      <h1>Resume &amp; ATS match</h1>

      <div className="ia-card" style={{ textAlign: 'center', marginBottom: 'var(--ia-space-6)' }}>
        <div style={{ fontSize: 'var(--ia-text-3xl)', fontWeight: 800, color: 'var(--ia-accent)' }}>{data.overall_score}</div>
        <p style={{ color: 'var(--ia-text-secondary)' }}>Overall score{data.jd_role ? ` for ${data.jd_role}` : ''}</p>
        {!data.has_jd && <p className="ia-state-hint">Add a job description in Settings for a keyword-match score against a specific role.</p>}
      </div>

      {data.has_jd && (
        <section style={{ marginBottom: 'var(--ia-space-6)' }}>
          <h2>Keyword match ({data.match_count}/{data.total_jd_keywords})</h2>
          {data.missing_required.length > 0 && (
            <div className="ia-card" style={{ marginBottom: 'var(--ia-space-3)' }}>
              <h3 style={{ color: 'var(--ia-danger)' }}>Missing required skills</h3>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--ia-space-2)' }}>
                {data.missing_required.map((k) => <span key={k} className="ia-badge ia-badge-danger">{k}</span>)}
              </div>
            </div>
          )}
          {data.matched_keywords.length > 0 && (
            <div className="ia-card">
              <h3 style={{ color: 'var(--ia-success)' }}>Matched</h3>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--ia-space-2)' }}>
                {data.matched_keywords.map((k) => <span key={k} className="ia-badge ia-badge-success">{k}</span>)}
              </div>
            </div>
          )}
        </section>
      )}

      <section style={{ marginBottom: 'var(--ia-space-6)' }}>
        <h2>Resume sections</h2>
        {data.sections.map((s) => (
          <div key={s.section} className="ia-card" style={{ marginBottom: 'var(--ia-space-2)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <strong>{s.section}</strong>
              {s.issue && <p className="ia-state-hint" style={{ margin: 0 }}>{s.issue}</p>}
            </div>
            <span className={`ia-badge ${s.score >= 70 ? 'ia-badge-success' : 'ia-badge-warning'}`}>{s.score}</span>
          </div>
        ))}
      </section>

      {data.suggestions.length > 0 && (
        <section>
          <h2>Suggestions</h2>
          {data.suggestions.map((s, i) => (
            <div key={i} className="ia-card" style={{ marginBottom: 'var(--ia-space-2)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <strong>{s.title}</strong>
                <span className={`ia-badge ${s.priority === 'high' ? 'ia-badge-danger' : s.priority === 'medium' ? 'ia-badge-warning' : 'ia-badge-info'}`}>{s.priority}</span>
              </div>
              <p style={{ color: 'var(--ia-text-secondary)' }}>{s.detail}</p>
            </div>
          ))}
        </section>
      )}
    </div>
  );
}
