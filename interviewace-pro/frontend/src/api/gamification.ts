import { apiFetch } from './client';

export interface Badge { badge_slug: string; badge_name: string; badge_desc: string; badge_icon: string; awarded_at: string }
export interface BadgeDef { slug: string; name: string; desc: string; icon: string; xp: number }
export interface XpEvent { event_type: string; xp_delta: number; description: string; created_at: string }

export interface GamificationProfile {
  total_xp: number;
  level_name: string;
  level_icon: string;
  level_progress_pct: number;
  xp_to_next: number;
  badges: Badge[];
  badge_count: number;
  recent_xp_events: XpEvent[];
  all_badges: BadgeDef[];
}

export const gamificationApi = {
  profile: () => apiFetch<GamificationProfile>('/gamification/profile'),
};
