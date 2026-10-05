/**
 * Parse platform CMS navigation JSON into NavItem[] for the public header.
 */
import { NAV_MENU } from '@/lib/nav'
import type { NavCol, NavItem } from '@/types'

function normalizeCol(raw: unknown): NavCol | null {
  if (!raw || typeof raw !== 'object') return null
  const o = raw as Record<string, unknown>
  const heading = String(o.heading || o.title || '').trim()
  const itemsRaw = o.items
  if (!Array.isArray(itemsRaw)) return null
  const items = itemsRaw
    .map((row) => {
      if (!row || typeof row !== 'object') return null
      const item = row as Record<string, unknown>
      const label = String(item.label || item.name || '').trim()
      const slug = String(item.slug || item.path || '').trim().replace(/^\/service\//, '')
      if (!label || !slug) return null
      return { label, slug }
    })
    .filter(Boolean) as NavCol['items']
  if (!heading && items.length === 0) return null
  return { heading: heading || 'Links', items }
}

function normalizeItem(raw: unknown, index: number): NavItem | null {
  if (!raw || typeof raw !== 'object') return null
  const o = raw as Record<string, unknown>
  const label = String(o.label || o.name || '').trim()
  if (!label) return null
  const key = String(o.key || o.id || `nav_${index}`).trim()
  const link = o.link != null ? String(o.link).trim() : o.url != null ? String(o.url).trim() : undefined
  const colsRaw = o.cols || o.columns
  let cols: NavCol[] | undefined
  if (Array.isArray(colsRaw) && colsRaw.length > 0) {
    cols = colsRaw.map(normalizeCol).filter(Boolean) as NavCol[]
    if (!cols.length) cols = undefined
  }
  if (!link && !cols?.length) return null
  return { label, key, link: link || undefined, cols }
}

/** Returns null when setting is empty — caller should fall back to API/static menu. */
export function parseNavMenuJson(raw: string | undefined): NavItem[] | null {
  if (!raw || !String(raw).trim()) return null
  try {
    const parsed = JSON.parse(String(raw)) as unknown
    if (!Array.isArray(parsed) || parsed.length === 0) return null
    const items = parsed
      .map((row, i) => normalizeItem(row, i))
      .filter(Boolean) as NavItem[]
    return items.length ? items : null
  } catch {
    return null
  }
}

export function resolvePublicNavMenu(apiMenu: NavItem[], settingsRaw?: string): NavItem[] {
  const fromSettings = parseNavMenuJson(settingsRaw)
  if (fromSettings?.length) return fromSettings
  if (apiMenu.length) return apiMenu
  return NAV_MENU
}
