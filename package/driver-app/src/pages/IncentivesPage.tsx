import { useEffect, useState } from 'react';
import { Screen } from '../components/Screen';
import { Skeleton } from '../components/Skeleton';
import { getActiveIncentives, getErrorMessage, type DriverIncentive } from '../api';

export function IncentivesPage() {
  const [items, setItems] = useState<DriverIncentive[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    getActiveIncentives()
      .then((r) => setItems(r.items))
      .catch((err) => setError(getErrorMessage(err, 'Could not load incentives.')));
  }, []);

  return (
    <Screen eyebrow="Earnings" title="Incentives & missions" withNav>
      {error && <p style={{ color: 'var(--danger)', fontSize: 14 }}>{error}</p>}
      {!items && !error && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Skeleton width="100%" height={100} radius={12} />
        </div>
      )}
      {items && items.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No active missions right now. Check back soon.</p>
      )}
      {items && items.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {items.map((inc) => {
            const pct = Math.min(100, Math.round((inc.progress / inc.target) * 100));
            return (
              <div
                key={inc.id}
                style={{
                  border: '1px solid var(--border)',
                  borderRadius: 16,
                  padding: 18,
                  background: inc.completed ? 'var(--success-soft, #f0fff4)' : 'var(--surface)',
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 16 }}>{inc.title}</div>
                    <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>{inc.description}</div>
                  </div>
                  <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: 'var(--accent-strong)', fontSize: 18 }}>
                    ₹{inc.bonus_amount}
                  </div>
                </div>
                <div style={{ marginTop: 14 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>
                    <span>{inc.progress} / {inc.target} trips</span>
                    <span>{inc.completed ? 'Completed!' : `${inc.remaining} to go`}</span>
                  </div>
                  <div style={{ height: 8, borderRadius: 4, background: 'var(--border)', overflow: 'hidden' }}>
                    <div style={{ height: '100%', width: `${pct}%`, background: inc.completed ? 'var(--success)' : 'var(--accent)', borderRadius: 4 }} />
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </Screen>
  );
}
