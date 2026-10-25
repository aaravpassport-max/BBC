import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { useAuth } from '../context/AuthContext';
import {
  getFleetDrivers,
  getFleetEarningsSummary,
  getErrorMessage,
  type FleetDriver,
  type FleetEarningsSummary,
} from '../api';
import { SkeletonRowList } from '../components/Skeleton';

const POLL_INTERVAL_MS = 10000;

const STATUS_LABEL: Record<FleetDriver['status'], string> = {
  online: 'Online',
  offline: 'Offline',
  on_trip: 'On trip',
};

const STATUS_COLOR: Record<FleetDriver['status'], string> = {
  online: 'var(--success)',
  offline: 'var(--text-muted)',
  on_trip: 'var(--accent-strong)',
};

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

export function HomePage() {
  const navigate = useNavigate();
  const auth = useAuth();
  const [drivers, setDrivers] = useState<FleetDriver[] | null>(null);
  const [earnings, setEarnings] = useState<FleetEarningsSummary | null>(null);
  const [error, setError] = useState('');

  const refresh = useCallback(async () => {
    try {
      const [driverList, earningsSummary] = await Promise.all([getFleetDrivers(), getFleetEarningsSummary()]);
      setDrivers(driverList);
      setEarnings(earningsSummary);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your fleet.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
    const interval = setInterval(() => void refresh(), POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [refresh]);

  return (
    <Screen eyebrow="Fleet dashboard" title="Your fleet">
      <Button variant="ghost" style={{ width: 'auto', padding: '4px 0', alignSelf: 'flex-end' }} onClick={() => auth.logout()}>
        Sign out
      </Button>

      {earnings && (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 16,
            background: 'var(--surface)',
            padding: 24,
            textAlign: 'center',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>Today's fleet earnings</div>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 36, fontWeight: 700, color: 'var(--accent-strong)' }}>
            {money(earnings.totalToday)}
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 6 }}>
            across {earnings.driverCount} driver{earnings.driverCount === 1 ? '' : 's'}
          </div>
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 4 }}>
        <h2 style={{ fontSize: 15 }}>Drivers</h2>
        <div style={{ display: 'flex', gap: 8 }}>
          <Button variant="ghost" style={{ width: 'auto', padding: '6px 14px' }} onClick={() => navigate('/vehicles')}>
            🚚 Vehicles
          </Button>
          <Button variant="ghost" style={{ width: 'auto', padding: '6px 14px' }} onClick={() => navigate('/add-driver')}>
            + Add driver
          </Button>
        </div>
      </div>

      {drivers === null && !error && <SkeletonRowList count={3} />}

      {drivers && drivers.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          No drivers in your fleet yet. Add one by their phone number to get started.
        </p>
      )}

      {drivers && drivers.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {drivers.map((d) => (
            <button
              key={d.driver_id}
              onClick={() => navigate(`/driver/${d.driver_id}`)}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                textAlign: 'left',
                border: '1px solid var(--border)',
                borderRadius: 12,
                background: 'var(--surface)',
                padding: '14px 16px',
                cursor: 'pointer',
                color: 'var(--text)',
              }}
            >
              <div>
                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 14 }}>+91 {d.phone}</div>
                {d.name && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{d.name}</div>}
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: STATUS_COLOR[d.status] }} />
                <span style={{ fontSize: 13, color: STATUS_COLOR[d.status], fontWeight: 600 }}>
                  {STATUS_LABEL[d.status]}
                </span>
              </div>
            </button>
          ))}
        </div>
      )}
    </Screen>
  );
}
