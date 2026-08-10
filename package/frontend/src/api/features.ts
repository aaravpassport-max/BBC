import { api, newIdempotencyKey } from './client';

// ---------- CMS / Promotions ----------

export interface PromoBanner {
  id: string;
  headline: string;
  image_url: string;
  cta_text: string | null;
  cta_deep_link: string;
}

export function getActiveBanners(segment?: string) {
  const query = segment ? `?segment=${encodeURIComponent(segment)}` : '';
  return api.get<PromoBanner[]>(`/v1/cms/banners/active${query}`);
}

// ---------- Support ----------

export interface SupportTicketSummary {
  id: string;
  category: string;
  status: string;
  priority: string;
  created_at: string;
  closed_at: string | null;
}

export interface SupportTicketMessage {
  id: string;
  sender_id: string;
  sender_role: 'customer' | 'agent';
  body: string;
  created_at: string;
}

export interface SupportTicketDetail extends SupportTicketSummary {
  linked_booking_id: string | null;
  messages: SupportTicketMessage[];
}

export function listSupportTickets() {
  return api.get<SupportTicketSummary[]>('/v1/support/tickets');
}

export function getSupportTicket(id: string) {
  return api.get<SupportTicketDetail>(`/v1/support/tickets/${id}`);
}

export function createSupportTicket(params: {
  category: string;
  description: string;
  linked_booking_id?: string;
  priority?: 'low' | 'normal' | 'high' | 'urgent';
}) {
  return api.post<{ id: string; status: string }>('/v1/support/tickets', params, newIdempotencyKey());
}

export function addSupportMessage(ticketId: string, body: string) {
  return api.post<{ ticketId: string; reopened: boolean; newTicketCreated: boolean }>(
    `/v1/support/tickets/${ticketId}/messages`,
    { body }
  );
}

// ---------- Referral ----------

export interface ReferralSummary {
  referral_code: string;
  successful_referrals: number;
  earned_confirmed: number;
  earned_pending_review: number;
}

export function getReferralSummary() {
  return api.get<ReferralSummary>('/v1/referral/summary');
}

export function redeemReferralCode(referralCode: string) {
  return api.post<{ redeemed: boolean }>('/v1/referral/redeem', { referral_code: referralCode });
}

// ---------- Notifications ----------

export interface NotificationPreference {
  category: string;
  channel: string;
  enabled: boolean;
}

export interface InboxNotification {
  id: string;
  category: string;
  channel: string;
  template_id: string;
  status: string;
  created_at: string;
}

export function getNotificationPreferences() {
  return api.get<NotificationPreference[]>('/v1/notifications/preferences');
}

export function setNotificationPreference(category: string, channel: string, enabled: boolean) {
  return api.put<{ updated: boolean }>('/v1/notifications/preferences', { category, channel, enabled });
}

export function getNotificationInbox() {
  return api.get<InboxNotification[]>('/v1/notifications/inbox');
}

// ---------- Subscriptions ----------

export interface Subscription {
  id: string;
  plan_id: string;
  status: string;
  current_period_start: string;
  current_period_end: string;
  grace_period_ends_at: string | null;
  retry_count: number;
}

export function getMySubscription() {
  return api.get<Subscription | null>('/v1/subscriptions/me');
}

export function purchaseSubscription(planId: string) {
  return api.post<{ id: string }>('/v1/subscriptions/purchase', { plan_id: planId });
}

export function cancelSubscription(id: string) {
  return api.post<{ cancelled: boolean }>(`/v1/subscriptions/${id}/cancel`);
}

export function reactivateSubscription(id: string) {
  return api.post<{ reactivated: boolean }>(`/v1/subscriptions/${id}/reactivate`);
}

// ---------- Corporate ----------

export interface CorporateAccount {
  account_id: string;
  role: string;
  name: string;
  status: string;
}

export function getMyCorporateAccounts() {
  return api.get<CorporateAccount[]>('/v1/corporate/my-accounts');
}

export function acceptCorporateInvite(email: string) {
  return api.post<{ accepted: boolean }>('/v1/corporate/invites/accept', { email });
}

// ---------- Subscription plans ----------

export interface SubscriptionPlan {
  id: string;
  name: string;
  monthly_fee: number;
  benefits: string[];
}

export function getSubscriptionPlans() {
  return api.get<SubscriptionPlan[]>('/v1/subscriptions/plans');
}

// ---------- SOS ----------

export function triggerSos(bookingId: string, lat?: number, lng?: number) {
  return api.post<{ id: string; status: string }>('/ops/v1/sos/trigger', {
    booking_id: bookingId,
    lat,
    lng,
  });
}
