import { api } from './client';

export interface RouteResult {
  distance_m: number;
  duration_s: number;
  geometry: Array<{ lat: number; lng: number }>;
}

export function getRoute(waypoints: Array<{ lat: number; lng: number }>) {
  const param = waypoints.map((p) => `${p.lat},${p.lng}`).join(';');
  return api.get<RouteResult>(`/v1/geo/route?waypoints=${encodeURIComponent(param)}`);
}
