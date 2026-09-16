import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { libraryApi, type SavedAnswer } from '@/api/library';
import { LoadingBlock, EmptyBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

/**
 * Frontend for the answer library (api/class-api-gamification.php's
 * get_lib/save_answer/del_answer) — fully implemented on the backend
 * (including a working SM-2 spaced-repetition queue, see Review.tsx) but
 * had no UI at all before this pass. Answers are currently saved into the
 * library from here directly (no interview-flow hook yet), searchable by
 * question/tag, and can be queued for spaced-repetition review.
 */
export default function Library() {
  const [answers, setAnswers] = useState<SavedAnswer[] | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [search, setSearch] = useState('');
  const [queuedIds, setQueuedIds] = useState<Set<number>>(new Set());
  const [form, setForm] = useState({ question: '', user_answer: '', ideal_answer: '', tags: '' });
  const [saving, setSaving] = useState(false);
  const [showForm, setShowForm] = useState(false);

  const load = useCallback(async (q: string) => {
    setError(null);
    try {
      const r = await libraryApi.list(q);
      setAnswers(r.answers);
    } catch (err) {
      setError(err as ApiError);
    }
  }, []);

  useEffect(() => {
    const t = window.setTimeout(() => void load(search), 300);
    return () => window.clearTimeout(t);
  }, [search, load]);

  async function onSave(e: React.FormEvent) {
    e.preventDefault();
    if (!form.question.trim()) return;
    setSaving(true);
    try {
      await libraryApi.save(form);
      setForm({ question: '', user_answer: '', ideal_answer: '', tags: '' });
      setShowForm(false);
      void load(search);
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSaving(false);
    }
  }

  async function onDelete(id: number) {
    if (!window.confirm('Remove this answer from your library?')) return;
    setAnswers((prev) => prev?.filter((a) => a.id !== id) ?? null);
    try {
      await libraryApi.remove(id);
    } catch {
      void load(search); // roll back on failure by reloading the real state
    }
  }

  async function onEnqueue(id: number) {
    try {
      await libraryApi.enqueue(id);
      setQueuedIds((prev) => new Set(prev).add(id));
    } catch (err) {
      setError(err as ApiError);
    }
  }

  return (
    <div className="ia-page-wide">
      <header className="ia-topbar" style={{ padding: '0 0 var(--ia-space-6)', border: 'none' }}>
        <h1 style={{ marginBottom: 0 }}>Answer library</h1>
        <nav style={{ display: 'flex', gap: 'var(--ia-space-2)' }}>
          <Link to="/library/review" className="ia-btn ia-btn-secondary">Review flashcards</Link>
          <button className="ia-btn ia-btn-primary" onClick={() => setShowForm((s) => !s)}>
            {showForm ? 'Cancel' : '+ Save an answer'}
          </button>
        </nav>
      </header>

      {showForm && (
        <form onSubmit={(e) => void onSave(e)} className="ia-card" style={{ marginBottom: 'var(--ia-space-5)', display: 'grid', gap: 'var(--ia-space-3)' }}>
          <textarea className="ia-input" placeholder="Question" rows={2} value={form.question}
            onChange={(e) => setForm((f) => ({ ...f, question: e.target.value }))} required />
          <textarea className="ia-input" placeholder="Your answer (optional)" rows={3} value={form.user_answer}
            onChange={(e) => setForm((f) => ({ ...f, user_answer: e.target.value }))} />
          <textarea className="ia-input" placeholder="Ideal answer (optional — generated automatically if left blank and you provided your own answer)" rows={3}
            value={form.ideal_answer} onChange={(e) => setForm((f) => ({ ...f, ideal_answer: e.target.value }))} />
          <input className="ia-input" placeholder="Tags, comma separated (optional)" value={form.tags}
            onChange={(e) => setForm((f) => ({ ...f, tags: e.target.value }))} />
          <button className="ia-btn ia-btn-primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save to library'}</button>
        </form>
      )}

      <input className="ia-input" placeholder="Search by question or tag…" value={search}
        onChange={(e) => setSearch(e.target.value)} style={{ marginBottom: 'var(--ia-space-5)' }} />

      {error && <ErrorBlock error={error} onRetry={() => void load(search)} />}
      {!error && !answers && <LoadingBlock label="Loading your library…" />}
      {!error && answers && answers.length === 0 && (
        <EmptyBlock title="Your library is empty" hint="Save strong answers here to build a personal question bank you can review later with spaced repetition." />
      )}
      {!error && answers && answers.length > 0 && (
        <div style={{ display: 'grid', gap: 'var(--ia-space-3)' }}>
          {answers.map((a) => (
            <div key={a.id} className="ia-card">
              <p style={{ fontWeight: 700, marginBottom: 'var(--ia-space-2)' }}>{a.question}</p>
              {a.user_answer && <p style={{ color: 'var(--ia-text-secondary)', fontSize: 'var(--ia-text-sm)' }}>Your answer: {a.user_answer}</p>}
              {a.ideal_answer && <p style={{ color: 'var(--ia-success)', fontSize: 'var(--ia-text-sm)', marginTop: 'var(--ia-space-2)' }}>💡 {a.ideal_answer}</p>}
              <div style={{ display: 'flex', gap: 'var(--ia-space-2)', flexWrap: 'wrap', marginTop: 'var(--ia-space-3)' }}>
                {a.tags.map((t) => <span key={t} className="ia-badge ia-badge-info">{t}</span>)}
              </div>
              <div style={{ display: 'flex', gap: 'var(--ia-space-2)', marginTop: 'var(--ia-space-3)' }}>
                <button className="ia-btn ia-btn-ghost" disabled={queuedIds.has(a.id)} onClick={() => void onEnqueue(a.id)}>
                  {queuedIds.has(a.id) ? 'Queued for review ✓' : 'Add to review queue'}
                </button>
                <button className="ia-btn ia-btn-ghost" aria-label={`Delete "${a.question}"`} onClick={() => void onDelete(a.id)}>Delete</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
