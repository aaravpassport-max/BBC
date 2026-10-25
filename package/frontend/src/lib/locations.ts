export interface LocationPoint {
  label: string;
  lat: number;
  lng: number;
  addressLine?: string;
}

/** Bengaluru reference locations for demo + offline use */
export const PRESET_LOCATIONS: LocationPoint[] = [
  { label: 'Koramangala Warehouse', addressLine: '80 Feet Rd, Koramangala 4th Block', lat: 12.9352, lng: 77.6245 },
  { label: 'Indiranagar Depot', addressLine: '100 Feet Rd, Indiranagar', lat: 12.9784, lng: 77.6408 },
  { label: 'HSR Layout Store', addressLine: 'Sector 2, HSR Layout', lat: 12.9116, lng: 77.6473 },
  { label: 'Whitefield Yard', addressLine: 'ITPL Main Rd, Whitefield', lat: 12.9698, lng: 77.75 },
  { label: 'MG Road Hub', addressLine: 'MG Road, Bengaluru', lat: 12.975, lng: 77.6063 },
  { label: 'Electronic City', addressLine: 'Phase 1, Electronic City', lat: 12.8456, lng: 77.6603 },
  { label: 'Jayanagar Market', addressLine: '4th Block, Jayanagar', lat: 12.9308, lng: 77.5838 },
  { label: 'Yelahanka Air Cargo', addressLine: 'Yelahanka New Town', lat: 13.1007, lng: 77.5963 },
];

export function formatCoords(lat: number, lng: number): string {
  return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
}

export function locationKey(loc: Pick<LocationPoint, 'lat' | 'lng'>): string {
  return `${loc.lat.toFixed(5)}:${loc.lng.toFixed(5)}`;
}

export function sameLocation(a: Pick<LocationPoint, 'lat' | 'lng'>, b: Pick<LocationPoint, 'lat' | 'lng'>): boolean {
  return locationKey(a) === locationKey(b);
}
