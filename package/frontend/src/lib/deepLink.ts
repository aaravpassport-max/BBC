import type { LocationPoint } from './locations';

export interface ParsedLocationLink {
  lat: number;
  lng: number;
  label?: string;
}

const PENDING_KEY = 'portmystuff_pending_location_link';

function parseCoordinatePair(value: string): { lat: number; lng: number } | null {
  const cleaned = value.replace(/[()]/g, ' ').trim();
  const match = cleaned.match(/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/);
  if (!match) return null;
  const lat = parseFloat(match[1]);
  const lng = parseFloat(match[2]);
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
  if (lat === 0 && lng === 0) return null;
  return { lat, lng };
}

function extractLabelFromGeoQuery(query: string): string | undefined {
  const labelMatch = query.match(/\(([^)]+)\)\s*$/);
  return labelMatch?.[1]?.trim() || undefined;
}

function parseGeoScheme(url: string): ParsedLocationLink | null {
  const body = url.slice('geo:'.length);
  const [coordsPart, queryPart] = body.split('?', 2);

  if (queryPart) {
    const q = decodeURIComponent(queryPart);
    const fromQuery = parseCoordinatePair(q.replace(/^q=/, ''));
    if (fromQuery) {
      return { ...fromQuery, label: extractLabelFromGeoQuery(q) };
    }
  }

  const direct = parseCoordinatePair(coordsPart);
  if (direct) return direct;
  return null;
}

/** Parse geo:, custom scheme, and common Google Maps share URLs. */
export function parseLocationUrl(url: string): ParsedLocationLink | null {
  const raw = url.trim();
  if (!raw) return null;

  try {
    if (raw.startsWith('geo:')) {
      return parseGeoScheme(raw);
    }

    const normalized = raw.replace(/^portmystuff:\/\//i, 'com.waybill.customer://');
    if (normalized.startsWith('com.waybill.customer://')) {
      const parsed = new URL(normalized);
      const lat = parseFloat(parsed.searchParams.get('lat') ?? '');
      const lng = parseFloat(parsed.searchParams.get('lng') ?? '');
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        return {
          lat,
          lng,
          label: parsed.searchParams.get('label') ?? undefined,
        };
      }
    }

    const withProtocol = /^https?:\/\//i.test(raw) ? raw : `https://${raw}`;
    const parsedUrl = new URL(withProtocol);
    const host = parsedUrl.hostname.toLowerCase();

    if (host.includes('google') || host.includes('maps') || host.includes('goo.gl')) {
      const q = parsedUrl.searchParams.get('q') ?? parsedUrl.searchParams.get('query');
      if (q) {
        const coords = parseCoordinatePair(q);
        if (coords) {
          return { ...coords, label: extractLabelFromGeoQuery(q) };
        }
      }

      const atMatch = withProtocol.match(/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/);
      if (atMatch) {
        return { lat: parseFloat(atMatch[1]), lng: parseFloat(atMatch[2]) };
      }

      const pathCoords = parseCoordinatePair(parsedUrl.pathname);
      if (pathCoords) return pathCoords;
    }
  } catch {
    return null;
  }

  return null;
}

export function parsedLinkToPoint(parsed: ParsedLocationLink): LocationPoint {
  const label = parsed.label?.trim() || 'Shared location';
  return {
    label,
    lat: parsed.lat,
    lng: parsed.lng,
    addressLine: parsed.label,
  };
}

export function storePendingLocationLink(parsed: ParsedLocationLink): void {
  sessionStorage.setItem(PENDING_KEY, JSON.stringify(parsed));
}

export function peekPendingLocationLink(): ParsedLocationLink | null {
  const raw = sessionStorage.getItem(PENDING_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as ParsedLocationLink;
  } catch {
    return null;
  }
}

export function consumePendingLocationLink(): ParsedLocationLink | null {
  const pending = peekPendingLocationLink();
  sessionStorage.removeItem(PENDING_KEY);
  return pending;
}
