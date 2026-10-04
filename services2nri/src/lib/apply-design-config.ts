/**
 * Applies S2NRI_CONFIG.design tokens to document :root (supplements PHP-injected CSS).
 */
import type { S2NRIConfig } from '@/types'
import { applyResolvedDesignToDocument, resolveDesignConfig } from '@/lib/design-resolve'
import { pageContextFromPath } from '@/lib/width-layout'

/** Public + portal runtimes may expose design on S2NRI_CONFIG and/or S2NRI_CFG. */
export function getRuntimeDesignConfig(): S2NRIConfig | undefined {
  if (typeof window === 'undefined') return undefined
  const cfg = window.S2NRI_CONFIG
  const alt = (window as Window & { S2NRI_CFG?: S2NRIConfig }).S2NRI_CFG
  if (cfg?.design) return cfg
  if (alt?.design) {
    return { ...(cfg || alt), design: alt.design }
  }
  return cfg || alt
}

/** Apply global design once (boot). Prefer applyDesignForPath on public SPA navigation. */
export function applyDesignConfig(cfg?: S2NRIConfig): void {
  const merged = cfg || getRuntimeDesignConfig()
  const design = merged?.design as Record<string, unknown> | undefined
  if (!design) return
  const path = typeof window !== 'undefined' ? window.location.pathname : '/'
  applyDesignForPath(path, design)
}

/** Re-resolve tokens when the SPA route changes (page-type overrides + widths). */
export function applyDesignForPath(pathname: string, design?: Record<string, unknown>): void {
  const payload = design || (getRuntimeDesignConfig()?.design as Record<string, unknown> | undefined)
  if (!payload) return
  const ctx = pageContextFromPath(pathname)
  const resolved = resolveDesignConfig(payload, ctx)
  applyResolvedDesignToDocument(resolved, ctx)
}
