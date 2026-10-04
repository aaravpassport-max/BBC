/**
 * Applies S2NRI_CONFIG.design tokens to document :root (supplements PHP-injected CSS).
 */
import type { S2NRIConfig } from '@/types'

export function applyDesignConfig(cfg?: S2NRIConfig): void {
  const design = cfg?.design as Record<string, unknown> | undefined
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
}
