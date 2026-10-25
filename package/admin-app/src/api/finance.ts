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

export interface PayoutBatchLine {
  id: string;
  driver_id: string;
  gross_earnings: string;
  net_payout: string;
  status: string;
  hold_reason: string | null;
  hold_note: string | null;
  failure_reason: string | null;
  provider_txn_ref: string | null;
  phone: string;
  name: string | null;
  kyc_status: string | null;
}

export interface PayoutBatchDetail extends PayoutBatch {
  lines: PayoutBatchLine[];
}

export function listPayoutBatches() {
  return api.get<PayoutBatch[]>('/admin/v1/finance/payout-batches');
}

export function getPayoutBatchDetail(batchId: string) {
  return api.get<PayoutBatchDetail>(`/admin/v1/finance/payout-batches/${batchId}`);
}

export function generatePayoutBatch(periodStart: string, periodEnd: string) {
  return api.post<{ batchId: string; driverCount: number; totalAmount: number }>(
    '/admin/v1/finance/payout-batches/generate',
    { period_start: periodStart, period_end: periodEnd }
  );
}

export function approvePayoutBatch(batchId: string) {
  return api.post<{ approved: boolean; submitted: number; failed: number }>(
    `/admin/v1/finance/payout-batches/${batchId}/approve`
  );
}

export function holdPayoutLine(batchId: string, lineId: string, reason: string, note?: string) {
  return api.post<{ held: boolean }>(`/admin/v1/finance/payout-batches/${batchId}/lines/${lineId}/hold`, {
    reason,
    note,
  });
}

export function releasePayoutLine(batchId: string, lineId: string) {
  return api.post<{ released: boolean }>(
    `/admin/v1/finance/payout-batches/${batchId}/lines/${lineId}/release`
  );
}

export function getLedgerIntegrity() {
  return api.get<{ mismatches: number }>('/admin/v1/finance/ledger-integrity');
}
