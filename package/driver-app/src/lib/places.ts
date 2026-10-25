import { api } from '../api/client';
import { PRESET_LOCATIONS, type LocationPoint } from './locations';

export interface PlaceResult extends LocationPoint {
  source: 'preset' | 'search' | 'saved';
  id?: string;
}

export function searchLocalPlaces(query: string, limit = 8): PlaceResult[] {
  const q = query.trim().toLowerCase();
  if (!q) {
    return PRESET_LOCATIONS.slice(0, limit).map((p) => ({ ...p, source: 'preset' as const }));
  }
  return PRESET_LOCATIONS.filter(
    (p) => p.label.toLowerCase().includes(q) || (p.addressLine?.toLowerCase().includes(q) ?? false)
  )
    .slice(0, limit)
    .map((p) => ({ ...p, source: 'preset' as const }));
}

export async function searchPlacesOnline(query: string): Promise<PlaceResult[]> {
  const q = query.trim();
  if (q.length < 2) return [];
  const results = await api.get<Array<{ place_id: string; label: string; address_line: string; lat: number; lng: number }>>(
    `/v1/geo/autocomplete?q=${encodeURIComponent(q)}`
  );
  return results.map((item) => ({
    label: item.label,
    addressLine: item.address_line,
    lat: item.lat,
    lng: item.lng,
    source: 'search' as const,
    id: item.place_id,
  }));
}

export async function searchPlaces(query: string): Promise<PlaceResult[]> {
  const local = searchLocalPlaces(query);
  try {
    const online = await searchPlacesOnline(query);
    const seen = new Set(local.map((p) => `${p.lat}:${p.lng}`));
    const merged = [...local];
    for (const p of online) {
      const key = `${p.lat}:${p.lng}`;
      if (!seen.has(key)) merged.push(p);
    }
    return merged.slice(0, 10);
  } catch {
    return local;
  }
}

export async function reverseGeocode(lat: number, lng: number): Promise<string> {
  try {
    const res = await api.get<{ address: string }>(`/v1/geo/reverse?lat=${lat}&lng=${lng}`);
    return res.address;
  } catch {
    return `Pinned location (${lat.toFixed(4)}, ${lng.toFixed(4)})`;
  }
}
