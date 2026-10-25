import { apiFetch, withRetry } from './client';
import type { Interview, InterviewQuestion, Turn } from '@/types';

interface CreateResponse {
  success: true;
  interview_id: number;
  plan: InterviewQuestion[];
  opening_line: string;
  max_minutes: number;
}
interface TurnResponse {
  success: true;
  ai_response: string;
  is_complete: boolean;
  turn_count?: number;
  report_id?: number;
  /** Set by the backend when this response was replayed from an idempotent retry rather than freshly generated. */
  replayed?: boolean;
}

/**
 * `turnNumber` is caller-supplied and MUST stay stable across a retry of
 * the same logical turn (don't increment it just because a request failed).
 * That's what lets the backend's uk_interview_turn_role unique key do its
 * job — see api/class-api-interviews.php::turn() for the server-side half.
 */
export const interviewsApi = {
  create: (data: {
    type: string;
    company_pack?: string;
    language_mode?: 'english' | 'hinglish';
    round_type?: 'technical' | 'hr' | 'behavioral' | 'general';
  }) => apiFetch<CreateResponse>('/interviews', { method: 'POST', body: JSON.stringify(data) }),

  get: (id: number) => apiFetch<{ interview: Interview }>(`/interviews/${id}`),

  start: (id: number) => apiFetch<{ success: true; started_at: string }>(`/interviews/${id}/start`, { method: 'POST' }),

  /** Wrapped in withRetry: a transient network failure here is safe to retry because the server-side turn is idempotent per turnNumber. */
  submitTurn: (
    id: number,
    data: { transcript: string; turn_number: number; filler_json?: string; wpm?: number; duration_ms?: number }
  ) =>
    withRetry(() =>
      apiFetch<TurnResponse>(`/interviews/${id}/turn`, { method: 'POST', body: JSON.stringify(data) })
    ),

  end: (id: number) => apiFetch<{ success: true; report_id: number }>(`/interviews/${id}/end`, { method: 'POST' }),

  getTurns: (id: number) => apiFetch<{ turns: Turn[] }>(`/interviews/${id}/turns`),

  idealAnswer: (id: number, question: string, user_answer: string) =>
    apiFetch<{ success: true; ideal_answer: string }>(`/interviews/${id}/ideal`, {
      method: 'POST',
      body: JSON.stringify({ question, user_answer }),
    }),

  reportCost: (id: number, data: { deepgram_seconds?: number; elevenlabs_chars?: number }) =>
    apiFetch<{ success: true }>(`/interviews/${id}/report-cost`, { method: 'POST', body: JSON.stringify(data) }).catch(() => void 0),
};
