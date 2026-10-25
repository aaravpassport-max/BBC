import { apiFetch } from './client';
import type { PlanConfig } from '@/types';

export interface BillingStatus {
  plan: string;
  status: string;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  week_interviews: number;
  week_limit: number | null;
  max_minutes: number;
  minutes_remaining_this_month: number;
}
export interface Invoice { id: number; payment_id: string | null; amount: number; currency: string; status: string; plan: string | null; date: string }

export const billingApi = {
  plans: () => apiFetch<{ plans: Record<string, PlanConfig & { id: string }> }>('/billing/plans'),
  status: () => apiFetch<BillingStatus>('/billing/status'),
  invoices: () => apiFetch<{ invoices: Invoice[] }>('/billing/invoices'),
  subscribe: (plan: 'pro' | 'premium') =>
    apiFetch<{ success: true; subscription_id: string; razorpay_key_id: string; amount: number; currency: string }>('/billing/create-subscription', {
      method: 'POST',
      body: JSON.stringify({ plan }),
    }),
  cancel: () => apiFetch<{ success: true; cancels_at: string }>('/billing/cancel', { method: 'POST' }),
};
