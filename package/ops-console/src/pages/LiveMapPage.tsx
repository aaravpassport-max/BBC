import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { getLiveDrivers, type LiveDriverPin } from '../api/ops';
import { useOpsRealtime } from '../hooks/useOpsRealtime';
import { SkeletonRowList } from '../components/Skeleton';

function FleetMap({ drivers }: { drivers: LiveDriverPin[] }) {
  if (drivers.length === 0) {
    return (
      <div
        style={{
          height: 420,
          borderRadius: 12,
          border: '1px solid var(--border)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: 'var(--text-muted)',
          background: 'var(--surface)',
        }}
      >
        No driver locations in the last 30 minutes.
      </div>
    );
  }

  const lats = drivers.map((d) => d.lat);
  const lngs = drivers.map((d) => d.lng);
  const minLat = Math.min(...lats);
  const maxLat = Math.max(...lats);
  const minLng = Math.min(...lngs);
  const maxLng = Math.max(...lngs);
  const pad = 0.01;

  function toXY(lat: number, lng: number): { x: number; y: number } {
    const x = ((lng - minLng + pad) / (maxLng - minLng + pad * 2)) * 100;
    const y = 100 - ((lat - minLat + pad) / (maxLat - minLat + pad * 2)) * 100;
    return { x, y };
  }

  return (
    <div
      style={{
        position: 'relative',
        height: 420,
        borderRadius: 12,
        border: '1px solid var(--border)',
        background: 'linear-gradient(180deg, #e8f0fe 0%, #f5f7fb 100%)',
        overflow: 'hidden',
      }}
    >
      {drivers.map((d) => {
        const { x, y } = toXY(d.lat, d.lng);
        const color = d.on_trip ? '#e65100' : d.online_status ? '#2e7d32' : '#757575';
        return (
          <div
            key={d.driver_id}
            title={`${d.name || d.phone} · ${d.on_trip ? 'On trip' : d.online_status ? 'Online' : 'Offline'}`}
            style={{
              position: 'absolute',
              left: `${x}%`,
              top: `${y}%`,
              transform: 'translate(-50%, -50%)',
              width: 14,
              height: 14,
              borderRadius: '50%',
              background: color,
              border: '2px solid white',
              boxShadow: '0 1px 4px rgba(0,0,0,0.25)',
            }}
          />
        );
      })}
      <div
        style={{
          position: 'absolute',
          bottom: 10,
          left: 10,
          fontSize: 11,
          background: 'rgba(255,255,255,0.9)',
          padding: '6px 10px',
          borderRadius: 8,
        }}
      >
        <span style={{ color: '#2e7d32' }}>●</span> Online &nbsp;
        <span style={{ color: '#e65100' }}>●</span> On trip &nbsp;
        <span style={{ color: '#757575' }}>●</span> Offline ping
      </div>
    </div>
  );
}

export function LiveMapPage() {
  const [drivers, setDrivers] = useState<LiveDriverPin[] | null>(null);
  const [error, setError] = useState('');

  const refresh = useCallback(async () => {
    try {
      const list = await getLiveDrivers();
      setDrivers(list);
    } catch {
      setError('Could not load live driver map.');
    }
  }, []);

  useEffect(() => {
    void refresh();
    const interval = setInterval(() => void refresh(), 15000);
    return () => clearInterval(interval);
  }, [refresh]);

  useOpsRealtime((event) => {
    if (event.event !== 'location.update' || typeof event.driver_id !== 'string') return;
    setDrivers((prev) => {
      if (!prev) return prev;
      const lat = event.lat as number;
      const lng = event.lng as number;
      const idx = prev.findIndex((d) => d.driver_id === event.driver_id);
      const updated: LiveDriverPin = {
        driver_id: event.driver_id as string,
        phone: idx >= 0 ? prev[idx].phone : '—',
        name: idx >= 0 ? prev[idx].name : null,
        lat,
        lng,
        online_status: true,
        on_trip: Boolean(event.on_trip),
        last_ping_at: (event.at as string) ?? new Date().toISOString(),
        active_booking_id: (event.booking_id as string) ?? null,
      };
      if (idx >= 0) {
        const next = [...prev];
        next[idx] = { ...prev[idx], ...updated };
        return next;
      }
      return [updated, ...prev].slice(0, 500);
    });
  });

  return (
    <Layout title="Live fleet map">
      {error && <p style={{ color: 'var(--danger)', fontSize: 14 }}>{error}</p>}
      {drivers === null && !error && <SkeletonRowList count={2} />}
      {drivers && (
        <>
          <FleetMap drivers={drivers} />
          <div style={{ marginTop: 16, fontSize: 13, color: 'var(--text-muted)' }}>
            {drivers.length} driver location{drivers.length === 1 ? '' : 's'} · refreshes every 15s · live via WebSocket
          </div>
          <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 6, maxHeight: 200, overflowY: 'auto' }}>
            {drivers.slice(0, 20).map((d) => (
              <div key={d.driver_id} style={{ fontSize: 12, display: 'flex', justifyContent: 'space-between' }}>
                <span>{d.name || `+91 ${d.phone}`}</span>
                <span style={{ color: 'var(--text-muted)' }}>
                  {d.on_trip ? 'On trip' : d.online_status ? 'Online' : 'Ping'} · {d.lat.toFixed(4)}, {d.lng.toFixed(4)}
                </span>
              </div>
            ))}
          </div>
        </>
      )}
    </Layout>
  );
}
