import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { libraryApi, type QueueItem } from '@/api/library';
import { LoadingBlock, EmptyBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

const QUALITY_OPTIONS: { value: number; label: string; hint: string }[] = [
  { value: 0, label: 'Blackout', hint: "Didn't recall at all" },
  { value: 2, label: 'Hard', hint: 'Recalled with real effort' },
  { value: 3, label: 'OK', hint: 'Recalled correctly, some hesitation' },
  { value: 4, label: 'Good', hint: 'Recalled correctly' },
  { value: 5, label: 'Perfect', hint: 'Instant, confident recall' },
];

/**
 * SM-2 spaced-repetition review UI — the algorithm and API
 * (get_queue/submit_review) were fully implemented server-side with no
 * frontend consumer at all before this pass. Standard flashcard flow: show
 * the question, let the user self-assess before revealing the answer,
 * reveal, then rate recall quality which the backend uses to schedule the
 * next due date.
 */
export default function Review() {
  const [queue, setQueue] = useState<QueueItem[] | null>(null);
  const [upcomingCount, setUpcomingCount] = useState(0);
  const [error, setError] = useState<ApiError | null>(null);
  const [revealed, setRevealed] = useState(false);
  const [rating, setRating] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const r = await libraryApi.queue();
      setQueue(r.due_today);
      setUpcomingCount(r.upcoming_count);
      setRevealed(false);
    } catch (err) {
      setError(err as ApiError);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function onRate(quality: number) {
    if (!queue || queue.length === 0) return;
    setRating(true);
    try {
      await libraryApi.submitReview(queue[0].queue_id, quality);
      setQueue((prev) => (prev ? prev.slice(1) : prev));
      setRevealed(false);
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setRating(false);
    }
  }

  if (error) return <div className="ia-page"><ErrorBlock error={error} onRetry={() => void load()} /></div>;
  if (!queue) return <div className="ia-page"><LoadingBlock label="Loading your review queue…" /></div>;

  if (queue.length === 0) {
    return (
      <div className="ia-page">
        <EmptyBlock
          title="Nothing due for review right now"
          hint={upcomingCount > 0
            ? `${upcomingCount} more card${upcomingCount !== 1 ? 's' : ''} scheduled for later — come back tomorrow.`
            : 'Save answers to your library and add them to the review queue to build up your flashcard deck.'}
          action={<Link to="/library" className="ia-btn ia-btn-primary">Go to library</Link>}
        />
      </div>
    );
  }

  const card = queue[0];

  return (
    <div className="ia-page">
      <header className="ia-topbar" style={{ padding: '0 0 var(--ia-space-5)', border: 'none' }}>
        <h1 style={{ marginBottom: 0 }}>Review</h1>
        <Link to="/library" className="ia-btn ia-btn-ghost">Back to library</Link>
      </header>

      <p className="ia-state-hint" style={{ marginBottom: 'var(--ia-space-4)' }}>{queue.length} card{queue.length !== 1 ? 's' : ''} due</p>

      <div className="ia-card" style={{ minHeight: 220 }}>
        <p style={{ fontWeight: 700, fontSize: 'var(--ia-text-lg)', marginBottom: 'var(--ia-space-4)' }}>{card.question}</p>

        {!revealed ? (
          <button className="ia-btn ia-btn-secondary" onClick={() => setRevealed(true)}>Show answer</button>
        ) : (
          <>
            {card.user_answer && (
              <div style={{ marginBottom: 'var(--ia-space-3)' }}>
                <p style={{ color: 'var(--ia-text-secondary)', fontSize: 'var(--ia-text-sm)', fontWeight: 700 }}>Your saved answer</p>
                <p>{card.user_answer}</p>
              </div>
            )}
            {card.ideal_answer && (
              <div style={{ marginBottom: 'var(--ia-space-4)' }}>
                <p style={{ color: 'var(--ia-success)', fontSize: 'var(--ia-text-sm)', fontWeight: 700 }}>💡 Ideal answer</p>
                <p>{card.ideal_answer}</p>
              </div>
            )}
            <p className="ia-state-hint" style={{ marginBottom: 'var(--ia-space-2)' }}>How well did you recall this?</p>
            <div style={{ display: 'flex', gap: 'var(--ia-space-2)', flexWrap: 'wrap' }}>
              {QUALITY_OPTIONS.map((q) => (
                <button key={q.value} className="ia-btn ia-btn-ghost" disabled={rating} title={q.hint} onClick={() => void onRate(q.value)}>
                  {q.label}
                </button>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
