/**
 * Global Zustand store — exact match of $ store defined in booking-ChxRsZYG.js
 *
 * State shape:
 *   user           – logged-in User or null
 *   authLoading    – true while OTP/login requests are in flight
 *   authError      – last auth error message
 *   notifications  – notification array
 *   unreadCount    – unread notification count
 *   notifLoading   – true while fetching notifications
 *   settings       – platform settings from S2NRI_CONFIG.settings
 *
 * Auth actions:
 *   sendOtp(email, purpose)       → POST auth/send-otp
 *   verifyOtp(email, otp, purpose)→ POST auth/verify-otp
 *   login(email, password)        → POST auth/login
 *   register(data)                → POST auth/register
 *   logout()                      → POST auth/logout
 */

import { create } from 'zustand'
import { api } from './api'
import type { User, Settings, Notification } from '@/types'

interface AuthResult {
  success: boolean
  message?: string
  user?: User
  requires_2fa?: boolean
  email?: string
}

interface StoreState {
  // ── Auth ────────────────────────────────────────────────────────────────────
  user: User | null
  authLoading: boolean
  authError: string | null

  setUser: (user: User | null) => void
  clearUser: () => void

  sendOtp: (email: string, purpose?: string) => Promise<AuthResult>
  verifyOtp: (email: string, otp: string, purpose?: string) => Promise<AuthResult>
  login: (email: string, password: string) => Promise<AuthResult>
  verify2fa: (email: string, otp: string) => Promise<AuthResult>
  register: (data: Record<string, string>) => Promise<AuthResult>
  logout: () => Promise<void>

  // ── Notifications ────────────────────────────────────────────────────────────
  notifications: Notification[]
  unreadCount: number
  notifLoading: boolean

  loadNotifications: () => Promise<void>
  markAllNotificationsRead: () => Promise<void>

  // ── Settings ─────────────────────────────────────────────────────────────────
  settings: Settings
}

export const useStore = create<StoreState>((set, get) => ({
  // ── Initial state ────────────────────────────────────────────────────────────
  user: window.S2NRI_CONFIG?.currentUser ?? null,
  authLoading: false,
  authError: null,

  settings: (window.S2NRI_CONFIG?.settings ?? {}) as Settings,

  notifications: [],
  unreadCount: 0,
  notifLoading: false,

  // ── Auth actions ─────────────────────────────────────────────────────────────
  setUser: (user) => set({ user }),
  clearUser: () => set({ user: null }),

  sendOtp: async (email, purpose = 'login') => {
    set({ authLoading: true, authError: null })
    try {
      await api.post('auth/send-otp', { email, purpose })
      set({ authLoading: false })
      return { success: true }
    } catch (err: unknown) {
      const e = err as { message: string }
      set({ authLoading: false, authError: e.message })
      return { success: false, message: e.message }
    }
  },

  verifyOtp: async (email, otp, purpose = 'login') => {
    set({ authLoading: true, authError: null })
    try {
      const res = await api.post<{ user: User; new_nonce?: string }>('auth/verify-otp', {
        email,
        otp,
        purpose,
      })
      if (res.new_nonce && window.S2NRI_CONFIG) {
        window.S2NRI_CONFIG.nonce = res.new_nonce
      }
      set({ authLoading: false, user: res.user })
      return { success: true, user: res.user }
    } catch (err: unknown) {
      const e = err as { message: string }
      set({ authLoading: false, authError: e.message })
      return { success: false, message: e.message }
    }
  },

  login: async (email, password) => {
    set({ authLoading: true, authError: null })
    try {
      const res = await api.post<{ user?: User; requires_2fa?: boolean; email?: string }>('auth/login', { email, password })
      set({ authLoading: false })
      // FIXED: previously this always did set({ user: res.user }) even
      // when the response was the new 2FA-required shape (no user field
      // at all) — harmless in practice since the caller's own
      // "success && user" check happened to prevent navigation, but it
      // would still unnecessarily overwrite state.user with undefined.
      // Now explicitly branches on the real response shape.
      if (res.requires_2fa) {
        return { success: true, requires_2fa: true, email: res.email }
      }
      set({ user: res.user })
      return { success: true, user: res.user }
    } catch (err: unknown) {
      const e = err as { message: string }
      set({ authLoading: false, authError: e.message })
      return { success: false, message: e.message }
    }
  },

  // ADDED: completes login after a staff/admin account with 2FA enabled
  // has entered their second verification code (sent via the existing
  // OtpService, matching the login OTP flow exactly).
  verify2fa: async (email, otp) => {
    set({ authLoading: true, authError: null })
    try {
      const res = await api.post<{ user: User }>('auth/verify-2fa', { email, otp })
      set({ authLoading: false, user: res.user })
      return { success: true, user: res.user }
    } catch (err: unknown) {
      const e = err as { message: string }
      set({ authLoading: false, authError: e.message })
      return { success: false, message: e.message }
    }
  },

  register: async (data) => {
    set({ authLoading: true, authError: null })
    try {
      const res = await api.post<{ user: User }>('auth/register', data)
      set({ authLoading: false, user: res.user })
      return { success: true }
    } catch (err: unknown) {
      const e = err as { message: string; fields?: Record<string, string> }
      set({ authLoading: false, authError: e.message })
      return { success: false, message: e.message }
    }
  },

  logout: async () => {
    try {
      await api.post('auth/logout', {})
    } catch {}
    set({ user: null })
    window.location.href = '/login'
  },

  // ── Notification actions ─────────────────────────────────────────────────────
  loadNotifications: async () => {
    if (get().notifLoading) return
    set({ notifLoading: true })
    try {
      const res = await api.get<{ rows: Notification[]; unread: number }>(
        'notifications?per_page=20'
      )
      set({
        notifications: res.rows || [],
        unreadCount: res.unread || 0,
        notifLoading: false,
      })
    } catch {
      set({ notifLoading: false })
    }
  },

  markAllNotificationsRead: async () => {
    try {
      await api.patch('notifications/read-all', {})
      set({
        unreadCount: 0,
        notifications: get().notifications.map((n) => ({ ...n, is_read: '1' as const })),
      })
    } catch {}
  },
}))

// ── i18n store (separate, matches Z store in compiled bundle) ─────────────────
interface LangState {
  lang: 'en' | 'hi'
  setLang: (lang: 'en' | 'hi') => void
}

export const useLang = create<LangState>((set) => ({
  lang: (() => {
    try { return (localStorage.getItem('s2nri_lang') as 'en' | 'hi') || 'en' } catch { return 'en' }
  })(),
  setLang: (lang) => {
    set({ lang })
    try { localStorage.setItem('s2nri_lang', lang) } catch {}
  },
}))
