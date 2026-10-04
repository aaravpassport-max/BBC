/**
 * Client-side width token resolver (mirrors S2NRI\Design\WidthLayout).
 */
import type { CSSProperties } from 'react'
import { cssVars } from '@/lib/design-tokens'

/** Marketing slugs with optional dedicated `widths.page_types.{slug}` layers. */
export const WIDTH_PAGE_TYPE_SLUG_ALIASES = [
  'faq', 'contact', 'pricing', 'about', 'terms', 'privacy', 'how-it-works',
] as const

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
  padding_x_left: 'padding-x-left',
  padding_x_right: 'padding-x-right',
  min_width: 'min',
  max_width_cap: 'max-cap',
}

export const WIDTH_BREAKPOINTS = {
  desktop: 1280,
  laptop: 1024,
  tablet: 768,
  mobile: 768,
} as const

export type WidthBreakpointId = keyof typeof WIDTH_BREAKPOINTS

const BP_ORDER: WidthBreakpointId[] = ['desktop', 'laptop', 'tablet', 'mobile']

function tokenValueAtBp(raw: unknown, bp: WidthBreakpointId): string {
  if (raw == null || raw === 'inherit') return ''
  if (isRecord(raw)) {
    const order: WidthBreakpointId[] =
      bp === 'mobile'
        ? ['mobile', 'tablet', 'laptop', 'desktop']
        : bp === 'tablet'
          ? ['tablet', 'laptop', 'desktop']
          : bp === 'laptop'
            ? ['laptop', 'desktop']
            : ['desktop']
    for (const key of order) {
      const v = String(raw[key] ?? '').trim()
      if (v) return v
    }
    return ''
  }
  return String(raw).trim()
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

export function sectionMaxFromLayer(layer: WidthLayer): string | null {
  const priority = ['content_max', 'section_wide', 'section_standard', 'section_narrow', 'page_max', 'inner_max']
  for (const key of priority) {
    const raw = layer[key]
    if (raw == null || raw === 'inherit') continue
    let val: string
    if (isRecord(raw)) {
      val = String(raw.desktop ?? Object.values(raw)[0] ?? '')
    } else {
      val = String(raw)
    }
    if (val.trim()) return val
  }
  return null
}

function layerToCssVars(layer: WidthLayer, prefix = '', bp: WidthBreakpointId = 'desktop'): Record<string, string> {
  const vars: Record<string, string> = {}
  for (const [key, cssKey] of Object.entries(TOKEN_MAP)) {
    const raw = layer[key]
    if (raw == null || raw === 'inherit') continue
    const val = tokenValueAtBp(raw, bp)
    if (!val) continue
    vars[`s2-width-${prefix}${cssKey}`] = val
  }
  return vars
}

function varsToCssBlock(vars: Record<string, string>): string {
  return Object.entries(vars)
    .map(([k, v]) => `--${k}:${v};`)
    .join('')
}

function renderLayerResponsiveCss(layer: WidthLayer, selector: string, prefix = ''): string {
  let css = ''
  for (const bp of BP_ORDER) {
    if (bp === 'desktop') continue
    const max = WIDTH_BREAKPOINTS[bp]
    const flat: WidthLayer = {}
    for (const [key, val] of Object.entries(layer)) {
      if (isRecord(val) && val[bp] != null && String(val[bp]).trim() !== '') {
        flat[key] = String(val[bp])
      }
    }
    if (!Object.keys(flat).length) continue
    const bpVars = layerToCssVars(flat, prefix, 'desktop')
    if (!Object.keys(bpVars).length) continue
    css += `@media (max-width:${max}px){${selector}{${varsToCssBlock(bpVars)}}}\n`
  }
  return css
}

export function activeWidthBreakpoint(): WidthBreakpointId {
  if (typeof window === 'undefined') return 'desktop'
  const w = window.innerWidth
  if (w <= WIDTH_BREAKPOINTS.mobile) return 'mobile'
  if (w <= 1023) return 'tablet'
  if (w <= 1279) return 'laptop'
  return 'desktop'
}

export function pageContextFromPath(pathname: string): PageWidthContext {
  const path = '/' + pathname.replace(/^\/+/, '').split('?')[0]
  if (path === '/' || path === '') return { page_type: 'home', page_slug: 'home' }
  if (/^\/service\/[^/]+/.test(path)) {
    return { page_type: 'service', page_slug: path.split('/')[2] || 'service' }
  }
  if (path.startsWith('/services/')) {
    const parts = path.split('/').filter(Boolean)
    if (parts.length > 1) return { page_type: 'category', page_slug: parts[1] }
  }
  if (path === '/services') {
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
  bp: WidthBreakpointId = activeWidthBreakpoint(),
): Record<string, string> {
  const widths = isRecord(design?.widths) ? (design.widths as Record<string, unknown>) : {}
  const global = (isRecord(widths.global) ? widths.global : {}) as WidthLayer
  let merged = { ...global }

  const pageTypes = isRecord(widths.page_types) ? widths.page_types : {}
  const ptLayer = pageTypes[ctx.page_type]
  if (isRecord(ptLayer)) merged = mergeLayer(merged, ptLayer as WidthLayer)

  if (
    ctx.page_type === 'page' &&
    ctx.page_slug &&
    (WIDTH_PAGE_TYPE_SLUG_ALIASES as readonly string[]).includes(ctx.page_slug)
  ) {
    const aliasLayer = pageTypes[ctx.page_slug]
    if (isRecord(aliasLayer)) merged = mergeLayer(merged, aliasLayer as WidthLayer)
  }

  if (ctx.page_type === 'service' && isRecord(widths.service_page)) {
    merged = mergeLayer(merged, widths.service_page as WidthLayer)
  }

  const pages = isRecord(widths.pages) ? widths.pages : {}
  const pageLayer = pages[ctx.page_slug]
  if (isRecord(pageLayer)) merged = mergeLayer(merged, pageLayer as WidthLayer)

  return layerToCssVars(merged, '', bp)
}

/** Mirrors WidthLayout::renderScopeCss for SPA navigation + live design sync. */
export function buildWidthResponsiveRuntimeCss(
  design: Record<string, unknown> | undefined,
  ctx: PageWidthContext,
): string {
  if (!design) return ''
  const widths = isRecord(design.widths) ? (design.widths as Record<string, unknown>) : {}
  const global = (isRecord(widths.global) ? widths.global : {}) as WidthLayer
  let css = `.s2-width-scope,.s2-page-wrap{${varsToCssBlock(layerToCssVars(global))}}\n`
  css += renderLayerResponsiveCss(global, '.s2-width-scope,.s2-page-wrap')

  const merged = mergeWidthContext(design, ctx)
  let pageSel = '.s2-width-scope,.s2-page-wrap'
  if (ctx.page_type) pageSel += `[data-s2-page-type="${ctx.page_type}"]`
  if (ctx.page_slug) pageSel += `[data-s2-page-slug="${ctx.page_slug}"]`
  css += `${pageSel}{${varsToCssBlock(layerToCssVars(merged))}}\n`
  css += renderLayerResponsiveCss(merged, pageSel)

  return css + buildWidthSectionRuntimeCss(design)
}

/** Merged width layer for a page context (mirrors server resolveVars without section scope). */
export function mergeWidthContext(
  design: Record<string, unknown> | undefined,
  ctx: PageWidthContext,
): WidthLayer {
  const widths = isRecord(design?.widths) ? (design.widths as Record<string, unknown>) : {}
  const global = (isRecord(widths.global) ? widths.global : {}) as WidthLayer
  let merged = { ...global }

  const pageTypes = isRecord(widths.page_types) ? widths.page_types : {}
  const ptLayer = pageTypes[ctx.page_type]
  if (isRecord(ptLayer)) merged = mergeLayer(merged, ptLayer as WidthLayer)

  if (
    ctx.page_type === 'page' &&
    ctx.page_slug &&
    (WIDTH_PAGE_TYPE_SLUG_ALIASES as readonly string[]).includes(ctx.page_slug)
  ) {
    const aliasLayer = pageTypes[ctx.page_slug]
    if (isRecord(aliasLayer)) merged = mergeLayer(merged, aliasLayer as WidthLayer)
  }

  if (ctx.page_type === 'service' && isRecord(widths.service_page)) {
    merged = mergeLayer(merged, widths.service_page as WidthLayer)
  }

  const pages = isRecord(widths.pages) ? widths.pages : {}
  const pageLayer = pages[ctx.page_slug]
  if (isRecord(pageLayer)) merged = mergeLayer(merged, pageLayer as WidthLayer)

  return merged
}

/** Section override layer (global sections + service sections when on service pages). */
export function resolveSectionLayer(
  design: Record<string, unknown> | undefined,
  pageType: string,
  section: string,
): WidthLayer | null {
  const widths = isRecord(design?.widths) ? (design.widths as Record<string, unknown>) : {}
  const layers: WidthLayer[] = []
  const sections = isRecord(widths.sections) ? widths.sections : {}
  if (isRecord(sections[section])) layers.push(sections[section] as WidthLayer)
  if (pageType) {
    const pageTypes = isRecord(widths.page_types) ? widths.page_types : {}
    const ptConfig = pageTypes[pageType]
    if (isRecord(ptConfig)) {
      const ptSections = isRecord(ptConfig.sections) ? ptConfig.sections : {}
      if (isRecord(ptSections[section])) layers.push(ptSections[section] as WidthLayer)
    }
  }
  if (pageType === 'service') {
    const sp = isRecord(widths.service_page) ? widths.service_page : {}
    const ss = isRecord(sp.sections) ? sp.sections : {}
    if (isRecord(ss[section])) layers.push(ss[section] as WidthLayer)
  }
  if (!layers.length) return null
  return layers.reduce((acc, layer) => mergeLayer(acc, layer), {} as WidthLayer)
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

function layerToCssDecls(layer: WidthLayer, section: string): string {
  const vars = layerToCssVars(layer, `sec-${section}-`)
  const lines: string[] = []
  for (const [k, v] of Object.entries(vars)) {
    lines.push(`--${k}:${v}`)
  }
  const max = sectionMaxFromLayer(layer)
  if (max) lines.push(`--s2-width-sec-${section}-max:${max}`)
  if (max) lines.push(`--s2-section-max:${max}`)
  return lines.join(';')
}

function appendSectionResponsiveCss(css: string, layer: WidthLayer, selector: string, section: string): string {
  return css + renderLayerResponsiveCss(layer, selector, `sec-${section}-`)
}

/** Mirrors WidthLayout::renderPageTypeSectionCss + global sections for SPA live sync. */
export function buildWidthSectionRuntimeCss(design: Record<string, unknown> | undefined): string {
  if (!design) return ''
  const widths = isRecord(design.widths) ? (design.widths as Record<string, unknown>) : {}
  let css = ''

  const sections = isRecord(widths.sections) ? widths.sections : {}
  for (const [sec, layer] of Object.entries(sections)) {
    if (!isRecord(layer)) continue
    const decl = layerToCssDecls(layer as WidthLayer, sec)
    if (!decl) continue
    const sel = `[data-s2-section="${sec}"]`
    css += `${sel}{${decl}}\n`
    css = appendSectionResponsiveCss(css, layer as WidthLayer, sel, sec)
  }

  const pageTypes = isRecord(widths.page_types) ? widths.page_types : {}
  for (const [pt, ptConfig] of Object.entries(pageTypes)) {
    if (!isRecord(ptConfig)) continue
    const ptSections = isRecord(ptConfig.sections) ? ptConfig.sections : {}
    for (const [sec, layer] of Object.entries(ptSections)) {
      if (!isRecord(layer)) continue
      const decl = layerToCssDecls(layer as WidthLayer, sec)
      if (!decl) continue
      const sel =
        `.s2-width-scope[data-s2-page-type="${pt}"] [data-s2-section="${sec}"],` +
        `.s2-page-wrap[data-s2-page-type="${pt}"] [data-s2-section="${sec}"]`
      css += `${sel}{${decl}}\n`
      css = appendSectionResponsiveCss(css, layer as WidthLayer, sel, sec)
    }
  }

  const sp = isRecord(widths.service_page) ? widths.service_page : {}
  const spSections = isRecord(sp.sections) ? sp.sections : {}
  for (const [sec, layer] of Object.entries(spSections)) {
    if (!isRecord(layer)) continue
    const decl = layerToCssDecls(layer as WidthLayer, sec)
    if (!decl) continue
    const sel =
      `.s2-width-scope[data-s2-page-type="service"] [data-s2-section="${sec}"],` +
      `.s2-page-wrap[data-s2-page-type="service"] [data-s2-section="${sec}"]`
    css += `${sel}{${decl}}\n`
    css = appendSectionResponsiveCss(css, layer as WidthLayer, sel, sec)
  }

  return css
}

/** Direct layout for homepage hero carousel (SPA-safe). */
export function homeHeroWidthStyle(design: Record<string, unknown> | undefined): CSSProperties | undefined {
  const layer = resolveSectionLayer(design, 'home', 'hero')
  if (!layer) return undefined
  const max = sectionMaxFromLayer(layer)
  if (!max) return undefined
  return {
    maxWidth: max,
    width: '100%',
    marginInline: 'auto',
    boxSizing: 'border-box',
  }
}
