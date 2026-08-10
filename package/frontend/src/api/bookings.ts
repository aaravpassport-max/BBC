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

export interface FareBreakdown {
  base_fare: number;
  distance_charge: number;
  time_charge: number;
  waiting_charge: number;
  toll_pass_through: number;
  night_surcharge: number;
  surge_multiplier: number;
  platform_fee: number;
  tax: number;
  coupon_discount: number;
  subscription_benefit: number;
  final_fare: number;
}

export interface Quote {
  quote_id: string;
  vehicle_category: string;
  expires_at: string;
  surge_multiplier: number;
  fare_breakdown: FareBreakdown;
}

export interface Stop {
  id: string;
  sequence: number;
  status: string;
  otp_code: string;
  instructions: string | null;
  drop_lat?: number;
  drop_lng?: number;
}

export interface DriverInfo {
  id: string;
  name: string;
  phone_masked: string | null;
  rating: number | null;
  vehicle: {
    plate: string;
    category: string;
    make: string | null;
    model: string | null;
  } | null;
}

export interface Booking {
  id: string;
  status: string;
  vehicle_category_id: string;
  fare_breakdown: FareBreakdown;
  driver_id: string | null;
  driver?: DriverInfo | null;
  created_at: string;
  pickup_otp?: string;
  pickup_lat?: number;
  pickup_lng?: number;
  stops?: Stop[];
}

export interface DriverLocation {
  lat: number;
  lng: number;
  last_ping_at: string;
}

// ---------- Auth ----------

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

// ---------- Pricing / Booking ----------

export function getQuote(params: {
  pickup: { lat: number; lng: number };
  drops: { lat: number; lng: number }[];
  vehicle_category?: string;
  coupon_code?: string;
  item_details?: {
    goods_category?: string;
    weight_band?: string;
    helper_needed?: boolean;
  };
}) {
  return api.post<{ quotes: Quote[] }>('/v1/pricing/quote', params);
}

export function confirmBooking(quoteId: string, paymentMethod: string, scheduledFor?: string) {
  return api.post<Booking & { payment_required?: boolean; gateway_session?: GatewaySession }>(
    '/v1/bookings',
    { quote_id: quoteId, payment_method: paymentMethod, scheduled_for: scheduledFor },
    newIdempotencyKey()
  );
}

export function verifyBookingPayment(
  bookingId: string,
  params: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }
) {
  return api.post<{ confirmed: boolean; booking_id: string }>(`/v1/bookings/${bookingId}/verify-payment`, params);
}

export function devConfirmBookingPayment(bookingId: string, gatewayRef: string) {
  return api.post<{ confirmed: boolean; booking_id: string }>(`/v1/bookings/${bookingId}/dev/confirm-payment`, {
    gateway_ref: gatewayRef,
  });
}

export async function downloadInvoicePdf(bookingId: string): Promise<Blob> {
  const token = localStorage.getItem('access_token');
  const base = import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000';
  const res = await fetch(`${base}/v1/bookings/${bookingId}/invoice.pdf`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  });
  if (!res.ok) throw new Error('Could not download invoice');
  return res.blob();
}

export function getBooking(id: string) {
  return api.get<Booking>(`/v1/bookings/${id}`);
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

export function getDriverLocation(bookingId: string) {
  return api.get<DriverLocation | null>(`/v1/bookings/${bookingId}/driver-location`);
}

export function listBookings(params?: { status?: string; page?: number; page_size?: number }) {
  const query = new URLSearchParams();
  if (params?.status) query.set('status', params.status);
  if (params?.page) query.set('page', String(params.page));
  if (params?.page_size) query.set('page_size', String(params.page_size));
  const qs = query.toString();
  return api.get<{ items: Booking[]; page: number }>(`/v1/bookings${qs ? `?${qs}` : ''}`);
}

export function previewCancellation(id: string) {
  return api.get<{ fee_charged: boolean; fee_amount: number; status: string }>(`/v1/bookings/${id}/cancel-preview`);
}

export function callDriver(id: string) {
  return api.get<{ call_uri: string; display_number: string }>(`/v1/bookings/${id}/call-driver`);
}

export function cancelBooking(id: string, reasonCode: string, note?: string) {
  return api.post<{ fee_charged: boolean; fee_amount: number }>(`/v1/bookings/${id}/cancel`, {
    reason_code: reasonCode,
    note,
  });
}

export function rateBooking(id: string, stars: number, tags: string[], comment?: string) {
  return api.post<{ isLate: boolean; safetyFlagRaised: boolean }>(`/v1/bookings/${id}/rate`, {
    stars,
    tags,
    comment,
  });
}

// ---------- Wallet ----------

export interface WalletBalance {
  real_money_balance: number;
  promotional_credit_balance: number;
  held_balance: number;
}

export interface WalletTransaction {
  id: string;
  entry_type: 'debit' | 'credit';
  balance_type: 'real' | 'promo';
  amount: string;
  balance_after: string;
  reason: string;
  created_at: string;
}

export interface GatewaySession {
  simulated: boolean;
  gateway_ref?: string; // simulated flow
  order_id?: string; // real Razorpay flow
  amount?: number;
  currency?: string;
  key_id?: string;
}

export function getWallet() {
  return api.get<WalletBalance>('/v1/wallet');
}

export function getWalletTransactions() {
  return api.get<WalletTransaction[]>('/v1/wallet/transactions');
}

export function addMoney(amount: number) {
  return api.post<{ transaction_id: string; gateway_session: GatewaySession }>('/v1/wallet/add-money', {
    amount,
    payment_method_id: 'razorpay',
  });
}

/** Real Razorpay Checkout's client-side confirmation — only used when
 * gateway_session.simulated is false. */
export function verifyPayment(params: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) {
  return api.post<{ confirmed: boolean }>('/v1/wallet/verify-payment', params);
}

/** Dev-only stand-in for the real gateway webhook, used only when
 * gateway_session.simulated is true (no real Razorpay account configured
 * on the backend). Never used in the real-payment path. */
export function devSimulateWebhook(gatewayRef: string) {
  return api.post<{ confirmed: boolean }>('/v1/wallet/dev/simulate-webhook', { gateway_ref: gatewayRef });
}
