import { api, newIdempotencyKey } from './client';
import {
  isDemoPhone,
  isDemoOtp,
  isDemoOtpRequest,
  isDemoSession,
  localDemoAuth,
  localRequestOtp,
  localVerifyOtp,
} from './demoAuth';
import {
  localRegisterAsDriver,
  localGetKycStatus,
  localSubmitKycStep,
  localGetTrainingStatus,
  localUpdateTrainingProgress,
  localSubmitTrainingQuiz,
  localSetOnlineStatus,
  localUpdateLocation,
  localGetPendingOffer,
  localGetActiveJob,
  localGetDriverDashboard,
  localGetActiveIncentives,
  localGetTripMessages,
  localSendTripMessage,
  localAcceptJob,
  localDeclineJob,
  localArriveAtPickup,
  localArriveAtStop,
  localVerifyPickupOtp,
  localCompleteStop,
  localCollectTripPayment,
  localListDriverDocuments,
  localUploadKycDocument,
  localGetWithdrawableBalance,
  localRequestWithdrawal,
  localGetEarningsHistory,
  localGetEarningsSummary,
  localListPenalties,
  localDisputePenalty,
  localRegisterVehicle,
  localGetDriverProfile,
  localUpdateDriverProfile,
  localListJobHistory,
  localGetJobDetail,
  localRateBooking,
  localCallCustomer,
} from './demoDriver';
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
  booking_type?: 'parcel' | 'ride';
  passenger_count?: number;
  fare_breakdown: { final_fare: number };
  pickup_lat: number;
  pickup_lng: number;
  pickup_address_snapshot?: AddressSnapshot;
  first_drop_address?: AddressSnapshot;
  stop_count?: number;
  vehicle_category_id?: string;
}

export interface AddressSnapshot {
  lat: number;
  lng: number;
  formatted?: string;
  line1?: string;
}

export interface Stop {
  id: string;
  sequence: number;
  status: string;
  instructions: string | null;
  drop_lat: number;
  drop_lng: number;
  address_snapshot?: AddressSnapshot;
  arrived_at?: string | null;
  delivery_preference?: 'otp' | 'photo_proof' | 'none';
  proof_photo_url?: string | null;
}

export interface ActiveJob {
  id: string;
  status: string;
  booking_type?: 'parcel' | 'ride';
  passenger_count?: number;
  pickup_lat: number;
  pickup_lng: number;
  pickup_address?: AddressSnapshot;
  payment_method?: string | null;
  payment_status?: string | null;
  stops: Stop[];
}

export interface DriverDashboard {
  trips_today: number;
  active_trips: number;
  gross_earnings_today: number;
  wallet_credits_today: number;
  rating_avg: number | null;
  rating_count: number;
  online_status: boolean;
}

export interface DriverIncentive {
  id: string;
  title: string;
  description: string;
  bonus_amount: number;
  target: number;
  progress: number;
  remaining: number;
  completed: boolean;
  period: string;
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
  if (isDemoSession()) return localRegisterAsDriver();
  return api.post<{ registered: boolean }>('/v1/driver/kyc/register');
}

export function getKycStatus() {
  if (isDemoSession()) return localGetKycStatus();
  return api.get<KycStatus>('/v1/driver/kyc/status');
}

export function submitKycStep(step: string, documentUrl: string) {
  if (isDemoSession()) return localSubmitKycStep();
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
  if (isDemoSession()) return localGetTrainingStatus();
  return api.get<TrainingStatus>('/v1/driver/training/modules');
}

export function updateTrainingProgress(watchedPct: number) {
  if (isDemoSession()) return localUpdateTrainingProgress(watchedPct);
  return api.post<TrainingStatus>('/v1/driver/training/platform_basics/progress', { watched_pct: watchedPct });
}

export function submitTrainingQuiz(answers: number[]) {
  if (isDemoSession()) return localSubmitTrainingQuiz();
  return api.post<{ passed: boolean; scorePct: number; status: string }>(
    '/v1/driver/training/platform_basics/quiz-submit',
    { answers }
  );
}

export function setOnlineStatus(online: boolean, offlineReason?: string) {
  if (isDemoSession()) return localSetOnlineStatus(online);
  return api.post<{ online: boolean }>('/v1/driver/status', { online, offline_reason: offlineReason });
}

export function updateLocation(lat: number, lng: number) {
  if (isDemoSession()) return localUpdateLocation();
  return api.post<{ acknowledged: boolean }>('/v1/driver/location', { lat, lng });
}

export function getPendingOffer() {
  if (isDemoSession()) return localGetPendingOffer();
  return api.get<PendingOffer | null>('/v1/driver/jobs/pending-offer');
}

export function getActiveJob() {
  if (isDemoSession()) return localGetActiveJob();
  return api.get<ActiveJob | null>('/v1/driver/jobs/active');
}

export function getDriverDashboard() {
  if (isDemoSession()) return localGetDriverDashboard();
  return api.get<DriverDashboard>('/v1/driver/dashboard');
}

export function getActiveIncentives() {
  if (isDemoSession()) return localGetActiveIncentives();
  return api.get<{ items: DriverIncentive[] }>('/v1/driver/incentives/active');
}

export interface TripMessage {
  id: string;
  sender_id: string;
  sender_role: 'customer' | 'driver';
  body: string;
  created_at: string;
}

export function getTripMessages(bookingId: string) {
  if (isDemoSession()) return localGetTripMessages();
  return api.get<TripMessage[]>(`/v1/bookings/${bookingId}/messages`);
}

export function sendTripMessage(bookingId: string, body: string) {
  if (isDemoSession()) return localSendTripMessage();
  return api.post<{ id: string; senderRole: string; createdAt: string }>(`/v1/bookings/${bookingId}/messages`, { body });
}

export function acceptJob(offerId: string) {
  if (isDemoSession()) return localAcceptJob();
  return api.post<{ bookingId: string }>(`/v1/driver/jobs/${offerId}/accept`);
}

export function declineJob(offerId: string) {
  if (isDemoSession()) return localDeclineJob();
  return api.post<{ declined: boolean }>(`/v1/driver/jobs/${offerId}/decline`);
}

export function arriveAtPickup(bookingId: string) {
  if (isDemoSession()) return localArriveAtPickup();
  return api.post<{ notified: boolean }>(`/v1/driver/jobs/${bookingId}/arrive-pickup`);
}

export function arriveAtStop(bookingId: string, stopId: string) {
  if (isDemoSession()) return localArriveAtStop();
  return api.post<{ status: string }>(`/v1/driver/jobs/${bookingId}/stops/${stopId}/arrive`);
}

export function verifyPickupOtp(bookingId: string, otp: string) {
  if (isDemoSession()) return localVerifyPickupOtp();
  return api.post<{ status: string }>(`/v1/driver/jobs/${bookingId}/verify-pickup`, { otp });
}

export function completeStop(bookingId: string, stopId: string, otp?: string, photoProofUrl?: string) {
  if (isDemoSession()) return localCompleteStop();
  return api.post<{ bookingStatus: string; tripCompleted: boolean }>(
    `/v1/driver/jobs/${bookingId}/stops/${stopId}/complete`,
    { otp, photo_proof_url: photoProofUrl }
  );
}

export function collectTripPayment(bookingId: string) {
  if (isDemoSession()) return localCollectTripPayment();
  return api.post<{ collected: boolean }>(`/v1/driver/jobs/${bookingId}/collect-payment`);
}

export interface DriverDocument {
  id: string;
  doc_type: string;
  status: string;
  document_url: string;
  expiry_date: string | null;
  rejection_reason: string | null;
  version: number;
  created_at: string;
  days_until_expiry: number | null;
}

export function listDriverDocuments() {
  if (isDemoSession()) return localListDriverDocuments();
  return api.get<{ items: DriverDocument[] }>('/v1/driver/kyc/documents');
}

export function uploadKycDocument(imageBase64: string) {
  if (isDemoSession()) return localUploadKycDocument();
  return api.post<{ url: string }>('/v1/driver/kyc/uploads/document', { image_base64: imageBase64 });
}

export function getWithdrawableBalance() {
  if (isDemoSession()) return localGetWithdrawableBalance();
  return api.get<{ available: number; held: number }>('/v1/driver/wallet/withdrawable');
}

export function requestWithdrawal(amount: number, mode: 'instant' | 'standard') {
  if (isDemoSession()) return localRequestWithdrawal(amount);
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
  if (isDemoSession()) return localGetEarningsHistory();
  return api.get<EarningsTransaction[]>('/v1/driver/wallet/transactions');
}

export function listPenalties() {
  if (isDemoSession()) return localListPenalties();
  return api.get<
    { id: string; amount: number; reason_code: string; status: string; dispute_note: string | null }[]
  >('/v1/driver/penalties');
}

export function disputePenalty(penaltyId: string, note: string) {
  if (isDemoSession()) return localDisputePenalty();
  return api.post<{ disputed: boolean }>(`/v1/driver/penalties/${penaltyId}/dispute`, { note });
}

export function registerVehicle(category: string, plateNumber: string) {
  if (isDemoSession()) return localRegisterVehicle();
  return api.post<{ id: string }>('/v1/driver/vehicles', { category, plate_number: plateNumber });
}

export function getDriverProfile() {
  if (isDemoSession()) return localGetDriverProfile();
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

export function updateDriverProfile(data: { name?: string; email?: string | null }) {
  if (isDemoSession()) return localUpdateDriverProfile(data);
  return api.put<{
    id: string;
    name: string | null;
    phone: string;
    email: string | null;
    online_status: boolean;
  }>('/v1/driver/profile', data);
}

export function listJobHistory(page = 1, pageSize = 20) {
  if (isDemoSession()) return localListJobHistory();
  return api.get<{
    items: {
      id: string;
      status: string;
      fare_breakdown: { final_fare: number };
      created_at: string;
      vehicle_category_id?: string;
      pickup_address?: AddressSnapshot;
      first_drop_address?: AddressSnapshot;
      stop_count?: number;
    }[];
    page: number;
  }>(`/v1/driver/jobs/history?page=${page}&page_size=${pageSize}`);
}

export function getJobDetail(bookingId: string) {
  if (isDemoSession()) return localGetJobDetail(bookingId);
  return api.get<{
    id: string;
    status: string;
    fare_breakdown: { final_fare: number; platform_fee?: number };
    created_at: string;
    updated_at: string;
    started_at?: string;
    vehicle_category_id?: string;
    pickup_address?: AddressSnapshot;
    pickup_lat: number;
    pickup_lng: number;
    customer_name?: string;
    stops: Stop[];
  }>(`/v1/driver/jobs/${bookingId}`);
}

export interface EarningsSummary {
  trips_week: number;
  trips_month: number;
  gross_earnings_week: number;
  gross_earnings_month: number;
  wallet_credits_week: number;
  wallet_credits_month: number;
  total_withdrawn: number;
}

export function getEarningsSummary() {
  if (isDemoSession()) return localGetEarningsSummary();
  return api.get<EarningsSummary>('/v1/driver/earnings/summary');
}

export function rateBooking(bookingId: string, stars: number, tags: string[], comment?: string) {
  if (isDemoSession()) return localRateBooking();
  return api.post<{ isLate: boolean; safetyFlagRaised: boolean }>(`/v1/bookings/${bookingId}/rate`, {
    stars,
    tags,
    comment,
  });
}

export function callCustomer(bookingId: string) {
  if (isDemoSession()) return localCallCustomer();
  return api.get<{ call_uri: string; display_number: string }>(`/v1/driver/jobs/${bookingId}/call-customer`);
}
