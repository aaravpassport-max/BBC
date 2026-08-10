import { PRESET_LOCATIONS, type LocationPoint } from './locations';

export interface PlaceResult extends LocationPoint {
  source: 'preset' | 'search' | 'saved';
  id?: string;
}

/** Local search across presets (instant, works offline) */
export function searchLocalPlaces(query: string, limit = 8): PlaceResult[] {
  const q = query.trim().toLowerCase();
  if (!q) {
    return PRESET_LOCATIONS.slice(0, limit).map((p) => ({ ...p, source: 'preset' as const }));
  }
  return PRESET_LOCATIONS.filter(
    (p) => p.label.toLowerCase().includes(q) || (p.addressLine?.toLowerCase().includes(q) ?? false),
  )
    .slice(0, limit)
    .map((p) => ({ ...p, source: 'preset' as const }));
}

/** OpenStreetMap Nominatim geocode — best-effort when online */
export async function searchPlacesOnline(query: string, limit = 6): Promise<PlaceResult[]> {
  const q = query.trim();
  if (q.length < 3) return [];

  const params = new URLSearchParams({
    q: `${q}, Bengaluru, India`,
    format: 'json',
    limit: String(limit),
    countrycodes: 'in',
  });

  const res = await fetch(`https://nominatim.openstreetmap.org/search?${params}`, {
    headers: { Accept: 'application/json' },
  });
  if (!res.ok) return [];

  const data = (await res.json()) as Array<{ display_name: string; lat: string; lon: string }>;
  return data.map((item) => ({
    label: item.display_name.split(',')[0] || item.display_name,
    addressLine: item.display_name,
    lat: parseFloat(item.lat),
    lng: parseFloat(item.lon),
    source: 'search' as const,
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
    const params = new URLSearchParams({ lat: String(lat), lon: String(lng), format: 'json' });
    const res = await fetch(`https://nominatim.openstreetmap.org/reverse?${params}`, {
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) return `Pinned location (${lat.toFixed(4)}, ${lng.toFixed(4)})`;
    const data = (await res.json()) as { display_name?: string };
    return data.display_name?.split(',').slice(0, 2).join(', ') || 'Pinned location';
  } catch {
    return `Pinned location (${lat.toFixed(4)}, ${lng.toFixed(4)})`;
  }
}
