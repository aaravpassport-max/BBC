export interface AddressSnapshot {
  lat?: number;
  lng?: number;
  formatted?: string;
  line1?: string;
  label?: string;
}

export function formatAddress(addr: AddressSnapshot | null | undefined, fallback = 'Address on map'): string {
  if (!addr) return fallback;
  if (addr.formatted) return addr.formatted;
  if (addr.line1) return addr.line1;
  if (addr.label) return addr.label;
  if (addr.lat != null && addr.lng != null) {
    return `${addr.lat.toFixed(4)}, ${addr.lng.toFixed(4)}`;
  }
  return fallback;
}
