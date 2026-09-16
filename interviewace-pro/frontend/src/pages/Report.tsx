import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { reportsApi } from '@/api/reports';
import { LoadingBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError, Report as ReportT } from '@/types';

type ViewState =
  | { kind: 'loading' }
  | { kind: 'pending' | 'generating' }
  | { kind: 'failed'; message: string }
  | { kind: 'done'; report: ReportT }
  | { kind: 'error'; error: ApiError };

/**
 * Direct fix for audit finding A3: a failed report used to be a permanent
 * dead end with no way for the user to recover. This screen polls while
 * pending/generating, and on a genuine 'failed' status offers a "Regenerate
 * report" action wired to the new POST /reports/{id}/retry endpoint,
 * capped at 3 attempts server-side.
 */
export default function Report() {
  const { id } = useParams<{ id: string }>();
  const reportId = Number(id);
  const [view, setView] = useState<ViewState>({ kind: 'loading' });
  const [retrying, setRetrying] = useState(false);
  const pollRef = useRef<number | null>(null);

  const load = useCallback(async () => {
    try {
      const r = await reportsApi.get(reportId);
      if ('report' in r) {
        setView({ kind: 'done', report: r.report });
      } else if (r.status === 'failed') {
        setView({ kind: 'failed', message: r.error });
      } else {
        setView({ kind: r.status });
      }
    } catch (err) {
      setView({ kind: 'error', error: err as ApiError });
    }
  }, [reportId]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (view.kind === 'pending' || view.kind === 'generating') {
      pollRef.current = window.setTimeout(() => void load(), 3000);
    }
    return () => {
      if (pollRef.current) window.clearTimeout(pollRef.current);
    };
  }, [view.kind, load]);

  async function onRetryGeneration() {
    setRetrying(true);
    try {
      await reportsApi.retry(reportId);
      setView({ kind: 'pending' });
    } catch (err) {
      setView({ kind: 'error', error: err as ApiError });
    } finally {
      setRetrying(false);
    }
  }

  if (view.kind === 'loading') return <div className="ia-page-wide"><LoadingBlock label="Loading your report…" /></div>;
  if (view.kind === 'error') return <div className="ia-page-wide"><ErrorBlock error={view.error} onRetry={() => void load()} /></div>;
  if (view.kind === 'pending' || view.kind === 'generating') {
    return (
      <div className="ia-page-wide">
        <LoadingBlock label="Your report is being generated — this usually takes under a minute…" />
      </div>
    );
  }
  if (view.kind === 'failed') {
    return (
      <div className="ia-page-wide">
        <div className="ia-state ia-state-error">
          <h3>Report generation failed</h3>
          <p>{view.message}</p>
          <button className="ia-btn ia-btn-primary" onClick={() => void onRetryGeneration()} disabled={retrying}>
            {retrying ? 'Retrying…' : 'Regenerate report'}
          </button>
        </div>
      </div>
    );
  }

  if (view.kind !== 'done') return null;
  const r = view.report;
  return (
    <div className="ia-page-wide">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--ia-space-3)' }}>
        <h1>Your interview report</h1>
        <button className="ia-btn ia-btn-secondary" onClick={() => void onDownloadPdf(r.interview_id)}>Download PDF</button>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 'var(--ia-space-4)', marginBottom: 'var(--ia-space-6)' }}>
        <ScoreCard label="Overall" value={r.overall_score} />
        <ScoreCard label="Communication" value={r.comm_score} />
        <ScoreCard label="Technical" value={r.tech_score} />
        <ScoreCard label="Confidence" value={r.conf_score} />
      </div>

      {r.recommendation && (
        <div className="ia-card" style={{ marginBottom: 'var(--ia-space-5)' }}>
          <span className={`ia-badge ${recBadgeClass(r.recommendation)}`}>{recLabel(r.recommendation)}</span>
          {r.recommendation_reason && <p style={{ marginTop: 'var(--ia-space-3)' }}>{r.recommendation_reason}</p>}
        </div>
      )}

      {r.summary && (
        <section className="ia-card" style={{ marginBottom: 'var(--ia-space-5)' }}>
          <h3>Summary</h3>
          <p>{r.summary}</p>
        </section>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 'var(--ia-space-4)', marginBottom: 'var(--ia-space-5)' }}>
        {r.strengths?.length > 0 && (
          <section className="ia-card">
            <h3 style={{ color: 'var(--ia-success)' }}>Strengths</h3>
            <ul>{r.strengths.map((s: string, i: number) => <li key={i}>{s}</li>)}</ul>
          </section>
        )}
        {r.weaknesses?.length > 0 && (
          <section className="ia-card">
            <h3 style={{ color: 'var(--ia-warning)' }}>Areas to improve</h3>
            <ul>{r.weaknesses.map((s: string, i: number) => <li key={i}>{s}</li>)}</ul>
          </section>
        )}
      </div>

      {r.question_evaluations?.length > 0 && (
        <section>
          <h2>Question-by-question feedback</h2>
          {r.question_evaluations.map((q: ReportT['question_evaluations'][number], i: number) => (
            <div key={i} className="ia-card" style={{ marginBottom: 'var(--ia-space-3)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--ia-space-3)' }}>
                <strong>{q.question}</strong>
                <span className="ia-badge ia-badge-info">{q.score}/10</span>
              </div>
              <p style={{ color: 'var(--ia-text-secondary)' }}>{q.feedback}</p>
            </div>
          ))}
        </section>
      )}
    </div>
  );
}

async function onDownloadPdf(interviewId: number) {
  try {
    const r = await reportsApi.pdfDownloadUrl(interviewId);
    // The returned URL is a signed, time-limited link served by a non-REST
    // handler (IA_PDF_Report::handle_download, hooked on `init`) — a normal
    // browser navigation, not a fetch, since it streams a binary PDF.
    window.location.href = r.url;
  } catch {
    window.alert('Could not generate a download link right now. Please try again.');
  }
}

function ScoreCard({ label, value }: { label: string; value: number | null }) {
  return (
    <div className="ia-card" style={{ textAlign: 'center' }}>
      <div style={{ fontSize: 'var(--ia-text-3xl)', fontWeight: 800, color: 'var(--ia-accent)' }}>{value ?? '—'}</div>
      <div style={{ color: 'var(--ia-text-secondary)', fontSize: 'var(--ia-text-sm)' }}>{label}</div>
    </div>
  );
}

function recLabel(rec: string) {
  return { strong_hire: 'Strong Hire', hire: 'Hire', borderline: 'Borderline', reject: 'Needs Improvement' }[rec] ?? rec;
}
function recBadgeClass(rec: string) {
  return { strong_hire: 'ia-badge-success', hire: 'ia-badge-success', borderline: 'ia-badge-warning', reject: 'ia-badge-danger' }[rec] ?? 'ia-badge-info';
}
