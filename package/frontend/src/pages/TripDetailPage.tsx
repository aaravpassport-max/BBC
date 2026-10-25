import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { StatusBadge } from '../components/StatusBadge';
import { FareCard, FareCardLine, FareCardDivider } from '../components/FareCard';
import { RatingPanel } from '../components/RatingPanel';
import { TipPanel } from '../components/TipPanel';
import { getBooking, rateBooking, downloadInvoicePdf, getErrorMessage, type Booking } from '../api';
import { formatAddress } from '../lib/address';

const ACTIVE_STATUSES = new Set(['scheduled', 'searching', 'driver_assigned', 'in_progress']);

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

export function TripDetailPage() {
  const { bookingId } = useParams<{ bookingId: string }>();
  const navigate = useNavigate();
  const [booking, setBooking] = useState<Booking | null>(null);
  const [error, setError] = useState('');
  const [rated, setRated] = useState(false);
  const [ratingSubmitting, setRatingSubmitting] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [showTip, setShowTip] = useState(false);

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

  async function handleRate(stars: number, tags: string[], comment: string) {
    if (!bookingId) return;
    setRatingSubmitting(true);
    try {
      await rateBooking(bookingId, stars, tags, comment || undefined);
      setRated(true);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not submit rating.'));
    } finally {
      setRatingSubmitting(false);
    }
  }

  async function handleDownloadInvoice() {
    if (!bookingId) return;
    setDownloading(true);
    try {
      const blob = await downloadInvoicePdf(bookingId);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `invoice-${bookingId.slice(0, 8)}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not download invoice.'));
    } finally {
      setDownloading(false);
    }
  }

  if (!booking) {
    return (
      <Screen eyebrow="Trip" title="Loading…" onBack={() => navigate('/history')}>
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
      </Screen>
    );
  }

  const fb = booking.fare_breakdown;

  return (
    <Screen eyebrow={`Trip #${booking.id.slice(0, 8).toUpperCase()}`} title="Trip details" onBack={() => navigate('/history')}>
      <StatusBadge status={booking.status} />
      <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{new Date(booking.created_at).toLocaleString()}</div>

      <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)' }}>
        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 4 }}>Route</div>
        <div style={{ fontSize: 14, marginBottom: 8 }}>
          <span style={{ color: 'var(--success)', fontWeight: 600 }}>Pickup · </span>
          {formatAddress(booking.pickup_address)}
        </div>
        {booking.stops?.map((s, i) => (
          <div key={s.id} style={{ fontSize: 14, marginBottom: 4 }}>
            <span style={{ color: 'var(--accent)', fontWeight: 600 }}>Drop {i + 1} · </span>
            {formatAddress(s.address_snapshot)}
            <span style={{ fontSize: 12, color: 'var(--text-muted)', marginLeft: 6 }}>
              ({s.status.replace(/_/g, ' ')})
            </span>
          </div>
        ))}
      </div>

      {booking.driver && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)' }}>
          <div style={{ fontWeight: 600 }}>{booking.driver.name}</div>
          {booking.driver.rating != null && <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>★ {booking.driver.rating.toFixed(1)}</div>}
          {booking.driver.vehicle && (
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {booking.driver.vehicle.plate} · {booking.driver.vehicle.category.replace(/_/g, ' ')}
            </div>
          )}
        </div>
      )}

      <FareCard label="Fare summary" id={booking.id.slice(0, 8).toUpperCase()}>
        <FareCardLine label="Base fare" value={money(fb.base_fare)} />
        <FareCardLine label="Distance" value={money(fb.distance_charge)} />
        <FareCardLine label="Platform fee" value={money(fb.platform_fee)} />
        <FareCardLine label="Tax" value={money(fb.tax)} />
        {fb.coupon_discount > 0 && <FareCardLine label="Coupon" value={`−${money(fb.coupon_discount)}`} muted />}
        <FareCardDivider />
        <FareCardLine label="Total" value={money(fb.final_fare)} emphasis />
      </FareCard>

      {booking.status === 'completed' && (
        <>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Button variant="ghost" style={{ width: 'auto', flex: 1 }} onClick={() => navigate('/invoices')}>
              View invoices
            </Button>
            <Button variant="ghost" style={{ width: 'auto', flex: 1 }} loading={downloading} onClick={() => void handleDownloadInvoice()}>
              Download PDF
            </Button>
          </div>
          <Button variant="ghost" onClick={() => navigate(`/receipt/${booking.id}`)}>View receipt</Button>
          <Button
            variant="ghost"
            onClick={() =>
              navigate('/support/new', {
                state: { linkedBookingId: booking.id, category: 'Trip issue' },
              })
            }
          >
            Report issue
          </Button>
          {!rated ? (
            <RatingPanel onSubmit={handleRate} submitting={ratingSubmitting} />
          ) : (
            <p style={{ textAlign: 'center', color: 'var(--success)', fontSize: 14 }}>Thanks for rating your trip.</p>
          )}
          {showTip ? (
            <TipPanel bookingId={booking.id} onTipped={() => setShowTip(false)} />
          ) : (
            <Button variant="ghost" onClick={() => setShowTip(true)}>Tip driver again</Button>
          )}
        </>
      )}

      <Button onClick={() => navigate('/home')}>Book again</Button>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
    </Screen>
  );
}
