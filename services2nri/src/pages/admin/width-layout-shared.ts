import { WIDTH_PAGE_TYPES, WIDTH_SECTIONS } from '@/lib/width-layout'
import { stripPxForInput } from './design-admin-fields'

export const GLOBAL_KEYS = [
  'page_max', 'content_max', 'inner_max', 'full_bleed',
  'section_standard', 'section_wide', 'section_narrow', 'section_compact',
  'padding_x', 'padding_x_left', 'padding_x_right', 'min_width', 'max_width_cap',
] as const

export type GlobalKey = (typeof GLOBAL_KEYS)[number]

export const BP = ['desktop', 'laptop', 'tablet', 'mobile'] as const
export type BreakpointId = (typeof BP)[number]

export const BP_META: Record<BreakpointId, { label: string; hint: string }> = {
  desktop: { label: 'Desktop', hint: '1280px and up' },
  laptop: { label: 'Laptop', hint: '1024–1279px' },
  tablet: { label: 'Tablet', hint: '768–1023px' },
  mobile: { label: 'Mobile', hint: 'Up to 767px' },
}

export type WidthConfig = Record<string, unknown>

export const FIELD_META: Record<
  GlobalKey,
  {
    label: string
    hint: string
    group: 'shell' | 'content' | 'sections' | 'advanced'
    heroRelevant?: boolean
    slider?: { min: number; max: number; step?: number }
  }
> = {
  page_max: {
    label: 'Page container',
    hint: 'Maximum width of the site shell (header, footer, main grid).',
    group: 'shell',
    heroRelevant: true,
    slider: { min: 960, max: 1600, step: 4 },
  },
  padding_x: {
    label: 'Horizontal gutter (both sides)',
    hint: 'Default left and right inset for containers, header, and marketing sections. Set Mobile separately for phone layouts.',
    group: 'shell',
    slider: { min: 0, max: 48, step: 2 },
  },
  padding_x_left: {
    label: 'Left inset',
    hint: 'Optional override for the left gutter only. Empty inherits the horizontal gutter for each breakpoint.',
    group: 'shell',
    slider: { min: 0, max: 48, step: 2 },
  },
  padding_x_right: {
    label: 'Right inset',
    hint: 'Optional override for the right gutter only. Empty inherits the horizontal gutter for each breakpoint.',
    group: 'shell',
    slider: { min: 0, max: 48, step: 2 },
  },
  content_max: {
    label: 'Primary content column',
    hint: 'Long-form content, articles, and main reading width.',
    group: 'content',
    heroRelevant: true,
    slider: { min: 560, max: 1200, step: 4 },
  },
  inner_max: {
    label: 'Narrow column',
    hint: 'Forms, footnotes, and compact prose blocks.',
    group: 'content',
    slider: { min: 400, max: 960, step: 4 },
  },
  section_standard: {
    label: 'Default section width',
    hint: 'Most marketing sections unless a block override applies.',
    group: 'sections',
    heroRelevant: true,
    slider: { min: 720, max: 1400, step: 4 },
  },
  section_wide: {
    label: 'Wide section width',
    hint: 'Comparison tables, dense feature grids.',
    group: 'sections',
    slider: { min: 800, max: 1600, step: 4 },
  },
  section_narrow: {
    label: 'Narrow section width',
    hint: 'FAQ stacks, testimonials, legal blocks.',
    group: 'sections',
    slider: { min: 480, max: 960, step: 4 },
  },
  section_compact: {
    label: 'Compact section width',
    hint: 'Utility rows, search, subtitles.',
    group: 'sections',
    slider: { min: 360, max: 800, step: 4 },
  },
  full_bleed: {
    label: 'Full-bleed band',
    hint: 'Viewport percentage for edge-to-edge bands.',
    group: 'advanced',
  },
  min_width: {
    label: 'Layout floor',
    hint: 'Minimum width before horizontal scroll (advanced).',
    group: 'advanced',
    slider: { min: 280, max: 480, step: 4 },
  },
  max_width_cap: {
    label: 'Absolute ceiling',
    hint: 'Hard cap on any resolved width token.',
    group: 'advanced',
    slider: { min: 1200, max: 2400, step: 8 },
  },
}

export const GROUP_LABELS: Record<(typeof FIELD_META)[GlobalKey]['group'], { title: string; blurb: string }> = {
  shell: { title: 'Site shell', blurb: 'The outer frame visitors see on every page.' },
  content: { title: 'Reading columns', blurb: 'Typography and content density.' },
  sections: { title: 'Section presets', blurb: 'Reusable inner widths for blocks and bands.' },
  advanced: { title: 'Advanced tokens', blurb: 'Edge cases — only change when you know you need them.' },
}

export type WidthTaskId =
  | 'site_defaults'
  | 'home_hero'
  | 'by_page_type'
  | 'single_page'
  | 'service_hero'
  | 'service_block'
  | 'marketing_section'

export type WidthLayoutFocus = {
  scope: 'global' | 'page_type' | 'page_type_section' | 'page' | 'service_section' | 'section'
  selectedType?: string
  selectedPage?: string
  selectedSection?: string
}

export const TASKS: {
  id: WidthTaskId
  title: string
  description: string
  scope: WidthLayoutFocus['scope']
  defaultSection?: string
  navGroup: 'foundation' | 'experiences' | 'targets'
}[] = [
  {
    id: 'site_defaults',
    title: 'Site foundation',
    description: 'Baseline widths every page inherits.',
    scope: 'global',
    navGroup: 'foundation',
  },
  {
    id: 'home_hero',
    title: 'Homepage hero only',
    description: 'Banner carousel width on / — does not change service page heroes.',
    scope: 'page_type_section',
    defaultSection: 'hero',
    navGroup: 'experiences',
  },
  {
    id: 'service_hero',
    title: 'Service hero',
    description: 'Hero band on all service detail URLs.',
    scope: 'service_section',
    defaultSection: 'hero',
    navGroup: 'experiences',
  },
  {
    id: 'marketing_section',
    title: 'Homepage other sections',
    description: 'Cities, newsletter, FAQ — not the homepage hero carousel.',
    scope: 'section',
    navGroup: 'experiences',
  },
  {
    id: 'service_block',
    title: 'Service page blocks',
    description: 'FAQ, wizard, pricing, and other service sections.',
    scope: 'service_section',
    navGroup: 'experiences',
  },
  {
    id: 'by_page_type',
    title: 'Page template',
    description: 'Override defaults for home, blog, city, catalog, etc.',
    scope: 'page_type',
    navGroup: 'targets',
  },
  {
    id: 'single_page',
    title: 'Single page URL',
    description: 'One slug — about, contact, pricing, or custom path.',
    scope: 'page',
    navGroup: 'targets',
  },
]

export const PAGE_TYPE_LABELS: Record<string, string> = {
  home: 'Homepage',
  page: 'Static page',
  service: 'Service detail',
  services: 'Services index',
  category: 'Category listing',
  listing: 'Listing',
  blog: 'Blog',
  city: 'City landing',
  visa: 'Visa hub',
  country: 'Country hub',
  faq: 'FAQ',
  contact: 'Contact',
  pricing: 'Pricing',
}

export const PREVIEW_SCENES = [
  { id: 'home', label: 'Homepage', ctx: { page_type: 'home', page_slug: 'home' }, section: 'hero' },
  { id: 'service', label: 'Service detail', ctx: { page_type: 'service', page_slug: 'passport' }, section: 'hero' },
  { id: 'about', label: 'About page', ctx: { page_type: 'page', page_slug: 'about' }, section: undefined },
  { id: 'blog', label: 'Blog', ctx: { page_type: 'blog', page_slug: 'blog' }, section: undefined },
] as const

export const SECTION_GROUPS: { label: string; keys: readonly string[] }[] = [
  { label: 'Hero & intro', keys: ['hero', 'intro', 'marquee', 'trust_badges'] },
  { label: 'Content', keys: ['description', 'features', 'benefits', 'process', 'requirements', 'documents', 'cms', 'about', 'stats'] },
  { label: 'Catalog & directory', keys: ['home_services', 'directory', 'cities', 'related_services', 'related_content', 'partners'] },
  { label: 'Conversion', keys: ['pricing', 'faq', 'cta', 'wizard', 'newsletter', 'compare', 'testimonials', 'app'] },
]

export function taskFromFocus(focus: WidthLayoutFocus): WidthTaskId {
  if (focus.scope === 'global') return 'site_defaults'
  if (focus.scope === 'page_type_section') {
    return focus.selectedType === 'home' && focus.selectedSection === 'hero' ? 'home_hero' : 'by_page_type'
  }
  if (focus.scope === 'page_type') return 'by_page_type'
  if (focus.scope === 'page') return 'single_page'
  if (focus.scope === 'service_section') {
    return focus.selectedSection === 'hero' ? 'service_hero' : 'service_block'
  }
  return 'marketing_section'
}

export function homeHeroLayer(pageTypes: Record<string, Record<string, unknown>>): Record<string, unknown> {
  const home = pageTypes.home || {}
  const sec = (home.sections || {}) as Record<string, Record<string, unknown>>
  return sec.hero || {}
}

export function readBpPx(value: unknown, bp: BreakpointId): number | null {
  if (value == null || value === 'inherit') return null
  if (typeof value === 'object' && !Array.isArray(value)) {
    const obj = value as Record<string, string>
    const order: BreakpointId[] = [bp, 'desktop', 'laptop', 'tablet', 'mobile']
    for (const key of order) {
      const raw = obj[key]
      if (raw == null || raw === '') continue
      const n = parseInt(stripPxForInput(String(raw)), 10)
      if (Number.isFinite(n)) return n
    }
    return null
  }
  const n = parseInt(stripPxForInput(String(value)), 10)
  return Number.isFinite(n) ? n : null
}

export function effectiveBp(
  layer: Record<string, unknown>,
  global: Record<string, unknown>,
  key: GlobalKey,
  bp: BreakpointId,
): number | null {
  return readBpPx(layer[key], bp) ?? readBpPx(global[key], bp)
}

export function countLayerOverrides(layer: Record<string, unknown>): number {
  return GLOBAL_KEYS.filter((k) => {
    const v = layer[k]
    return v != null && v !== 'inherit'
  }).length
}

export function pageTypeOptions(): { value: string; label: string }[] {
  return WIDTH_PAGE_TYPES.map((t) => ({ value: t, label: PAGE_TYPE_LABELS[t] || t }))
}

export function sectionOptions(): { value: string; label: string; group: string }[] {
  const used = new Set<string>()
  const out: { value: string; label: string; group: string }[] = []
  for (const g of SECTION_GROUPS) {
    for (const key of g.keys) {
      if (!(WIDTH_SECTIONS as readonly string[]).includes(key)) continue
      used.add(key)
      out.push({ value: key, label: key.replace(/_/g, ' '), group: g.label })
    }
  }
  for (const s of WIDTH_SECTIONS) {
    if (used.has(s)) continue
    out.push({ value: s, label: s.replace(/_/g, ' '), group: 'Other' })
  }
  return out
}
