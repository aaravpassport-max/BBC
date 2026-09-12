import { api } from './client';

// ---------- Auth (shared OTP contract) ----------

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

// ---------- SOS (PRD 10A.1) ----------

export interface SosEvent {
  id: string;
  booking_id: string;
  triggered_by_role: string;
  trigger_lat: number | null;
  trigger_lng: number | null;
  status: string;
  acknowledged_at: string | null;
  created_at: string;
  escalated_at: string | null;
  auto_escalated: boolean;
  booking_status: string;
  pickup_address_snapshot: Record<string, unknown>;
  driver_lat?: number | null;
  driver_lng?: number | null;
  driver_last_ping?: string | null;
}

export function getSosQueue() {
  return api.get<SosEvent[]>('/ops/v1/sos/queue');
}

export function acknowledgeSos(id: string) {
  return api.post<{ acknowledged: boolean }>(`/ops/v1/sos/${id}/acknowledge`);
}

export function escalateSos(id: string) {
  return api.post<{ escalated: boolean }>(`/ops/v1/sos/${id}/escalate`);
}

export function resolveSos(id: string, outcomeTag: string, resolutionNote: string) {
  return api.post<{ resolved: boolean }>(`/ops/v1/sos/${id}/resolve`, {
    outcome_tag: outcomeTag,
    resolution_note: resolutionNote,
  });
}

// ---------- Dispatch monitoring (PRD A.3) ----------

export interface DispatchOffer {
  id: string;
  driver_id: string;
  status: string;
  offered_at: string;
  responded_at: string | null;
  expires_at: string;
}

export interface DispatchLog {
  booking: { id: string; status: string; driver_id: string | null };
  offers: DispatchOffer[];
}

export function getDispatchLog(bookingId: string) {
  return api.get<DispatchLog>(`/ops/v1/bookings/${bookingId}/dispatch-log`);
}

export function forceAssignDriver(bookingId: string, driverId: string) {
  return api.post<{ assigned: boolean }>(`/ops/v1/bookings/${bookingId}/force-assign`, { driver_id: driverId });
}

// ---------- Live fleet map ----------

export interface LiveDriverPin {
  driver_id: string;
  phone: string;
  name: string | null;
  lat: number;
  lng: number;
  online_status: boolean;
  on_trip: boolean;
  last_ping_at: string | null;
  active_booking_id: string | null;
}

export function getLiveDrivers() {
  return api.get<LiveDriverPin[]>('/ops/v1/live-map/drivers');
}
