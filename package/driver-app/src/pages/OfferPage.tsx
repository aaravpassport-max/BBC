import { useState, useEffect, useCallback } from 'react';
import { useParams, useLocation, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { FareCard, FareCardLine } from '../components/FareCard';
import { LiveMap } from '../components/LiveMap';
import { acceptJob, declineJob, getPendingOffer, getErrorMessage, type PendingOffer } from '../api';

export function OfferPage() {
  const { offerId } = useParams<{ offerId: string }>();
  const location = useLocation();
  const navigate = useNavigate();
  const goHome = useCallback(() => navigate('/home', { replace: true }), [navigate]);
  const offer = (location.state as { offer: PendingOffer } | undefined)?.offer;
  const [loadedOffer, setLoadedOffer] = useState<PendingOffer | null>(offer ?? null);

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

  const ringPct = Math.max(0, Math.min(100, (secondsLeft / 15) * 100));

  return (
    <Screen eyebrow="New job" title="Job offer">
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

      <LiveMap pickup={{ lat: activeOffer.pickup_lat, lng: activeOffer.pickup_lng }} drops={[]} driver={null} />

      <FareCard label="Job details">
        <FareCardLine label="Estimated earnings" value={`₹${activeOffer.fare_breakdown.final_fare.toFixed(2)}`} emphasis />
        <FareCardLine label="Pickup" value={`${activeOffer.pickup_lat.toFixed(4)}, ${activeOffer.pickup_lng.toFixed(4)}`} />
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
