/** Parse homepage JSON content settings with safe fallbacks. */

export type WhyChooseCard = { icon: string; title: string; sub: string }
export type FaqPair = { q: string; a: string }
export type LogoChip = { name: string; brand?: string }

function tryParseJson<T>(raw: string | undefined, fallback: T): T {
  if (!raw || !String(raw).trim()) return fallback
  try {
    const parsed = JSON.parse(String(raw)) as T
    return parsed ?? fallback
  } catch {
    return fallback
  }
}

export function parseWhyChooseCards(
  raw: string | undefined,
  fallback: WhyChooseCard[],
): WhyChooseCard[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const title = String(o.title || o.t || '').trim()
      const sub = String(o.sub || o.desc || o.d || '').trim()
      const icon = String(o.icon || '✓').trim()
      if (!title) return null
      return { icon, title, sub }
    })
    .filter(Boolean) as WhyChooseCard[]
}

export function parseHomeFaqPairs(raw: string | undefined, fallback: FaqPair[]): FaqPair[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const q = String(o.q || o.question || '').trim()
      const a = String(o.a || o.answer || '').trim()
      if (!q) return null
      return { q, a }
    })
    .filter(Boolean) as FaqPair[]
}

export function parseLogoChips(raw: string | undefined, fallback: LogoChip[]): LogoChip[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item) => {
      if (typeof item === 'string') return { name: item.trim() }
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const name = String(o.name || '').trim()
      if (!name) return null
      return { name, brand: o.brand ? String(o.brand) : undefined }
    })
    .filter(Boolean) as LogoChip[]
}

export type AwardBadge = { emoji: string; text: string; variant?: 'orange' | 'primary' }

export type HoursRow = { day: string; hours: string }
export type CompareBrandRow = { feature: string; us: string; them: string }
export type FooterNavLink = { path: string; label: string }

export function parseFooterNavLinks(raw: string | undefined, fallback: FooterNavLink[]): FooterNavLink[] {
  if (!raw || !String(raw).trim()) return fallback
  try {
    const parsed = JSON.parse(String(raw)) as unknown
    if (!Array.isArray(parsed) || parsed.length === 0) return fallback
    return parsed
      .map((item) => {
        if (Array.isArray(item) && item.length >= 2) {
          const path = String(item[0]).trim()
          const label = String(item[1]).trim()
          if (!path || !label) return null
          return { path, label }
        }
        if (!item || typeof item !== 'object') return null
        const o = item as Record<string, unknown>
        const path = String(o.path || o.to || o.url || '').trim()
        const label = String(o.label || o.name || '').trim()
        if (!path || !label) return null
        return { path, label }
      })
      .filter(Boolean) as FooterNavLink[]
  } catch {
    return fallback
  }
}

export function parseCompareBrandRows(raw: string | undefined, fallback: CompareBrandRow[]): CompareBrandRow[] {
  if (!raw || !String(raw).trim()) return fallback
  try {
    const parsed = JSON.parse(String(raw)) as unknown
    if (!Array.isArray(parsed) || parsed.length === 0) return fallback
    return parsed
      .map((item) => {
        if (Array.isArray(item) && item.length >= 3) {
          return { feature: String(item[0]).trim(), us: String(item[1]).trim(), them: String(item[2]).trim() }
        }
        if (!item || typeof item !== 'object') return null
        const o = item as Record<string, unknown>
        const feature = String(o.feature || o.f || '').trim()
        if (!feature) return null
        return {
          feature,
          us: String(o.us || o.brand || o.nriway || '').trim(),
          them: String(o.them || o.other || o.traditional || '').trim(),
        }
      })
      .filter(Boolean) as CompareBrandRow[]
  } catch {
    return fallback
  }
}

export function parseHoursRows(raw: string | undefined, fallback: HoursRow[]): HoursRow[] {
  if (!raw || !String(raw).trim()) return fallback
  try {
    const parsed = JSON.parse(String(raw)) as unknown
    if (!Array.isArray(parsed) || parsed.length === 0) return fallback
    return parsed
      .map((item) => {
        if (Array.isArray(item) && item.length >= 2) {
          return { day: String(item[0]).trim(), hours: String(item[1]).trim() }
        }
        if (!item || typeof item !== 'object') return null
        const o = item as Record<string, unknown>
        const day = String(o.day || o.d || '').trim()
        const hours = String(o.hours || o.h || o.time || '').trim()
        if (!day) return null
        return { day, hours }
      })
      .filter(Boolean) as HoursRow[]
  } catch {
    return fallback
  }
}

export function parseStringListJson(raw: string | undefined, fallback: string[]): string[] {
  if (!raw || !String(raw).trim()) return fallback
  try {
    const parsed = JSON.parse(String(raw)) as unknown
    if (!Array.isArray(parsed) || parsed.length === 0) return fallback
    const list = parsed.map((item) => String(item).trim()).filter(Boolean)
    return list.length ? list : fallback
  } catch {
    return fallback
  }
}

export function parseAwardBadges(raw: string | undefined, fallback: AwardBadge[]): AwardBadge[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const text = String(o.text || o.label || '').trim()
      if (!text) return null
      return {
        emoji: String(o.emoji || '🏆'),
        text,
        variant: o.variant === 'primary' ? 'primary' : 'orange',
      } as AwardBadge
    })
    .filter(Boolean) as AwardBadge[]
}

export type ValueCard = { icon: string; t: string; d: string }
export type TeamMember = { name: string; role: string; img?: string; bio?: string }
export type HiwStepPage = { n: number; icon: string; t: string; d: string }
export type HighlightPair = { value: string; label: string }

export function parseValueCards(raw: string | undefined, fallback: ValueCard[]): ValueCard[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const t = String(o.t || o.title || '').trim()
      if (!t) return null
      return {
        icon: String(o.icon || '✓'),
        t,
        d: String(o.d || o.desc || o.description || '').trim(),
      }
    })
    .filter(Boolean) as ValueCard[]
}

export function parseTeamMembers(raw: string | undefined, fallback: TeamMember[]): TeamMember[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const name = String(o.name || o.n || '').trim()
      if (!name) return null
      return {
        name,
        role: String(o.role || '').trim(),
        img: o.img ? String(o.img) : undefined,
        bio: o.bio ? String(o.bio) : undefined,
      }
    })
    .filter(Boolean) as TeamMember[]
}

export function parseAboutHighlights(raw: string | undefined, fallback: HighlightPair[]): HighlightPair[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item) => {
      if (Array.isArray(item) && item.length >= 2) {
        const value = String(item[0] ?? '').trim()
        const label = String(item[1] ?? '').trim()
        if (!value || !label) return null
        return { value, label }
      }
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const value = String(o.value || o.v || '').trim()
      const label = String(o.label || o.l || '').trim()
      if (!value || !label) return null
      return { value, label }
    })
    .filter(Boolean) as HighlightPair[]
}

export function parseHiwSteps(raw: string | undefined, fallback: HiwStepPage[]): HiwStepPage[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (!Array.isArray(parsed) || parsed.length === 0) return fallback
  return parsed
    .map((item, idx) => {
      if (!item || typeof item !== 'object') return null
      const o = item as Record<string, unknown>
      const t = String(o.t || o.title || '').trim()
      if (!t) return null
      return {
        n: Number(o.n) || idx + 1,
        icon: String(o.icon || '✓'),
        t,
        d: String(o.d || o.desc || o.description || '').trim(),
      }
    })
    .filter(Boolean) as HiwStepPage[]
}

export function parseStringList(raw: string | undefined, fallback: string[]): string[] {
  const parsed = tryParseJson<unknown>(raw, null)
  if (Array.isArray(parsed) && parsed.length > 0) {
    return parsed.map((x) => String(x).trim()).filter(Boolean)
  }
  if (raw && String(raw).includes(',')) {
    return String(raw)
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)
  }
  return fallback
}
