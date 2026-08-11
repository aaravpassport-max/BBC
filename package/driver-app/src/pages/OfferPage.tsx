import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useLocation, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { FareCard, FareCardLine } from '../components/FareCard';
import { LiveMap } from '../components/LiveMap';
import { acceptJob, declineJob, getPendingOffer, getErrorMessage, type PendingOffer } from '../api';
import { formatAddress } from '../lib/address';
import { useRoute } from '../hooks/useRoute';
import { formatDistanceKm } from '../lib/geo';

function formatCategory(id: string | undefined): string {
  if (!id) return '—';
  return id.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function OfferPage() {
  const { offerId } = useParams<{ offerId: string }>();
  const location = useLocation();
  const navigate = useNavigate();
  const goHome = useCallback(() => navigate('/home', { replace: true }), [navigate]);
  const offer = (location.state as { offer: PendingOffer } | undefined)?.offer;
  const [loadedOffer, setLoadedOffer] = useState<PendingOffer | null>(offer ?? null);
  const totalSecondsRef = useRef<number | null>(null);

  useEffect(() => {
    if (offer) {
      setLoadedOffer(offer);
      return;
    }
    if (!offerId) return;
    getPendingOffer()
      .then((o) => {
        if (o && o.offer_id === offerId) setLoadedOffer(o);
        else goHome();
      })
      .catch(() => goHome());
  }, [offer, offerId, goHome]);

  const activeOffer = loadedOffer;

  const [secondsLeft, setSecondsLeft] = useState(0);
  const [acting, setActing] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!activeOffer) return;
    if (totalSecondsRef.current === null) {
      totalSecondsRef.current = Math.max(
        1,
        Math.floor((new Date(activeOffer.expires_at).getTime() - Date.now()) / 1000)
      );
    }
    const tick = () => {
      const remaining = Math.max(0, Math.floor((new Date(activeOffer.expires_at).getTime() - Date.now()) / 1000));
      setSecondsLeft(remaining);
      if (remaining === 0) goHome();
    };
    tick();
    const interval = setInterval(tick, 1000);
    return () => clearInterval(interval);
  }, [activeOffer, goHome]);

  if (!activeOffer || !offerId) return null;

  const pickup = {
    lat: activeOffer.pickup_address_snapshot?.lat ?? activeOffer.pickup_lat,
    lng: activeOffer.pickup_address_snapshot?.lng ?? activeOffer.pickup_lng,
  };
  const drop = activeOffer.first_drop_address;
  const { route } = useRoute([pickup, drop?.lat != null && drop?.lng != null ? { lat: drop.lat, lng: drop.lng } : null]);

  const drops =
    drop?.lat != null && drop?.lng != null ? [{ lat: drop.lat, lng: drop.lng }] : [];

  async function handleAccept() {
    setActing(true);
    setError('');
    try {
      const result = await acceptJob(offerId!);
      navigate(`/trip/${result.bookingId}`, { replace: true });
    } catch (err) {
      setError(getErrorMessage(err, 'This offer is no longer available.'));
      setTimeout(goHome, 1500);
    } finally {
      setActing(false);
    }
  }

  async function handleDecline() {
    setActing(true);
    try {
      await declineJob(offerId!);
    } catch {
      // Declining is best-effort from the driver's point of view — even if
      // the request fails, returning home is still the right outcome.
    } finally {
      goHome();
    }
  }

  const totalSeconds = totalSecondsRef.current ?? 15;
  const ringPct = Math.max(0, Math.min(100, (secondsLeft / totalSeconds) * 100));

  return (
    <Screen eyebrow="New job" title="Job offer">
      <div
        style={{
          padding: '12px 14px',
          borderRadius: 12,
          marginBottom: 12,
          fontWeight: 700,
          fontSize: 15,
          textAlign: 'center',
          background: activeOffer.booking_type === 'ride' ? '#e8f4fd' : '#fff7ed',
          color: activeOffer.booking_type === 'ride' ? '#0369a1' : '#c2410c',
          border: `2px solid ${activeOffer.booking_type === 'ride' ? '#7dd3fc' : '#fdba74'}`,
        }}
      >
        {activeOffer.booking_type === 'ride' ? '🚗 PASSENGER RIDE' : '📦 PARCEL DELIVERY'}
      </div>
      <div style={{ textAlign: 'center', margin: '4px 0 8px' }}>
        <div
          style={{
            width: 64,
            height: 64,
            borderRadius: '50%',
            margin: '0 auto 10px',
            background: `conic-gradient(var(--accent) ${ringPct}%, var(--border) ${ringPct}%)`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <div
            style={{
              width: 52,
              height: 52,
              borderRadius: '50%',
              background: 'var(--bg)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontFamily: 'var(--font-mono)',
              fontWeight: 700,
              fontSize: 18,
            }}
          >
            {secondsLeft}
          </div>
        </div>
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Respond before this offer expires</p>
      </div>

      <LiveMap pickup={pickup} drops={drops} driver={null} routePoints={route?.geometry} />

      <FareCard label="Job details">
        <FareCardLine label="Type" value={activeOffer.booking_type === 'ride' ? 'Passenger ride' : 'Parcel delivery'} />
        {activeOffer.booking_type === 'ride' && activeOffer.passenger_count != null && (
          <FareCardLine label="Passengers" value={String(activeOffer.passenger_count)} />
        )}
        <FareCardLine label="Estimated earnings" value={`₹${activeOffer.fare_breakdown.final_fare.toFixed(2)}`} emphasis />
        <FareCardLine label="Pickup" value={formatAddress(activeOffer.pickup_address_snapshot)} />
        <FareCardLine label="First drop" value={formatAddress(activeOffer.first_drop_address, '—')} />
        {route != null ? (
          <FareCardLine
            label="Route to first drop"
            value={`${formatDistanceKm(route.distanceM / 1000)} · ~${route.etaMinutes} min`}
          />
        ) : (
          drop?.lat != null &&
          drop?.lng != null && (
            <FareCardLine label="Route to first drop" value="Calculating route…" />
          )
        )}
        {(activeOffer.stop_count ?? 0) > 0 && (
          <FareCardLine
            label="Stops"
            value={`${activeOffer.stop_count} stop${activeOffer.stop_count === 1 ? '' : 's'}`}
          />
        )}
        {activeOffer.vehicle_category_id && (
          <FareCardLine label="Vehicle type" value={formatCategory(activeOffer.vehicle_category_id)} />
        )}
      </FareCard>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, textAlign: 'center' }}>{error}</p>}

      <Button onClick={handleAccept} loading={acting}>
        Accept
      </Button>
      <Button variant="ghost" onClick={handleDecline} disabled={acting}>
        Decline
      </Button>
    </Screen>
  );
}
