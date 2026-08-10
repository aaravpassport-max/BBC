import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { PortMyStuffHeader } from '../components/PortMyStuffHeader';
import { notify } from '../lib/notify';
import { Button } from '../components/Button';
import { Skeleton } from '../components/Skeleton';
import {
  getKycStatus,
  getTrainingStatus,
  setOnlineStatus,
  getPendingOffer,
  getActiveJob,
  updateLocation,
  getEarningsHistory,
  getDriverProfile,
  ApiError,
  getErrorMessage,
} from '../api';
import { LiveMap } from '../components/LiveMap';
import styles from '../pages/LoginPage.module.css';

const POLL_INTERVAL_MS = 3000;
const FALLBACK_LOCATION = { lat: 12.951, lng: 77.601 };

export function DriverHomePage() {
  const navigate = useNavigate();
  const [online, setOnline] = useState(false);
  const [toggling, setToggling] = useState(false);
  const [error, setError] = useState('');
  const [checkingKyc, setCheckingKyc] = useState(true);
  const [todayEarnings, setTodayEarnings] = useState(0);
  const [hasVehicle, setHasVehicle] = useState(true);
  const [driverLoc, setDriverLoc] = useState(FALLBACK_LOCATION);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const checkKycThenLoad = useCallback(async () => {
    try {
      const kyc = await getKycStatus();
      if (kyc.overall_status !== 'approved') {
        navigate('/kyc');
        return;
      }
      const training = await getTrainingStatus();
      if (training.status !== 'passed') {
        navigate('/training');
        return;
      }
      const profile = await getDriverProfile();
      setHasVehicle(!!profile.vehicle);
      setOnline(!!profile.online_status);
      if (!profile.vehicle) {
        navigate('/vehicle');
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
    getEarningsHistory()
      .then((txns) => {
        const today = new Date().toDateString();
        const sum = txns
          .filter((t) => t.entry_type === 'credit' && new Date(t.created_at).toDateString() === today)
          .reduce((s, t) => s + parseFloat(t.amount), 0);
        setTodayEarnings(sum);
      })
      .catch(() => undefined);
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
        void notify('New job offer', 'A new delivery job is available — respond quickly.');
        navigate(`/offer/${offer.offer_id}`, { state: { offer } });
      }
    } catch (err) {
      if (err instanceof ApiError) setError(err.message);
    }
  }, [navigate]);

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
    void getLocation().then((loc) => {
      setDriverLoc(loc);
      return updateLocation(loc.lat, loc.lng);
    });
    void pollForWork();
    pollRef.current = setInterval(() => {
      void getLocation().then((loc) => {
        setDriverLoc(loc);
        return updateLocation(loc.lat, loc.lng);
      });
      void pollForWork();
    }, POLL_INTERVAL_MS);
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [online, checkingKyc, pollForWork, getLocation]);

  useEffect(() => {
    if (!checkingKyc) void pollForWork();
  }, [checkingKyc, pollForWork]);

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
      <div className={styles.page}>
        <PortMyStuffHeader />
        <div style={{ padding: 20 }}>
          <Skeleton width="60%" height={14} />
        </div>
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <PortMyStuffHeader />
      <div style={{ padding: '0 16px 16px' }}>
        <LiveMap pickup={driverLoc} drops={[]} driver={online ? driverLoc : null} />
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 16,
            background: 'var(--surface)',
            padding: 24,
            textAlign: 'center',
            marginBottom: 12,
            boxShadow: 'var(--shadow-sm)',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Today&apos;s earnings</div>
          <div style={{ fontSize: 28, fontWeight: 700, color: 'var(--accent-strong)' }}>₹{todayEarnings.toFixed(0)}</div>
        </div>

        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 16,
            background: 'var(--surface)',
            padding: 28,
            textAlign: 'center',
            boxShadow: 'var(--shadow-sm)',
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
          <h2 style={{ fontSize: 20, marginBottom: 8 }}>{online ? "You're online" : "You're offline"}</h2>
          <p style={{ color: 'var(--text-muted)', fontSize: 14, marginBottom: 18 }}>
            {online ? 'Looking for nearby jobs…' : 'Go online to start receiving job offers.'}
          </p>
          <Button onClick={handleToggle} loading={toggling} variant={online ? 'danger' : 'primary'}>
            {online ? 'Go offline' : 'Go online'}
          </Button>
        </div>

        {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 12 }}>{error}</p>}
        {!hasVehicle && (
          <Button variant="ghost" onClick={() => navigate('/vehicle')} style={{ marginTop: 12 }}>
            Register your vehicle
          </Button>
        )}
      </div>
    </div>
  );
}
