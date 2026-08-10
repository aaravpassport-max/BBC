import { api } from './client';

export interface PayoutBatch {
  id: string;
  period_start: string;
  period_end: string;
  status: string;
  total_amount: string;
  approved_at: string | null;
  created_at: string;
}

export function listPayoutBatches() {
  return api.get<PayoutBatch[]>('/admin/v1/finance/payout-batches');
}

export function generatePayoutBatch(periodStart: string, periodEnd: string) {
  return api.post<{ batchId: string; driverCount: number; totalAmount: number }>(
    '/admin/v1/finance/payout-batches/generate',
    { period_start: periodStart, period_end: periodEnd }
  );
}

export function approvePayoutBatch(batchId: string) {
  return api.post<{ approved: boolean }>(`/admin/v1/finance/payout-batches/${batchId}/approve`);
}

export function getLedgerIntegrity() {
  return api.get<{ mismatches: number }>('/admin/v1/finance/ledger-integrity');
}
