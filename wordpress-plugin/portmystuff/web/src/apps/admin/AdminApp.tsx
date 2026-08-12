import { useEffect, useState, type ReactNode } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { getConfig } from '@/config';
import { AppShell } from '@/shared/AppShell';
import { StatCard } from '@/shared/StatCard';
import { adminBookings, adminDrivers, adminRevenue } from '@/api/admin';
import styles from './AdminApp.module.css';

const nav = [
  { to: '/admin', label: 'Dashboard' },
  { to: '/admin/bookings', label: 'Bookings' },
  { to: '/admin/drivers', label: 'Drivers' },
  { to: '/admin/analytics', label: 'Analytics' },
];

function Gate({ children }: { children: ReactNode }) {
  const cfg = getConfig();
  if (!cfg.canAdmin) {
    window.location.href = cfg.wpLoginUrl;
    return <AppShell title="Admin">Redirecting to login…</AppShell>;
  }
  return <>{children}</>;
}

function Dashboard() {
  const [rev, setRev] = useState({ trips: 0, revenue: 0 });
  useEffect(() => {
    adminRevenue().then((r) => setRev({ trips: r.total_trips, revenue: r.gross_revenue }));
  }, []);
  return (
    <AppShell title="Admin Console" subtitle="Business overview" backTo="/" nav={nav}>
      <div className={styles.grid}>
        <StatCard label="Completed trips" value={rev.trips} />
        <StatCard label="Gross revenue" value={`₹${rev.revenue}`} />
      </div>
    </AppShell>
  );
}

function BookingsPage() {
  const [items, setItems] = useState<Array<{ id: string; status: string; booking_type: string }>>([]);
  useEffect(() => {
    adminBookings().then((r) => setItems(r.items));
  }, []);
  return (
    <AppShell title="Bookings" backTo="/admin" nav={nav}>
      {items.map((b) => (
        <div key={b.id} className={styles.row}>
          <code>{b.id.slice(0, 8)}</code>
          <span>{b.booking_type}</span>
          <strong>{b.status}</strong>
        </div>
      ))}
    </AppShell>
  );
}

function DriversPage() {
  const [items, setItems] = useState<Array<{ id: number; phone: string; name: string; kyc_status: string }>>([]);
  useEffect(() => {
    adminDrivers().then((r) => setItems(r.items));
  }, []);
  return (
    <AppShell title="Drivers" backTo="/admin" nav={nav}>
      {items.map((d) => (
        <div key={d.id} className={styles.row}>
          <strong>{d.name}</strong>
          <span>{d.phone}</span>
          <span>{d.kyc_status}</span>
        </div>
      ))}
    </AppShell>
  );
}

function AnalyticsPage() {
  const [rev, setRev] = useState({ trips: 0, revenue: 0, fees: 0 });
  useEffect(() => {
    adminRevenue().then((r) => setRev({ trips: r.total_trips, revenue: r.gross_revenue, fees: r.platform_fees }));
  }, []);
  return (
    <AppShell title="Analytics" backTo="/admin" nav={nav}>
      <div className={styles.grid}>
        <StatCard label="Trips" value={rev.trips} />
        <StatCard label="Revenue" value={`₹${rev.revenue}`} />
        <StatCard label="Platform fees" value={`₹${rev.fees}`} />
      </div>
    </AppShell>
  );
}

export function AdminApp() {
  return (
    <Gate>
      <Routes>
        <Route index element={<Dashboard />} />
        <Route path="bookings" element={<BookingsPage />} />
        <Route path="drivers" element={<DriversPage />} />
        <Route path="analytics" element={<AnalyticsPage />} />
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
    </Gate>
  );
}
