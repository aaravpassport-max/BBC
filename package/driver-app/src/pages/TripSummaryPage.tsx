import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { StatusBadge } from '../components/StatusBadge';
import { FareCard, FareCardLine, FareCardDivider } from '../components/FareCard';
import { Skeleton } from '../components/Skeleton';
import { getJobDetail, getErrorMessage } from '../api';
import { formatAddress } from '../lib/address';

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

function maskName(name: string | undefined | null): string {
  if (!name?.trim()) return 'Customer';
  return name
    .trim()
    .split(/\s+/)
    .map((part) => (part.length <= 1 ? part : `${part[0]}${'*'.repeat(Math.min(3, part.length - 1))}`))
    .join(' ');
}

function formatCategory(id: string | undefined): string {
  if (!id) return '—';
  return id.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
}

export function TripSummaryPage() {
  const { bookingId } = useParams<{ bookingId: string }>();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [detail, setDetail] = useState<Awaited<ReturnType<typeof getJobDetail>> | null>(null);

  useEffect(() => {
    if (!bookingId) return;
    getJobDetail(bookingId)
      .then((d) => {
        if (d.status !== 'completed' && d.status !== 'cancelled') {
          navigate(`/trip/${d.id}`, { replace: true });
          return;
        }
        setDetail(d);
      })
      .catch((err) => setError(getErrorMessage(err, 'Could not load trip details.')))
      .finally(() => setLoading(false));
  }, [bookingId, navigate]);

  if (loading) {
    return (
      <Screen eyebrow="Trips" title="Trip summary" onBack={() => navigate('/history')}>
        <Skeleton width="60%" height={14} />
        <Skeleton width="100%" height={80} radius={12} />
      </Screen>
    );
  }

  if (error || !detail) {
    return (
      <Screen eyebrow="Trips" title="Trip summary" onBack={() => navigate('/history')}>
        <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error || 'Trip not found.'}</p>
      </Screen>
    );
  }

  const timeline: { label: string; time: string }[] = [
    { label: 'Booked', time: formatTime(detail.created_at) },
  ];
  if (detail.started_at) {
    timeline.push({ label: 'Trip started', time: formatTime(detail.started_at) });
  }
  detail.stops.forEach((stop) => {
    if (stop.arrived_at) {
      timeline.push({ label: `Arrived at stop ${stop.sequence}`, time: formatTime(stop.arrived_at) });
    }
    const completedAt = (stop as { completed_at?: string }).completed_at;
    if (completedAt) {
      timeline.push({ label: `Completed stop ${stop.sequence}`, time: formatTime(completedAt) });
    }
  });
  timeline.push({
    label: detail.status === 'cancelled' ? 'Cancelled' : 'Completed',
    time: formatTime(detail.updated_at),
  });

  return (
    <Screen
      eyebrow={`Trip #${detail.id.slice(0, 8).toUpperCase()}`}
      title="Trip summary"
      onBack={() => navigate('/history')}
    >
      <div style={{ marginBottom: 12 }}>
        <StatusBadge status={detail.status} />
      </div>

      <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 16, background: 'var(--surface)' }}>
        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 4 }}>Customer</div>
        <div style={{ fontSize: 15, fontWeight: 600 }}>{maskName(detail.customer_name)}</div>
        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8 }}>
          Vehicle: {formatCategory(detail.vehicle_category_id)}
        </div>
      </div>

      <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 16, background: 'var(--surface)' }}>
        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 10 }}>Route</div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start', marginBottom: 10 }}>
          <span style={{ color: 'var(--success)', fontWeight: 700 }}>A</span>
          <div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Pickup</div>
            <div style={{ fontSize: 14 }}>{formatAddress(detail.pickup_address)}</div>
          </div>
        </div>
        {detail.stops.map((stop) => (
          <div key={stop.id} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', marginBottom: 10 }}>
            <span style={{ color: 'var(--accent-strong)', fontWeight: 700 }}>{stop.sequence}</span>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Stop {stop.sequence}</div>
              <div style={{ fontSize: 14 }}>{formatAddress(stop.address_snapshot)}</div>
              <div style={{ marginTop: 4 }}>
                <StatusBadge status={stop.status === 'completed' ? 'completed' : 'in_progress'} />
              </div>
            </div>
          </div>
        ))}
      </div>

      <FareCard label="Fare breakdown" id={detail.id.slice(0, 8).toUpperCase()}>
        <FareCardLine label="Your earnings" value={money(detail.fare_breakdown.final_fare)} emphasis />
        {detail.fare_breakdown.platform_fee != null && (
          <>
            <FareCardDivider />
            <FareCardLine label="Platform fee" value={money(detail.fare_breakdown.platform_fee)} muted />
          </>
        )}
      </FareCard>

      <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 16, background: 'var(--surface)' }}>
        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 12 }}>Timeline</div>
        {timeline.map((event, i) => (
          <div
            key={`${event.label}-${i}`}
            style={{
              display: 'flex',
              gap: 12,
              paddingBottom: i < timeline.length - 1 ? 12 : 0,
              marginBottom: i < timeline.length - 1 ? 12 : 0,
              borderBottom: i < timeline.length - 1 ? '1px solid var(--border)' : 'none',
            }}
          >
            <div
              style={{
                width: 8,
                height: 8,
                borderRadius: '50%',
                background: 'var(--accent)',
                marginTop: 5,
                flexShrink: 0,
              }}
            />
            <div>
              <div style={{ fontSize: 14, fontWeight: 500 }}>{event.label}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{event.time}</div>
            </div>
          </div>
        ))}
      </div>
    </Screen>
  );
}
