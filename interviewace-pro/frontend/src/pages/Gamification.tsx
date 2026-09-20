import { useEffect, useState } from 'react';
import { gamificationApi, type GamificationProfile } from '@/api/gamification';
import { LoadingBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

export default function Gamification() {
  const [data, setData] = useState<GamificationProfile | null>(null);
  const [error, setError] = useState<ApiError | null>(null);

  function load() {
    setError(null);
    gamificationApi.profile().then(setData).catch((err) => setError(err as ApiError));
  }
  useEffect(load, []);

  if (error) return <div className="ia-page-wide"><ErrorBlock error={error} onRetry={load} /></div>;
  if (!data) return <div className="ia-page-wide"><LoadingBlock label="Loading your progress…" /></div>;

  const earnedSlugs = new Set(data.badges.map((b) => b.badge_slug));

  return (
    <div className="ia-page-wide">
      <h1>Your progress</h1>

      <div className="ia-card" style={{ marginBottom: 'var(--ia-space-6)', textAlign: 'center' }}>
        <div style={{ fontSize: 'var(--ia-text-3xl)' }}>{data.level_icon}</div>
        <h2 style={{ margin: '4px 0' }}>{data.level_name}</h2>
        <p style={{ color: 'var(--ia-text-secondary)' }}>{data.total_xp} XP · {data.xp_to_next} to next level</p>
        <div style={{ height: 8, borderRadius: 4, background: 'var(--ia-border)', overflow: 'hidden', marginTop: 'var(--ia-space-3)' }}>
          <div style={{ height: '100%', width: `${data.level_progress_pct}%`, background: 'var(--ia-accent)' }} />
        </div>
      </div>

      <h2>Badges ({data.badge_count}/{data.all_badges.length})</h2>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 'var(--ia-space-3)', marginBottom: 'var(--ia-space-6)' }}>
        {data.all_badges.map((b) => {
          const earned = earnedSlugs.has(b.slug);
          return (
            <div key={b.slug} className="ia-card" style={{ textAlign: 'center', opacity: earned ? 1 : 0.4, padding: 'var(--ia-space-3)' }}>
              <div style={{ fontSize: 'var(--ia-text-2xl)' }}>{b.icon}</div>
              <div style={{ fontSize: 'var(--ia-text-sm)', fontWeight: 600 }}>{b.name}</div>
              <div className="ia-state-hint">{b.desc}</div>
            </div>
          );
        })}
      </div>

      <h2>Recent activity</h2>
      {data.recent_xp_events.length === 0 ? (
        <p style={{ color: 'var(--ia-text-tertiary)' }}>No activity yet — complete an interview to start earning XP.</p>
      ) : (
        data.recent_xp_events.map((e, i) => (
          <div key={i} style={{ display: 'flex', justifyContent: 'space-between', padding: 'var(--ia-space-2) 0', borderBottom: '1px solid var(--ia-border)' }}>
            <span>{e.description || e.event_type}</span>
            <span style={{ color: 'var(--ia-success)' }}>+{e.xp_delta} XP</span>
          </div>
        ))
      )}
    </div>
  );
}
