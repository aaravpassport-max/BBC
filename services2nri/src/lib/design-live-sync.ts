/**
 * Pull latest design tokens from the API and apply without a full page reload.
 */
import { api } from '@/lib/api'
import { applyDesignForPath } from '@/lib/apply-design-config'

export const DESIGN_UPDATED_EVENT = 's2nri-design-updated'

type PublicDesignResponse = {
  design: Record<string, unknown>
  revision: string
  fonts_css_url?: string
  font_stacks?: Record<string, string>
}

let lastRevision: string | null = null
let inFlight = false
let broadcastChannel: BroadcastChannel | null = null

/** Admin design editor keeps its own draft config — avoid polling public design over it. */
export function isAdminDesignEditorPath(pathname?: string): boolean {
  const path = pathname ?? (typeof window !== 'undefined' ? window.location.pathname : '')
  return path.includes('/admin/design')
}

function getBroadcastChannel(): BroadcastChannel | null {
  if (typeof window === 'undefined' || typeof BroadcastChannel === 'undefined') return null
  if (!broadcastChannel) broadcastChannel = new BroadcastChannel('s2nri-design')
  return broadcastChannel
}

function mergeRuntimeDesign(design: Record<string, unknown>): void {
  if (typeof window === 'undefined') return
  const cfg = window.S2NRI_CONFIG
  if (!cfg) return
  cfg.design = design
  const colors = (design.colors || {}) as Record<string, string>
  if (colors.primary) {
    cfg.settings = { ...(cfg.settings || {}), primary_color: colors.primary }
  }
  if (colors.accent) {
    cfg.settings = { ...(cfg.settings || {}), accent_color: colors.accent }
  }
}

function applyFontsStylesheet(url: string): void {
  if (!url || typeof document === 'undefined') return
  let link = document.getElementById('s2nri-design-fonts-live') as HTMLLinkElement | null
  if (!link) {
    link = document.createElement('link')
    link.id = 's2nri-design-fonts-live'
    link.rel = 'stylesheet'
    document.head.appendChild(link)
  }
  if (link.href !== url) link.href = url
}

function applyFontStackVars(stacks: Record<string, string>): void {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  const map: Record<string, string> = {
    heading: '--s2-font-heading',
    body: '--s2-font-body',
    ui: '--s2-font-ui',
    button: '--s2-font-button',
    fallback: '--s2-font-fallback',
  }
  for (const [key, cssVar] of Object.entries(map)) {
    const val = stacks[key]
    if (typeof val === 'string' && val) root.style.setProperty(cssVar, val)
  }
}

function notifyDesignUpdated(): void {
  if (typeof window === 'undefined') return
  window.dispatchEvent(new CustomEvent(DESIGN_UPDATED_EVENT))
}

/** Tell other tabs (and this tab) to pull the latest public design payload. */
export function broadcastDesignSaved(): void {
  getBroadcastChannel()?.postMessage({ type: 'design-saved', at: Date.now() })
  void syncDesignFromServer({ force: true })
}

/**
 * Fetch design/public and apply tokens when revision changes (or when force=true).
 */
export async function syncDesignFromServer(options?: { force?: boolean }): Promise<boolean> {
  if (typeof window === 'undefined' || inFlight) return false
  if (isAdminDesignEditorPath()) return false
  inFlight = true
  try {
    const data = await api.get<PublicDesignResponse>('design/public')
    if (!data?.design || !data.revision) return false
    if (!options?.force && lastRevision !== null && data.revision === lastRevision) {
      return false
    }
    lastRevision = data.revision
    mergeRuntimeDesign(data.design)
    if (data.fonts_css_url) applyFontsStylesheet(data.fonts_css_url)
    if (data.font_stacks) applyFontStackVars(data.font_stacks)
    applyDesignForPath(window.location.pathname, data.design)
    notifyDesignUpdated()
    return true
  } catch {
    return false
  } finally {
    inFlight = false
  }
}

/** Visibility + cross-tab listeners; returns cleanup. */
export function installDesignLiveSync(): () => void {
  if (typeof window === 'undefined') return () => {}

  if (!isAdminDesignEditorPath()) {
    void syncDesignFromServer({ force: true })
  }

  const onVisible = () => {
    if (document.visibilityState === 'visible' && !isAdminDesignEditorPath()) void syncDesignFromServer()
  }
  document.addEventListener('visibilitychange', onVisible)

  const bc = getBroadcastChannel()
  const onMessage = () => {
    if (!isAdminDesignEditorPath()) void syncDesignFromServer({ force: true })
  }
  bc?.addEventListener('message', onMessage)

  const interval = window.setInterval(() => {
    if (document.visibilityState === 'visible' && !isAdminDesignEditorPath()) void syncDesignFromServer()
  }, 45_000)

  return () => {
    document.removeEventListener('visibilitychange', onVisible)
    bc?.removeEventListener('message', onMessage)
    window.clearInterval(interval)
  }
}
