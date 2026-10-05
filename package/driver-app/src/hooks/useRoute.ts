import { useEffect, useState } from 'react';
import { getRoute } from '../api/geo';

export interface RouteInfo {
  geometry: Array<{ lat: number; lng: number }>;
  distanceM: number;
  durationS: number;
  etaMinutes: number;
}

export function useRoute(waypoints: Array<{ lat: number; lng: number } | null | undefined>) {
  const [route, setRoute] = useState<RouteInfo | null>(null);
  const [loading, setLoading] = useState(false);

  const key = waypoints
    .filter((p): p is { lat: number; lng: number } => !!p && Number.isFinite(p.lat) && Number.isFinite(p.lng))
    .map((p) => `${p.lat.toFixed(5)},${p.lng.toFixed(5)}`)
    .join('|');

  useEffect(() => {
    const points = key
      .split('|')
      .filter(Boolean)
      .map((pair) => {
        const [lat, lng] = pair.split(',').map(Number);
        return { lat, lng };
      });

    if (points.length < 2) {
      setRoute(null);
      return;
    }

    let cancelled = false;
    setLoading(true);
    void getRoute(points)
      .then((result) => {
        if (cancelled) return;
        setRoute({
          geometry: result.geometry,
          distanceM: result.distance_m,
          durationS: result.duration_s,
          etaMinutes: Math.max(1, Math.round(result.duration_s / 60)),
        });
      })
      .catch(() => {
        if (!cancelled) setRoute(null);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [key]);

  return { route, loading };
}
