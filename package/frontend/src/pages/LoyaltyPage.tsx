import { useEffect, useState } from 'react';
import { Screen } from '../components/Screen';
import { getLoyaltySummary, getLoyaltyHistory, getErrorMessage, type LoyaltySummary, type LoyaltyTransaction } from '../api';
import { Skeleton, SkeletonRowList } from '../components/Skeleton';

const TIER_LABELS: Record<string, string> = {
  bronze: 'Bronze',
  silver: 'Silver',
  gold: 'Gold',
  platinum: 'Platinum',
};

export function LoyaltyPage() {
  const [summary, setSummary] = useState<LoyaltySummary | null>(null);
  const [history, setHistory] = useState<LoyaltyTransaction[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    Promise.all([getLoyaltySummary(), getLoyaltyHistory()])
      .then(([s, h]) => {
        setSummary(s);
        setHistory(h);
      })
      .catch((err) => setError(getErrorMessage(err, 'Could not load loyalty details.')));
  }, []);

  return (
    <Screen eyebrow="Rewards" title="Loyalty points">
      {error && <p style={{ color: 'var(--danger)', fontSize: 14 }}>{error}</p>}

      {!summary && !error && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 16, padding: 24 }}>
          <Skeleton width={120} height={14} style={{ marginBottom: 12 }} />
          <Skeleton width={80} height={32} />
        </div>
      )}

      {summary && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 16, padding: 24, background: 'var(--surface)' }}>
          <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>Your balance</div>
          <div style={{ fontSize: 36, fontWeight: 700, fontFamily: 'var(--font-mono)', color: 'var(--accent-strong)' }}>
            {summary.balance} pts
          </div>
          <div style={{ marginTop: 8, fontSize: 14 }}>
            Tier: <strong>{TIER_LABELS[summary.tier] ?? summary.tier}</strong>
            {summary.next_tier_at != null && (
              <span style={{ color: 'var(--text-muted)' }}> · {summary.next_tier_at - summary.lifetime_earned} pts to next tier</span>
            )}
          </div>
          <div style={{ marginTop: 4, fontSize: 12, color: 'var(--text-muted)' }}>
            Lifetime earned: {summary.lifetime_earned} points · 1 point per ₹10 spent
          </div>
        </div>
      )}

      <div style={{ marginTop: 24 }}>
        <h2 style={{ fontSize: 15, marginBottom: 12 }}>How to redeem</h2>
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)', fontSize: 13, color: 'var(--text-muted)' }}>
          <p style={{ margin: '0 0 8px' }}>• Earn 1 point for every ₹10 spent on completed trips</p>
          <p style={{ margin: '0 0 8px' }}>• Redeem points at checkout — 10 points = ₹1 off your fare</p>
          <p style={{ margin: 0 }}>• Higher tiers unlock better redemption rates in future releases</p>
        </div>
      </div>

      <div style={{ marginTop: 24 }}>
        <h2 style={{ fontSize: 15, marginBottom: 12 }}>Points history</h2>
        {history === null && !error && <SkeletonRowList count={3} />}
        {history && history.length === 0 && (
          <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>Complete trips to start earning points.</p>
        )}
        {history && history.length > 0 && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {history.map((tx) => (
              <div
                key={tx.id}
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  padding: '12px 14px',
                  border: '1px solid var(--border)',
                  borderRadius: 12,
                  background: 'var(--surface)',
                }}
              >
                <div>
                  <div style={{ fontSize: 14, fontWeight: 600 }}>+{tx.points} points</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                    {tx.reason.replace(/_/g, ' ')} · {new Date(tx.created_at).toLocaleDateString()}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </Screen>
  );
}
