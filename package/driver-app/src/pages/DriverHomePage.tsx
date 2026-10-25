import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { notify } from '../lib/notify';
import { Screen } from '../components/Screen';
import { Skeleton } from '../components/Skeleton';
import { Button } from '../components/Button';
import {
  getKycStatus,
  getTrainingStatus,
  setOnlineStatus,
  getPendingOffer,
  getActiveJob,
  updateLocation,
  ApiError, getErrorMessage,
} from '../api';
import { useAuth } from '../context/AuthContext';

const POLL_INTERVAL_MS = 3000;

// Fallback coordinate, the same seeded service-zone point the Customer app
// uses — kept as a fallback (not removed) because a driver's REAL GPS
// location will very often fall outside the backend's fixed demo service
// zone polygon (any real-world testing location isn't Bengaluru), which
// would silently stop dispatch from ever matching them with zero visible
// error. Real GPS is attempted first; this is what keeps the app testable
// end-to-end when it isn't available.
const FALLBACK_LOCATION = { lat: 12.951, lng: 77.601 };

export function DriverHomePage() {
  const navigate = useNavigate();
  const auth = useAuth();
  const [online, setOnline] = useState(false);
  const [toggling, setToggling] = useState(false);
  const [error, setError] = useState('');
  const [checkingKyc, setCheckingKyc] = useState(true);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const checkKycThenLoad = useCallback(async () => {
    try {
      const kyc = await getKycStatus();
      if (kyc.overall_status !== 'approved') {
        navigate('/kyc');
        return;
      }
      // PRD 3.2: training completion is as hard a gate as document KYC — a
      // driver cannot go online having passed documents but skipped
      // training, so this check happens right alongside the KYC one, not
      // as an afterthought.
      const training = await getTrainingStatus();
      if (training.status !== 'passed') {
        navigate('/training');
        return;
      }
    } catch {
      navigate('/kyc');
      return;
    } finally {
      setCheckingKyc(false);
    }
  }, [navigate]);

  useEffect(() => {
    void checkKycThenLoad();
  }, [checkKycThenLoad]);

  const pollForWork = useCallback(async () => {
    try {
      const activeJob = await getActiveJob();
      if (activeJob) {
        navigate(`/trip/${activeJob.id}`);
        return;
      }
      const offer = await getPendingOffer();
      if (offer) {
        void notify('New job offer', 'A new delivery job is available — respond quickly, offers expire fast.');
        navigate(`/offer/${offer.offer_id}`, { state: { offer } });
        return;
      }
    } catch (err) {
      if (err instanceof ApiError) setError(err.message);
    }
  }, [navigate]);

  // Reads the device's real GPS on every poll tick — a driver moving
  // between polls needs a fresh position each time for dispatch/tracking
  // to be meaningful, the same way any real ride/delivery app works.
  // Falls back to the fixed demo coordinate on any failure — permission
  // denied, GPS unavailable, timeout — so the app stays fully testable.
  const getLocation = useCallback(async (): Promise<{ lat: number; lng: number }> => {
    try {
      const permission = await Geolocation.requestPermissions();
      if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') {
        return FALLBACK_LOCATION;
      }
      const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 8000 });
      return { lat: position.coords.latitude, lng: position.coords.longitude };
    } catch {
      return FALLBACK_LOCATION;
    }
  }, []);

  useEffect(() => {
    if (!online || checkingKyc) return;
    void getLocation().then((loc) => updateLocation(loc.lat, loc.lng));
    void pollForWork();
    pollRef.current = setInterval(() => {
      void getLocation().then((loc) => updateLocation(loc.lat, loc.lng));
      void pollForWork();
    }, POLL_INTERVAL_MS);
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [online, checkingKyc, pollForWork, getLocation]);

  // On mount, also check once whether there's already an active job even
  // while offline (e.g. app was closed mid-trip) — a driver should always
  // resume where they left off.
  useEffect(() => {
    if (!checkingKyc) void pollForWork();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [checkingKyc]);

  async function handleToggle() {
    setError('');
    setToggling(true);
    try {
      const next = !online;
      await setOnlineStatus(next);
      setOnline(next);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update your status.'));
    } finally {
      setToggling(false);
    }
  }

  if (checkingKyc) {
    return (
      <Screen eyebrow="Driver" title="Checking your account…">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Skeleton width="60%" height={14} />
          <Skeleton width="40%" height={14} />
        </div>
      </Screen>
    );
  }

  return (
    <Screen eyebrow="Driver" title={online ? "You're online" : "You're offline"}>
      <div
        style={{
          border: '1px solid var(--border)',
          borderRadius: 16,
          background: 'var(--surface)',
          padding: 28,
          textAlign: 'center',
        }}
      >
        <div
          style={{
            width: 14,
            height: 14,
            borderRadius: '50%',
            margin: '0 auto 14px',
            background: online ? 'var(--success)' : 'var(--text-muted)',
            boxShadow: online ? '0 0 16px var(--success)' : 'none',
          }}
        />
        <p style={{ color: 'var(--text-muted)', fontSize: 14, marginBottom: 18 }}>
          {online ? 'Looking for nearby jobs…' : 'Go online to start receiving job offers.'}
        </p>
        <Button onClick={handleToggle} loading={toggling} variant={online ? 'danger' : 'primary'}>
          {online ? 'Go offline' : 'Go online'}
        </Button>
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      <Button variant="ghost" onClick={() => navigate('/earnings')}>
        Earnings & payouts
      </Button>
      <Button variant="ghost" onClick={auth.logout}>
        Sign out
      </Button>
    </Screen>
  );
}
