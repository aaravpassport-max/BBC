export type BookingType = 'parcel' | 'ride';

export type VehicleGroupId = 'two_wheeler' | 'three_wheeler' | 'truck' | 'ride';

/** Home-screen service entry — may map to a vehicle group or a specialised flow. */
export type ServiceId = VehicleGroupId | 'packers_movers' | 'ride';

export function serviceToVehicleGroup(serviceId: ServiceId): VehicleGroupId {
  if (serviceId === 'packers_movers') return 'truck';
  if (serviceId === 'ride') return 'ride';
  return serviceId;
}

export function serviceDefaults(serviceId: ServiceId): {
  goodsCategory: string;
  weightBand: string;
  helperNeeded: boolean;
  bookingType: BookingType;
} {
  if (serviceId === 'ride') {
    return { goodsCategory: '', weightBand: 'light', helperNeeded: false, bookingType: 'ride' };
  }
  if (serviceId === 'packers_movers') {
    return { goodsCategory: 'Furniture', weightBand: 'heavy', helperNeeded: true, bookingType: 'parcel' };
  }
  return { goodsCategory: 'Furniture', weightBand: 'medium', helperNeeded: false, bookingType: 'parcel' };
}

export interface VehicleTypeMeta {
  id: string;
  label: string;
  icon: string;
  blurb: string;
  capacity: string;
  examples?: string;
  group: VehicleGroupId;
  bookingType: BookingType;
  sortOrder: number;
}

export const VEHICLE_GROUPS: { id: VehicleGroupId; label: string; description: string }[] = [
  { id: 'ride', label: 'Ride', description: 'Book a vehicle to travel' },
  { id: 'two_wheeler', label: 'Bike & Scooter', description: 'Fast delivery for small parcels' },
  { id: 'three_wheeler', label: '3-Wheeler', description: 'Auto / tempo for medium loads' },
  { id: 'truck', label: 'Trucks', description: 'Mini to large trucks for furniture & bulk' },
];

/** Porter-style parcel service tiles on the home screen. */
export const HOME_PARCEL_TILES: {
  id: ServiceId;
  label: string;
  description: string;
  icon: string;
  wide?: boolean;
}[] = [
  {
    id: 'truck',
    label: 'Trucks',
    description: 'Mini to large trucks for furniture & bulk',
    icon: '🚚',
  },
  {
    id: 'two_wheeler',
    label: '2 Wheeler',
    description: 'Bikes & scooters for parcels',
    icon: '🛵',
  },
  {
    id: 'packers_movers',
    label: 'Packers & Movers',
    description: 'Full home & office shifting with helpers',
    icon: '📦',
    wide: true,
  },
];

/** @deprecated use HOME_PARCEL_TILES */
export const HOME_SERVICE_TILES = HOME_PARCEL_TILES;

export const HOME_RIDE_TILE = {
  id: 'ride' as const,
  label: 'Book a Ride',
  description: 'Travel from pickup to drop — like Uber or Rapido',
  icon: '🚗',
};

export const VEHICLE_TYPES: VehicleTypeMeta[] = [
  {
    id: 'auto',
    label: 'Auto',
    icon: '🛺',
    blurb: 'Affordable 3-wheeler ride',
    capacity: 'Up to 3 passengers',
    group: 'ride',
    bookingType: 'ride',
    sortOrder: 1,
  },
  {
    id: 'hatchback',
    label: 'Hatchback',
    icon: '🚗',
    blurb: 'Compact car for city trips',
    capacity: 'Up to 4 passengers',
    group: 'ride',
    bookingType: 'ride',
    sortOrder: 2,
  },
  {
    id: 'sedan',
    label: 'Sedan',
    icon: '🚙',
    blurb: 'Comfortable sedan',
    capacity: 'Up to 4 passengers',
    group: 'ride',
    bookingType: 'ride',
    sortOrder: 3,
  },
  {
    id: 'suv',
    label: 'SUV',
    icon: '🚐',
    blurb: 'Spacious SUV for groups',
    capacity: 'Up to 6 passengers',
    group: 'ride',
    bookingType: 'ride',
    sortOrder: 4,
  },
  {
    id: 'bike',
    label: 'Bike',
    icon: '🏍️',
    blurb: 'Documents, food, small parcels',
    capacity: 'Up to 10 kg',
    examples: 'Envelopes, tiffin, pharmacy',
    group: 'two_wheeler',
    bookingType: 'parcel',
    sortOrder: 10,
  },
  {
    id: 'scooter',
    label: 'Scooter',
    icon: '🛵',
    blurb: 'Small boxes and ecommerce parcels',
    capacity: 'Up to 20 kg',
    examples: 'Shoes, gadgets, groceries',
    group: 'two_wheeler',
    bookingType: 'parcel',
    sortOrder: 11,
  },
  {
    id: 'two_wheeler',
    label: '2-Wheeler',
    icon: '🛵',
    blurb: 'General two-wheeler delivery',
    capacity: 'Up to 20 kg',
    group: 'two_wheeler',
    bookingType: 'parcel',
    sortOrder: 12,
  },
  {
    id: 'three_wheeler',
    label: '3-Wheeler / Auto',
    icon: '🛺',
    blurb: 'Medium household items',
    capacity: 'Up to 500 kg',
    examples: 'Cartons, small appliances',
    group: 'three_wheeler',
    bookingType: 'parcel',
    sortOrder: 13,
  },
  {
    id: 'mini_truck',
    label: 'Mini Truck',
    icon: '🚚',
    blurb: 'Tata Ace, Dost, similar',
    capacity: 'Up to 750 kg',
    examples: 'Single sofa, fridge, 15–20 boxes',
    group: 'truck',
    bookingType: 'parcel',
    sortOrder: 14,
  },
  {
    id: 'pickup_truck',
    label: 'Pickup Truck',
    icon: '🛻',
    blurb: 'Open-body pickup / 8–10 ft',
    capacity: 'Up to 1,500 kg',
    examples: 'Office shifting, construction material',
    group: 'truck',
    bookingType: 'parcel',
    sortOrder: 15,
  },
  {
    id: 'large_truck',
    label: 'Large Truck',
    icon: '🚛',
    blurb: '14 ft+ closed or open body',
    capacity: 'Up to 5,000 kg',
    examples: 'Full home move, pallet loads',
    group: 'truck',
    bookingType: 'parcel',
    sortOrder: 16,
  },
];

const VEHICLE_BY_ID = Object.fromEntries(VEHICLE_TYPES.map((v) => [v.id, v])) as Record<string, VehicleTypeMeta>;

export function getVehicleMeta(categoryName: string): VehicleTypeMeta {
  return (
    VEHICLE_BY_ID[categoryName] ?? {
      id: categoryName,
      label: categoryName.replace(/_/g, ' '),
      icon: '🚐',
      blurb: '',
      capacity: '',
      group: 'truck' as VehicleGroupId,
      bookingType: 'parcel' as BookingType,
      sortOrder: 99,
    }
  );
}

export const WEIGHT_RECOMMENDATIONS: Record<string, string[]> = {
  light: ['bike', 'scooter', 'two_wheeler'],
  medium: ['scooter', 'three_wheeler', 'mini_truck'],
  heavy: ['mini_truck', 'pickup_truck'],
  bulk: ['pickup_truck', 'large_truck'],
};

export function isRecommendedForWeight(categoryName: string, weightBand: string): boolean {
  const rec = WEIGHT_RECOMMENDATIONS[weightBand];
  return rec ? rec.includes(categoryName) : false;
}

export function bookingTypeLabel(type: BookingType): string {
  return type === 'ride' ? 'Ride' : 'Parcel';
}

export function bookingTypeIcon(type: BookingType): string {
  return type === 'ride' ? '🚗' : '📦';
}
