import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { StatusBadge } from '../components/StatusBadge';
import { Waybill, WaybillLine, WaybillDivider } from '../components/Waybill';
import { LiveMap } from '../components/LiveMap';
import { TripChat } from '../components/TripChat';
import { notify } from '../lib/notify';
import { getBooking, getDriverLocation, cancelBooking, rateBooking, getErrorMessage, type Booking, type DriverLocation } from '../api';

const POLL_INTERVAL_MS = 3000;
const CANCELLABLE = new Set(['scheduled', 'searching', 'driver_assigned']);
const TRACKABLE = new Set(['driver_assigned', 'in_progress']);

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

export function TrackPage() {
  const { bookingId } = useParams<{ bookingId: string }>();
  const navigate = useNavigate();
  const [booking, setBooking] = useState<Booking | null>(null);
  const [driverLocation, setDriverLocation] = useState<DriverLocation | null>(null);
  const [error, setError] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [rated, setRated] = useState(false);
  const [ratingSubmitting, setRatingSubmitting] = useState(false);
  const [selectedStars, setSelectedStars] = useState(0);
  const previousStatus = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    if (!bookingId) return;
    try {
      const b = await getBooking(bookingId);

      // Fire a real notification only on a genuine transition, never on
      // every 3s poll tick that happens to report the same status again —
      // that would spam the notification tray instead of informing anyone.
      if (previousStatus.current && previousStatus.current !== b.status) {
        if (b.status === 'driver_assigned') {
          void notify('Driver on the way', 'Your driver has been assigned and is heading to pickup.');
        } else if (b.status === 'in_progress') {
          void notify('Pickup verified', 'Your delivery is now on its way.');
        } else if (b.status === 'completed') {
          void notify('Delivery complete', 'Your delivery has been completed. Rate your experience!');
        } else if (b.status === 'no_drivers_found') {
          void notify('No drivers available', 'We could not find a driver for this booking right now.');
        }
      }
      previousStatus.current = b.status;

      setBooking(b);
      if (TRACKABLE.has(b.status)) {
        // Polled alongside the booking itself, not as a separate timer —
        // one interval driving both keeps the map and the status text
        // updating in lockstep rather than drifting out of sync.
        const loc = await getDriverLocation(bookingId);
        setDriverLocation(loc);
      } else {
        setDriverLocation(null);
      }
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load this trip.'));
    }
  }, [bookingId]);

  useEffect(() => {
    void refresh();
    const interval = setInterval(() => {
      void refresh();
    }, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [refresh]);

  async function handleCancel() {
    if (!bookingId) return;
    setCancelling(true);
    try {
      await cancelBooking(bookingId, 'BOOKED_BY_MISTAKE');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not cancel this trip.'));
    } finally {
      setCancelling(false);
    }
  }

  async function handleRate(stars: number) {
    if (!bookingId) return;
    setSelectedStars(stars);
    setRatingSubmitting(true);
    try {
      await rateBooking(bookingId, stars, []);
      setRated(true);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not submit your rating.'));
    } finally {
      setRatingSubmitting(false);
    }
  }

  if (!booking) {
    return (
      <Screen eyebrow="Tracking" title="Loading your trip…">
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
      </Screen>
    );
  }

  const fb = booking.fare_breakdown;

  return (
    <Screen eyebrow={`Trip #${booking.id.slice(0, 8).toUpperCase()}`} title="Your delivery">
      <StatusBadge status={booking.status} />

      {booking.status === 'scheduled' && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          Your delivery is scheduled. We'll start looking for a driver closer to your requested time.
        </p>
      )}

      {(booking.status === 'searching' || booking.status === 'driver_assigned') && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          {booking.status === 'searching'
            ? 'Looking for a nearby driver…'
            : 'Your driver is on the way to pickup.'}
        </p>
      )}

      {TRACKABLE.has(booking.status) && booking.pickup_lat != null && booking.pickup_lng != null && (
        <LiveMap
          pickup={{ lat: booking.pickup_lat, lng: booking.pickup_lng }}
          driver={driverLocation ? { lat: driverLocation.lat, lng: driverLocation.lng } : null}
        />
      )}

      {TRACKABLE.has(booking.status) && bookingId && <TripChat bookingId={bookingId} myRole="customer" />}

      {booking.status === 'no_drivers_found' && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          No drivers were available nearby. You have not been charged — try again in a few minutes.
        </p>
      )}

      {booking.pickup_otp && (booking.status === 'driver_assigned' || booking.status === 'searching') && (
        <div
          style={{
            background: 'var(--surface)',
            border: '1px solid var(--border)',
            borderRadius: 12,
            padding: 18,
            textAlign: 'center',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 8 }}>
            Read this code to your driver at pickup
          </div>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 34, fontWeight: 700, letterSpacing: '0.15em', color: 'var(--accent-strong)' }}>
            {booking.pickup_otp}
          </div>
        </div>
      )}

      {booking.status === 'in_progress' && booking.stops && booking.stops.length > 0 && (
        <div
          style={{
            background: 'var(--surface)',
            border: '1px solid var(--border)',
            borderRadius: 12,
            padding: 18,
            textAlign: 'center',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 8 }}>
            In transit. Drop code, read aloud on arrival
          </div>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 34, fontWeight: 700, letterSpacing: '0.15em', color: 'var(--accent-strong)' }}>
            {booking.stops[0].otp_code}
          </div>
        </div>
      )}

      {CANCELLABLE.has(booking.status) && (
        <Button variant="ghost" onClick={handleCancel} loading={cancelling}>
          Cancel trip
        </Button>
      )}

      {booking.status === 'completed' && (
        <>
          <Waybill label="Trip receipt" id={booking.id.slice(0, 8).toUpperCase()}>
            <WaybillLine label="Base fare" value={money(fb.base_fare)} />
            <WaybillLine label="Distance" value={money(fb.distance_charge)} />
            <WaybillLine label="Platform fee" value={money(fb.platform_fee)} />
            <WaybillLine label="Tax" value={money(fb.tax)} />
            <WaybillDivider />
            <WaybillLine label="Total paid" value={money(fb.final_fare)} emphasis />
          </Waybill>

          <Button variant="ghost" onClick={() => navigate(`/receipt/${booking.id}`)}>
            🧾 View full receipt
          </Button>

          {!rated ? (
            <div style={{ textAlign: 'center', paddingTop: 8 }}>
              <div style={{ fontSize: 14, color: 'var(--text-muted)', marginBottom: 10 }}>How was your delivery?</div>
              <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
                {[1, 2, 3, 4, 5].map((star) => (
                  <button
                    key={star}
                    onClick={() => handleRate(star)}
                    disabled={ratingSubmitting}
                    aria-label={`Rate ${star} star${star > 1 ? 's' : ''}`}
                    style={{
                      background: 'none',
                      border: 'none',
                      fontSize: 32,
                      cursor: 'pointer',
                      color: star <= selectedStars ? 'var(--accent)' : 'var(--border)',
                      lineHeight: 1,
                    }}
                  >
                    ★
                  </button>
                ))}
              </div>
            </div>
          ) : (
            <p style={{ textAlign: 'center', color: 'var(--success)', fontSize: 14 }}>Thanks for rating your trip.</p>
          )}

          <Button variant="ghost" onClick={() => navigate('/home')}>
            Book another trip
          </Button>
        </>
      )}

      {booking.status === 'cancelled' && (
        <Button onClick={() => navigate('/home')}>Book another trip</Button>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
    </Screen>
  );
}
