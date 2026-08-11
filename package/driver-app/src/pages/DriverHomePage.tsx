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
  getDriverDashboard,
  getActiveIncentives,
  getDriverProfile,
  ApiError,
  getErrorMessage,
  isDemoSession,
} from '../api';
import { SafeLiveMap } from '../components/SafeLiveMap';
import { useDriverRealtime } from '../hooks/useDriverRealtime';
import styles from './DriverHomePage.module.css';

const POLL_INTERVAL_MS = 3000;
const BOOT_TIMEOUT_MS = 5000;
const FALLBACK_LOCATION = { lat: 12.951, lng: 77.601 };

export function DriverHomePage() {
  const navigate = useNavigate();
  const [online, setOnline] = useState(false);
  const [toggling, setToggling] = useState(false);
  const [error, setError] = useState('');
  const [checkingKyc, setCheckingKyc] = useState(true);
  const [todayEarnings, setTodayEarnings] = useState(0);
  const [tripsToday, setTripsToday] = useState(0);
  const [incentiveRemaining, setIncentiveRemaining] = useState<number | null>(null);
  const [hasVehicle, setHasVehicle] = useState(true);
  const [showOfflineReason, setShowOfflineReason] = useState(false);
  const [offlineReason, setOfflineReason] = useState('break');
  const [driverLoc, setDriverLoc] = useState(FALLBACK_LOCATION);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const checkKycThenLoad = useCallback(async () => {
    const demo = isDemoSession();
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
      if (!profile.vehicle && !demo) {
        navigate('/vehicle');
        return;
      }
      setCheckingKyc(false);
    } catch (err) {
      if (demo) {
        // Demo mode must never white-screen when the backend is unreachable on a physical phone.
        setHasVehicle(true);
        setOnline(false);
        setCheckingKyc(false);
        return;
      }
      setError(getErrorMessage(err, 'Could not load your partner profile.'));
      navigate('/kyc');
    }
  }, [navigate]);

  useEffect(() => {
    let cancelled = false;
    const bootTimer = setTimeout(() => {
      if (!cancelled) setCheckingKyc(false);
    }, BOOT_TIMEOUT_MS);

    void checkKycThenLoad().finally(() => {
      if (!cancelled) clearTimeout(bootTimer);
    });

    getDriverDashboard()
      .then((d) => {
        setTodayEarnings(d.wallet_credits_today || d.gross_earnings_today);
        setTripsToday(d.trips_today);
      })
      .catch(() => undefined);
    getActiveIncentives()
      .then((r) => {
        const first = r.items[0];
        if (first && !first.completed) setIncentiveRemaining(first.remaining);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
      clearTimeout(bootTimer);
    };
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

  useDriverRealtime({
    enabled: !isDemoSession(),
    onNewOffer: (payload) => {
      void notify('New job offer', 'A new delivery job is available — respond quickly.');
      void getPendingOffer().then((offer) => {
        if (offer) navigate(`/offer/${offer.offer_id}`, { state: { offer } });
        else navigate(`/offer/${payload.offer_id}`);
      });
    },
  });

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
    if (online) {
      setShowOfflineReason(true);
      return;
    }
    setToggling(true);
    try {
      await setOnlineStatus(true);
      setOnline(true);
      setShowOfflineReason(false);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update your status.'));
    } finally {
      setToggling(false);
    }
  }

  async function confirmGoOffline() {
    setToggling(true);
    setError('');
    try {
      await setOnlineStatus(false, offlineReason);
      setOnline(false);
      setShowOfflineReason(false);
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
        <div className={styles.body}>
          <Skeleton width="60%" height={14} />
          <div style={{ marginTop: 16 }}>
            <Skeleton width="100%" height={220} radius={16} />
          </div>
          <div style={{ marginTop: 16 }}>
            <Skeleton width="100%" height={120} radius={16} />
          </div>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, textAlign: 'center', marginTop: 16 }}>
            Loading your partner dashboard…
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <PortMyStuffHeader />
      <div className={styles.body}>
        <div className={styles.mapWrap}>
          <SafeLiveMap pickup={driverLoc} drops={[]} driver={online ? driverLoc : null} />
        </div>
        <div className={styles.statsCard}>
          <div className={styles.statsGrid}>
            <div>
              <div className={styles.statLabel}>Today&apos;s earnings</div>
              <div className={styles.statValueAccent}>₹{todayEarnings.toFixed(0)}</div>
            </div>
            <div>
              <div className={styles.statLabel}>Trips today</div>
              <div className={styles.statValue}>{tripsToday}</div>
            </div>
          </div>
          {incentiveRemaining != null && incentiveRemaining > 0 && (
            <button
              type="button"
              onClick={() => navigate('/incentives')}
              className={styles.incentiveBtn}
            >
              🎯 {incentiveRemaining} more trip{incentiveRemaining === 1 ? '' : 's'} for today&apos;s bonus
            </button>
          )}
        </div>

        <div className={styles.statusCard}>
          <div className={online ? styles.statusDotOnline : styles.statusDotOffline} />
          <h2 className={styles.statusTitle}>{online ? "You're online" : "You're offline"}</h2>
          <p className={styles.statusHint}>
            {online ? 'Looking for nearby jobs…' : 'Go online to start receiving job offers.'}
          </p>
          <Button onClick={handleToggle} loading={toggling} variant={online ? 'danger' : 'primary'}>
            {online ? 'Go offline' : 'Go online'}
          </Button>
        </div>

        {showOfflineReason && (
          <div className={styles.offlinePanel}>
            <div className={styles.offlineTitle}>Why are you going offline?</div>
            <select
              value={offlineReason}
              onChange={(e) => setOfflineReason(e.target.value)}
              className={styles.offlineSelect}
            >
              <option value="break">Taking a break</option>
              <option value="fuel">Refuelling</option>
              <option value="personal">Personal errand</option>
              <option value="end_of_shift">End of shift</option>
              <option value="other">Other</option>
            </select>
            <div className={styles.offlineActions}>
              <Button variant="ghost" onClick={() => setShowOfflineReason(false)}>Cancel</Button>
              <Button variant="danger" loading={toggling} onClick={() => void confirmGoOffline()}>Confirm offline</Button>
            </div>
          </div>
        )}

        {online && (
          <button type="button" onClick={() => navigate('/heatmap')} className={styles.heatmapBtn}>
            🗺️ View demand heatmap
          </button>
        )}

        {error && <p className={styles.error}>{error}</p>}
        {!hasVehicle && (
          <Button variant="ghost" onClick={() => navigate('/vehicle')} className={styles.vehicleBtn}>
            Register your vehicle
          </Button>
        )}
      </div>
    </div>
  );
}
