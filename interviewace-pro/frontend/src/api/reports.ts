import { apiFetch } from './client';
import type { Report } from '@/types';

type ReportResponse =
  | { report: Report }
  | { status: 'pending' | 'generating'; report_id: number; message: string }
  | { status: 'failed'; report_id: number; error: string };

export const reportsApi = {
  get: (id: number) => apiFetch<ReportResponse>(`/reports/${id}`),
  byInterview: (interviewId: number) => apiFetch<ReportResponse>(`/reports/by-interview/${interviewId}`),
  /** Calls the new retry endpoint — closes the A3 "permanent dead end" gap from the audit. */
  retry: (id: number) => apiFetch<{ success: true; status: string }>(`/reports/${id}/retry`, { method: 'POST' }),
  /** interviewId, not reportId — matches class-pdf-report.php's route. Returns a signed, 1-hour-lived download URL (see IA_PDF_Report::generate_token). */
  pdfDownloadUrl: (interviewId: number) =>
    apiFetch<{ url: string; expires_in: number }>(`/reports/${interviewId}/pdf-token`, { method: 'POST' }),
};
