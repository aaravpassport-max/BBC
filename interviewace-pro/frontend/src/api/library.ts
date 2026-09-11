import { apiFetch } from './client';

export interface SavedAnswer {
  id: number;
  question: string;
  user_answer: string | null;
  ideal_answer: string | null;
  tags: string[];
  created_at: string;
}

export interface QueueItem {
  queue_id: number;
  saved_answer_id: number;
  question: string;
  user_answer: string | null;
  ideal_answer: string | null;
  tags: string[];
  due_date: string;
  review_count: number;
  interval_days: number;
  last_quality: number | null;
}

export interface ReviewQueueResponse {
  due_today: QueueItem[];
  due_count: number;
  upcoming_count: number;
}

/**
 * Wires up the backend's answer-library and SM-2 spaced-repetition review
 * queue (api/class-api-gamification.php get_lib/save_answer/del_answer/
 * get_queue/submit_review/enqueue_answer) — this was fully implemented
 * server-side but had no frontend at all before this pass.
 */
export const libraryApi = {
  list: (search = '') => apiFetch<{ answers: SavedAnswer[] }>(`/library${search ? `?search=${encodeURIComponent(search)}` : ''}`),
  save: (data: { question: string; user_answer?: string; ideal_answer?: string; interview_id?: number; tags?: string }) =>
    apiFetch<{ success: true; id: number }>('/library', { method: 'POST', body: JSON.stringify(data) }),
  remove: (id: number) => apiFetch<{ success: true }>(`/library/${id}`, { method: 'DELETE' }),
  enqueue: (savedAnswerId: number) =>
    apiFetch<{ success: true; already_queued?: boolean; queue_id?: number }>(`/gamification/enqueue/${savedAnswerId}`, { method: 'POST' }),
  queue: () => apiFetch<ReviewQueueResponse>('/gamification/review-queue'),
  submitReview: (queueId: number, quality: number) =>
    apiFetch<{ success: true; next_due: string; interval_days: number; ease_factor: number }>('/gamification/review', {
      method: 'POST',
      body: JSON.stringify({ queue_id: queueId, quality }),
    }),
};
