import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { StatusBadge } from '../components/StatusBadge';
import { SkeletonRowList } from '../components/Skeleton';
import { listJobHistory, getErrorMessage } from '../api';

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

export function HistoryPage() {
  const navigate = useNavigate();
  const [items, setItems] = useState<{ id: string; status: string; fare_breakdown: { final_fare: number }; created_at: string }[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    listJobHistory()
      .then((res) => setItems(res.items))
      .catch((err) => setError(getErrorMessage(err, 'Could not load trip history.')));
  }, []);

  return (
    <Screen eyebrow="Trips" title="Trip history" withNav>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {items === null && !error && <SkeletonRowList count={3} />}
      {items && items.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No completed trips yet.</p>
      )}
      {items && items.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {items.map((b) => (
            <button
              key={b.id}
              type="button"
              onClick={() => navigate(`/trip/${b.id}`)}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                textAlign: 'left',
                border: '1px solid var(--border)',
                borderRadius: 12,
                background: 'var(--surface)',
                padding: '14px 16px',
                cursor: 'pointer',
                color: 'var(--text)',
              }}
            >
              <div>
                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>
                  #{b.id.slice(0, 8).toUpperCase()}
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
                  {new Date(b.created_at).toLocaleString()}
                </div>
                <div style={{ marginTop: 6 }}>
                  <StatusBadge status={b.status} />
                </div>
              </div>
              <div style={{ fontWeight: 700, fontSize: 16, color: 'var(--accent-strong)' }}>
                {money(b.fare_breakdown.final_fare)}
              </div>
            </button>
          ))}
        </div>
      )}
    </Screen>
  );
}
