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

export interface DemandHeatCell {
  lat: number;
  lng: number;
  demand_score: number;
  label: string;
  booking_count: number;
  surge_zone: boolean;
}

export function getDemandHeatmap(lat?: number, lng?: number) {
  const params = new URLSearchParams();
  if (lat !== undefined) params.set('lat', String(lat));
  if (lng !== undefined) params.set('lng', String(lng));
  const qs = params.toString();
  return api.get<{ cells: DemandHeatCell[]; updated_at: string }>(
    `/v1/driver/demand-heatmap${qs ? `?${qs}` : ''}`
  );
}

export function uploadProofPhoto(imageBase64: string) {
  return api.post<{ url: string }>('/v1/driver/uploads/proof-photo', { image_base64: imageBase64 });
}
