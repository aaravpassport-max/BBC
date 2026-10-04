/**
 * Client-side design config resolver (mirrors DesignSystem::resolve + token refs).
 */
import type { PageWidthContext } from '@/lib/width-layout'
import { resolveWidthCssVars } from '@/lib/width-layout'

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
}
