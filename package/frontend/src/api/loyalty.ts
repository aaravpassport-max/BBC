import { api } from './client';

export interface LoyaltySummary {
  balance: number;
  lifetime_earned: number;
  tier: string;
  next_tier_at: number | null;
}

export interface LoyaltyTransaction {
  id: string;
  points: number;
  reason: string;
  linked_booking_id: string | null;
  created_at: string;
}

export function getLoyaltySummary() {
  return api.get<LoyaltySummary>('/v1/loyalty/me');
}

export function getLoyaltyHistory() {
  return api.get<LoyaltyTransaction[]>('/v1/loyalty/history');
}
