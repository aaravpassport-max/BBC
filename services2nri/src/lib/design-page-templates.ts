/**
 * Enterprise template catalog — maps public routes to width + override keys.
 */
import { WIDTH_PAGE_TYPES } from '@/lib/width-layout'

export type PageTemplateDef = {
  id: string
  label: string
  description: string
  previewPath: string
  /** Width & Layout → page type scope */
  widthPageType?: string
  /** Width & Layout → page slug scope (widths.pages) */
  widthPageSlug?: string
  /** Also apply widths.page_types[slug] when route uses page_type=page */
  widthSlugAlias?: string
  /** overrides.page_types key */
  overridePageType?: string
  /** overrides.pages key */
  overridePageSlug?: string
}

export const PAGE_TEMPLATES: PageTemplateDef[] = [
  {
    id: 'home',
    label: 'Homepage',
    description: 'Hero, services grid, cities, FAQ, newsletter',
    previewPath: '/',
    widthPageType: 'home',
    overridePageType: 'home',
  },
  {
    id: 'services',
    label: 'Services directory',
    description: 'Search, categories sidebar, service cards',
    previewPath: '/services',
    widthPageType: 'services',
    overridePageType: 'services',
  },
  {
    id: 'category',
    label: 'Category listing',
    description: '/services/{category}',
    previewPath: '/services/passport',
    widthPageType: 'category',
    overridePageType: 'category',
  },
  {
    id: 'service',
    label: 'Service detail',
    description: 'Hero, CMS sections, booking wizard',
    previewPath: '/service/passport-renewal',
    widthPageType: 'service',
    overridePageType: 'service',
  },
  {
    id: 'blog',
    label: 'Blog',
    description: 'PHP blog hub and articles',
    previewPath: '/blog',
    widthPageType: 'blog',
    overridePageType: 'blog',
  },
  {
    id: 'city',
    label: 'City landing',
    description: 'Geo landing pages',
    previewPath: '/cities/dubai',
    widthPageType: 'city',
    overridePageType: 'city',
  },
  {
    id: 'about',
    label: 'About',
    description: 'Marketing page',
    previewPath: '/about',
    widthPageType: 'page',
    widthPageSlug: 'about',
    widthSlugAlias: 'about',
    overridePageType: 'page',
    overridePageSlug: 'about',
  },
  {
    id: 'contact',
    label: 'Contact',
    description: 'Form + hero',
    previewPath: '/contact',
    widthPageType: 'page',
    widthPageSlug: 'contact',
    widthSlugAlias: 'contact',
    overridePageType: 'contact',
    overridePageSlug: 'contact',
  },
  {
    id: 'faq',
    label: 'FAQ',
    description: 'Accordion FAQ',
    previewPath: '/faq',
    widthPageType: 'page',
    widthPageSlug: 'faq',
    widthSlugAlias: 'faq',
    overridePageType: 'faq',
    overridePageSlug: 'faq',
  },
  {
    id: 'pricing',
    label: 'Pricing',
    description: 'Plans table',
    previewPath: '/pricing',
    widthPageType: 'page',
    widthPageSlug: 'pricing',
    widthSlugAlias: 'pricing',
    overridePageType: 'pricing',
    overridePageSlug: 'pricing',
  },
  {
    id: 'how-it-works',
    label: 'How it works',
    description: 'Process marketing page',
    previewPath: '/how-it-works',
    widthPageType: 'page',
    widthPageSlug: 'how-it-works',
    widthSlugAlias: 'how-it-works',
    overridePageSlug: 'how-it-works',
  },
  {
    id: 'terms',
    label: 'Terms',
    description: 'Legal',
    previewPath: '/terms',
    widthPageType: 'page',
    widthPageSlug: 'terms',
    overridePageSlug: 'terms',
  },
  {
    id: 'privacy',
    label: 'Privacy',
    description: 'Legal',
    previewPath: '/privacy',
    widthPageType: 'page',
    widthPageSlug: 'privacy',
    overridePageSlug: 'privacy',
  },
  {
    id: 'visa',
    label: 'Visa hub',
    description: 'Visa content routes',
    previewPath: '/visa',
    widthPageType: 'visa',
    overridePageType: 'visa',
  },
  {
    id: 'country',
    label: 'Country hub',
    description: 'Country content routes',
    previewPath: '/country',
    widthPageType: 'country',
    overridePageType: 'country',
  },
]

export const ALL_WIDTH_PAGE_TYPES = WIDTH_PAGE_TYPES

/** chrome.page_types / chrome.pages patch path for a template field */
export function templateChromePath(t: PageTemplateDef, field: string): string[] {
  if (t.widthPageSlug) return ['chrome', 'pages', t.widthPageSlug, field]
  const pt = t.widthSlugAlias || t.widthPageType || t.overridePageType || t.id
  return ['chrome', 'page_types', pt, field]
}

export function templateTypographyPath(t: PageTemplateDef, role: string, field: string): string[] {
  if (t.overridePageSlug) {
    return ['overrides', 'pages', t.overridePageSlug, 'typography', role, field]
  }
  const pt = t.overridePageType || t.widthSlugAlias || t.widthPageType || t.id
  return ['overrides', 'page_types', pt, 'typography', role, field]
}

export function readNestedString(root: Record<string, unknown>, path: string[]): string {
  let cur: unknown = root
  for (const p of path) {
    if (!cur || typeof cur !== 'object') return ''
    cur = (cur as Record<string, unknown>)[p]
  }
  return typeof cur === 'string' ? cur : ''
}

export function templatePageMaxPath(t: PageTemplateDef): string[] | null {
  if (t.widthPageSlug) return ['widths', 'pages', t.widthPageSlug, 'page_max']
  if (t.widthSlugAlias) return ['widths', 'page_types', t.widthSlugAlias, 'page_max']
  if (t.widthPageType) return ['widths', 'page_types', t.widthPageType, 'page_max']
  return null
}

export const TEMPLATE_COLOR_KEYS = ['primary', 'background', 'heading', 'accent'] as const

export function templateColorOverridePath(t: PageTemplateDef, key: string): string[] {
  if (t.overridePageSlug) return ['overrides', 'pages', t.overridePageSlug, 'colors', key]
  const pt = t.overridePageType || t.widthPageType || t.id
  return ['overrides', 'page_types', pt, 'colors', key]
}

/** Clear all page-template overrides (colors, typography samples, chrome, page_max width). */
export function clearTemplateOverrides(
  t: PageTemplateDef,
  patch: (path: string[], value: unknown) => void,
): void {
  for (const key of TEMPLATE_COLOR_KEYS) {
    patch(templateColorOverridePath(t, key), null)
  }
  patch(templateTypographyPath(t, 'page_title', 'size_desktop'), null)
  patch(templateTypographyPath(t, 'body', 'size_desktop'), null)
  patch(templateChromePath(t, 'footer_bg'), null)
  patch(templateChromePath(t, 'footer_variant'), null)
  const pm = templatePageMaxPath(t)
  if (pm) patch(pm, null)
}
