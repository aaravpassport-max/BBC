import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { profileApi, type StatsResponse } from '@/api/profile';

/**
 * Dashboard IA follows the audit's own framework: "Current Progress →
 * Recommended Next Action → Recent Performance → Historical Analytics" —
 * the primary "Start Interview" action gets the most visual weight, not
 * equal billing with secondary stats.
 */
export default function Dashboard() {
  const { user, logout } = useAuth();
  const [stats, setStats] = useState<StatsResponse | null>(null);
  const [statsError, setStatsError] = useState(false);

  useEffect(() => {
    profileApi
      .stats()
      .then(setStats)
      .catch(() => setStatsError(true));
  }, []);

  return (
    <div className="ia-page-wide">
      <header className="ia-topbar" style={{ padding: '0 0 var(--ia-space-6)', border: 'none' }}>
        <div>
          <h1 style={{ marginBottom: 0 }}>Hi, {user?.name?.split(' ')[0] ?? 'there'}</h1>
          <span className="ia-badge ia-badge-info">{planLabel(user?.plan)}</span>
        </div>
        <nav style={{ display: 'flex', gap: 'var(--ia-space-2)', flexWrap: 'wrap' }}>
          <Link to="/history" className="ia-btn ia-btn-ghost">History</Link>
          <Link to="/progress" className="ia-btn ia-btn-ghost">Progress</Link>
          <Link to="/library" className="ia-btn ia-btn-ghost">Library</Link>
          <Link to="/ats" className="ia-btn ia-btn-ghost">Resume match</Link>
          <Link to="/settings" className="ia-btn ia-btn-ghost">Settings</Link>
          <Link to="/billing" className="ia-btn ia-btn-ghost">Billing</Link>
          <button className="ia-btn ia-btn-ghost" onClick={() => void logout()}>Sign out</button>
        </nav>
      </header>

      <section className="ia-card" style={{ marginBottom: 'var(--ia-space-6)', textAlign: 'center' }}>
        <h2>Ready for your next interview?</h2>
        <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>
          Practice with an AI interviewer tailored to your target role.
        </p>
        <Link to="/interview/new" className="ia-btn ia-btn-primary">Start a new interview</Link>
        {stats && stats.plan === 'free' && stats.week_limit !== null && (
          <p className="ia-state-hint" style={{ marginTop: 'var(--ia-space-3)' }}>
            {stats.week_count} of {stats.week_limit} free interviews used this week.
          </p>
        )}
      </section>

      <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 'var(--ia-space-4)' }}>
        <div className="ia-card">
          <h3 style={{ fontSize: 'var(--ia-text-sm)', color: 'var(--ia-text-secondary)' }}>Recent performance</h3>
          {statsError ? (
            <p style={{ color: 'var(--ia-text-tertiary)' }}>Couldn't load your stats right now.</p>
          ) : !stats || stats.total_interviews === 0 ? (
            <p style={{ color: 'var(--ia-text-tertiary)' }}>No interviews completed yet — your scores and trends will show up here.</p>
          ) : (
            <>
              <p style={{ fontSize: 'var(--ia-text-2xl)', fontWeight: 800, margin: 0 }}>{stats.avg_score ?? '—'}</p>
              <p style={{ color: 'var(--ia-text-secondary)', fontSize: 'var(--ia-text-sm)' }}>
                avg score across {stats.total_interviews} interview{stats.total_interviews !== 1 ? 's' : ''} · best {stats.best_score ?? '—'} · {stats.streak_days}-day streak
              </p>
            </>
          )}
        </div>
        <div className="ia-card">
          <h3 style={{ fontSize: 'var(--ia-text-sm)', color: 'var(--ia-text-secondary)' }}>Plan</h3>
          <p>{planLabel(user?.plan)}</p>
          {user?.plan === 'free' && <Link to="/billing" className="ia-btn ia-btn-secondary" style={{ marginTop: 'var(--ia-space-3)' }}>Upgrade</Link>}
        </div>
      </section>
    </div>
  );
}

function planLabel(plan?: string) {
  switch (plan) {
    case 'pro': return 'Pro plan';
    case 'premium': return 'Premium plan';
    case 'b2b': return 'Business plan';
    case 'admin': return 'Admin';
    default: return 'Free plan';
  }
}
