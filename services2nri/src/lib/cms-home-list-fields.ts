/**
 * Per-item platform setting keys + catalog field defs for homepage list bands.
 */
import type { ContentFieldDef } from '@/lib/design-system-catalog'
import type { CanonicalElement } from '@/lib/cms-element-registry'

export const HOME_LIST_LIMITS = {
  features: 8,
  stats: 4,
  faq: 10,
  press: 8,
  partners: 10,
  awards: 6,
  testimonials: 6,
  /** Homepage services band grid slots (registry fills content; Elements styles each slot). */
  serviceGrid: 8,
  /** Homepage cities band grid slots. */
  cityGrid: 8,
  /** /services directory grid slots (styling per visible position). */
  serviceDirectory: 24,
} as const

type ItemPart = { suffix: string; label: string }

function range(n: number): number[] {
  return Array.from({ length: n }, (_, i) => i + 1)
}

export function featureContentFields(): ContentFieldDef[] {
  return range(HOME_LIST_LIMITS.features).flatMap((n) => [
    { key: `feature_${n}_icon`, label: `Feature ${n} icon`, placeholder: '★' },
    { key: `feature_${n}_title`, label: `Feature ${n} title` },
    { key: `feature_${n}_sub`, label: `Feature ${n} description`, type: 'textarea' as const, rows: 2 },
  ])
}

export function faqContentFields(): ContentFieldDef[] {
  return range(HOME_LIST_LIMITS.faq).flatMap((n) => [
    { key: `faq_${n}_q`, label: `FAQ ${n} question` },
    { key: `faq_${n}_a`, label: `FAQ ${n} answer`, type: 'textarea' as const, rows: 3 },
  ])
}

export function pressContentFields(): ContentFieldDef[] {
  return range(HOME_LIST_LIMITS.press).flatMap((n) => [
    { key: `press_${n}_name`, label: `Press logo ${n} name` },
    { key: `press_${n}_brand`, label: `Press logo ${n} brand slug`, placeholder: 'inc42' },
  ])
}

export function partnerContentFields(): ContentFieldDef[] {
  return range(HOME_LIST_LIMITS.partners).map((n) => ({
    key: `partner_${n}_name`,
    label: `Partner ${n} name`,
  }))
}

export function awardContentFields(): ContentFieldDef[] {
  return range(HOME_LIST_LIMITS.awards).flatMap((n) => [
    { key: `award_${n}_emoji`, label: `Award ${n} emoji`, placeholder: '🏆' },
    { key: `award_${n}_text`, label: `Award ${n} label` },
    {
      key: `award_${n}_variant`,
      label: `Award ${n} style`,
      placeholder: 'orange or primary',
    },
  ])
}

export function listItemCanonical(
  itemPrefix: string,
  count: number,
  sectionLabel: string,
  parts: ItemPart[],
  shared?: { id: string; label: string },
): CanonicalElement[] {
  const out: CanonicalElement[] = []
  if (shared) out.push(shared)
  for (let n = 1; n <= count; n++) {
    out.push({ id: `${itemPrefix}_${n}`, label: `${sectionLabel} ${n} card` })
    for (const p of parts) {
      out.push({ id: `${itemPrefix}_${n}_${p.suffix}`, label: `${sectionLabel} ${n} ${p.label}` })
    }
  }
  return out
}

export const FEATURE_ITEM_PARTS: ItemPart[] = [
  { suffix: 'icon', label: 'icon' },
  { suffix: 'title', label: 'title' },
  { suffix: 'desc', label: 'description' },
]

export const STAT_ITEM_PARTS: ItemPart[] = [
  { suffix: 'value', label: 'value' },
  { suffix: 'label', label: 'label' },
]

export const FAQ_ITEM_PARTS: ItemPart[] = [
  { suffix: 'question', label: 'question' },
  { suffix: 'answer', label: 'answer' },
]

export const PRESS_ITEM_PARTS: ItemPart[] = [
  { suffix: 'title', label: 'name' },
  { suffix: 'brand', label: 'brand slug' },
]

export const PARTNER_ITEM_PARTS: ItemPart[] = [{ suffix: 'title', label: 'name' }]

export const AWARD_ITEM_PARTS: ItemPart[] = [
  { suffix: 'icon', label: 'emoji' },
  { suffix: 'title', label: 'label' },
  { suffix: 'variant', label: 'style' },
]

export const TESTIMONIAL_ITEM_PARTS: ItemPart[] = [
  { suffix: 'quote', label: 'quote' },
  { suffix: 'author', label: 'author' },
  { suffix: 'meta', label: 'location / rating' },
]

export const SERVICE_GRID_ITEM_PARTS: ItemPart[] = [
  { suffix: 'media', label: 'image' },
  { suffix: 'title', label: 'title' },
  { suffix: 'desc', label: 'description' },
  { suffix: 'cta', label: 'CTA' },
]

export const SERVICE_DIRECTORY_ITEM_PARTS: ItemPart[] = [
  { suffix: 'media', label: 'image' },
  { suffix: 'badge', label: 'category badge' },
  { suffix: 'title', label: 'title' },
  { suffix: 'desc', label: 'description' },
  { suffix: 'link', label: 'view link' },
  { suffix: 'turnaround', label: 'turnaround label' },
]

export const CITY_GRID_ITEM_PARTS: ItemPart[] = [
  { suffix: 'photo', label: 'photo' },
  { suffix: 'eyebrow', label: 'eyebrow' },
  { suffix: 'name', label: 'name' },
]

export function allHomeListFlatSettingKeys(): string[] {
  return [
    ...featureContentFields().map((f) => f.key),
    ...faqContentFields().map((f) => f.key),
    ...pressContentFields().map((f) => f.key),
    ...partnerContentFields().map((f) => f.key),
    ...awardContentFields().map((f) => f.key),
  ]
}
