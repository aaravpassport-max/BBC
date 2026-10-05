import type { CSSProperties } from 'react'
import { DEFAULT_HOME_SECTION_ORDER, parseHomeSectionOrder } from '@/lib/home-section-order'

export const PAGE_SECTION_ORDERS_KEY = 'public_page_section_orders_json'

export function parsePageSectionOrders(raw: string | undefined): Record<string, string[]> {
  if (!raw?.trim()) return {}
  try {
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {}
    const out: Record<string, string[]> = {}
    for (const [pageId, val] of Object.entries(parsed as Record<string, unknown>)) {
      if (!Array.isArray(val)) continue
      const ids = val.filter((x): x is string => typeof x === 'string' && x.length > 0)
      if (ids.length) out[pageId] = ids
    }
    return out
  } catch {
    return {}
  }
}

export function mergeLegacyHomeOrder(
  orders: Record<string, string[]>,
  legacyHomeJson: string | undefined,
): Record<string, string[]> {
  if (orders.home?.length) return orders
  const legacy = parseHomeSectionOrder(legacyHomeJson)
  if (legacy.length && JSON.stringify(legacy) !== JSON.stringify(DEFAULT_HOME_SECTION_ORDER)) {
    return { ...orders, home: legacy }
  }
  return orders
}

export function orderIdsForPage(
  orders: Record<string, string[]>,
  pageId: string,
  catalogIds: string[],
  legacyHomeJson?: string,
): string[] {
  const merged = pageId === 'home' ? mergeLegacyHomeOrder(orders, legacyHomeJson) : orders
  const stored = merged[pageId]
  const known = new Set(catalogIds)
  const base = stored?.filter((id) => known.has(id)) ?? []
  catalogIds.forEach((id) => {
    if (!base.includes(id)) base.push(id)
  })
  return base
}

export function sectionOrderStyle(sectionId: string, order: string[]): CSSProperties {
  const idx = order.indexOf(sectionId)
  return { order: idx === -1 ? 999 : idx }
}
