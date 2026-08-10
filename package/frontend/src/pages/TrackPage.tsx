import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { StatusBadge } from '../components/StatusBadge';
import { FareCard, FareCardLine, FareCardDivider } from '../components/FareCard';
import { LiveMap } from '../components/LiveMap';
import { TripChat } from '../components/TripChat';
import { CancelTripModal } from '../components/CancelTripModal';
import { RatingPanel } from '../components/RatingPanel';
import { TipPanel } from '../components/TipPanel';
import { useBookingRealtime } from '../hooks/useBookingRealtime';
import { useRoute } from '../hooks/useRoute';
import { formatDistanceKm } from '../lib/geo';
import { notify } from '../lib/notify';
import {
  getBooking,
  getDriverLocation,
  cancelBooking,
  rateBooking,
  triggerSos,
  callDriver,
  getErrorMessage,
  type Booking,
  type DriverLocation,
} from '../api';
import type { CancelReasonCode } from '../constants/brand';

const POLL_INTERVAL_MS = 3000;
const CANCELLABLE = new Set(['scheduled', 'searching', 'driver_assigned']);
const TRACKABLE = new Set(['driver_assigned', 'in_progress']);
const SOS_ELIGIBLE = new Set(['driver_assigned', 'in_progress']);

const TIMELINE_STEPS = [
  { status: 'scheduled', label: 'Scheduled', icon: '📅' },
  { status: 'searching', label: 'Finding driver', icon: '🔍' },
  { status: 'driver_assigned', label: 'Driver assigned', icon: '🚚' },
  { status: 'in_progress', label: 'On the way', icon: '📦' },
  { status: 'completed', label: 'Completed', icon: '✓' },
];

const STATUS_ORDER = ['scheduled', 'searching', 'driver_assigned', 'in_progress', 'completed'];

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
  const [showCancelModal, setShowCancelModal] = useState(false);
  const [rated, setRated] = useState(false);
  const [tipped, setTipped] = useState(false);
  const [ratingSubmitting, setRatingSubmitting] = useState(false);
  const [sosSending, setSosSending] = useState(false);
  const [sosSent, setSosSent] = useState(false);
  const previousStatus = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    if (!bookingId) return;
    try {
      const b = await getBooking(bookingId);

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
        const loc = await getDriverLocation(bookingId);
        setDriverLocation(loc);
      } else {
        setDriverLocation(null);
      }
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load this trip.'));
    }
  }, [bookingId]);

  useBookingRealtime(bookingId, {
    onStatusChange: () => {
      void refresh();
    },
    onDriverLocation: (lat, lng) => {
      setDriverLocation({ lat, lng, last_ping_at: new Date().toISOString() });
    },
  });

  useEffect(() => {
    void refresh();
    const interval = setInterval(() => {
      void refresh();
    }, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [refresh]);

  const routeWaypoints = useMemo(() => {
    if (!booking || !TRACKABLE.has(booking.status)) return [];
    const pickup =
      booking.pickup_lat != null && booking.pickup_lng != null
        ? { lat: booking.pickup_lat, lng: booking.pickup_lng }
        : null;
    const driver = driverLocation ? { lat: driverLocation.lat, lng: driverLocation.lng } : null;
    const nextStop = booking.stops?.find((s) => s.status !== 'completed');
    const nextDrop =
      nextStop?.drop_lat != null && nextStop?.drop_lng != null
        ? { lat: nextStop.drop_lat, lng: nextStop.drop_lng }
        : null;

    if (booking.status === 'driver_assigned' && driver && pickup) return [driver, pickup];
    if (booking.status === 'in_progress' && driver && nextDrop) return [driver, nextDrop];
    if (pickup && nextDrop) return [pickup, nextDrop];
    return [];
  }, [booking, driverLocation]);

  const { route } = useRoute(routeWaypoints);

  async function handleCancelConfirm(reason: CancelReasonCode, note?: string) {
    if (!bookingId) throw new Error('Missing booking');
    setCancelling(true);
    try {
      const result = await cancelBooking(bookingId, reason, note);
      await refresh();
      return result;
    } finally {
      setCancelling(false);
    }
  }

  async function handleRate(stars: number, tags: string[], comment: string) {
    if (!bookingId) return;
    setRatingSubmitting(true);
    try {
      await rateBooking(bookingId, stars, tags, comment || undefined);
      setRated(true);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not submit your rating.'));
    } finally {
      setRatingSubmitting(false);
    }
  }

  async function handleSos() {
    if (!bookingId || sosSent) return;
    setSosSending(true);
    setError('');
    try {
      let lat: number | undefined;
      let lng: number | undefined;
      try {
        const pos = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 5000 });
        lat = pos.coords.latitude;
        lng = pos.coords.longitude;
      } catch {
        // Location optional for SOS
      }
      await triggerSos(bookingId, lat, lng);
      setSosSent(true);
      void notify('SOS sent', 'Our safety team has been alerted.');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not send SOS alert.'));
    } finally {
      setSosSending(false);
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
  const currentStatusIndex = STATUS_ORDER.indexOf(booking.status);
  const tripRef = booking.id.slice(0, 8).toUpperCase();

  async function handleShareTrip() {
    const text = `Track my PORTMYSTUFF delivery — Trip #${tripRef}`;
    const url = window.location.href;
    if (navigator.share) {
      try {
        await navigator.share({ title: 'Share trip', text, url });
        return;
      } catch {
        // user cancelled
      }
    }
    await navigator.clipboard.writeText(`${text}\n${url}`);
    void notify('Link copied', 'Trip link copied to clipboard.');
  }

  return (
    <Screen eyebrow={`Trip #${booking.id.slice(0, 8).toUpperCase()}`} title="Your delivery">
      <StatusBadge status={booking.status} />

      <div style={{ display: 'flex', gap: 8 }}>
        <Button variant="ghost" style={{ width: 'auto', flex: 1, padding: '8px 14px' }} onClick={() => void handleShareTrip()}>
          Share trip
        </Button>
      </div>

      {booking.status !== 'cancelled' && booking.status !== 'no_drivers_found' && (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: 16,
          }}
        >
          <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 12 }}>Trip timeline</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
            {TIMELINE_STEPS.map((step, i) => {
              const stepIndex = STATUS_ORDER.indexOf(step.status);
              const done = currentStatusIndex >= stepIndex && currentStatusIndex >= 0;
              const active = booking.status === step.status;
              return (
                <div key={step.status} style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                    <div
                      style={{
                        width: 32,
                        height: 32,
                        borderRadius: '50%',
                        background: done ? 'var(--accent-soft)' : 'var(--bg)',
                        border: `2px solid ${active ? 'var(--accent)' : done ? 'var(--accent)' : 'var(--border)'}`,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: 14,
                        color: done ? 'var(--accent-strong)' : 'var(--text-muted)',
                      }}
                    >
                      {done ? step.icon : '○'}
                    </div>
                    {i < TIMELINE_STEPS.length - 1 && (
                      <div
                        style={{
                          width: 2,
                          height: 20,
                          background: done && currentStatusIndex > stepIndex ? 'var(--accent)' : 'var(--border)',
                          margin: '4px 0',
                        }}
                      />
                    )}
                  </div>
                  <div style={{ paddingBottom: i < TIMELINE_STEPS.length - 1 ? 16 : 0 }}>
                    <div style={{ fontSize: 14, fontWeight: active ? 700 : 600, color: done ? 'var(--text)' : 'var(--text-muted)' }}>
                      {step.label}
                    </div>
                    {active && (
                      <div style={{ fontSize: 12, color: 'var(--accent-strong)', marginTop: 2 }}>Current step</div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {booking.status === 'scheduled' && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          Your delivery is scheduled. We&apos;ll start looking for a driver closer to your requested time.
        </p>
      )}

      {(booking.status === 'searching' || booking.status === 'driver_assigned') && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          {booking.status === 'searching'
            ? 'Looking for a nearby driver…'
            : 'Your driver is on the way to pickup.'}
        </p>
      )}

      {booking.driver && TRACKABLE.has(booking.status) && (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: '14px 16px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <div
              style={{
                width: 44,
                height: 44,
                borderRadius: '50%',
                background: 'var(--accent-soft)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 22,
              }}
            >
              🚚
            </div>
            <div>
              <div style={{ fontSize: 14, fontWeight: 600 }}>{booking.driver.name}</div>
              {booking.driver.rating != null && (
                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>★ {booking.driver.rating.toFixed(1)}</div>
              )}
              {booking.driver.vehicle && (
                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                  {booking.driver.vehicle.plate} · {booking.driver.vehicle.category.replace(/_/g, ' ')}
                </div>
              )}
            </div>
          </div>
          {booking.driver.phone_masked && bookingId && (
            <Button
              variant="ghost"
              style={{ width: 'auto', padding: '8px 14px' }}
              onClick={() => {
                void callDriver(bookingId).then((r) => {
                  window.location.href = r.call_uri;
                });
              }}
            >
              📞 Call
            </Button>
          )}
        </div>
      )}

      {booking.driver_id && !booking.driver && TRACKABLE.has(booking.status) && (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: '14px 16px',
            display: 'flex',
            alignItems: 'center',
            gap: 12,
          }}
        >
          <div
            style={{
              width: 44,
              height: 44,
              borderRadius: '50%',
              background: 'var(--accent-soft)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 22,
            }}
          >
            🚚
          </div>
          <div>
            <div style={{ fontSize: 14, fontWeight: 600 }}>Your driver</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Partner ID {booking.driver_id.slice(0, 8)}</div>
          </div>
        </div>
      )}

      {TRACKABLE.has(booking.status) && booking.pickup_lat != null && booking.pickup_lng != null && (
        <>
          <LiveMap
            pickup={{ lat: booking.pickup_lat, lng: booking.pickup_lng }}
            drops={(booking.stops || [])
              .filter((s) => s.drop_lat != null && s.drop_lng != null)
              .map((s) => ({ lat: s.drop_lat!, lng: s.drop_lng! }))}
            driver={driverLocation ? { lat: driverLocation.lat, lng: driverLocation.lng } : null}
            routePoints={route?.geometry}
          />
          {route && (
            <p style={{ fontSize: 13, color: 'var(--text-muted)', textAlign: 'center', marginTop: -4 }}>
              ETA ~{route.etaMinutes} min · {formatDistanceKm(route.distanceM / 1000)} remaining
            </p>
          )}
        </>
      )}

      {TRACKABLE.has(booking.status) && bookingId && <TripChat bookingId={bookingId} myRole="customer" />}

      {SOS_ELIGIBLE.has(booking.status) && (
        <Button variant="danger" onClick={() => void handleSos()} loading={sosSending} disabled={sosSent}>
          {sosSent ? 'SOS alert sent' : 'SOS — Emergency help'}
        </Button>
      )}

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
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {booking.stops.map((stop, i) => (
            <div
              key={stop.id}
              style={{
                background: 'var(--surface)',
                border: '1px solid var(--border)',
                borderRadius: 12,
                padding: 18,
                textAlign: 'center',
              }}
            >
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 8 }}>
                Drop {booking.stops!.length > 1 ? i + 1 : ''} code — read aloud on arrival
              </div>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 34, fontWeight: 700, letterSpacing: '0.15em', color: 'var(--accent-strong)' }}>
                {stop.otp_code}
              </div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 6, textTransform: 'capitalize' }}>
                Status: {stop.status.replace(/_/g, ' ')}
              </div>
            </div>
          ))}
        </div>
      )}

      {CANCELLABLE.has(booking.status) && (
        <Button variant="ghost" onClick={() => setShowCancelModal(true)}>
          Cancel trip
        </Button>
      )}

      {booking.status === 'completed' && (
        <>
          <FareCard label="Trip receipt" id={booking.id.slice(0, 8).toUpperCase()}>
            <FareCardLine label="Base fare" value={money(fb.base_fare)} />
            <FareCardLine label="Distance" value={money(fb.distance_charge)} />
            <FareCardLine label="Platform fee" value={money(fb.platform_fee)} />
            <FareCardLine label="Tax" value={money(fb.tax)} />
            <FareCardDivider />
            <FareCardLine label="Total paid" value={money(fb.final_fare)} emphasis />
          </FareCard>

          <Button variant="ghost" onClick={() => navigate(`/receipt/${booking.id}`)}>
            View full receipt
          </Button>

          {!rated ? (
            <RatingPanel onSubmit={handleRate} submitting={ratingSubmitting} />
          ) : !tipped ? (
            <>
              <p style={{ textAlign: 'center', color: 'var(--success)', fontSize: 14 }}>Thanks for rating your trip.</p>
              <TipPanel bookingId={booking.id} onTipped={() => setTipped(true)} />
            </>
          ) : (
            <p style={{ textAlign: 'center', color: 'var(--success)', fontSize: 14 }}>Thanks for rating and tipping your driver!</p>
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

      <CancelTripModal
        open={showCancelModal}
        onClose={() => setShowCancelModal(false)}
        onConfirm={handleCancelConfirm}
        loading={cancelling}
        bookingId={bookingId}
      />
    </Screen>
  );
}
