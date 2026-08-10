import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { StatusBadge } from '../components/StatusBadge';
import { Waybill, WaybillLine, WaybillDivider } from '../components/Waybill';
import { getBooking, getErrorMessage, type Booking } from '../api';

const ACTIVE_STATUSES = new Set(['scheduled', 'searching', 'driver_assigned', 'in_progress']);

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

export function TripDetailPage() {
  const { bookingId } = useParams<{ bookingId: string }>();
  const navigate = useNavigate();
  const [booking, setBooking] = useState<Booking | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!bookingId) return;
    getBooking(bookingId)
      .then((b) => {
        if (ACTIVE_STATUSES.has(b.status)) {
          navigate(`/track/${b.id}`, { replace: true });
          return;
        }
        setBooking(b);
      })
      .catch((err) => setError(getErrorMessage(err, 'Could not load trip.')));
  }, [bookingId, navigate]);

  if (!booking) {
    return (
      <Screen eyebrow="Trip" title="Loading…" onBack={() => navigate('/history')}>
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
      </Screen>
    );
  }

  const fb = booking.fare_breakdown;

  return (
    <Screen
      eyebrow={`Trip #${booking.id.slice(0, 8).toUpperCase()}`}
      title="Trip details"
      onBack={() => navigate('/history')}
    >
      <StatusBadge status={booking.status} />
      <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{new Date(booking.created_at).toLocaleString()}</div>

      <Waybill label="Fare summary" id={booking.id.slice(0, 8).toUpperCase()}>
        <WaybillLine label="Base fare" value={money(fb.base_fare)} />
        <WaybillLine label="Distance" value={money(fb.distance_charge)} />
        <WaybillLine label="Platform fee" value={money(fb.platform_fee)} />
        <WaybillLine label="Tax" value={money(fb.tax)} />
        {fb.coupon_discount > 0 && (
          <WaybillLine label="Coupon" value={`−${money(fb.coupon_discount)}`} muted />
        )}
        <WaybillDivider />
        <WaybillLine label="Total" value={money(fb.final_fare)} emphasis />
      </Waybill>

      {booking.status === 'completed' && (
        <>
          <Button variant="ghost" onClick={() => navigate(`/receipt/${booking.id}`)}>
            View receipt
          </Button>
          <Button variant="ghost" onClick={() => navigate(`/track/${booking.id}`)}>
            Rate trip
          </Button>
        </>
      )}

      <Button onClick={() => navigate('/home')}>Book again</Button>
    </Screen>
  );
}
