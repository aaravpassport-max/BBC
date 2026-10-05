/** Parse hero meta chips JSON: [{"icon":"⚡","label":"Fast responses"}] */

export type HeroMetaChip = { icon?: string; label: string }

export function parseHeroMetaJson(raw: string | undefined, fallback: HeroMetaChip[]): HeroMetaChip[] {
  if (!raw || !String(raw).trim()) return fallback
  try {
    const parsed = JSON.parse(String(raw)) as unknown
    if (!Array.isArray(parsed) || parsed.length === 0) return fallback
    return parsed
      .map((item) => {
        if (!item || typeof item !== 'object') return null
        const o = item as Record<string, unknown>
        const label = String(o.label || '').trim()
        if (!label) return null
        return { icon: o.icon ? String(o.icon) : undefined, label }
      })
      .filter(Boolean) as HeroMetaChip[]
  } catch {
    return fallback
  }
}
