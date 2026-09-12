import type { ActiveJob, EarningsTransaction, KycStatus, PendingOffer, TrainingStatus } from './driver';
import { DEMO_PHONE, DEMO_USER_ID } from '../config/testCredentials';

const DEMO_KYC: KycStatus = {
  overall_status: 'approved',
  steps: [
    { step: 'personal_details', status: 'approved', rejection_reason: null },
    { step: 'identity_document', status: 'approved', rejection_reason: null },
    { step: 'driving_license', status: 'approved', rejection_reason: null },
    { step: 'vehicle_documents', status: 'approved', rejection_reason: null },
    { step: 'bank_details', status: 'approved', rejection_reason: null },
    { step: 'vehicle_photos', status: 'approved', rejection_reason: null },
    { step: 'consent', status: 'approved', rejection_reason: null },
  ],
};

const DEMO_TRAINING: TrainingStatus = {
  module: 'platform_basics',
  status: 'passed',
  video_watched_pct: 100,
  quiz_attempts: 1,
  max_attempts: 3,
  can_retake_at: null,
};

const DEMO_EARNINGS: EarningsTransaction[] = [
  {
    id: 'demo-txn-1',
    entry_type: 'credit',
    balance_type: 'real',
    amount: '450.00',
    balance_after: '2500.00',
    reason: 'trip_earning',
    created_at: new Date().toISOString(),
  },
];

export function localRegisterAsDriver() {
  return Promise.resolve({ registered: true });
}

export function localGetKycStatus() {
  return Promise.resolve(DEMO_KYC);
}

export function localSubmitKycStep() {
  return Promise.resolve({ submitted: true });
}

export function localGetTrainingStatus() {
  return Promise.resolve(DEMO_TRAINING);
}

export function localUpdateTrainingProgress(watchedPct: number) {
  return Promise.resolve({ ...DEMO_TRAINING, video_watched_pct: watchedPct });
}

export function localSubmitTrainingQuiz() {
  return Promise.resolve({ passed: true, scorePct: 100, status: 'passed' });
}

export function localSetOnlineStatus(online: boolean) {
  return Promise.resolve({ online });
}

export function localUpdateLocation() {
  return Promise.resolve({ acknowledged: true });
}

export function localGetPendingOffer(): Promise<PendingOffer | null> {
  return Promise.resolve(null);
}

export function localGetActiveJob(): Promise<ActiveJob | null> {
  return Promise.resolve(null);
}

export function localGetTripMessages() {
  return Promise.resolve([]);
}

export function localSendTripMessage() {
  return Promise.resolve({ id: 'demo-msg', senderRole: 'driver', createdAt: new Date().toISOString() });
}

export function localAcceptJob() {
  return Promise.resolve({ bookingId: '00000000-0000-4000-8000-000000000099' });
}

export function localDeclineJob() {
  return Promise.resolve({ declined: true });
}

export function localVerifyPickupOtp() {
  return Promise.resolve({ status: 'in_progress' });
}

export function localCompleteStop() {
  return Promise.resolve({ bookingStatus: 'completed', tripCompleted: true });
}

export function localGetWithdrawableBalance() {
  return Promise.resolve({ available: 2500, held: 0 });
}

export function localRequestWithdrawal(_amount: number) {
  return Promise.resolve({ id: 'demo-withdrawal', status: 'pending' });
}

export function localGetEarningsHistory() {
  return Promise.resolve(DEMO_EARNINGS);
}

export function localListPenalties() {
  return Promise.resolve([]);
}

export function localDisputePenalty() {
  return Promise.resolve({ disputed: true });
}

export function localRegisterVehicle() {
  return Promise.resolve({ id: 'demo-vehicle-id' });
}

export function localGetDriverProfile() {
  return Promise.resolve({
    id: DEMO_USER_ID,
    name: 'Demo Driver',
    phone: DEMO_PHONE,
    email: null,
    kyc_status: 'approved',
    training_status: 'passed',
    rating_avg: 4.8,
    rating_count: 42,
    online_status: false,
    vehicle: { plate: 'KA01DE1234', category: 'mini_truck', make: 'Tata', model: 'Ace' },
  });
}

export function localGetDriverDashboard() {
  return Promise.resolve({
    trips_today: 3,
    active_trips: 0,
    gross_earnings_today: 450,
    wallet_credits_today: 450,
    rating_avg: 4.8,
    rating_count: 42,
    online_status: false,
  });
}

export function localGetActiveIncentives() {
  return Promise.resolve({
    items: [
      {
        id: 'demo-incentive-1',
        title: 'Complete 5 trips today',
        description: 'Earn a ₹100 bonus when you finish 5 deliveries.',
        bonus_amount: 100,
        target: 5,
        progress: 3,
        remaining: 2,
        completed: false,
        period: 'daily',
      },
    ],
  });
}

export function localGetEarningsSummary() {
  return Promise.resolve({
    trips_week: 12,
    trips_month: 48,
    gross_earnings_week: 3200,
    gross_earnings_month: 12800,
    wallet_credits_week: 3200,
    wallet_credits_month: 12800,
    total_withdrawn: 5000,
  });
}

export function localGetJobDetail(bookingId: string) {
  return Promise.resolve({
    id: bookingId,
    status: 'completed',
    fare_breakdown: { final_fare: 150, platform_fee: 10 },
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    started_at: new Date().toISOString(),
    vehicle_category_id: 'cc0530bc-0866-406f-86cd-2244d997ea9f',
    pickup_lat: 12.951,
    pickup_lng: 77.601,
    pickup_address: { lat: 12.951, lng: 77.601, formatted: 'Demo pickup' },
    customer_name: 'Demo Customer',
    stops: [],
  });
}

export function localArriveAtPickup() {
  return Promise.resolve({ notified: true });
}

export function localArriveAtStop() {
  return Promise.resolve({ status: 'arrived' });
}

export function localCollectTripPayment() {
  return Promise.resolve({ collected: true });
}

export function localListDriverDocuments() {
  return Promise.resolve({ items: [] });
}

export function localUploadKycDocument() {
  return Promise.resolve({ url: 'demo://document' });
}

export function localUpdateDriverProfile(data: { name?: string; email?: string | null }) {
  return Promise.resolve({
    id: DEMO_USER_ID,
    name: data.name ?? 'Demo Driver',
    phone: DEMO_PHONE,
    email: data.email ?? null,
    online_status: false,
  });
}

export function localCallCustomer() {
  return Promise.resolve({ call_uri: 'tel:+919000000001', display_number: '9000000001' });
}

export function localRegisterDeviceToken() {
  return Promise.resolve({ registered: true });
}

export function localGetActiveBanners() {
  return Promise.resolve([]);
}

export function localListJobHistory() {
  return Promise.resolve({ items: [], page: 1 });
}

export function localRateBooking() {
  return Promise.resolve({ isLate: false, safetyFlagRaised: false });
}

export function localTriggerSos() {
  return Promise.resolve({ id: 'demo-sos', status: 'open' });
}

export function localReferralSummary() {
  return Promise.resolve({
    referral_code: 'DRIVER01',
    successful_referrals: 2,
    earned_confirmed: 200,
    earned_pending_review: 0,
  });
}

export function localRedeemReferral() {
  return Promise.resolve({ redeemed: true });
}

export function localNotificationInbox() {
  return Promise.resolve([]);
}

export function localNotificationPreferences() {
  return Promise.resolve([
    { category: 'trip_updates', channel: 'push', enabled: true },
    { category: 'promotions', channel: 'push', enabled: true },
  ]);
}

export function localSetNotificationPreference() {
  return Promise.resolve({ updated: true });
}

export function localListSupportTickets() {
  return Promise.resolve([]);
}

export function localGetSupportTicket(id: string) {
  return Promise.resolve({
    id,
    category: 'Trip issue',
    status: 'open',
    priority: 'normal',
    created_at: new Date().toISOString(),
    closed_at: null,
    linked_booking_id: null,
    messages: [],
  });
}

export function localCreateSupportTicket() {
  return Promise.resolve({ id: 'demo-ticket', status: 'open' });
}

export function localAddSupportMessage() {
  return Promise.resolve({ ticketId: 'demo-ticket', reopened: false, newTicketCreated: false });
}
