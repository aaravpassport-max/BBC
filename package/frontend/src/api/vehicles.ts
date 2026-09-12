import { api } from './client';
import type { Quote } from './bookings';
import type { LocationPoint } from '../lib/locations';

export interface VehicleCategoryInfo {
  name: string;
  capacity_descriptor: string;
  license_class_required: string | null;
  permit_required: boolean;
  vehicle_group: string;
}

export function listVehicleCategories(lat: number, lng: number) {
  return api.get<VehicleCategoryInfo[]>(`/v1/pricing/vehicle-categories?lat=${lat}&lng=${lng}`);
}

export interface BookingDraft {
  pickup: LocationPoint;
  drops: LocationPoint[];
  goodsCategory: string;
  weightBand: string;
  helperNeeded: boolean;
  couponCode?: string;
  loyaltyToRedeem?: number;
  scheduledFor?: string;
}

export interface VehicleQuoteOption {
  quote: Quote;
  category: VehicleCategoryInfo;
}

export function mergeQuotesWithCategories(quotes: Quote[], categories: VehicleCategoryInfo[]): VehicleQuoteOption[] {
  const byName = Object.fromEntries(categories.map((c) => [c.name, c]));
  return quotes
    .map((quote) => ({
      quote,
      category: byName[quote.vehicle_category] ?? {
        name: quote.vehicle_category,
        capacity_descriptor: '',
        license_class_required: null,
        permit_required: false,
        vehicle_group: 'other',
      },
    }))
    .sort((a, b) => a.quote.fare_breakdown.final_fare - b.quote.fare_breakdown.final_fare);
}
