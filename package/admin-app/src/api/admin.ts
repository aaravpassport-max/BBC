import { api } from './client';

export interface RateCard {
  id: string;
  status: string;
  version: number;
  base_fare: string;
  per_km_rate: string;
  minimum_fare: string;
  platform_fee: string;
}

export interface FraudFlag {
  id: string;
  subject_type: string;
  subject_id: string;
  signal_types: string[];
  evidence: Record<string, unknown>;
  severity: string;
  status: string;
  created_at: string;
}

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

// ---------- Rate Cards (PRD 9A.1) ----------

export function listRateCards(params: { cityId?: string; vehicleCategoryId?: string }) {
  const query = new URLSearchParams();
  if (params.cityId) query.set('city_id', params.cityId);
  if (params.vehicleCategoryId) query.set('vehicle_category_id', params.vehicleCategoryId);
  return api.get<(RateCard & { vehicle_category_name: string; city_name: string })[]>(
    `/admin/v1/pricing/rate-cards?${query.toString()}`
  );
}

export function createRateCard(params: {
  city_id: string;
  vehicle_category_id: string;
  base_fare: number;
  per_km_rate: number;
  minimum_fare: number;
  platform_fee?: number;
}) {
  return api.post<{ id: string }>('/admin/v1/pricing/rate-cards', params);
}

export function publishRateCard(id: string, expectedVersion: number) {
  return api.post<{ version: number }>(`/admin/v1/pricing/rate-cards/${id}/publish`, {
    expected_version: expectedVersion,
  });
}

// ---------- Drivers (PRD 9A.2) ----------

export function suspendDriver(driverId: string, reasonCode: string, note?: string) {
  return api.post<{ suspended: boolean }>(`/admin/v1/drivers/${driverId}/suspend`, { reason_code: reasonCode, note });
}

export function reinstateDriver(driverId: string) {
  return api.post<{ reinstated: boolean }>(`/admin/v1/drivers/${driverId}/reinstate`);
}

// ---------- Fraud Queue (PRD 17A.1) ----------

export function getFraudQueue(status?: string) {
  return api.get<FraudFlag[]>(`/admin/v1/fraud/queue${status ? `?status=${status}` : ''}`);
}

export function resolveFraudFlag(id: string, action: 'clear' | 'escalate' | 'hold' | 'suspend', note: string) {
  return api.post<{ resolved: boolean }>(`/admin/v1/fraud/queue/${id}/resolve`, { action, note });
}

// ---------- Support (PRD 11A.1) ----------

export interface SupportTicket {
  id: string;
  user_id: string;
  category: string;
  status: string;
  priority: string;
  sla_due_at: string;
  sla_breached?: boolean;
  created_at: string;
}

export interface SupportMessage {
  id: string;
  sender_id: string;
  sender_role: string;
  body: string;
  created_at: string;
}

export function getSupportQueue(params: { status?: string; priority?: string }) {
  const query = new URLSearchParams();
  if (params.status) query.set('status', params.status);
  if (params.priority) query.set('priority', params.priority);
  return api.get<SupportTicket[]>(`/v1/support/queue?${query.toString()}`);
}

export function getSupportTicket(id: string) {
  return api.get<SupportTicket & { messages: SupportMessage[] }>(`/v1/support/tickets/${id}`);
}

export function replyToTicket(id: string, body: string) {
  return api.post<{ reopened: boolean; newTicketCreated: boolean }>(`/v1/support/tickets/${id}/messages`, { body });
}

export function closeTicket(id: string, resolutionCategory: string, resolutionNote: string) {
  return api.post<{ closed: boolean }>(`/v1/support/tickets/${id}/close`, {
    resolution_category: resolutionCategory,
    resolution_note: resolutionNote,
  });
}

export function escalateTicket(id: string) {
  return api.post<{ escalated: boolean }>(`/v1/support/tickets/${id}/escalate`);
}

// ---------- Analytics (PRD Section 20) ----------

export interface RevenueDashboard {
  completed_bookings: number;
  cancelled_bookings: number;
  total_bookings: number;
  gross_revenue: number;
  platform_fee_revenue: number;
  coupon_discount_liability: number;
  subscription_benefit_liability: number;
  take_rate_pct: number;
}

export interface BookingFunnel {
  stages: { stage: string; count: number; conversion_from_previous_pct: number }[];
  no_drivers_found: number;
  cancelled: number;
}

export function getRevenueDashboard() {
  return api.get<RevenueDashboard>('/analytics/v1/revenue');
}

export function getBookingFunnel() {
  return api.get<BookingFunnel>('/analytics/v1/funnel/booking');
}

// ---------- KYC Review ----------

export interface PendingKycDoc {
  id: string;
  driver_id: string;
  doc_type: string;
  status: string;
  created_at: string;
  phone: string;
  name: string | null;
}

export function listPendingKyc() {
  return api.get<PendingKycDoc[]>('/v1/driver/kyc/admin/pending');
}

export function reviewKycDocument(documentId: string, decision: 'approved' | 'rejected', rejectionReason?: string) {
  return api.post<{ reviewed: boolean }>(`/v1/driver/kyc/documents/${documentId}/review`, {
    decision,
    rejection_reason: rejectionReason,
  });
}

// ---------- Penalty management ----------

export interface AdminPenalty {
  id: string;
  driver_id: string;
  amount: string;
  reason_code: string;
  status: string;
  dispute_note: string | null;
  created_at: string;
  phone: string;
}

export function listAdminPenalties(status?: string) {
  return api.get<AdminPenalty[]>(`/v1/driver/admin/penalties${status ? `?status=${status}` : ''}`);
}

export function issuePenalty(driverId: string, amount: number, reasonCode: string, note?: string) {
  return api.post<{ id: string }>('/v1/driver/admin/penalties', {
    driver_id: driverId,
    amount,
    reason_code: reasonCode,
    reason_note: note,
  });
}

export function resolvePenaltyDispute(penaltyId: string, resolution: 'upheld' | 'reversed', note: string) {
  return api.post<{ resolved: boolean }>(`/v1/driver/admin/penalties/${penaltyId}/resolve`, {
    resolution,
    resolution_note: note,
  });
}

export interface CancellationBreakdown {
  total_bookings: number;
  cancellation_rate_pct: number;
  by_reason: { reason_code: string; count: number }[];
}

export interface DriverUtilizationRow {
  driver_id: string;
  completed_trips: number;
  trip_hours: number;
}

export function getCancellationBreakdown() {
  return api.get<CancellationBreakdown>('/analytics/v1/cancellations');
}

export function getDriverUtilization() {
  return api.get<DriverUtilizationRow[]>('/analytics/v1/driver-utilization');
}

// ---------- RBAC (PRD 22A.1/22A.2) ----------

export interface Role {
  id: string;
  name: string;
  description: string | null;
}

export interface Permission {
  id: string;
  resource: string;
  action: string;
}

export interface UserLookupResult {
  id: string;
  phone: string;
  account_type: string;
}

export function listRoles() {
  return api.get<Role[]>('/admin/v1/rbac/roles');
}

export function listPermissions() {
  return api.get<Permission[]>('/admin/v1/rbac/permissions');
}

export function getRolePermissions(roleId: string) {
  return api.get<Permission[]>(`/admin/v1/rbac/roles/${roleId}/permissions`);
}

export function createRole(name: string, description?: string) {
  return api.post<{ id: string }>('/admin/v1/rbac/roles', { name, description });
}

export function setRolePermissions(roleId: string, permissionIds: string[]) {
  return api.put<{ updated: boolean }>(`/admin/v1/rbac/roles/${roleId}/permissions`, { permission_ids: permissionIds });
}

export function lookupUserByPhone(phone: string) {
  return api.get<UserLookupResult | null>(`/admin/v1/rbac/users/lookup?phone=${phone}`);
}

export function getUserRoles(userId: string) {
  return api.get<Role[]>(`/admin/v1/rbac/users/${userId}/roles`);
}

export function assignRoleToUser(userId: string, roleId: string) {
  return api.post<{ assigned: boolean }>('/admin/v1/rbac/user-roles', { user_id: userId, role_id: roleId });
}

export function revokeRoleFromUser(userId: string, roleId: string) {
  return api.del<{ revoked: boolean }>(`/admin/v1/rbac/user-roles/${userId}/${roleId}`);
}

// ---------- Marketing / CMS (PRD 9B.1) ----------

export interface Banner {
  id: string;
  headline: string;
  image_url: string;
  cta_text: string | null;
  cta_deep_link: string;
  status: string;
  priority: number;
  start_at: string;
  end_at: string;
}

export function listBanners() {
  return api.get<Banner[]>('/v1/cms/banners');
}

export function createBanner(params: {
  headline: string;
  image_url: string;
  cta_text?: string;
  cta_deep_link: string;
  priority?: number;
  start_at: string;
  end_at: string;
}) {
  return api.post<{ id: string }>('/v1/cms/banners', params);
}

export function publishBanner(id: string) {
  return api.post<{ published: boolean }>(`/v1/cms/banners/${id}/publish`);
}
