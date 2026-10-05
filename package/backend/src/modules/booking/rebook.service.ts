import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

export interface LocationSnapshot {
  label?: string;
  lat: number;
  lng: number;
  addressLine?: string;
  unitDetail?: string;
  contactName?: string;
  contactPhone?: string;
  saveAs?: string;
  formatted?: string;
  line1?: string;
}

export interface RebookSnapshot {
  serviceId?: string;
  vehicleGroup?: string;
  goodsCategory?: string;
  weightBand?: string;
  helperNeeded?: boolean;
  pickup: LocationSnapshot;
  drops: LocationSnapshot[];
}

function snapshotFromJson(
  raw: Record<string, unknown> | null | undefined,
  lat: number,
  lng: number
): LocationSnapshot {
  if (!raw || typeof raw !== 'object') {
    return { lat, lng, label: 'Pinned location' };
  }
  const label =
    (raw.label as string) ||
    (raw.formatted as string) ||
    (raw.line1 as string) ||
    'Pinned location';
  const addressLine =
    (raw.addressLine as string) || (raw.formatted as string) || (raw.line1 as string) || label;
  return {
    label,
    lat: (raw.lat as number) ?? lat,
    lng: (raw.lng as number) ?? lng,
    addressLine,
    unitDetail: (raw.unitDetail as string) || (raw.landmark as string) || undefined,
    contactName: raw.contactName as string | undefined,
    contactPhone: raw.contactPhone as string | undefined,
    saveAs: raw.saveAs as string | undefined,
    formatted: raw.formatted as string | undefined,
    line1: raw.line1 as string | undefined,
  };
}

function vehicleGroupFromCategory(categoryName: string): string {
  const n = categoryName.toLowerCase();
  if (n.includes('bike') || n.includes('scooter') || n.includes('two')) return 'two_wheeler';
  if (n.includes('three') || n.includes('auto') || n.includes('tempo')) return 'three_wheeler';
  return 'truck';
}

function serviceIdFromCategory(categoryName: string): string {
  const n = categoryName.toLowerCase();
  if (n.includes('packer') || n.includes('mover')) return 'packers_movers';
  return vehicleGroupFromCategory(categoryName);
}

export async function getRebookPrefill(bookingId: string, customerId: string) {
  const result = await pool.query(
    `SELECT b.id, b.rebook_snapshot, b.pickup_address_snapshot,
            ST_X(b.pickup_geo::geometry) AS pickup_lng, ST_Y(b.pickup_geo::geometry) AS pickup_lat,
            vc.name AS vehicle_category_name
     FROM bookings b
     LEFT JOIN vehicle_categories vc ON vc.id = b.vehicle_category_id
     WHERE b.id = $1 AND b.customer_id = $2`,
    [bookingId, customerId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Booking');
  }

  const row = result.rows[0];
  const stopsResult = await pool.query(
    `SELECT address_snapshot, instructions,
            ST_X(geo::geometry) AS drop_lng, ST_Y(geo::geometry) AS drop_lat
     FROM booking_stops WHERE booking_id = $1 ORDER BY sequence`,
    [bookingId]
  );

  const stored = row.rebook_snapshot as RebookSnapshot | null;
  if (stored?.pickup && stored.drops?.length) {
    return {
      booking_id: row.id,
      source: 'snapshot' as const,
      snapshot: stored,
    };
  }

  const pickup = snapshotFromJson(
    row.pickup_address_snapshot as Record<string, unknown>,
    row.pickup_lat,
    row.pickup_lng
  );

  const drops = stopsResult.rows.map((stop) => {
    const snap = snapshotFromJson(
      stop.address_snapshot as Record<string, unknown>,
      stop.drop_lat,
      stop.drop_lng
    );
    if (stop.instructions && !snap.unitDetail) {
      snap.unitDetail = stop.instructions;
    }
    return snap;
  });

  const categoryName = (row.vehicle_category_name as string) || 'two_wheeler';
  const snapshot: RebookSnapshot = {
    serviceId: serviceIdFromCategory(categoryName),
    vehicleGroup: vehicleGroupFromCategory(categoryName),
    goodsCategory: 'Furniture',
    weightBand: 'medium',
    helperNeeded: false,
    pickup,
    drops: drops.length > 0 ? drops : [pickup],
  };

  return {
    booking_id: row.id,
    source: 'reconstructed' as const,
    snapshot,
  };
}

export function locationSnapshotToJson(point: LocationSnapshot): Record<string, unknown> {
  return {
    label: point.label,
    lat: point.lat,
    lng: point.lng,
    addressLine: point.addressLine,
    unitDetail: point.unitDetail,
    contactName: point.contactName,
    contactPhone: point.contactPhone,
    saveAs: point.saveAs,
    formatted: point.formatted || point.addressLine || point.label,
    line1: point.line1 || point.addressLine || point.label,
  };
}
