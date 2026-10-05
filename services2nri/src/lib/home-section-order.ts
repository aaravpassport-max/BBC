import type { CSSProperties } from 'react'

/** Default public homepage band order (matches design catalog). */
export const DEFAULT_HOME_SECTION_ORDER: string[] = [
  'hero',
  'notice',
  'search',
  'features',
  'services',
  'cities',
  'stats',
  'tagline',
  'testimonials',
  'how',
  'press',
  'partners',
  'about',
  'awards',
  'faq',
  'newsletter',
  'app',
  'locations',
]

export function parseHomeSectionOrder(raw: string | undefined): string[] {
  if (!raw?.trim()) return [...DEFAULT_HOME_SECTION_ORDER]
  try {
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return [...DEFAULT_HOME_SECTION_ORDER]
    const ids = parsed.filter((x): x is string => typeof x === 'string' && x.length > 0)
    if (ids.length === 0) return [...DEFAULT_HOME_SECTION_ORDER]
    const known = new Set(DEFAULT_HOME_SECTION_ORDER)
    const ordered = ids.filter((id) => known.has(id))
    for (const id of DEFAULT_HOME_SECTION_ORDER) {
      if (!ordered.includes(id)) ordered.push(id)
    }
    return ordered
  } catch {
    return [...DEFAULT_HOME_SECTION_ORDER]
  }
}

export function homeSectionOrderStyle(sectionId: string, order: string[]): CSSProperties {
  const idx = order.indexOf(sectionId)
  return { order: idx === -1 ? 999 : idx }
}
