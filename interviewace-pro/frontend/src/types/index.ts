export interface IAConfig {
  apiBase: string;
  siteUrl: string;
  elevenLabsVoice: string;
  /** Whether ElevenLabs is configured server-side (no secret exposed). */
  hasElevenLabs?: boolean;
  /** Whether Deepgram is configured server-side (no secret exposed). */
  hasDeepgram?: boolean;
  razorpayKeyId: string;
  googleClientId: string;
  version: string;
  assetsUrl: string;
  priyaAvatarUrl: string;
  plans: Record<string, PlanConfig>;
}

export interface PlanConfig {
  display_name: string;
  badge_label: string;
  price_display: string;
  price_period: string;
  highlight: boolean;
  ribbon_text: string;
  features: string[];
  session_minutes: number;
  monthly_minutes: number;
}

export interface User {
  id: number;
  email: string;
  name: string;
  experience_level: string;
  industry: string | null;
  current_role: string | null;
  target_role: string | null;
  language_pref: 'english' | 'hinglish';
  email_verified: boolean;
  onboarding_complete: boolean;
  has_resume: boolean;
  plan: 'free' | 'pro' | 'premium' | 'b2b' | 'admin';
}

export interface Interview {
  id: number;
  type: string;
  status: 'created' | 'active' | 'completed' | 'abandoned';
  company_pack: string | null;
  language_mode: 'english' | 'hinglish';
  plan: InterviewQuestion[];
  started_at: string | null;
  ended_at: string | null;
  duration_seconds: number;
  turn_count: number;
}

export interface InterviewQuestion {
  question_number: number;
  category: string;
  question: string;
  is_star_expected: boolean;
  difficulty: 'easy' | 'medium' | 'hard';
}

export interface Turn {
  id: number;
  turn_number: number;
  role: 'ai' | 'user';
  transcript: string;
  filler_words: Record<string, number>;
  wpm: number | null;
  duration_ms: number | null;
}

export interface Report {
  id: number;
  interview_id: number;
  status: 'pending' | 'generating' | 'done' | 'failed';
  type: string;
  company_pack: string | null;
  duration_seconds: number;
  turn_count: number;
  overall_score: number | null;
  comm_score: number | null;
  tech_score: number | null;
  conf_score: number | null;
  recommendation: 'strong_hire' | 'hire' | 'borderline' | 'reject' | null;
  recommendation_reason: string | null;
  summary: string | null;
  strengths: string[];
  weaknesses: string[];
  improvements: { area: string; issue: string; suggestion: string }[];
  communication_breakdown: Record<string, number> | null;
  hiring_radar: Record<string, number> | null;
  question_evaluations: {
    question: string;
    answer_summary: string;
    score: number;
    feedback: string;
    ideal_answer_hint: string;
  }[];
  filler_total: Record<string, number>;
  wpm_avg: number | null;
  filler_word_feedback: string | null;
  wpm_feedback: string | null;
  star_compliance: string | null;
  generated_at: string | null;
  error?: string;
}

/**
 * Every API error surfaces this shape (see api/client.ts). `retryable`
 * lets every screen render a consistent "Try again" vs. "Contact support"
 * UI instead of each component guessing from a raw HTTP status — this is
 * the client-side half of the backend's A1/A2 retry-safety fixes.
 */
export interface ApiError {
  code: string;
  message: string;
  status: number;
  retryable: boolean;
  extra?: Record<string, unknown>;
}
