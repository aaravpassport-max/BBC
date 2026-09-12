import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { StatusBadge } from '../components/StatusBadge';
import { Skeleton } from '../components/Skeleton';
import { TripChat } from '../components/TripChat';
import { getActiveJob, verifyPickupOtp, completeStop, getErrorMessage, type ActiveJob } from '../api';

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

  async function handleCompleteStop() {
    if (!bookingId || !nextStop) return;
    setSubmitting(true);
    setError('');
    try {
      const result = await completeStop(bookingId, nextStop.id, otp);
      setOtp('');
      if (result.tripCompleted) {
        navigate('/home', { replace: true });
      } else {
        await refresh();
      }
    } catch (err) {
      setError(getErrorMessage(err, 'Could not verify this code.'));
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
              {stop.instructions ? ` — ${stop.instructions}` : ''}
            </span>
          </div>
        ))}
      </div>

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

      <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 18, background: 'var(--surface)' }}>
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
      </div>

      <TripChat bookingId={job.id} myRole="driver" />

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, textAlign: 'center' }}>{error}</p>}
    </Screen>
  );
}
