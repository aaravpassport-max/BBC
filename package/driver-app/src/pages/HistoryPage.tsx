import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { StatusBadge } from '../components/StatusBadge';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import { listJobHistory, getErrorMessage, type AddressSnapshot } from '../api';
import { formatAddress } from '../lib/address';

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

function formatCategory(id: string | undefined): string {
  if (!id) return '';
  return id.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

type HistoryItem = {
  id: string;
  status: string;
  fare_breakdown: { final_fare: number };
  created_at: string;
  vehicle_category_id?: string;
  pickup_address?: AddressSnapshot;
  first_drop_address?: AddressSnapshot;
  stop_count?: number;
};

export function HistoryPage() {
  const navigate = useNavigate();
  const [items, setItems] = useState<HistoryItem[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  const loadPage = useCallback(async (pageNum: number, append: boolean) => {
    try {
      const res = await listJobHistory(pageNum);
      setItems((prev) => (append ? [...prev, ...res.items] : res.items));
      setHasMore(res.items.length >= 20);
      setPage(pageNum);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load trip history.'));
    }
  }, []);

  useEffect(() => {
    setLoading(true);
    void loadPage(1, false).finally(() => setLoading(false));
  }, [loadPage]);

  async function loadMore() {
    setLoadingMore(true);
    await loadPage(page + 1, true);
    setLoadingMore(false);
  }

  return (
    <Screen eyebrow="Trips" title="Trip history" withNav>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {loading && !error && <SkeletonRowList count={3} />}
      {!loading && items.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No completed trips yet.</p>
      )}
      {items.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {items.map((b) => (
            <button
              key={b.id}
              type="button"
              onClick={() => navigate(`/trip-summary/${b.id}`)}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'flex-start',
                textAlign: 'left',
                border: '1px solid var(--border)',
                borderRadius: 12,
                background: 'var(--surface)',
                padding: '14px 16px',
                cursor: 'pointer',
                color: 'var(--text)',
                gap: 12,
              }}
            >
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>
                  #{b.id.slice(0, 8).toUpperCase()}
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
                  {new Date(b.created_at).toLocaleString()}
                </div>
                <div style={{ fontSize: 13, marginTop: 8, lineHeight: 1.4 }}>
                  <span style={{ color: 'var(--success)', fontWeight: 600 }}>A</span>{' '}
                  {formatAddress(b.pickup_address, 'Pickup')}
                  <br />
                  <span style={{ color: 'var(--accent-strong)', fontWeight: 600 }}>B</span>{' '}
                  {formatAddress(b.first_drop_address, 'Drop')}
                  {(b.stop_count ?? 0) > 1 && (
                    <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>
                      {' '}
                      · +{(b.stop_count ?? 1) - 1} more stop{(b.stop_count ?? 1) - 1 === 1 ? '' : 's'}
                    </span>
                  )}
                </div>
                {b.vehicle_category_id && (
                  <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>
                    {formatCategory(b.vehicle_category_id)}
                  </div>
                )}
                <div style={{ marginTop: 6 }}>
                  <StatusBadge status={b.status} />
                </div>
              </div>
              <div style={{ fontWeight: 700, fontSize: 16, color: 'var(--accent-strong)', flexShrink: 0 }}>
                {money(b.fare_breakdown.final_fare)}
              </div>
            </button>
          ))}
        </div>
      )}
      {hasMore && items.length > 0 && (
        <Button variant="ghost" loading={loadingMore} onClick={() => void loadMore()}>
          Load more
        </Button>
      )}
    </Screen>
  );
}
