import { api, newIdempotencyKey } from './client';
import {
  isDemoPhone,
  isDemoOtp,
  isDemoOtpRequest,
  localDemoAuth,
  localRequestOtp,
  localVerifyOtp,
} from './demoAuth';
import { DEMO_PHONE } from '../config/testCredentials';

export interface KycStepStatus {
  step: string;
  status: string;
  rejection_reason: string | null;
}

export interface KycStatus {
  overall_status: string;
  steps: KycStepStatus[];
}

export interface PendingOffer {
  offer_id: string;
  booking_id: string;
  expires_at: string;
  fare_breakdown: { final_fare: number };
  pickup_lat: number;
  pickup_lng: number;
}

export interface Stop {
  id: string;
  sequence: number;
  status: string;
  instructions: string | null;
  drop_lat: number;
  drop_lng: number;
}

export interface ActiveJob {
  id: string;
  status: string;
  pickup_lat: number;
  pickup_lng: number;
  stops: Stop[];
}

// ---------- Auth (shared OTP flow with the Customer app's contract) ----------

export function requestOtp(phone: string, deviceId: string) {
  if (isDemoPhone(phone)) {
    return Promise.resolve(localRequestOtp(phone));
  }
  return api.public.post<{ otp_id: string; expires_in_seconds: number; resend_after_seconds: number }>(
    '/v1/auth/otp/request',
    { phone, country_code: '+91', device_id: deviceId, app_version: '1.0.0' }
  );
}

export function verifyOtp(otpId: string, code: string, deviceId: string, phone?: string) {
  if ((phone && isDemoPhone(phone) && isDemoOtp(code)) || (isDemoOtpRequest(otpId) && isDemoOtp(code))) {
    return Promise.resolve(localVerifyOtp(phone ?? DEMO_PHONE, code));
  }
  return api.public.post<{ access_token: string; refresh_token: string; is_new_user: boolean; user_id: string }>(
    '/v1/auth/otp/verify',
    { otp_id: otpId, code, device_id: deviceId }
  );
}

export function demoLogin(phone: string, deviceId: string) {
  if (isDemoPhone(phone)) {
    return Promise.resolve(localDemoAuth());
  }
  return api.public.post<{ access_token: string; refresh_token: string; is_new_user: boolean; user_id: string }>(
    '/v1/auth/demo/login',
    { phone, country_code: '+91', device_id: deviceId }
  );
}

// ---------- KYC (PRD 3.2) ----------

export function registerAsDriver() {
  return api.post<{ registered: boolean }>('/v1/driver/kyc/register');
}

export function getKycStatus() {
  return api.get<KycStatus>('/v1/driver/kyc/status');
}

export function submitKycStep(step: string, documentUrl: string) {
  return api.post<{ submitted: boolean }>(`/v1/driver/kyc/${step}`, { document_url: documentUrl });
}

// ---------- Training (PRD 3.2) ----------

export interface TrainingStatus {
  module: string;
  status: string;
  video_watched_pct: number;
  quiz_attempts: number;
  max_attempts: number;
  can_retake_at: string | null;
  quiz_questions?: { question: string; options: string[] }[];
}

export function getTrainingStatus() {
  return api.get<TrainingStatus>('/v1/driver/training/modules');
}

export function updateTrainingProgress(watchedPct: number) {
  return api.post<TrainingStatus>('/v1/driver/training/platform_basics/progress', { watched_pct: watchedPct });
}

export function submitTrainingQuiz(answers: number[]) {
  return api.post<{ passed: boolean; scorePct: number; status: string }>(
    '/v1/driver/training/platform_basics/quiz-submit',
    { answers }
  );
}

// ---------- Online status + job polling (PRD Home screen, 3.3) ----------

export function setOnlineStatus(online: boolean) {
  return api.post<{ online: boolean }>('/v1/driver/status', { online });
}

export function updateLocation(lat: number, lng: number) {
  return api.post<{ acknowledged: boolean }>('/v1/driver/location', { lat, lng });
}

export function getPendingOffer() {
  return api.get<PendingOffer | null>('/v1/driver/jobs/pending-offer');
}

export function getActiveJob() {
  return api.get<ActiveJob | null>('/v1/driver/jobs/active');
}

export interface TripMessage {
  id: string;
  sender_id: string;
  sender_role: 'customer' | 'driver';
  body: string;
  created_at: string;
}

export function getTripMessages(bookingId: string) {
  return api.get<TripMessage[]>(`/v1/bookings/${bookingId}/messages`);
}

export function sendTripMessage(bookingId: string, body: string) {
  return api.post<{ id: string; senderRole: string; createdAt: string }>(`/v1/bookings/${bookingId}/messages`, { body });
}

// ---------- Job accept/decline (PRD 3.3) ----------

export function acceptJob(offerId: string) {
  return api.post<{ bookingId: string }>(`/v1/driver/jobs/${offerId}/accept`);
}

export function declineJob(offerId: string) {
  return api.post<{ declined: boolean }>(`/v1/driver/jobs/${offerId}/decline`);
}

// ---------- Trip execution (PRD 2.2.7, 3B.1) ----------

export function verifyPickupOtp(bookingId: string, otp: string) {
  return api.post<{ status: string }>(`/v1/driver/jobs/${bookingId}/verify-pickup`, { otp });
}

export function completeStop(bookingId: string, stopId: string, otp: string) {
  return api.post<{ bookingStatus: string; tripCompleted: boolean }>(
    `/v1/driver/jobs/${bookingId}/stops/${stopId}/complete`,
    { otp }
  );
}

// ---------- Earnings / withdraw (PRD Section A.2) ----------

export function getWithdrawableBalance() {
  return api.get<{ available: number; held: number }>('/v1/driver/wallet/withdrawable');
}

export function requestWithdrawal(amount: number, mode: 'instant' | 'standard') {
  return api.post<{ id: string; status: string }>(
    '/v1/driver/wallet/withdraw',
    { amount, mode },
    newIdempotencyKey()
  );
}

export interface EarningsTransaction {
  id: string;
  entry_type: 'debit' | 'credit';
  balance_type: 'real' | 'promo';
  amount: string;
  balance_after: string;
  reason: string;
  created_at: string;
}

export function getEarningsHistory() {
  return api.get<EarningsTransaction[]>('/v1/driver/wallet/transactions');
}

export function listPenalties() {
  return api.get<
    { id: string; amount: number; reason_code: string; status: string; dispute_note: string | null }[]
  >('/v1/driver/penalties');
}

export function disputePenalty(penaltyId: string, note: string) {
  return api.post<{ disputed: boolean }>(`/v1/driver/penalties/${penaltyId}/dispute`, { note });
}

export function registerVehicle(category: string, plateNumber: string) {
  return api.post<{ id: string }>('/v1/driver/vehicles', { category, plate_number: plateNumber });
}

export function getDriverProfile() {
  return api.get<{
    id: string;
    name: string | null;
    phone: string;
    email: string | null;
    kyc_status: string;
    training_status: string;
    rating_avg: number | null;
    rating_count: number;
    online_status: boolean;
    vehicle: { plate: string; category: string; make: string | null; model: string | null } | null;
  }>('/v1/driver/profile');
}

export function listJobHistory(page = 1, pageSize = 20) {
  return api.get<{ items: { id: string; status: string; fare_breakdown: { final_fare: number }; created_at: string }[]; page: number }>(
    `/v1/driver/jobs/history?page=${page}&page_size=${pageSize}`
  );
}

export function rateBooking(bookingId: string, stars: number, tags: string[], comment?: string) {
  return api.post<{ isLate: boolean; safetyFlagRaised: boolean }>(`/v1/bookings/${bookingId}/rate`, {
    stars,
    tags,
    comment,
  });
}
