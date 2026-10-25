import { useEffect, useState } from 'react';
import { Routes, Route, useNavigate } from 'react-router-dom';
import { AppShell } from '@/shared/AppShell';
import { Button } from '@/shared/Button';
import { StatCard } from '@/shared/StatCard';
import { requestOtp, verifyOtp } from '@/api/auth';
import { setAccessToken, clearAccessToken, hasAccessToken } from '@/api/client';
import {
  acceptOffer,
  activeJob,
  advanceJob,
  driverDashboard,
  pendingOffer,
  rejectOffer,
  setOnline,
  updateLocation,
} from '@/api/driver';
import type { DriverOffer } from '@/api/driver';
import styles from './DriverApp.module.css';

function LoginScreen() {
  const nav = useNavigate();
  const [error, setError] = useState('');

  async function quickLogin() {
    setError('');
    try {
      const otp = await requestOtp('9000000002');
      const res = await verifyOtp(otp.otp_id, '222222');
      setAccessToken(res.access_token);
      nav('/driver/home');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Login failed');
    }
  }

  return (
    <AppShell title="Driver Partner" subtitle="Demo: 9000000002 / 222222" backTo="/">
      <Button onClick={quickLogin}>Quick login</Button>
      {error ? <p className={styles.error}>{error}</p> : null}
    </AppShell>
  );
}

function HomeScreen() {
  const nav = useNavigate();
  const [stats, setStats] = useState({ trips: 0, earnings: 0 });

  useEffect(() => {
    driverDashboard().then((d) => setStats({ trips: d.trips_today, earnings: d.gross_earnings_today }));
  }, []);

  return (
    <AppShell title="Dashboard" backTo="/">
      <div className={styles.stats}>
        <StatCard label="Trips today" value={stats.trips} />
        <StatCard label="Earnings" value={`₹${stats.earnings}`} />
      </div>
      <div className={styles.actions}>
        <Button onClick={() => nav('/driver/online')}>Go online</Button>
        <Button
          variant="secondary"
          onClick={() => {
            clearAccessToken();
            nav('/driver');
          }}
        >
          Log out
        </Button>
      </div>
    </AppShell>
  );
}

function OnlineScreen() {
  const nav = useNavigate();
  const [offer, setOffer] = useState<DriverOffer | null>(null);
  const [online, setOnlineState] = useState(false);

  useEffect(() => {
    setOnline(true).then(() => updateLocation(12.9716, 77.5946));
    setOnlineState(true);
    const timer = setInterval(async () => {
      const job = await activeJob();
      if (job?.id) {
        nav(`/driver/trip/${job.id}`);
        return;
      }
      const pending = await pendingOffer();
      if (pending?.offer_id) setOffer(pending);
    }, 4000);
    return () => clearInterval(timer);
  }, [nav]);

  return (
    <AppShell title="Online" subtitle={online ? 'Waiting for offers…' : ''} backTo="/driver/home">
      {offer ? (
        <div className={styles.offer}>
          <p>New {offer.booking_type} — ₹{offer.fare_breakdown.final_fare}</p>
          <Button onClick={() => acceptOffer(offer.offer_id).then(() => nav(`/driver/trip/${offer.booking_id}`))}>
            Accept
          </Button>
          <Button variant="secondary" onClick={() => rejectOffer(offer.offer_id).then(() => setOffer(null))}>
            Reject
          </Button>
        </div>
      ) : (
        <p className={styles.muted}>No offers yet. Keep this screen open.</p>
      )}
      <Button
        variant="secondary"
        onClick={() => setOnline(false).then(() => nav('/driver/home'))}
      >
        Go offline
      </Button>
    </AppShell>
  );
}

function TripScreen({ bookingId }: { bookingId: string }) {
  const nav = useNavigate();
  const [status, setStatus] = useState('driver_assigned');

  const next: Record<string, string> = {
    driver_assigned: 'driver_arriving',
    driver_arriving: 'driver_arrived',
    driver_arrived: 'in_progress',
    in_progress: 'completed',
  };

  async function advance() {
    const n = next[status];
    if (!n) return;
    const job = await advanceJob(bookingId, n);
    setStatus(job.status);
    if (job.status === 'completed') nav('/driver/home');
  }

  return (
    <AppShell title="Active trip" backTo="/driver/home">
      <p>Status: <strong>{status}</strong></p>
      {next[status] ? <Button onClick={advance}>Mark {next[status].replace(/_/g, ' ')}</Button> : null}
    </AppShell>
  );
}

export function DriverApp() {
  return (
    <Routes>
      <Route index element={hasAccessToken() ? <HomeScreen /> : <LoginScreen />} />
      <Route path="home" element={<HomeScreen />} />
      <Route path="online" element={<OnlineScreen />} />
      <Route path="trip/:id" element={<TripRoute />} />
    </Routes>
  );
}

function TripRoute() {
  const id = window.location.pathname.split('/').pop() ?? '';
  return <TripScreen bookingId={id} />;
}
