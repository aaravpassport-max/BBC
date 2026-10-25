/**
 * Keeps public-facing Zustand settings aligned with settings/public after CMS saves.
 */
import { api } from '@/lib/api'
import { useStore } from '@/lib/store'
import type { Settings } from '@/types'

export const SETTINGS_UPDATED_EVENT = 's2nri-settings-updated'

let inFlight = false

export function mergePublicSettings(flat: Record<string, string>): void {
  if (typeof window === 'undefined' || !flat) return
  if (window.S2NRI_CONFIG) {
    window.S2NRI_CONFIG.settings = { ...(window.S2NRI_CONFIG.settings || {}), ...flat }
  }
  useStore.setState((s) => ({ settings: { ...s.settings, ...flat } as Settings }))
  window.dispatchEvent(new CustomEvent(SETTINGS_UPDATED_EVENT))
}

/** Pull latest public settings (homepage bands, chrome, etc.). */
export async function syncSettingsFromServer(force = false): Promise<void> {
  if (typeof window === 'undefined') return
  if (inFlight && !force) return
  inFlight = true
  try {
    const data = await api.getCached<{ settings?: Record<string, string> }>('settings/public', {
      force: true,
      ttl: 0,
      persist: false,
    })
    if (data.settings) mergePublicSettings(data.settings)
  } catch {
    /* offline / mock */
  } finally {
    inFlight = false
  }
}

export function isPublicMarketingPath(pathname?: string): boolean {
  const path = pathname ?? (typeof window !== 'undefined' ? window.location.pathname : '')
  if (!path || path === '/') return true
  if (path.startsWith('/admin') || path.startsWith('/dashboard') || path.startsWith('/login')) return false
  if (path.startsWith('/register') || path.startsWith('/forgot-password')) return false
  return true
}
