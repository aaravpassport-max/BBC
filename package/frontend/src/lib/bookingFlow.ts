import type { BookingDraft } from '../api/vehicles';
import type { Quote } from '../api/bookings';
import type { ServiceId, VehicleGroupId } from '../constants/vehicleCatalog';
import type { LocationPoint } from './locations';

/** Navigation state when moving between booking steps. */
export interface BookingFlowState {
  draft: BookingDraft;
  /** Where to return after editing contact details. */
  returnTo?: 'vehicles' | 'confirm';
  dropIndex?: number;
  confirmState?: ConfirmNavState;
}

export interface BookingLocationNavState {
  serviceId: ServiceId;
  draft?: BookingDraft;
  /** Which end was filled from a shared WhatsApp / maps link. */
  deepLinkFilled?: 'pickup' | 'drop';
}

export interface SharedLocationNavState {
  point: LocationPoint;
  role?: 'pickup' | 'drop';
}

export interface ConfirmNavState {
  quote: Quote;
  pickup: LocationPoint;
  drops: LocationPoint[];
  goodsCategory: string;
  weightBand?: string;
  helperNeeded: boolean;
  couponCode?: string;
  loyaltyToRedeem?: number;
  scheduledFor?: string;
  vehicleGroup: VehicleGroupId;
  serviceId: ServiceId;
}

export function draftToConfirmState(
  draft: BookingDraft,
  quote: Quote,
  extras?: { couponCode?: string; loyaltyToRedeem?: number }
): ConfirmNavState {
  return {
    quote,
    pickup: draft.pickup,
    drops: draft.drops,
    goodsCategory: draft.goodsCategory,
    weightBand: draft.weightBand,
    helperNeeded: draft.helperNeeded,
    couponCode: extras?.couponCode ?? draft.couponCode,
    loyaltyToRedeem: extras?.loyaltyToRedeem ?? draft.loyaltyToRedeem,
    scheduledFor: draft.scheduledFor,
    vehicleGroup: draft.vehicleGroup,
    serviceId: draft.serviceId,
  };
}
