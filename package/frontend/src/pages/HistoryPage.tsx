import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { StatusBadge } from '../components/StatusBadge';
import { SkeletonRowList } from '../components/Skeleton';
import { Button } from '../components/Button';
import { listBookings, getErrorMessage, type Booking } from '../api';
import { formatAddress } from '../lib/address';

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
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<(typeof FILTERS)[number]['id']>('all');

  async function loadPage(p: number, append = false, statusFilter?: string) {
    setLoading(true);
    try {
      const apiStatus =
        statusFilter === 'completed' || statusFilter === 'cancelled' ? statusFilter : undefined;
      const res = await listBookings({ page: p, page_size: 10, status: apiStatus });
      setBookings((prev) => (append ? [...prev, ...res.items] : res.items));
      setHasMore(res.items.length === 10);
      setPage(p);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your trip history.'));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadPage(1, false, filter);
  }, [filter]);

  const filtered =
    filter === 'active' ? bookings.filter((b) => ACTIVE.has(b.status)) : bookings;

  const tripCount = filtered.length;
  const totalSpent = filtered.reduce((sum, b) => sum + b.fare_breakdown.final_fare, 0);

  function openTrip(b: Booking) {
    if (ACTIVE.has(b.status)) navigate(`/track/${b.id}`);
    else navigate(`/trip/${b.id}`);
  }

  return (
    <Screen eyebrow="Trips" title="Your orders" withNav>
      {tripCount > 0 && (
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: '12px 16px',
            fontSize: 13,
          }}
        >
          <span>
            <strong>{tripCount}</strong> trip{tripCount !== 1 ? 's' : ''} in this view
          </span>
          <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: 'var(--accent-strong)' }}>
            {money(totalSpent)} total
          </span>
        </div>
      )}

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

      {filtered.length > 0 && (
        <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>
          {filtered.length} trip{filtered.length === 1 ? '' : 's'}
          {totalSpent > 0 && ` · ${money(totalSpent)} spent`}
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {loading && bookings.length === 0 && <SkeletonRowList count={3} />}

      {filtered.length === 0 && !loading && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No trips in this category yet.</p>
      )}

      {filtered.length > 0 && (
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
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4, maxWidth: 200 }}>
                  {formatAddress(b.pickup_address, 'Pickup')} → {formatAddress(b.first_drop_address, 'Drop')}
                  {(b.stop_count ?? 0) > 1 ? ` (+${(b.stop_count ?? 1) - 1} more)` : ''}
                </div>
                <div style={{ fontSize: 13, marginTop: 4, textTransform: 'capitalize' }}>
                  {b.vehicle_category_id?.replace(/_/g, ' ') || 'Delivery'}
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

      {hasMore && filter !== 'active' && (
        <Button variant="ghost" loading={loading} onClick={() => void loadPage(page + 1, true, filter)}>
          Load more
        </Button>
      )}
    </Screen>
  );
}
