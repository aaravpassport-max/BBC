/**
 * Client-side design config resolver (mirrors DesignSystem::resolve + token refs).
 */
import type { PageWidthContext } from '@/lib/width-layout'
import {
  buildWidthResponsiveRuntimeCss,
  resolveWidthCssVars,
} from '@/lib/width-layout'

export type DesignPayload = Record<string, unknown>

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

export function deepMergeDesign(...layers: DesignPayload[]): DesignPayload {
  const out: DesignPayload = {}
  for (const layer of layers) {
    for (const [k, v] of Object.entries(layer)) {
      if (isRecord(v) && isRecord(out[k])) {
        out[k] = deepMergeDesign(out[k] as DesignPayload, v)
      } else {
        out[k] = v
      }
    }
  }
  return out
}

export function resolveTokenRef(value: string, config: DesignPayload): string {
  const m = value.match(/^\{colors\.([a-z0-9_]+)\}$/i)
  if (m) {
    const colors = (config.colors || {}) as Record<string, string>
    const raw = colors[m[1]]
    if (raw) return raw
    return `var(--s2-color-${m[1]})`
  }
  const rm = value.match(/^\{radius\.([a-z0-9_]+)\}$/i)
  if (rm) {
    const radius = (config.radius || {}) as Record<string, string>
    if (radius[rm[1]]) return radius[rm[1]]
    return `var(--s2-radius-${rm[1]})`
  }
  return value
}

export function resolveDesignConfig(design: DesignPayload | undefined, ctx: PageWidthContext): DesignPayload {
  if (!design) return {}
  let config = deepMergeDesign({ ...design })

  const overrides = isRecord(design.overrides) ? (design.overrides as DesignPayload) : {}
  const pageTypes = isRecord(overrides.page_types) ? (overrides.page_types as Record<string, DesignPayload>) : {}
  const pages = isRecord(overrides.pages) ? (overrides.pages as Record<string, DesignPayload>) : {}

  if (ctx.page_type && pageTypes[ctx.page_type]) {
    config = deepMergeDesign(config, pageTypes[ctx.page_type] as DesignPayload)
  }
  if (ctx.page_type === 'page' && ctx.page_slug && pageTypes[ctx.page_slug]) {
    config = deepMergeDesign(config, pageTypes[ctx.page_slug] as DesignPayload)
  }
  if (ctx.page_slug && pages[ctx.page_slug]) {
    config = deepMergeDesign(config, pages[ctx.page_slug] as DesignPayload)
  }

  const colors = (config.colors || {}) as Record<string, string>
  const resolvedColors: Record<string, string> = {}
  for (const [k, v] of Object.entries(colors)) {
    if (typeof v === 'string') resolvedColors[k] = resolveTokenRef(v, config)
  }
  config.colors = resolvedColors

  return config
}

export type ChromeLayer = Record<string, string | boolean>

export function resolveChromeLayer(design: DesignPayload | undefined, ctx: PageWidthContext): ChromeLayer {
  if (!design) return {}
  const tree = isRecord(design.chrome) ? (design.chrome as DesignPayload) : {}
  const global = isRecord(tree.global) ? (tree.global as ChromeLayer) : {}
  let merged: ChromeLayer = { ...global }
  const types = isRecord(tree.page_types) ? (tree.page_types as Record<string, ChromeLayer>) : {}
  const pages = isRecord(tree.pages) ? (tree.pages as Record<string, ChromeLayer>) : {}
  if (ctx.page_type && types[ctx.page_type]) merged = { ...merged, ...types[ctx.page_type] }
  if (ctx.page_type === 'page' && ctx.page_slug && types[ctx.page_slug]) {
    merged = { ...merged, ...types[ctx.page_slug] }
  }
  if (ctx.page_slug && pages[ctx.page_slug]) merged = { ...merged, ...pages[ctx.page_slug] }
  return merged
}

function chromeToCssVars(layer: ChromeLayer, config: DesignPayload): Record<string, string> {
  const vars: Record<string, string> = {}
  const keys: Record<string, string> = {
    topbar_bg: '--s2-chrome-topbar-bg',
    topbar_text: '--s2-chrome-topbar-text',
    header_bg: '--s2-chrome-header-bg',
    header_text: '--s2-chrome-header-text',
    footer_bg: '--s2-chrome-footer-bg',
    footer_text: '--s2-chrome-footer-text',
    footer_heading_text: '--s2-chrome-footer-heading-text',
  }
  for (const [k, cssVar] of Object.entries(keys)) {
    const v = layer[k]
    if (typeof v === 'string' && v) vars[cssVar] = resolveTokenRef(v, config)
  }
  let h = layer.header_height_px
  if (typeof h === 'string' && h) {
    vars['--s2-chrome-header-height'] = /^\d+(\.\d+)?$/.test(h.trim()) ? `${h}px` : h
  }
  if (layer.header_variant === 'compact') {
    vars['--s2-chrome-header-height'] = vars['--s2-chrome-header-height'] || '56px'
  }
  return vars
}

export function applyTypographyRuntime(typography: Record<string, Record<string, string>>, config: DesignPayload): void {
  if (typeof document === 'undefined') return
  let css = ''
  for (const [role, t] of Object.entries(typography)) {
    if (!t || typeof t !== 'object') continue
    const cls = '.s2-t-' + role.replace(/_/g, '-')
    const size = t.size_desktop || ''
    const weight = t.font_weight || ''
    const lh = t.line_height || ''
    const color = t.color ? resolveTokenRef(t.color, config) : ''
    const parts: string[] = []
    if (size) parts.push(`font-size:${size}`)
    if (weight) parts.push(`font-weight:${weight}`)
    if (lh) parts.push(`line-height:${lh}`)
    if (color) parts.push(`color:${color}`)
    if (parts.length) css += `${cls}{${parts.join(';')};}\n`
  }
  let el = document.getElementById('s2nri-typography-runtime') as HTMLStyleElement | null
  if (!el) {
    el = document.createElement('style')
    el.id = 's2nri-typography-runtime'
    document.head.appendChild(el)
  }
  el.textContent = css
}

export function applyChromeVars(layer: ChromeLayer, config: DesignPayload): void {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  Object.entries(chromeToCssVars(layer, config)).forEach(([k, v]) => root.style.setProperty(k, v))
}

export function cssVarName(scope: 'color' | 'space' | 'radius' | 'shadow' | 'width', key: string): string {
  const safe = key.replace(/[^a-z0-9_]/gi, '_').toLowerCase()
  if (scope === 'color') return `--s2-color-${safe}`
  if (scope === 'space') return `--s2-space-${safe}`
  if (scope === 'radius') return `--s2-radius-${safe}`
  if (scope === 'shadow') return `--s2-shadow-${safe}`
  return `--s2-width-${key}`
}

export function applyResolvedDesignToDocument(resolved: DesignPayload, ctx: PageWidthContext): void {
  if (typeof document === 'undefined') return
  const root = document.documentElement

  const colors = (resolved.colors || {}) as Record<string, string>
  Object.entries(colors).forEach(([key, val]) => {
    if (typeof val === 'string' && val) {
      root.style.setProperty(cssVarName('color', key), val)
      if (key === 'primary') root.style.setProperty('--s2-primary', val)
    }
  })

  const spacing = (resolved.spacing || {}) as Record<string, string>
  Object.entries(spacing).forEach(([key, val]) => {
    if (typeof val === 'string' && val) root.style.setProperty(cssVarName('space', key), val)
  })

  const radius = (resolved.radius || {}) as Record<string, string>
  Object.entries(radius).forEach(([key, val]) => {
    if (typeof val === 'string' && val) root.style.setProperty(cssVarName('radius', key), val)
  })

  const shadow = (resolved.shadow || {}) as Record<string, string>
  Object.entries(shadow).forEach(([key, val]) => {
    if (typeof val === 'string' && val) root.style.setProperty(cssVarName('shadow', key), val)
  })

  const components = (resolved.components || {}) as Record<string, string>
  const compMap: Record<string, string> = {
    button_primary_bg: '--s2-btn-primary-bg',
    button_primary_color: '--s2-btn-primary-color',
    button_radius: '--s2-btn-radius',
    card_radius: '--s2-card-radius',
    input_radius: '--s2-input-radius',
  }
  Object.entries(compMap).forEach(([k, varName]) => {
    const raw = components[k]
    if (typeof raw === 'string' && raw) {
      root.style.setProperty(varName, resolveTokenRef(raw, resolved))
    }
  })

  const widthVars = resolveWidthCssVars(resolved, ctx)
  Object.entries(widthVars).forEach(([k, v]) => {
    root.style.setProperty(`--${k}`, v)
  })
  if (widthVars['s2-width-page-max']) {
    root.style.setProperty('--s2-space-container_max', widthVars['s2-width-page-max'])
  }
  if (widthVars['s2-width-padding-x']) {
    root.style.setProperty('--s2-space-page_margin', widthVars['s2-width-padding-x'])
  }

  const chrome = resolveChromeLayer(resolved, ctx)
  applyChromeVars(chrome, resolved)

  const typography = (resolved.typography || {}) as Record<string, Record<string, string>>
  if (Object.keys(typography).length > 0) {
    applyTypographyRuntime(typography, resolved)
  }

  applySectionOverrideCss(resolved)
  applyWidthResponsiveRuntimeCss(resolved, ctx)
}

export function applyWidthResponsiveRuntimeCss(config: DesignPayload, ctx: PageWidthContext): void {
  if (typeof document === 'undefined') return
  const css = buildWidthResponsiveRuntimeCss(config, ctx)
  let el = document.getElementById('s2nri-width-sections-runtime') as HTMLStyleElement | null
  if (!el) {
    el = document.createElement('style')
    el.id = 's2nri-width-sections-runtime'
    document.head.appendChild(el)
  }
  el.textContent = css
}

/** Mirrors DesignSystem::renderSectionOverrideCss for SPA live sync. */
export function applySectionOverrideCss(config: DesignPayload): void {
  if (typeof document === 'undefined') return
  const overrides = isRecord(config.overrides) ? (config.overrides as DesignPayload) : {}
  const sections = isRecord(overrides.sections) ? (overrides.sections as Record<string, DesignPayload>) : {}
  let css = ''
  for (const [sec, ov] of Object.entries(sections)) {
    if (!isRecord(ov)) continue
    const colors = isRecord(ov.colors) ? (ov.colors as Record<string, string>) : {}
    const lines: string[] = []
    for (const [k, v] of Object.entries(colors)) {
      if (typeof v === 'string' && v) {
        lines.push(`  --s2-color-${k.replace(/[^a-z0-9_]/gi, '_').toLowerCase()}:${resolveTokenRef(v, config)};`)
      }
    }
    if (lines.length) {
      const safe = sec.replace(/[^a-z0-9_-]/gi, '_').toLowerCase()
      css += `[data-s2-section="${safe}"]{${lines.join('')}}\n`
    }
  }
  let el = document.getElementById('s2nri-section-overrides-runtime') as HTMLStyleElement | null
  if (!el) {
    el = document.createElement('style')
    el.id = 's2nri-section-overrides-runtime'
    document.head.appendChild(el)
  }
  el.textContent = css
}
