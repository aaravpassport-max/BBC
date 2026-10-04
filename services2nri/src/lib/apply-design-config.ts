/**
 * Applies S2NRI_CONFIG.design tokens to document :root (supplements PHP-injected CSS).
 */
import type { S2NRIConfig } from '@/types'

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

export function applyDesignConfig(cfg?: S2NRIConfig): void {
  const merged = cfg || getRuntimeDesignConfig()
  const design = merged?.design as Record<string, unknown> | undefined
  if (!design || typeof document === 'undefined') return

  const root = document.documentElement
  const colors = (design.colors || {}) as Record<string, string>
  Object.entries(colors).forEach(([key, val]) => {
    if (typeof val === 'string' && val) {
      root.style.setProperty(`--s2-color-${key.replace(/_/g, '-')}`, val)
      if (key === 'primary') root.style.setProperty('--s2-primary', val)
    }
  })

  const spacing = (design.spacing || {}) as Record<string, string>
  Object.entries(spacing).forEach(([key, val]) => {
    if (typeof val === 'string' && val) {
      root.style.setProperty(`--s2-space-${key.replace(/_/g, '-')}`, val)
    }
  })

  const radius = (design.radius || {}) as Record<string, string>
  Object.entries(radius).forEach(([key, val]) => {
    if (typeof val === 'string' && val) {
      root.style.setProperty(`--s2-radius-${key.replace(/_/g, '-')}`, val)
    }
  })

  const shadow = (design.shadow || {}) as Record<string, string>
  Object.entries(shadow).forEach(([key, val]) => {
    if (typeof val === 'string' && val) {
      root.style.setProperty(`--s2-shadow-${key.replace(/_/g, '-')}`, val)
    }
  })

  const widths = design.widths as Record<string, unknown> | undefined
  const global = (widths?.global || {}) as Record<string, unknown>
  const pageMax = global.page_max
  const desktop =
    typeof pageMax === 'object' && pageMax !== null && 'desktop' in (pageMax as object)
      ? String((pageMax as Record<string, string>).desktop)
      : typeof pageMax === 'string'
        ? pageMax
        : ''
  if (desktop) {
    root.style.setProperty('--s2-width-page-max', desktop)
    root.style.setProperty('--s2-space-container_max', desktop)
  }
}
