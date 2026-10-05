import type { CSSProperties } from 'react'
import { ELEMENT_STYLE_FIELDS } from '@/lib/element-style-fields'
import { resolveTokenRef, type DesignPayload } from '@/lib/design-resolve'
import { getRuntimeDesignConfig } from '@/lib/apply-design-config'

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

export function readElementStyleTokens(
  design: DesignPayload | undefined,
  sectionKey: string,
  elementId: string,
): Record<string, string> {
  if (!design) return {}
  const overrides = isRecord(design.overrides) ? (design.overrides as DesignPayload) : {}
  const sections = isRecord(overrides.sections) ? (overrides.sections as Record<string, DesignPayload>) : {}
  const section = sections[sectionKey]
  if (!isRecord(section)) return {}
  const elements = isRecord(section.elements) ? (section.elements as Record<string, Record<string, unknown>>) : {}
  const raw = elements[elementId]
  if (!isRecord(raw)) return {}
  const out: Record<string, string> = {}
  for (const [k, v] of Object.entries(raw)) {
    if (v === undefined || v === null || v === '') continue
    out[k] = String(v)
  }
  return out
}

export function elementStyleToReactStyle(
  tokens: Record<string, string>,
  design?: DesignPayload,
): CSSProperties {
  const cfg = design || (getRuntimeDesignConfig()?.design as DesignPayload | undefined)
  const style: CSSProperties = {}
  for (const def of ELEMENT_STYLE_FIELDS) {
    const raw = tokens[def.key]
    if (!raw) continue
    let val = raw.trim()
    if (!val) continue
    if (def.type === 'px' && /^\d+(\.\d+)?$/.test(val)) val = `${val}px`
    if (def.key === 'color' || def.key === 'background' || def.key === 'border_color') {
      val = cfg ? resolveTokenRef(val, cfg) : val
    }
    const camel = def.cssProperty.replace(/-([a-z])/g, (_, c: string) => c.toUpperCase()) as keyof CSSProperties
    ;(style as Record<string, string>)[camel as string] = val
  }
  if (tokens.border_width && !tokens.border_color) {
    style.borderStyle = 'solid'
  }
  return style
}

export function resolveCmsElementStyle(sectionKey: string, elementId: string): CSSProperties {
  const design = getRuntimeDesignConfig()?.design as DesignPayload | undefined
  const tokens = readElementStyleTokens(design, sectionKey, elementId)
  return elementStyleToReactStyle(tokens, design)
}
