import { apiFetch } from './client';

export interface HistoryItem {
  id: number;
  type: string;
  company_pack: string | null;
  language_mode: string;
  ended_at: string;
  duration_seconds: number;
  turn_count: number;
  overall_score: number | null;
  recommendation: string | null;
  report_status: string | null;
}
export interface HistoryResponse { interviews: HistoryItem[]; total: number; page: number; pages: number }

export const historyApi = {
  list: (page = 1) => apiFetch<HistoryResponse>(`/history?page=${page}`),
};
