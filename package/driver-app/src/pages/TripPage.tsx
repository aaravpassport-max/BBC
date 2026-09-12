import { useState, useEffect, useCallback, useMemo } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { StatusBadge } from '../components/StatusBadge';
import { Skeleton } from '../components/Skeleton';
import { TripChat } from '../components/TripChat';
import { LiveMap } from '../components/LiveMap';
import { POSITIVE_RATING_TAGS, NEGATIVE_RATING_TAGS } from '../constants/brand';
import { formatAddress } from '../lib/address';
import { formatDistanceKm } from '../lib/geo';
import { useRoute } from '../hooks/useRoute';
import { getActiveJob, verifyPickupOtp, completeStop, arriveAtPickup, arriveAtStop, rateBooking, triggerSos, callCustomer, uploadProofPhoto, collectTripPayment, getErrorMessage, type ActiveJob } from '../api';
import { Geolocation } from '@capacitor/geolocation';

// Deep-links to the device's own maps app rather than embedding a routing
// engine or maps SDK in this app — this URL format is Google Maps'
// documented universal link: on Android with the Google Maps app
// installed it opens natively via an Android intent; otherwise it falls
// back to Google Maps in the browser. No API key or native plugin needed
// either way (P1 gap-analysis item — turn-by-turn navigation).
function openNavigation(lat: number, lng: number) {
  window.open(`https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`, '_blank');
}

export function TripPage() {
  const { bookingId } = useParams<{ bookingId: string }>();
  const navigate = useNavigate();
  const [job, setJob] = useState<ActiveJob | null>(null);
  const [otp, setOtp] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [sosSent, setSosSent] = useState(false);
  const [ratingStars, setRatingStars] = useState(0);
  const [ratingTags, setRatingTags] = useState<string[]>([]);
  const [tripDone, setTripDone] = useState(false);
  const [arrivedPickup, setArrivedPickup] = useState(false);
  const [proofPreview, setProofPreview] = useState<string | null>(null);
  const [proofUrl, setProofUrl] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    try {
      const activeJob = await getActiveJob();
      if (!activeJob || activeJob.id !== bookingId) {
        navigate('/home', { replace: true });
        return;
      }
      setJob(activeJob);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load this trip.'));
    } finally {
      setLoading(false);
    }
  }, [bookingId, navigate]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const nextStop = job?.stops.find((s) => s.status !== 'completed');
  const awaitingPickup = job?.status === 'driver_assigned';
  const needsPhotoProof = !awaitingPickup && nextStop?.delivery_preference === 'photo_proof';

  const routeWaypoints = useMemo(() => {
    if (!job) return [];
    const pickup = { lat: job.pickup_lat, lng: job.pickup_lng };
    const targetStop = awaitingPickup ? job.stops[0] : nextStop;
    if (targetStop) return [pickup, { lat: targetStop.drop_lat, lng: targetStop.drop_lng }];
    return [];
  }, [job, awaitingPickup, nextStop]);

  const { route } = useRoute(routeWaypoints);

  async function handleArrivePickup() {
    if (!bookingId) return;
    setSubmitting(true);
    setError('');
    try {
      await arriveAtPickup(bookingId);
      setArrivedPickup(true);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not mark arrival.'));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleArriveStop() {
    if (!bookingId || !nextStop) return;
    setSubmitting(true);
    setError('');
    try {
      await arriveAtStop(bookingId, nextStop.id);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not mark arrival.'));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleVerifyPickup() {
    if (!bookingId) return;
    setSubmitting(true);
    setError('');
    try {
      await verifyPickupOtp(bookingId, otp);
      setOtp('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not verify this code.'));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleCaptureProof() {
    setSubmitting(true);
    setError('');
    try {
      const photo = await Camera.getPhoto({
        quality: 80,
        resultType: CameraResultType.DataUrl,
        source: CameraSource.Camera,
        allowEditing: false,
      });
      if (!photo.dataUrl) throw new Error('No photo captured');
      setProofPreview(photo.dataUrl);
      const uploaded = await uploadProofPhoto(photo.dataUrl);
      setProofUrl(uploaded.url);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not capture delivery photo.'));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleCompleteStop() {
    if (!bookingId || !nextStop) return;
    setSubmitting(true);
    setError('');
    try {
      const result = await completeStop(
        bookingId,
        nextStop.id,
        needsPhotoProof ? undefined : otp,
        needsPhotoProof ? proofUrl ?? undefined : undefined
      );
      setOtp('');
      setProofPreview(null);
      setProofUrl(null);
      if (result.tripCompleted) {
        setTripDone(true);
      } else {
        await refresh();
      }
    } catch (err) {
      setError(getErrorMessage(err, 'Could not complete this stop.'));
    } finally {
      setSubmitting(false);
    }
  }

  if (loading || !job) {
    return (
      <Screen eyebrow="Active trip" title="Loading your trip…">
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Skeleton width="50%" height={14} />
          <Skeleton width="70%" height={40} radius={12} />
        </div>
      </Screen>
    );
  }

  return (
    <Screen
      eyebrow={`Trip #${job.id.slice(0, 8).toUpperCase()}`}
      title={awaitingPickup ? 'Head to pickup' : 'On the way to drop'}
    >
      <StatusBadge status={job.status} />

      {job.pickup_address && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: '12px 16px', background: 'var(--surface)' }}>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 4 }}>Pickup</div>
          <div style={{ fontSize: 14 }}>{formatAddress(job.pickup_address)}</div>
        </div>
      )}

      <LiveMap
        pickup={{ lat: job.pickup_lat, lng: job.pickup_lng }}
        drops={job.stops.map((s) => ({ lat: s.drop_lat, lng: s.drop_lng }))}
        driver={null}
        routePoints={route?.geometry}
      />
      {route && (
        <p style={{ fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>
          Route ~{formatDistanceKm(route.distanceM / 1000)} · ~{route.etaMinutes} min
        </p>
      )}

      <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 16, background: 'var(--surface)' }}>
        {job.stops.map((stop) => (
          <div
            key={stop.id}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              padding: '8px 0',
              opacity: stop.status === 'completed' ? 0.5 : 1,
            }}
          >
            <span
              style={{
                width: 20,
                height: 20,
                borderRadius: '50%',
                background: stop.status === 'completed' ? 'var(--success)' : 'var(--border)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 11,
                fontWeight: 700,
                flexShrink: 0,
              }}
            >
              {stop.status === 'completed' ? '✓' : stop.sequence}
            </span>
            <span style={{ fontSize: 14 }}>
              Stop {stop.sequence}
              {stop.address_snapshot && ` — ${formatAddress(stop.address_snapshot)}`}
              {stop.instructions ? ` (${stop.instructions})` : ''}
            </span>
          </div>
        ))}
      </div>

      {job.payment_method === 'upi' && job.payment_status === 'pending_collection' && (
        <div style={{ border: '1px solid var(--accent)', borderRadius: 12, padding: 14, background: 'var(--accent-soft)' }}>
          <p style={{ fontSize: 13, marginBottom: 10 }}>Collect UPI/cash payment from the customer before completing the trip.</p>
          <Button
            variant="ghost"
            loading={submitting}
            onClick={() => {
              if (!bookingId) return;
              setSubmitting(true);
              void collectTripPayment(bookingId)
                .then(() => refresh())
                .catch((err) => setError(getErrorMessage(err, 'Could not confirm payment collection.')))
                .finally(() => setSubmitting(false));
            }}
          >
            💵 Payment collected
          </Button>
        </div>
      )}

      <Button
        variant="ghost"
        onClick={() => {
          if (!job) return;
          if (awaitingPickup) {
            openNavigation(job.pickup_lat, job.pickup_lng);
          } else if (nextStop) {
            openNavigation(nextStop.drop_lat, nextStop.drop_lng);
          }
        }}
      >
        🧭 Navigate to {awaitingPickup ? 'pickup' : `stop ${nextStop?.sequence}`}
      </Button>

      {awaitingPickup && !arrivedPickup && (
        <Button variant="ghost" onClick={() => void handleArrivePickup()} loading={submitting}>
          📍 I&apos;ve arrived at pickup
        </Button>
      )}
      {awaitingPickup && arrivedPickup && (
        <p style={{ fontSize: 13, color: 'var(--success)', textAlign: 'center' }}>Customer notified of your arrival</p>
      )}

      {!awaitingPickup && nextStop && nextStop.status === 'pending' && (
        <Button variant="ghost" onClick={() => void handleArriveStop()} loading={submitting}>
          📍 I&apos;ve arrived at stop {nextStop.sequence}
        </Button>
      )}

      {bookingId && (
        <Button
          variant="ghost"
          onClick={() => {
            void callCustomer(bookingId).then((r) => {
              window.location.href = r.call_uri;
            });
          }}
        >
          📞 Call customer
        </Button>
      )}

      <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 18, background: 'var(--surface)' }}>
        {needsPhotoProof ? (
          <>
            <p style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 10 }}>
              This stop requires a delivery photo instead of an OTP. Capture proof of handoff at stop {nextStop?.sequence}.
            </p>
            {proofPreview && (
              <img
                src={proofPreview}
                alt="Delivery proof"
                style={{ width: '100%', borderRadius: 10, marginBottom: 12, maxHeight: 200, objectFit: 'cover' }}
              />
            )}
            <Button variant="ghost" onClick={() => void handleCaptureProof()} loading={submitting}>
              {proofPreview ? 'Retake photo' : '📷 Capture delivery photo'}
            </Button>
            <div style={{ marginTop: 12 }}>
              <Button onClick={handleCompleteStop} loading={submitting} disabled={!proofUrl}>
                Confirm delivery with photo
              </Button>
            </div>
          </>
        ) : (
          <>
            <p style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 10 }}>
              {awaitingPickup
                ? 'Ask the customer for their pickup code and enter it below.'
                : `Ask the customer for the drop code for stop ${nextStop?.sequence} and enter it below.`}
            </p>
            <input
              value={otp}
              onChange={(e) => setOtp(e.target.value.replace(/\D/g, '').slice(0, 4))}
              inputMode="numeric"
              placeholder="0000"
              maxLength={4}
              style={{
                width: '100%',
                textAlign: 'center',
                fontFamily: 'var(--font-mono)',
                fontSize: 28,
                fontWeight: 700,
                letterSpacing: '0.2em',
                padding: '14px',
                background: 'var(--bg)',
                border: '1px solid var(--border)',
                borderRadius: 10,
                color: 'var(--text)',
                marginBottom: 12,
              }}
            />
            <Button
              onClick={awaitingPickup ? handleVerifyPickup : handleCompleteStop}
              loading={submitting}
              disabled={otp.length !== 4}
            >
              {awaitingPickup ? 'Confirm pickup' : 'Confirm drop'}
            </Button>
          </>
        )}
      </div>

      <TripChat bookingId={job.id} myRole="driver" />

      <Button
        variant="danger"
        onClick={() => {
          if (!bookingId || sosSent) return;
          void (async () => {
            try {
              let lat: number | undefined;
              let lng: number | undefined;
              try {
                const pos = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 5000 });
                lat = pos.coords.latitude;
                lng = pos.coords.longitude;
              } catch {
                // optional
              }
              await triggerSos(bookingId, lat, lng);
              setSosSent(true);
            } catch (err) {
              setError(getErrorMessage(err, 'Could not send SOS.'));
            }
          })();
        }}
        disabled={sosSent}
      >
        {sosSent ? 'SOS sent' : 'SOS — Emergency'}
      </Button>

      {tripDone && (
        <div style={{ textAlign: 'center', paddingTop: 8 }}>
          <p style={{ fontSize: 14, color: 'var(--text-muted)' }}>Rate your customer</p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', margin: '10px 0' }}>
            {[1, 2, 3, 4, 5].map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => setRatingStars(s)}
                style={{ background: 'none', border: 'none', fontSize: 28, color: s <= ratingStars ? 'var(--accent)' : 'var(--border)', cursor: 'pointer' }}
              >
                ★
              </button>
            ))}
          </div>
          {ratingStars > 0 && (
            <>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, justifyContent: 'center', margin: '10px 0' }}>
                {(ratingStars >= 4 ? POSITIVE_RATING_TAGS : NEGATIVE_RATING_TAGS).map((tag) => (
                  <button
                    key={tag}
                    type="button"
                    onClick={() => setRatingTags((prev) => (prev.includes(tag) ? prev.filter((t) => t !== tag) : [...prev, tag]))}
                    style={{
                      border: `1px solid ${ratingTags.includes(tag) ? 'var(--accent)' : 'var(--border)'}`,
                      background: ratingTags.includes(tag) ? 'var(--accent-soft)' : 'var(--surface)',
                      borderRadius: 20,
                      padding: '4px 10px',
                      fontSize: 12,
                      cursor: 'pointer',
                    }}
                  >
                    {tag}
                  </button>
                ))}
              </div>
              <Button
                onClick={() => {
                  if (!bookingId) return;
                  void rateBooking(bookingId, ratingStars, ratingTags).then(() => navigate('/home', { replace: true }));
                }}
              >
                Submit & go home
              </Button>
            </>
          )}
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, textAlign: 'center' }}>{error}</p>}
    </Screen>
  );
}
