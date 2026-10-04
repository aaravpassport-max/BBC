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
