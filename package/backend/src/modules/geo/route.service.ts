const OSRM_BASE = process.env.OSRM_BASE_URL || 'https://router.project-osrm.org';

export interface RouteWaypoint {
  lat: number;
  lng: number;
}

export interface RouteResult {
  distance_m: number;
  duration_s: number;
  geometry: Array<{ lat: number; lng: number }>;
}

/**
 * Fetches a driving route via OSRM (public demo or self-hosted).
 * Waypoints are visited in order: pickup → drops → optional driver position prefix.
 */
export async function fetchDrivingRoute(waypoints: RouteWaypoint[]): Promise<RouteResult | null> {
  if (waypoints.length < 2) return null;

  const coordPath = waypoints.map((p) => `${p.lng},${p.lat}`).join(';');
  const url = `${OSRM_BASE}/route/v1/driving/${coordPath}?overview=full&geometries=geojson`;

  const res = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!res.ok) return null;

  const body = (await res.json()) as {
    code?: string;
    routes?: Array<{
      distance: number;
      duration: number;
      geometry: { coordinates: [number, number][] };
    }>;
  };

  if (body.code !== 'Ok' || !body.routes?.[0]) return null;

  const route = body.routes[0];
  return {
    distance_m: Math.round(route.distance),
    duration_s: Math.round(route.duration),
    geometry: route.geometry.coordinates.map(([lng, lat]) => ({ lat, lng })),
  };
}
