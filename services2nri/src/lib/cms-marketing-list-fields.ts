/**
 * Per-item platform setting keys + catalog field defs for marketing page list bands.
 */
import type { ContentFieldDef } from '@/lib/design-system-catalog'
import type { CanonicalElement } from '@/lib/cms-element-registry'
import { listItemCanonical } from '@/lib/cms-home-list-fields'

export const MARKETING_LIST_LIMITS = {
  values: 8,
  team: 8,
  highlights: 6,
  hiwPageSteps: 8,
  brandCompare: 10,
  contactHours: 5,
  pricingPlans: 4,
} as const

function range(n: number): number[] {
  return Array.from({ length: n }, (_, i) => i + 1)
}

export function valueContentFields(): ContentFieldDef[] {
  return range(MARKETING_LIST_LIMITS.values).flatMap((n) => [
    { key: `value_${n}_icon`, label: `Value ${n} icon`, placeholder: '🔒' },
    { key: `value_${n}_title`, label: `Value ${n} title` },
    { key: `value_${n}_desc`, label: `Value ${n} description`, type: 'textarea' as const, rows: 2 },
  ])
}

export function teamContentFields(): ContentFieldDef[] {
  return range(MARKETING_LIST_LIMITS.team).flatMap((n) => [
    { key: `team_${n}_name`, label: `Team member ${n} name` },
    { key: `team_${n}_role`, label: `Team member ${n} role` },
    { key: `team_${n}_bio`, label: `Team member ${n} bio`, type: 'textarea' as const, rows: 2 },
    { key: `team_${n}_img`, label: `Team member ${n} photo URL`, type: 'url' as const },
  ])
}

export function aboutHighlightContentFields(): ContentFieldDef[] {
  return range(MARKETING_LIST_LIMITS.highlights).flatMap((n) => [
    { key: `about_highlight_${n}_value`, label: `Highlight ${n} value`, placeholder: '10,000+' },
    { key: `about_highlight_${n}_label`, label: `Highlight ${n} label`, placeholder: 'Clients Served' },
  ])
}

export function hiwPageStepContentFields(): ContentFieldDef[] {
  return range(MARKETING_LIST_LIMITS.hiwPageSteps).flatMap((n) => [
    { key: `hiw_page_step${n}_icon`, label: `Step ${n} icon (emoji)`, placeholder: '🔍' },
    { key: `hiw_page_step${n}_title`, label: `Step ${n} title` },
    { key: `hiw_page_step${n}_desc`, label: `Step ${n} description`, type: 'textarea' as const, rows: 2 },
  ])
}

export function brandCompareRowContentFields(): ContentFieldDef[] {
  return range(MARKETING_LIST_LIMITS.brandCompare).flatMap((n) => [
    { key: `pricing_brand_row_${n}_feature`, label: `Compare row ${n} feature` },
    { key: `pricing_brand_row_${n}_us`, label: `Compare row ${n} — your brand` },
    { key: `pricing_brand_row_${n}_them`, label: `Compare row ${n} — competitor` },
  ])
}

export function contactHoursContentFields(): ContentFieldDef[] {
  return range(MARKETING_LIST_LIMITS.contactHours).flatMap((n) => [
    { key: `contact_hours_${n}_day`, label: `Hours row ${n} day label`, placeholder: 'Mon – Fri' },
    { key: `contact_hours_${n}_hours`, label: `Hours row ${n} times`, placeholder: '9:00 AM – 8:00 PM IST' },
  ])
}

export const VALUE_ITEM_PARTS = [
  { suffix: 'icon', label: 'icon' },
  { suffix: 'title', label: 'title' },
  { suffix: 'desc', label: 'description' },
]

export const TEAM_ITEM_PARTS = [
  { suffix: 'name', label: 'name' },
  { suffix: 'role', label: 'role' },
  { suffix: 'bio', label: 'bio' },
  { suffix: 'photo', label: 'photo' },
]

export const HIGHLIGHT_ITEM_PARTS = [
  { suffix: 'value', label: 'value' },
  { suffix: 'label', label: 'label' },
]

export const PAGE_STEP_ITEM_PARTS = [
  { suffix: 'icon', label: 'icon' },
  { suffix: 'title', label: 'title' },
  { suffix: 'desc', label: 'description' },
]

export const BRAND_ROW_ITEM_PARTS = [
  { suffix: 'feature', label: 'feature' },
  { suffix: 'us', label: 'your brand' },
  { suffix: 'them', label: 'competitor' },
]

export const HOURS_ITEM_PARTS = [
  { suffix: 'day', label: 'day label' },
  { suffix: 'hours', label: 'hours' },
]

export const PLAN_ITEM_PARTS = [
  { suffix: 'name', label: 'plan name' },
  { suffix: 'subtitle', label: 'subtitle' },
  { suffix: 'price', label: 'price' },
]

export function marketingListItemCanonical(
  itemPrefix: string,
  count: number,
  sectionLabel: string,
  parts: { suffix: string; label: string }[],
  shared?: { id: string; label: string },
): CanonicalElement[] {
  return listItemCanonical(itemPrefix, count, sectionLabel, parts, shared)
}

export function allMarketingListFlatSettingKeys(): string[] {
  return [
    ...valueContentFields().map((f) => f.key),
    ...teamContentFields().map((f) => f.key),
    ...aboutHighlightContentFields().map((f) => f.key),
    ...hiwPageStepContentFields().map((f) => f.key),
    ...brandCompareRowContentFields().map((f) => f.key),
    ...contactHoursContentFields().map((f) => f.key),
  ]
}

export const HIW_PAGE_STEP_KEYS = range(MARKETING_LIST_LIMITS.hiwPageSteps).flatMap((n) => [
  `hiw_page_step${n}_icon`,
  `hiw_page_step${n}_title`,
  `hiw_page_step${n}_desc`,
])
