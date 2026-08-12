import { useEffect, useState, type ReactNode } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { getConfig } from '@/config';
import { AppShell } from '@/shared/AppShell';
import { opsLiveDrivers, opsSos } from '@/api/admin';
import styles from './OpsApp.module.css';

const nav = [
  { to: '/ops', label: 'SOS Queue' },
  { to: '/ops/live-map', label: 'Live Map' },
];

function Gate({ children }: { children: ReactNode }) {
  const cfg = getConfig();
  if (!cfg.canOps) {
    window.location.href = cfg.wpLoginUrl;
    return <AppShell title="Control Room">Redirecting to login…</AppShell>;
  }
  return <>{children}</>;
}

function SosPage() {
  const [items, setItems] = useState<Array<{ id: string; booking_id: string; status: string }>>([]);
  useEffect(() => {
    opsSos().then(setItems);
  }, []);
  return (
    <AppShell title="SOS Queue" subtitle="Active emergencies" backTo="/" nav={nav}>
      {items.length === 0 ? <p className={styles.muted}>No active SOS events.</p> : null}
      {items.map((s) => (
        <div key={s.id} className={styles.alert}>
          <strong>{s.status.toUpperCase()}</strong>
          <span>Booking {s.booking_id.slice(0, 8)}</span>
        </div>
      ))}
    </AppShell>
  );
}

function LiveMapPage() {
  const [drivers, setDrivers] = useState<Array<{ driver_id: number; lat: number; lng: number }>>([]);
  useEffect(() => {
    opsLiveDrivers().then(setDrivers);
    const t = setInterval(() => opsLiveDrivers().then(setDrivers), 10000);
    return () => clearInterval(t);
  }, []);
  return (
    <AppShell title="Live Map" subtitle="Online drivers" backTo="/ops" nav={nav}>
      {drivers.map((d) => (
        <div key={d.driver_id} className={styles.row}>
          <strong>Driver #{d.driver_id}</strong>
          <span>{d.lat?.toFixed(4)}, {d.lng?.toFixed(4)}</span>
        </div>
      ))}
    </AppShell>
  );
}

export function OpsApp() {
  return (
    <Gate>
      <Routes>
        <Route index element={<SosPage />} />
        <Route path="live-map" element={<LiveMapPage />} />
        <Route path="*" element={<Navigate to="/ops" replace />} />
      </Routes>
    </Gate>
  );
}
