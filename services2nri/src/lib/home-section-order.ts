import type { CSSProperties } from 'react'

/** Legacy DOM keys → design catalog section ids (admin reorder saves catalog ids). */
const HOME_SECTION_ORDER_ALIASES: Record<string, string> = {
  services: 'home_services',
  how: 'process',
}

/** Normalize a homepage band id for order lookup (legacy + catalog). */
export function normalizeHomeSectionOrderId(id: string): string {
  return HOME_SECTION_ORDER_ALIASES[id] ?? id
}

/** Default public homepage band order (matches design catalog section ids). */
export const DEFAULT_HOME_SECTION_ORDER: string[] = [
  'hero',
  'notice',
  'search',
  'features',
  'home_services',
  'cities',
  'stats',
  'tagline',
  'testimonials',
  'process',
  'press',
  'partners',
  'about',
  'awards',
  'faq',
  'newsletter',
  'app',
  'locations',
]

function mergeKnownOrder(ids: string[]): string[] {
  const known = new Set(DEFAULT_HOME_SECTION_ORDER)
  const seen = new Set<string>()
  const ordered: string[] = []
  for (const raw of ids) {
    const id = normalizeHomeSectionOrderId(raw)
    if (!known.has(id) || seen.has(id)) continue
    seen.add(id)
    ordered.push(id)
  }
  for (const id of DEFAULT_HOME_SECTION_ORDER) {
    if (!seen.has(id)) ordered.push(id)
  }
  return ordered
}

export function parseHomeSectionOrder(raw: string | undefined): string[] {
  if (!raw?.trim()) return [...DEFAULT_HOME_SECTION_ORDER]
  try {
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return [...DEFAULT_HOME_SECTION_ORDER]
    const ids = parsed.filter((x): x is string => typeof x === 'string' && x.length > 0)
    if (ids.length === 0) return [...DEFAULT_HOME_SECTION_ORDER]
    return mergeKnownOrder(ids)
  } catch {
    return [...DEFAULT_HOME_SECTION_ORDER]
  }
}

export function homeSectionOrderStyle(sectionId: string, order: string[]): CSSProperties {
  const id = normalizeHomeSectionOrderId(sectionId)
  const normalizedOrder = order.map(normalizeHomeSectionOrderId)
  const idx = normalizedOrder.indexOf(id)
  return { order: idx === -1 ? 999 : idx }
}
