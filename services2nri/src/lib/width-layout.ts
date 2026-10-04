/**
 * Client-side width token resolver (mirrors S2NRI\Design\WidthLayout).
 */
import type { CSSProperties } from 'react'
import { cssVars } from '@/lib/design-tokens'

export const WIDTH_PAGE_TYPES = [
  'home', 'page', 'service', 'services', 'category', 'listing',
  'blog', 'city', 'visa', 'country', 'faq', 'contact', 'pricing',
] as const

export const WIDTH_SECTIONS = [
  'hero', 'intro', 'description', 'features', 'benefits', 'process',
  'requirements', 'documents', 'pricing', 'faq', 'cta',
  'related_services', 'related_content', 'testimonials', 'wizard',
  'marquee', 'trust_badges', 'cms', 'compare', 'newsletter', 'directory',
  'cities', 'stats', 'partners', 'about', 'app', 'home_services',
] as const

export type PageWidthContext = {
  page_type: string
  page_slug: string
}

const TOKEN_MAP: Record<string, string> = {
  page_max: 'page-max',
  content_max: 'content-max',
  inner_max: 'inner-max',
  full_bleed: 'full-bleed',
  section_standard: 'section-standard',
  section_wide: 'section-wide',
  section_narrow: 'section-narrow',
  section_compact: 'section-compact',
  padding_x: 'padding-x',
  min_width: 'min',
  max_width_cap: 'max-cap',
}

type WidthLayer = Record<string, string | Record<string, string>>

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

function mergeLayer(base: WidthLayer, patch: WidthLayer): WidthLayer {
  const out = { ...base }
  for (const [key, val] of Object.entries(patch)) {
    if (key === 'sections' || key === 'inherit') continue
    if (isRecord(val) && isRecord(out[key])) {
      out[key] = { ...(out[key] as Record<string, string>), ...(val as Record<string, string>) }
    } else {
      out[key] = val as string | Record<string, string>
    }
  }
  return out
}

function layerToCssVars(layer: WidthLayer, prefix = ''): Record<string, string> {
  const vars: Record<string, string> = {}
  for (const [key, cssKey] of Object.entries(TOKEN_MAP)) {
    const raw = layer[key]
    if (raw == null || raw === 'inherit') continue
    let val: string
    if (isRecord(raw)) {
      val = String(raw.desktop ?? Object.values(raw)[0] ?? '')
    } else {
      val = String(raw)
    }
    if (!val) continue
    vars[`s2-width-${prefix}${cssKey}`] = val
  }
  return vars
}

export function pageContextFromPath(pathname: string): PageWidthContext {
  const path = '/' + pathname.replace(/^\/+/, '').split('?')[0]
  if (path === '/' || path === '') return { page_type: 'home', page_slug: 'home' }
  if (/^\/service\/[^/]+/.test(path)) {
    return { page_type: 'service', page_slug: path.split('/')[2] || 'service' }
  }
  if (path === '/services' || path.startsWith('/services/')) {
    const parts = path.split('/').filter(Boolean)
    if (parts.length > 1) return { page_type: 'category', page_slug: parts[1] }
    return { page_type: 'services', page_slug: 'services' }
  }
  if (path.startsWith('/blog')) return { page_type: 'blog', page_slug: path.replace(/^\//, '') }
  if (path.startsWith('/cities/')) return { page_type: 'city', page_slug: path.replace(/^\//, '') }
  const staticMap: Record<string, string> = {
    '/about': 'about',
    '/contact': 'contact',
    '/how-it-works': 'how-it-works',
    '/faq': 'faq',
    '/pricing': 'pricing',
    '/terms': 'terms',
    '/privacy': 'privacy',
  }
  if (staticMap[path]) return { page_type: 'page', page_slug: staticMap[path] }
  if (path.startsWith('/visa')) return { page_type: 'visa', page_slug: path.replace(/^\//, '') }
  if (path.startsWith('/country')) return { page_type: 'country', page_slug: path.replace(/^\//, '') }
  return { page_type: 'page', page_slug: path.replace(/^\//, '') || 'page' }
}

export function resolveWidthCssVars(
  design: Record<string, unknown> | undefined,
  ctx: PageWidthContext,
): Record<string, string> {
  const widths = isRecord(design?.widths) ? (design.widths as Record<string, unknown>) : {}
  const global = (isRecord(widths.global) ? widths.global : {}) as WidthLayer
  let merged = { ...global }

  const pageTypes = isRecord(widths.page_types) ? widths.page_types : {}
  const ptLayer = pageTypes[ctx.page_type]
  if (isRecord(ptLayer)) merged = mergeLayer(merged, ptLayer as WidthLayer)

  if (ctx.page_type === 'service' && isRecord(widths.service_page)) {
    merged = mergeLayer(merged, widths.service_page as WidthLayer)
  }

  const pages = isRecord(widths.pages) ? widths.pages : {}
  const pageLayer = pages[ctx.page_slug]
  if (isRecord(pageLayer)) merged = mergeLayer(merged, pageLayer as WidthLayer)

  return layerToCssVars(merged)
}

export function widthScopeStyle(
  design: Record<string, unknown> | undefined,
  ctx: PageWidthContext,
): CSSProperties {
  return cssVars(resolveWidthCssVars(design, ctx))
}

export function sectionWidthAttr(section: string): { 'data-s2-section': string } {
  return { 'data-s2-section': section }
}
