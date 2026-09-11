import { api } from './client';

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

// ---------- Corporate self-service (PRD 14A.1 / 14B.1) ----------

export interface MyAccount {
  account_id: string;
  role: string;
  name: string;
  status: string;
}

export interface AccountSummary {
  id: string;
  name: string;
  credit_limit: string;
  committed_spend: string;
  reserved_spend: string;
  available_credit: string;
  status: string;
}

export interface Employee {
  id: string;
  email: string;
  role: string;
  status: string;
  per_user_monthly_cap: string | null;
  invited_at: string;
  removed_at: string | null;
}

export function getMyAccounts() {
  return api.get<MyAccount[]>('/v1/corporate/my-accounts');
}

export function acceptInvite(email: string) {
  return api.post<{ accountId: string; role: string }>('/v1/corporate/invites/accept', { email });
}

export function getAccountSummary(accountId: string) {
  return api.get<AccountSummary>(`/v1/corporate/${accountId}`);
}

export interface AccountBooking {
  id: string;
  status: string;
  fare_breakdown: { final_fare: number };
  created_at: string;
  employee_phone: string;
}

export function getAccountBookings(accountId: string) {
  return api.get<AccountBooking[]>(`/v1/corporate/${accountId}/bookings`);
}

export function listEmployees(accountId: string) {
  return api.get<Employee[]>(`/v1/corporate/${accountId}/employees`);
}

export function inviteEmployee(accountId: string, email: string, role: 'employee' | 'account_admin') {
  return api.post<{ id: string }>(`/v1/corporate/${accountId}/employees`, { email, role });
}

export function removeEmployee(accountId: string, employeeId: string) {
  return api.del<{ removed: boolean }>(`/v1/corporate/${accountId}/employees/${employeeId}`);
}

export function updateEmployeeCap(accountId: string, employeeId: string, newCap: number | null) {
  return api.patch<{ updated: boolean }>(`/v1/corporate/${accountId}/employees/${employeeId}/cap`, {
    per_user_monthly_cap: newCap,
  });
}

// ---------- Enterprise invoicing (P2 gap-analysis item) ----------

export interface Invoice {
  id: string;
  invoice_number: string;
  period_start: string;
  period_end: string;
  total_amount: string;
  booking_count: number;
  status: 'issued' | 'paid';
  generated_at: string;
}

export interface InvoiceLineItem {
  id: string;
  fare_breakdown: { final_fare: number };
  created_at: string;
  employee_phone: string;
}

export interface InvoiceDetail extends Invoice {
  lineItems: InvoiceLineItem[];
}

export function generateInvoice(accountId: string, periodStart: string, periodEnd: string) {
  return api.post<{ id: string; invoiceNumber: string; totalAmount: number; bookingCount: number }>(
    `/v1/corporate/${accountId}/invoices/generate`,
    { period_start: periodStart, period_end: periodEnd }
  );
}

export function listInvoices(accountId: string) {
  return api.get<Invoice[]>(`/v1/corporate/${accountId}/invoices`);
}

export function getInvoiceDetail(accountId: string, invoiceId: string) {
  return api.get<InvoiceDetail>(`/v1/corporate/${accountId}/invoices/${invoiceId}`);
}

export function markInvoicePaid(accountId: string, invoiceId: string) {
  return api.post<{ paid: boolean }>(`/v1/corporate/${accountId}/invoices/${invoiceId}/mark-paid`);
}

export async function downloadCorporateInvoicePdf(accountId: string, invoiceId: string): Promise<Blob> {
  const token = localStorage.getItem('access_token');
  const base = import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000';
  const res = await fetch(`${base}/v1/corporate/${accountId}/invoices/${invoiceId}/invoice.pdf`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  });
  if (!res.ok) throw new Error('Could not download invoice PDF');
  return res.blob();
}

export function emailCorporateInvoice(accountId: string, invoiceId: string, toEmail?: string) {
  return api.post<{ sent: boolean; recipients: string[] }>(
    `/v1/corporate/${accountId}/invoices/${invoiceId}/email`,
    toEmail ? { to_email: toEmail } : {}
  );
}

export interface SpendAnalyticsRow {
  month: string;
  total_spend: number;
  trip_count: number;
}

export function getSpendAnalytics(accountId: string, months = 6) {
  return api.get<SpendAnalyticsRow[]>(`/v1/corporate/${accountId}/spend-analytics?months=${months}`);
}

export interface SpendByEmployeeRow {
  employee_phone: string;
  employee_name: string;
  trip_count: number;
  total_spend: number;
}

export interface SpendByEmployeeResult {
  period_start: string;
  period_end: string;
  employees: SpendByEmployeeRow[];
}

export function getSpendByEmployee(accountId: string, periodStart?: string, periodEnd?: string) {
  const query = new URLSearchParams();
  if (periodStart) query.set('period_start', periodStart);
  if (periodEnd) query.set('period_end', periodEnd);
  const qs = query.toString();
  return api.get<SpendByEmployeeResult>(`/v1/corporate/${accountId}/spend-by-employee${qs ? `?${qs}` : ''}`);
}

export interface SuggestedInvoicePeriod {
  period_start: string;
  period_end: string;
  trip_count: number;
  estimated_total: number;
  invoice_exists: boolean;
  needs_invoice: boolean;
}

export interface InvoiceAutomationStatus {
  last_sweep: {
    status: string;
    finished_at: string;
    invoices_generated: number;
    error_detail: string | null;
  } | null;
  suggested_period: SuggestedInvoicePeriod;
}

export function getSuggestedInvoicePeriod(accountId: string) {
  return api.get<SuggestedInvoicePeriod>(`/v1/corporate/${accountId}/invoices/suggested-period`);
}

export function getInvoiceAutomationStatus(accountId: string) {
  return api.get<InvoiceAutomationStatus>(`/v1/corporate/${accountId}/invoices/automation-status`);
}
