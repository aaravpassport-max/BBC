/**
 * Merge flat per-item platform settings over JSON/list fallbacks for public render.
 */
import {
  parseAwardBadges,
  parseHomeFaqPairs,
  parseLogoChips,
  parseStringList,
  parseWhyChooseCards,
  type AwardBadge,
  type FaqPair,
  type LogoChip,
  type WhyChooseCard,
} from '@/lib/home-content-settings'
import { HOME_LIST_LIMITS } from '@/lib/cms-home-list-fields'

type Settings = Record<string, string>

function pick(s: Settings, key: string, fallback: string): string {
  const v = s[key]
  return v !== undefined && String(v).trim() !== '' ? String(v) : fallback
}

export function mergeWhyChooseCards(s: Settings, fallback: WhyChooseCard[]): WhyChooseCard[] {
  const fromJson = parseWhyChooseCards(s.home_why_choose_json, fallback)
  const max = Math.max(fromJson.length, HOME_LIST_LIMITS.features)
  const out: WhyChooseCard[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const title = pick(s, `feature_${n}_title`, fb?.title || '')
    const sub = pick(s, `feature_${n}_sub`, fb?.sub || '')
    const icon = pick(s, `feature_${n}_icon`, fb?.icon || '✓')
    if (!title && !sub && !fb) continue
    if (title || sub || fb) out.push({ icon, title: title || fb?.title || '', sub: sub || fb?.sub || '' })
  }
  return out.length ? out : fromJson
}

export function mergeHomeFaqPairs(s: Settings, fallback: FaqPair[]): FaqPair[] {
  const fromJson = parseHomeFaqPairs(s.home_faq_json, fallback)
  const max = Math.max(fromJson.length, HOME_LIST_LIMITS.faq)
  const out: FaqPair[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const q = pick(s, `faq_${n}_q`, fb?.q || '')
    const a = pick(s, `faq_${n}_a`, fb?.a || '')
    if (!q && !a && !fb) continue
    if (q || fb?.q) out.push({ q: q || fb?.q || '', a: a || fb?.a || '' })
  }
  return out.length ? out : fromJson
}

export function mergePressLogos(s: Settings, fallback: LogoChip[]): LogoChip[] {
  const fromJson = parseLogoChips(s.home_press_json, fallback)
  const max = Math.max(fromJson.length, HOME_LIST_LIMITS.press)
  const out: LogoChip[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const name = pick(s, `press_${n}_name`, fb?.name || '')
    const brandRaw = pick(s, `press_${n}_brand`, fb?.brand || '')
    if (!name && !fb) continue
    if (name || fb?.name) {
      out.push({ name: name || fb?.name || '', brand: brandRaw || fb?.brand })
    }
  }
  return out.length ? out : fromJson
}

export function mergePartnerNames(s: Settings, fallback: string[]): string[] {
  const fromJson = parseStringList(s.home_partners_json, fallback)
  const max = Math.max(fromJson.length, HOME_LIST_LIMITS.partners)
  const out: string[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1] || ''
    const name = pick(s, `partner_${n}_name`, fb)
    if (name) out.push(name)
  }
  return out.length ? out : fromJson
}

export function mergeAwardBadges(s: Settings, fallback: AwardBadge[]): AwardBadge[] {
  const fromJson = parseAwardBadges(s.home_awards_json, fallback)
  const max = Math.max(fromJson.length, HOME_LIST_LIMITS.awards)
  const out: AwardBadge[] = []
  for (let n = 1; n <= max; n++) {
    const fb = fromJson[n - 1]
    const text = pick(s, `award_${n}_text`, fb?.text || '')
    if (!text && !fb) continue
    const emoji = pick(s, `award_${n}_emoji`, fb?.emoji || '🏆')
    const variantRaw = pick(s, `award_${n}_variant`, fb?.variant || 'orange')
    const variant = variantRaw === 'primary' ? 'primary' : 'orange'
    out.push({ emoji, text: text || fb?.text || '', variant })
  }
  return out.length ? out : fromJson
}

/** Write flat keys when JSON list editors save (keeps Elements tab in sync). */
export function flatKeysFromWhyChoose(cards: WhyChooseCard[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= HOME_LIST_LIMITS.features; n++) {
    const c = cards[n - 1]
    out[`feature_${n}_icon`] = c?.icon ?? ''
    out[`feature_${n}_title`] = c?.title ?? ''
    out[`feature_${n}_sub`] = c?.sub ?? ''
  }
  return out
}

export function flatKeysFromFaq(items: FaqPair[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= HOME_LIST_LIMITS.faq; n++) {
    const c = items[n - 1]
    out[`faq_${n}_q`] = c?.q ?? ''
    out[`faq_${n}_a`] = c?.a ?? ''
  }
  return out
}

export function flatKeysFromPress(chips: LogoChip[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= HOME_LIST_LIMITS.press; n++) {
    const c = chips[n - 1]
    out[`press_${n}_name`] = c?.name ?? ''
    out[`press_${n}_brand`] = c?.brand ?? ''
  }
  return out
}

export function flatKeysFromPartners(names: string[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= HOME_LIST_LIMITS.partners; n++) {
    out[`partner_${n}_name`] = names[n - 1] ?? ''
  }
  return out
}

export function flatKeysFromAwards(badges: AwardBadge[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (let n = 1; n <= HOME_LIST_LIMITS.awards; n++) {
    const c = badges[n - 1]
    out[`award_${n}_emoji`] = c?.emoji ?? ''
    out[`award_${n}_text`] = c?.text ?? ''
    out[`award_${n}_variant`] = c?.variant ?? ''
  }
  return out
}
