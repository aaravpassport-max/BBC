import { apiFetch } from './client';

export interface ProfileData {
  user_id: number;
  name: string;
  experience_level: string;
  industry: string | null;
  current_role: string | null;
  target_role: string | null;
  language_pref: 'english' | 'hinglish';
  email_verified: boolean;
  onboarding_complete: boolean;
  has_resume: boolean;
  resume_parsed: Record<string, unknown> | null;
  jd_parsed: Record<string, unknown> | null;
}

export interface StatsResponse {
  total_interviews: number;
  avg_score: number | null;
  best_score: number | null;
  trend: { date: string; score: number }[];
  streak_days: number;
  plan: string;
  week_count: number;
  week_limit: number | null;
  minutes_used_this_month: number;
  minutes_remaining_this_month: number;
}

export const profileApi = {
  get: () => apiFetch<{ profile: ProfileData | null }>('/profile'),
  update: (data: Partial<Pick<ProfileData, 'name' | 'experience_level' | 'industry' | 'current_role' | 'target_role' | 'language_pref' | 'onboarding_complete'>>) =>
    apiFetch<{ success: true; profile: ProfileData }>('/profile', { method: 'PUT', body: JSON.stringify(data) }),
  parseJd: (jd_text: string) =>
    apiFetch<{ success: true; parsed: Record<string, unknown> | null; note?: string }>('/profile/jd', {
      method: 'POST',
      body: JSON.stringify({ jd_text }),
    }),
  stats: () => apiFetch<StatsResponse>('/profile/stats'),
  uploadResume: (file: File) => {
    const form = new FormData();
    form.append('resume', file);
    return apiFetch<{ success: true; parsed: Record<string, unknown> | null }>('/resume/upload', { method: 'POST', body: form });
  },
};
