import { apiFetch } from './client';

export interface AtsSection { section: string; score: number; issue: string | null }
export interface AtsSuggestion { type: string; title: string; detail: string; priority: 'high' | 'medium' | 'low' }

export interface AtsScore {
  has_jd: boolean;
  overall_score: number;
  keyword_score: number | null;
  section_score: number;
  matched_keywords: string[];
  missing_required: string[];
  missing_preferred: string[];
  match_count: number;
  total_jd_keywords: number;
  sections: AtsSection[];
  suggestions: AtsSuggestion[];
  resume_skills: string[];
  jd_role: string | null;
}

export const atsApi = {
  score: () => apiFetch<AtsScore>('/ats/score'),
};
