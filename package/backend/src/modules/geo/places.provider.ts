/**
 * Google Places API (New) — server-side proxy so the API key never ships
 * to clients. Falls back to Nominatim when GOOGLE_PLACES_API_KEY is unset.
 */

const GOOGLE_PLACES_BASE = 'https://places.googleapis.com/v1/places:searchText';
const NOMINATIM_BASE = 'https://nominatim.openstreetmap.org';

export function isGoogleConfigured(): boolean {
  return Boolean(process.env.GOOGLE_PLACES_API_KEY);
}

export interface PlaceSuggestion {
  place_id: string;
  label: string;
  address_line: string;
  lat: number;
  lng: number;
}

export interface AutocompleteOptions {
  limit?: number;
  sessionToken?: string;
  biasLat?: number;
  biasLng?: number;
  biasRadiusMeters?: number;
}

export async function autocomplete(query: string, options: AutocompleteOptions = {}): Promise<PlaceSuggestion[]> {
  const q = query.trim();
  if (q.length < 2) return [];

  const limit = options.limit ?? 8;

  if (isGoogleConfigured()) {
    return searchGooglePlaces(q, limit, options);
  }
  return searchNominatim(q, limit, options);
}

async function searchGooglePlaces(
  query: string,
  limit: number,
  options: AutocompleteOptions
): Promise<PlaceSuggestion[]> {
  const body: Record<string, unknown> = {
    textQuery: query,
    maxResultCount: limit,
    languageCode: 'en',
    regionCode: 'IN',
  };

  if (options.biasLat != null && options.biasLng != null) {
    body.locationBias = {
      circle: {
        center: { latitude: options.biasLat, longitude: options.biasLng },
        radius: options.biasRadiusMeters ?? 25000,
      },
    };
  } else {
    body.textQuery = `${query}, Bengaluru, India`;
  }

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'X-Goog-Api-Key': process.env.GOOGLE_PLACES_API_KEY as string,
    'X-Goog-FieldMask': 'places.id,places.displayName,places.formattedAddress,places.location',
  };
  if (options.sessionToken) {
    headers['X-Goog-Session-Token'] = options.sessionToken;
  }

  const res = await fetch(GOOGLE_PLACES_BASE, {
    method: 'POST',
    headers,
    body: JSON.stringify(body),
  });

  if (!res.ok) return [];

  const data = (await res.json()) as {
    places?: Array<{
      id?: string;
      displayName?: { text?: string };
      formattedAddress?: string;
      location?: { latitude?: number; longitude?: number };
    }>;
  };

  return (data.places ?? []).map((p) => ({
    place_id: p.id ?? '',
    label: p.displayName?.text ?? 'Unknown',
    address_line: p.formattedAddress ?? '',
    lat: p.location?.latitude ?? 0,
    lng: p.location?.longitude ?? 0,
  }));
}

async function searchNominatim(
  query: string,
  limit: number,
  options: AutocompleteOptions
): Promise<PlaceSuggestion[]> {
  const params = new URLSearchParams({
    q: options.biasLat != null ? query : `${query}, Bengaluru, India`,
    format: 'json',
    limit: String(limit),
    countrycodes: 'in',
  });

  if (options.biasLat != null && options.biasLng != null) {
    const delta = 0.25;
    params.set('viewbox', `${options.biasLng - delta},${options.biasLat + delta},${options.biasLng + delta},${options.biasLat - delta}`);
    params.set('bounded', '1');
  }

  const res = await fetch(`${NOMINATIM_BASE}/search?${params}`, {
    headers: { Accept: 'application/json', 'User-Agent': 'PORTMYSTUFF/1.0' },
  });
  if (!res.ok) return [];

  const data = (await res.json()) as Array<{ place_id: number; display_name: string; lat: string; lon: string }>;
  return data.map((item) => ({
    place_id: String(item.place_id),
    label: item.display_name.split(',')[0] || item.display_name,
    address_line: item.display_name,
    lat: parseFloat(item.lat),
    lng: parseFloat(item.lon),
  }));
}

export async function reverseGeocode(lat: number, lng: number): Promise<string> {
  if (isGoogleConfigured()) {
    const res = await fetch(
      `https://maps.googleapis.com/maps/api/geocode/json?latlng=${lat},${lng}&key=${process.env.GOOGLE_PLACES_API_KEY}`
    );
    if (res.ok) {
      const data = (await res.json()) as { results?: Array<{ formatted_address?: string }> };
      if (data.results?.[0]?.formatted_address) {
        return data.results[0].formatted_address.split(',').slice(0, 2).join(', ');
      }
    }
  }

  const params = new URLSearchParams({ lat: String(lat), lon: String(lng), format: 'json' });
  const res = await fetch(`${NOMINATIM_BASE}/reverse?${params}`, {
    headers: { Accept: 'application/json', 'User-Agent': 'PORTMYSTUFF/1.0' },
  });
  if (!res.ok) return `Pinned location (${lat.toFixed(4)}, ${lng.toFixed(4)})`;
  const data = (await res.json()) as { display_name?: string };
  return data.display_name?.split(',').slice(0, 2).join(', ') || 'Pinned location';
}
