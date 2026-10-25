import { useEffect, useState } from 'react';
import { Geolocation } from '@capacitor/geolocation';
import { Screen } from '../components/Screen';
import { LiveMap } from '../components/LiveMap';
import { Skeleton } from '../components/Skeleton';
import { getDemandHeatmap, getErrorMessage, type DemandHeatCell } from '../api';

const FALLBACK = { lat: 12.951, lng: 77.601 };

export function HeatmapPage() {
  const [cells, setCells] = useState<DemandHeatCell[]>([]);
  const [updatedAt, setUpdatedAt] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [center, setCenter] = useState(FALLBACK);

  const load = async () => {
    setError('');
    try {
      let lat = center.lat;
      let lng = center.lng;
      try {
        const pos = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 8000 });
        lat = pos.coords.latitude;
        lng = pos.coords.longitude;
        setCenter({ lat, lng });
      } catch {
        // use last known center
      }
      const result = await getDemandHeatmap(lat, lng);
      setCells(result.cells);
      setUpdatedAt(result.updated_at);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load demand heatmap.'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
    const interval = setInterval(() => void load(), 60_000);
    return () => clearInterval(interval);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const topCells = [...cells].sort((a, b) => b.demand_score - a.demand_score).slice(0, 6);

  return (
    <Screen eyebrow="Demand" title="Heatmap" withNav>
      <p style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 12 }}>
        Hotter zones show more recent booking demand. Position yourself near high-demand areas to receive more offers.
      </p>

      {loading && cells.length === 0 ? (
        <Skeleton width="100%" height={220} radius={12} />
      ) : (
        <LiveMap pickup={null} drops={[]} driver={center} heatCells={cells} />
      )}

      {updatedAt && (
        <p style={{ fontSize: 11, color: 'var(--text-muted)', textAlign: 'right', marginTop: 6 }}>
          Updated {new Date(updatedAt).toLocaleTimeString()}
        </p>
      )}

      {topCells.length > 0 && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)', marginTop: 12 }}>
          <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 10 }}>Top demand zones</div>
          {topCells.map((cell, i) => (
            <div
              key={`${cell.lat}-${cell.lng}`}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                padding: '8px 0',
                borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                fontSize: 13,
              }}
            >
              <span>
                {cell.label}
                {cell.surge_zone && <span style={{ color: '#e67e22', marginLeft: 6 }}>⚡ Surge</span>}
              </span>
              <span style={{ color: 'var(--text-muted)' }}>
                {cell.booking_count > 0 ? `${cell.booking_count} booking${cell.booking_count === 1 ? '' : 's'}` : 'Zone'}
              </span>
            </div>
          ))}
        </div>
      )}

      {cells.length === 0 && !loading && (
        <p style={{ fontSize: 13, color: 'var(--text-muted)', textAlign: 'center', marginTop: 16 }}>
          No active demand clusters nearby right now. Stay online — demand updates every minute.
        </p>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 12 }}>{error}</p>}
    </Screen>
  );
}
