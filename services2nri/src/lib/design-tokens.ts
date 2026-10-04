import type { CSSProperties } from 'react'

/** Build inline style for custom properties (keeps dynamic CMS values out of class names). */
export function cssVars(vars: Record<string, string | number>): CSSProperties {
  const style: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(vars)) {
    style[`--${key}`] = value
  }
  return style as CSSProperties
}

/** Resolve brand primary from design tokens (CSS vars) with settings fallback. */
export function resolvePrimary(settings?: { primary_color?: string }): string {
  if (typeof document !== 'undefined') {
    const fromVar = getComputedStyle(document.documentElement).getPropertyValue('--s2-primary').trim()
    if (fromVar) return fromVar
  }
  return settings?.primary_color || '#4A6FA5'
}

export function resolveSecondary(settings?: { secondary_color?: string }): string {
  if (typeof document !== 'undefined') {
    const fromVar = getComputedStyle(document.documentElement).getPropertyValue('--s2-color-secondary').trim()
    if (fromVar) return fromVar
  }
  return settings?.secondary_color || '#1E2D40'
}
