import { api } from './client';

export function requestOtp(phone: string, deviceId: string) {
  return api.public.post<{ otp_id: string; expires_in_seconds: number; resend_after_seconds: number }>(
    '/v1/auth/otp/request',
    { phone, country_code: '+91', device_id: deviceId, app_version: '1.0.0' }
  );
}

export function verifyOtp(otpId: string, code: string, deviceId: string) {
  return api.public.post<{ access_token: string; refresh_token: string; is_new_user: boolean; user_id: string }>(
    '/v1/auth/otp/verify',
    { otp_id: otpId, code, device_id: deviceId }
  );
}

export interface FleetDriver {
  driver_id: string;
  phone: string;
  name: string | null;
  status: 'online' | 'offline' | 'on_trip';
  current_lat: number | null;
  current_lng: number | null;
}

export function getFleetDrivers() {
  return api.get<FleetDriver[]>('/v1/fleet/drivers');
}

export function addDriverToFleet(driverPhone: string) {
  return api.post<{ driverId: string }>('/v1/fleet/drivers', { driver_phone: driverPhone });
}

export function removeDriverFromFleet(driverId: string) {
  return api.del<{ removed: boolean }>(`/v1/fleet/drivers/${driverId}`);
}

export interface FleetDriverTransaction {
  entry_type: 'debit' | 'credit';
  balance_type: 'real' | 'promo';
  amount: string;
  reason: string;
  created_at: string;
}

export interface FleetDriverDetail {
  balance: number;
  transactions: FleetDriverTransaction[];
}

export function getFleetDriverDetail(driverId: string) {
  return api.get<FleetDriverDetail>(`/v1/fleet/drivers/${driverId}`);
}

export interface FleetEarningsSummary {
  totalToday: number;
  driverCount: number;
}

export function getFleetEarningsSummary() {
  return api.get<FleetEarningsSummary>('/v1/fleet/earnings');
}

export interface FleetVehicle {
  id: string;
  category: string;
  plate_number: string;
  status: string;
  driver_id: string | null;
  scheduled_reassignment_to: string | null;
}

export function getFleetVehicles() {
  return api.get<FleetVehicle[]>('/v1/fleet/vehicles');
}

export function reassignVehicle(vehicleId: string, newDriverId: string) {
  return api.post<{ effective: 'immediate' | 'on_next_completion' }>(`/v1/fleet/vehicles/${vehicleId}/reassign`, {
    new_driver_id: newDriverId,
  });
}
