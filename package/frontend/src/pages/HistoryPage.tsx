import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { StatusBadge } from '../components/StatusBadge';
import { SkeletonRowList } from '../components/Skeleton';
import { listBookings, getErrorMessage, type Booking } from '../api';

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

export function HistoryPage() {
  const navigate = useNavigate();
  const [bookings, setBookings] = useState<Booking[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    listBookings()
      .then((res) => setBookings(res.items))
      .catch((err) => setError(getErrorMessage(err, 'Could not load your trip history.')));
  }, []);

  return (
    <Screen eyebrow="History" title="Your trips">
      <Button variant="ghost" style={{ width: 'auto', padding: '4px 0', marginBottom: 4 }} onClick={() => navigate(-1)}>
        ← Back
      </Button>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      {bookings === null && !error && <SkeletonRowList count={3} />}

      {bookings && bookings.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No trips yet — your bookings will show up here.</p>
      )}

      {bookings && bookings.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {bookings.map((b) => (
            <button
              key={b.id}
              onClick={() => navigate(`/track/${b.id}`)}
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
              <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, fontSize: 16, color: 'var(--accent-strong)' }}>
                {money(b.fare_breakdown.final_fare)}
              </div>
            </button>
          ))}
        </div>
      )}
    </Screen>
  );
}
