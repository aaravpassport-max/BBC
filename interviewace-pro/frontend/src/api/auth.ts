import { apiFetch, setAccessToken } from './client';
import type { User } from '@/types';

interface AuthResponse { success: true; access_token: string; user: User }
interface RegisterResponse { success: true; unverified_token: string; email: string; message: string }

export const authApi = {
  register: (data: { name: string; email: string; password: string; experience_level: string }) =>
    apiFetch<RegisterResponse>('/auth/register', { method: 'POST', body: JSON.stringify(data), skipRefresh: true }),

  verifyOtp: async (data: { otp: string; unverified_token: string }) => {
    const r = await apiFetch<AuthResponse>('/auth/verify-otp', { method: 'POST', body: JSON.stringify(data), skipRefresh: true });
    setAccessToken(r.access_token);
    return r;
  },

  resendOtp: (unverified_token: string) =>
    apiFetch<{ success: true }>('/auth/resend-otp', { method: 'POST', body: JSON.stringify({ unverified_token }), skipRefresh: true }),

  login: async (data: { email: string; password: string }) => {
    const r = await apiFetch<AuthResponse>('/auth/login', { method: 'POST', body: JSON.stringify(data), skipRefresh: true });
    setAccessToken(r.access_token);
    return r;
  },

  logout: async () => {
    await apiFetch('/auth/logout', { method: 'POST' }).catch(() => void 0);
    setAccessToken(null);
  },

  forgotPassword: (email: string) =>
    apiFetch<{ success: true; message: string }>('/auth/forgot-password', { method: 'POST', body: JSON.stringify({ email }), skipRefresh: true }),

  me: () => apiFetch<User>('/auth/me'),

  /** Matches the backend's new one-time-code OAuth exchange (replaces the old token-in-URL redirect). */
  exchangeCode: async (code: string) => {
    const r = await apiFetch<AuthResponse>('/auth/exchange-code', { method: 'POST', body: JSON.stringify({ code }), skipRefresh: true });
    setAccessToken(r.access_token);
    return r;
  },

  googleStart: () => apiFetch<{ url: string }>('/auth/google', { skipRefresh: true }),
};
