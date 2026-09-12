import { api } from '../api/client';
import { PRESET_LOCATIONS, type LocationPoint } from './locations';

export interface PlaceResult extends LocationPoint {
  source: 'preset' | 'search' | 'saved' | 'recent' | 'favourite';
  id?: string;
}

interface GeoSuggestion {
  place_id: string;
  label: string;
  address_line: string;
  lat: number;
  lng: number;
}

let sessionToken: string | null = null;

function getSessionToken(): string {
  if (!sessionToken) {
    sessionToken = crypto.randomUUID();
  }
  return sessionToken;
}

export function resetPlacesSession(): void {
  sessionToken = null;
}

/** Local search across presets (instant, works offline) */
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

/** Server-side places proxy (Google Places or Nominatim fallback) */
export async function searchPlacesOnline(
  query: string,
  bias?: { lat: number; lng: number }
): Promise<PlaceResult[]> {
  const q = query.trim();
  if (q.length < 2) return [];

  const params = new URLSearchParams({
    q,
    session_token: getSessionToken(),
  });
  if (bias) {
    params.set('lat', String(bias.lat));
    params.set('lng', String(bias.lng));
  }

  const results = await api.get<GeoSuggestion[]>(`/v1/geo/autocomplete?${params}`);
  return results.map((item) => ({
    label: item.label,
    addressLine: item.address_line,
    lat: item.lat,
    lng: item.lng,
    source: 'search' as const,
    id: item.place_id,
  }));
}

export async function searchPlaces(query: string, bias?: { lat: number; lng: number }): Promise<PlaceResult[]> {
  const local = searchLocalPlaces(query);
  try {
    const online = await searchPlacesOnline(query, bias);
    const seen = new Set(local.map((p) => `${p.lat}:${p.lng}`));
    const merged = [...local];
    for (const p of online) {
      const key = `${p.lat}:${p.lng}`;
      if (!seen.has(key)) {
        seen.add(key);
        merged.push(p);
      }
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
