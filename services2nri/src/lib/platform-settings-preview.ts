/**
 * Admin Design System iframe preview — merge draft platform settings without reload.
 */
import { useStore } from '@/lib/store'
import type { Settings } from '@/types'

export const PLATFORM_SETTINGS_PREVIEW_MSG = 's2nri-platform-settings-preview' as const

export type PlatformSettingsPreviewMessage = {
  type: typeof PLATFORM_SETTINGS_PREVIEW_MSG
  settings: Record<string, string>
}

export function isDesignPreviewFrame(): boolean {
  if (typeof window === 'undefined') return false
  return new URLSearchParams(window.location.search).get('s2nri_preview') === '1'
}

export function applyPlatformSettingsPreview(patch: Record<string, string>): void {
  if (!patch || typeof patch !== 'object') return
  useStore.setState((state) => ({
    settings: { ...state.settings, ...patch } as Settings,
  }))
  if (window.S2NRI_CONFIG?.settings) {
    window.S2NRI_CONFIG.settings = { ...window.S2NRI_CONFIG.settings, ...patch }
  }
}

/** Listen for draft settings from the admin builder (preview iframe only). */
export function installPlatformSettingsPreviewListener(): () => void {
  if (typeof window === 'undefined' || !isDesignPreviewFrame()) return () => {}

  const onMessage = (event: MessageEvent) => {
    if (event.origin !== window.location.origin) return
    if (event.source !== window.parent) return
    const data = event.data as PlatformSettingsPreviewMessage | undefined
    if (!data || data.type !== PLATFORM_SETTINGS_PREVIEW_MSG) return
    if (!data.settings || typeof data.settings !== 'object') return
    applyPlatformSettingsPreview(data.settings)
  }

  window.addEventListener('message', onMessage)
  return () => window.removeEventListener('message', onMessage)
}

export function buildDesignPreviewUrl(path: string, reloadToken: number): string {
  const origin =
    (typeof window !== 'undefined' && (window.S2NRI_CONFIG?.spaBase || window.location.origin)) || ''
  const route = path.startsWith('/') ? path : `/${path}`
  const joiner = route.includes('?') ? '&' : '?'
  return `${String(origin).replace(/\/$/, '')}${route}${joiner}s2nri_preview=1&_s2pv=${reloadToken}`
}
