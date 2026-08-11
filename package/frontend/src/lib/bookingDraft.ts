import type { BookingDraft } from '../api/vehicles';
import type { ServiceId, VehicleGroupId } from '../constants/vehicleCatalog';
import { getVehicleMeta, serviceDefaults, serviceToVehicleGroup } from '../constants/vehicleCatalog';
import type { AddressSnapshot } from '../lib/address';
import type { LocationPoint } from './locations';

export interface RebookSnapshotPayload {
  serviceId: ServiceId;
  vehicleGroup: VehicleGroupId;
  goodsCategory: string;
  weightBand: string;
  helperNeeded: boolean;
  pickup: LocationPoint;
  drops: LocationPoint[];
}

export interface RebookPrefillResponse {
  booking_id: string;
  source: 'snapshot' | 'reconstructed';
  snapshot: RebookSnapshotPayload;
}

export interface BookingTemplate {
  id: string;
  name: string;
  snapshot: RebookSnapshotPayload;
  is_favourite: boolean;
  last_used_at: string | null;
  usage_count: number;
  created_at: string;
  updated_at: string;
}

function snapshotToPoint(
  snap: Record<string, unknown> | AddressSnapshot | null | undefined,
  lat?: number,
  lng?: number
): LocationPoint {
  const raw = (snap ?? {}) as Record<string, unknown>;
  const pointLat = (raw.lat as number) ?? lat ?? 0;
  const pointLng = (raw.lng as number) ?? lng ?? 0;
  const label =
    (raw.label as string) ||
    (raw.formatted as string) ||
    (raw.line1 as string) ||
    (raw.addressLine as string) ||
    'Pinned location';
  const addressLine =
    (raw.addressLine as string) || (raw.formatted as string) || (raw.line1 as string) || label;

  return {
    label,
    lat: pointLat,
    lng: pointLng,
    addressLine,
    unitDetail: (raw.unitDetail as string) || (raw.landmark as string) || undefined,
    contactName: raw.contactName as string | undefined,
    contactPhone: raw.contactPhone as string | undefined,
    saveAs: raw.saveAs as LocationPoint['saveAs'],
  };
}

export function vehicleCategoryToServiceId(categoryName: string): ServiceId {
  const n = categoryName.toLowerCase();
  if (n.includes('packer') || n.includes('mover')) return 'packers_movers';
  const meta = getVehicleMeta(categoryName);
  return meta.group;
}

export function snapshotPayloadToDraft(snapshot: RebookSnapshotPayload): BookingDraft {
  const defaults = serviceDefaults(snapshot.serviceId);
  return {
    bookingType: defaults.bookingType,
    serviceId: snapshot.serviceId,
    vehicleGroup: snapshot.vehicleGroup ?? serviceToVehicleGroup(snapshot.serviceId),
    pickup: snapshot.pickup,
    drops: snapshot.drops.length > 0 ? snapshot.drops : [snapshot.pickup],
    goodsCategory: snapshot.goodsCategory ?? defaults.goodsCategory,
    weightBand: snapshot.weightBand ?? defaults.weightBand,
    helperNeeded: snapshot.helperNeeded ?? defaults.helperNeeded,
  };
}

export function buildRebookSnapshot(draft: BookingDraft): RebookSnapshotPayload {
  return {
    serviceId: draft.serviceId,
    vehicleGroup: draft.vehicleGroup,
    goodsCategory: draft.goodsCategory,
    weightBand: draft.weightBand,
    helperNeeded: draft.helperNeeded,
    pickup: { ...draft.pickup },
    drops: draft.drops.map((d) => ({ ...d })),
  };
}

/** Exchange pickup ↔ first drop including all contact and address fields. */
export function swapBookingParties(draft: BookingDraft): BookingDraft {
  if (!draft.pickup || draft.drops.length === 0) return draft;
  const [firstDrop, ...restDrops] = draft.drops;
  return {
    ...draft,
    pickup: { ...firstDrop },
    drops: [{ ...draft.pickup }, ...restDrops],
  };
}

export function rebookResponseToDraft(response: RebookPrefillResponse): BookingDraft {
  const snap = response.snapshot;
  return snapshotPayloadToDraft({
    serviceId: (snap.serviceId as ServiceId) ?? 'two_wheeler',
    vehicleGroup: (snap.vehicleGroup as VehicleGroupId) ?? serviceToVehicleGroup('two_wheeler'),
    goodsCategory: snap.goodsCategory ?? serviceDefaults('two_wheeler').goodsCategory,
    weightBand: snap.weightBand ?? 'medium',
    helperNeeded: snap.helperNeeded ?? false,
    pickup: snapshotToPoint(snap.pickup as unknown as Record<string, unknown>, snap.pickup.lat, snap.pickup.lng),
    drops: (snap.drops ?? []).map((d) =>
      snapshotToPoint(d as unknown as Record<string, unknown>, d.lat, d.lng)
    ),
  });
}

export function bookingDetailToDraft(booking: {
  pickup_lat?: number;
  pickup_lng?: number;
  pickup_address?: AddressSnapshot | null;
  stops?: Array<{
    drop_lat?: number;
    drop_lng?: number;
    address_snapshot?: AddressSnapshot | null;
    instructions?: string | null;
  }>;
  vehicle_category_id?: string;
}): BookingDraft {
  const serviceId = vehicleCategoryToServiceId(booking.vehicle_category_id ?? 'two_wheeler');
  const defaults = serviceDefaults(serviceId);
  const pickup = snapshotToPoint(booking.pickup_address, booking.pickup_lat, booking.pickup_lng);
  const drops =
    booking.stops?.map((s) => {
      const point = snapshotToPoint(s.address_snapshot, s.drop_lat, s.drop_lng);
      if (s.instructions && !point.unitDetail) point.unitDetail = s.instructions;
      return point;
    }) ?? [];

  return {
    bookingType: defaults.bookingType,
    serviceId,
    vehicleGroup: serviceToVehicleGroup(serviceId),
    pickup,
    drops: drops.length > 0 ? drops : [pickup],
    goodsCategory: defaults.goodsCategory,
    weightBand: defaults.weightBand,
    helperNeeded: defaults.helperNeeded,
  };
}

export function draftTripLabel(draft: BookingDraft): string {
  const from = draft.pickup.label || 'Pickup';
  const to = draft.drops[0]?.label || 'Drop';
  return `${from} → ${to}`;
}
