import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { StatusBadge } from '../components/StatusBadge';
import { SkeletonRowList } from '../components/Skeleton';
import { listBookings, getErrorMessage, type Booking } from '../api';

const FILTERS = [
  { id: 'all', label: 'All' },
  { id: 'active', label: 'Active' },
  { id: 'completed', label: 'Completed' },
  { id: 'cancelled', label: 'Cancelled' },
] as const;

const ACTIVE = new Set(['scheduled', 'searching', 'driver_assigned', 'in_progress']);

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

export function HistoryPage() {
  const navigate = useNavigate();
  const [bookings, setBookings] = useState<Booking[] | null>(null);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState<(typeof FILTERS)[number]['id']>('all');

  useEffect(() => {
    listBookings()
      .then((res) => setBookings(res.items))
      .catch((err) => setError(getErrorMessage(err, 'Could not load your trip history.')));
  }, []);

  const filtered =
    bookings?.filter((b) => {
      if (filter === 'all') return true;
      if (filter === 'active') return ACTIVE.has(b.status);
      if (filter === 'completed') return b.status === 'completed';
      if (filter === 'cancelled') return b.status === 'cancelled';
      return true;
    }) ?? null;

  function openTrip(b: Booking) {
    if (ACTIVE.has(b.status)) {
      navigate(`/track/${b.id}`);
    } else {
      navigate(`/trip/${b.id}`);
    }
  }

  return (
    <Screen eyebrow="Trips" title="Your orders" withNav>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {FILTERS.map((f) => (
          <button
            key={f.id}
            type="button"
            onClick={() => setFilter(f.id)}
            style={{
              border: `1px solid ${filter === f.id ? 'var(--accent)' : 'var(--border)'}`,
              background: filter === f.id ? 'var(--accent-soft)' : 'var(--surface)',
              color: filter === f.id ? 'var(--accent-strong)' : 'var(--text-muted)',
              borderRadius: 20,
              padding: '6px 14px',
              fontSize: 13,
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            {f.label}
          </button>
        ))}
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      {filtered === null && !error && <SkeletonRowList count={3} />}

      {filtered && filtered.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No trips in this category yet.</p>
      )}

      {filtered && filtered.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {filtered.map((b) => (
            <button
              key={b.id}
              type="button"
              onClick={() => openTrip(b)}
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
