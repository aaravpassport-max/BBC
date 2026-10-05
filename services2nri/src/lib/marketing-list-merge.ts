/**
 * Merge flat per-item platform settings over JSON/list fallbacks on marketing pages.
 */
import {
  parseAboutHighlights,
  parseCompareBrandRows,
  parseHiwSteps,
  parseHoursRows,
  parseTeamMembers,
  parseValueCards,
  type CompareBrandRow,
  type HighlightPair,
  type HiwStepPage,
  type HoursRow,
  type TeamMember,
  type ValueCard,
} from '@/lib/home-content-settings'
import { MARKETING_LIST_LIMITS } from '@/lib/cms-marketing-list-fields'

type Settings = Record<string, string>

function pick(s: Settings, key: string, fallback: string): string {
  const v = s[key]
  return v !== undefined && String(v).trim() !== '' ? String(v) : fallback
}

export function mergeValueCards(s: Settings, fallback: ValueCard[]): ValueCard[] {
  const fromJson = parseValueCards(s.about_values_json, fallback)
  const max = Math.max(fromJson.length, MARKETING_LIST_LIMITS.values)
  const out: ValueCard[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const t = pick(s, `value_${n}_title`, fb?.t || '')
    const d = pick(s, `value_${n}_desc`, fb?.d || '')
    const icon = pick(s, `value_${n}_icon`, fb?.icon || '✓')
    if (!t && !d && !fb) continue
    if (t || d || fb) out.push({ icon, t: t || fb?.t || '', d: d || fb?.d || '' })
  }
  return out.length ? out : fromJson
}

export function mergeTeamMembers(s: Settings, fallback: TeamMember[]): TeamMember[] {
  const fromJson = parseTeamMembers(s.about_team_json, fallback)
  const max = Math.max(fromJson.length, MARKETING_LIST_LIMITS.team)
  const out: TeamMember[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const name = pick(s, `team_${n}_name`, fb?.name || '')
    if (!name && !fb) continue
    out.push({
      name: name || fb?.name || '',
      role: pick(s, `team_${n}_role`, fb?.role || ''),
      bio: pick(s, `team_${n}_bio`, fb?.bio || ''),
      img: pick(s, `team_${n}_img`, fb?.img || ''),
    })
  }
  return out.length ? out : fromJson
}

export function mergeAboutHighlights(s: Settings, fallback: HighlightPair[]): HighlightPair[] {
  const fromJson = parseAboutHighlights(s.about_highlights_json, fallback)
  const max = Math.max(fromJson.length, MARKETING_LIST_LIMITS.highlights)
  const out: HighlightPair[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const value = pick(s, `about_highlight_${n}_value`, fb?.value || '')
    const label = pick(s, `about_highlight_${n}_label`, fb?.label || '')
    if (!value && !label && !fb) continue
    if (value || label || fb) out.push({ value: value || fb?.value || '', label: label || fb?.label || '' })
  }
  return out.length ? out : fromJson
}

export function mergeHiwPageSteps(s: Settings, fallback: HiwStepPage[]): HiwStepPage[] {
  const fromJson = parseHiwSteps(s.hiw_page_steps_json, fallback)
  const max = Math.max(fromJson.length, MARKETING_LIST_LIMITS.hiwPageSteps)
  const out: HiwStepPage[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const t = pick(s, `hiw_page_step${n}_title`, fb?.t || '')
    const d = pick(s, `hiw_page_step${n}_desc`, fb?.d || '')
    const icon = pick(s, `hiw_page_step${n}_icon`, fb?.icon || '✓')
    if (!t && !d && !fb) continue
    const stepN = fb?.n ?? n
    if (t || d || fb) out.push({ n: stepN, icon, t: t || fb?.t || '', d: d || fb?.d || '' })
  }
  return out.length ? out : fromJson
}

export function mergeBrandCompareRows(s: Settings, fallback: CompareBrandRow[]): CompareBrandRow[] {
  const fromJson = parseCompareBrandRows(s.pricing_compare_brand_rows_json, fallback)
  const max = Math.max(fromJson.length, MARKETING_LIST_LIMITS.brandCompare)
  const out: CompareBrandRow[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const feature = pick(s, `pricing_brand_row_${n}_feature`, fb?.feature || '')
    if (!feature && !fb) continue
    out.push({
      feature: feature || fb?.feature || '',
      us: pick(s, `pricing_brand_row_${n}_us`, fb?.us || ''),
      them: pick(s, `pricing_brand_row_${n}_them`, fb?.them || ''),
    })
  }
  return out.length ? out : fromJson
}

export function mergeContactHoursRows(s: Settings, fallback: HoursRow[]): HoursRow[] {
  const fromJson = parseHoursRows(s.contact_hours_json, fallback)
  const max = Math.max(fromJson.length, MARKETING_LIST_LIMITS.contactHours)
  const out: HoursRow[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const day = pick(s, `contact_hours_${n}_day`, fb?.day || '')
    if (!day && !fb) continue
    out.push({
      day: day || fb?.day || '',
      hours: pick(s, `contact_hours_${n}_hours`, fb?.hours || ''),
    })
  }
  return out.length ? out : fromJson
}

export function flatKeysFromValueCards(cards: ValueCard[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= MARKETING_LIST_LIMITS.values; n++) {
    const c = cards[n - 1]
    out[`value_${n}_icon`] = c?.icon ?? ''
    out[`value_${n}_title`] = c?.t ?? ''
    out[`value_${n}_desc`] = c?.d ?? ''
  }
  return out
}

export function flatKeysFromTeamMembers(members: TeamMember[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= MARKETING_LIST_LIMITS.team; n++) {
    const m = members[n - 1]
    out[`team_${n}_name`] = m?.name ?? ''
    out[`team_${n}_role`] = m?.role ?? ''
    out[`team_${n}_bio`] = m?.bio ?? ''
    out[`team_${n}_img`] = m?.img ?? ''
  }
  return out
}

export function flatKeysFromAboutHighlights(highlights: HighlightPair[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= MARKETING_LIST_LIMITS.highlights; n++) {
    const h = highlights[n - 1]
    out[`about_highlight_${n}_value`] = h?.value ?? ''
    out[`about_highlight_${n}_label`] = h?.label ?? ''
  }
  return out
}

export function flatKeysFromHiwPageSteps(steps: HiwStepPage[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= MARKETING_LIST_LIMITS.hiwPageSteps; n++) {
    const s = steps[n - 1]
    out[`hiw_page_step${n}_icon`] = s?.icon ?? ''
    out[`hiw_page_step${n}_title`] = s?.t ?? ''
    out[`hiw_page_step${n}_desc`] = s?.d ?? ''
  }
  return out
}

export function flatKeysFromBrandCompareRows(rows: CompareBrandRow[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= MARKETING_LIST_LIMITS.brandCompare; n++) {
    const r = rows[n - 1]
    out[`pricing_brand_row_${n}_feature`] = r?.feature ?? ''
    out[`pricing_brand_row_${n}_us`] = r?.us ?? ''
    out[`pricing_brand_row_${n}_them`] = r?.them ?? ''
  }
  return out
}

export function flatKeysFromContactHours(rows: HoursRow[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= MARKETING_LIST_LIMITS.contactHours; n++) {
    const r = rows[n - 1]
    out[`contact_hours_${n}_day`] = r?.day ?? ''
    out[`contact_hours_${n}_hours`] = r?.hours ?? ''
  }
  return out
}
