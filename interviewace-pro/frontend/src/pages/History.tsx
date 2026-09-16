import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { historyApi, type HistoryItem } from '@/api/history';
import { LoadingBlock, ErrorBlock, EmptyBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

export default function History() {
  const [items, setItems] = useState<HistoryItem[] | null>(null);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [error, setError] = useState<ApiError | null>(null);

  function load(p: number) {
    setError(null);
    historyApi
      .list(p)
      .then((r) => {
        setItems(r.interviews);
        setPages(r.pages);
        setPage(r.page);
      })
      .catch((err) => setError(err as ApiError));
  }

  useEffect(() => load(1), []);

  if (error) return <div className="ia-page-wide"><ErrorBlock error={error} onRetry={() => load(page)} /></div>;
  if (items === null) return <div className="ia-page-wide"><LoadingBlock label="Loading your interview history…" /></div>;

  return (
    <div className="ia-page-wide">
      <h1>Interview history</h1>
      {items.length === 0 ? (
        <EmptyBlock
          title="No interviews yet"
          hint="Complete your first interview to see it here."
          action={<Link to="/interview/new" className="ia-btn ia-btn-primary">Start an interview</Link>}
        />
      ) : (
        <>
          {items.map((it) => (
            <div key={it.id} className="ia-card" style={{ marginBottom: 'var(--ia-space-3)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 'var(--ia-space-4)', flexWrap: 'wrap' }}>
              <div>
                <strong>{it.type}</strong>
                {it.company_pack && <span className="ia-state-hint"> · {it.company_pack}</span>}
                <p className="ia-state-hint" style={{ margin: 0 }}>{new Date(it.ended_at).toLocaleDateString()} · {Math.round(it.duration_seconds / 60)} min</p>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--ia-space-3)' }}>
                {it.report_status === 'done' && it.overall_score !== null && (
                  <span className="ia-badge ia-badge-info">{it.overall_score}/100</span>
                )}
                {it.report_status === 'failed' && <span className="ia-badge ia-badge-danger">Report failed</span>}
                {(it.report_status === 'pending' || it.report_status === 'generating') && <span className="ia-badge ia-badge-warning">Generating…</span>}
                <Link to={`/history/${it.id}`} className="ia-btn ia-btn-secondary">View</Link>
              </div>
            </div>
          ))}
          {pages > 1 && (
            <div style={{ display: 'flex', gap: 'var(--ia-space-2)', justifyContent: 'center', marginTop: 'var(--ia-space-5)' }}>
              <button className="ia-btn ia-btn-secondary" disabled={page <= 1} onClick={() => load(page - 1)}>Previous</button>
              <span className="ia-state-hint" style={{ alignSelf: 'center' }}>Page {page} of {pages}</span>
              <button className="ia-btn ia-btn-secondary" disabled={page >= pages} onClick={() => load(page + 1)}>Next</button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
