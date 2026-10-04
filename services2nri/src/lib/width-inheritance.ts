/**
 * Width override inheritance — store only explicit overrides; resolve parents at runtime.
 */
import { GLOBAL_KEYS, type GlobalKey } from '@/pages/admin/width-layout-shared'
import { mergeWidthContext, type PageWidthContext } from '@/lib/width-layout'

type WidthLayer = Record<string, unknown>
type WidthTree = Record<string, unknown>

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

function normToken(v: unknown): string {
  if (v == null || v === 'inherit') return ''
  if (isRecord(v)) {
    const bps = ['desktop', 'laptop', 'tablet', 'mobile'] as const
    return bps.map((bp) => String(v[bp] ?? '').trim()).join('|')
  }
  return String(v).trim()
}

export function widthTokenEquals(a: unknown, b: unknown): boolean {
  return normToken(a) === normToken(b)
}

function stripSections(layer: WidthLayer): WidthLayer {
  const { sections: _s, ...rest } = layer
  return rest
}

function pruneLayerAgainstParent(layer: WidthLayer, parent: WidthLayer): WidthLayer {
  const out: WidthLayer = { ...layer }
  for (const key of GLOBAL_KEYS) {
    if (!(key in out)) continue
    if (out[key] === null) {
      delete out[key]
      continue
    }
    if (widthTokenEquals(out[key], parent[key])) {
      delete out[key]
    }
  }
  return out
}

function isEmptyWidthLayer(layer: WidthLayer): boolean {
  return GLOBAL_KEYS.every((k) => layer[k] == null)
}

function pruneSectionMap(
  sections: Record<string, WidthLayer>,
  parentForSection: (section: string) => WidthLayer,
): Record<string, WidthLayer> {
  const out: Record<string, WidthLayer> = {}
  for (const [sec, layer] of Object.entries(sections)) {
    if (!isRecord(layer)) continue
    const pruned = pruneLayerAgainstParent(layer, parentForSection(sec))
    if (!isEmptyWidthLayer(pruned)) out[sec] = pruned
  }
  return out
}

function cloneWidths(widths: WidthTree): WidthTree {
  return JSON.parse(JSON.stringify(widths)) as WidthTree
}

/** Remove width keys that match their inherited parent (persist sparse overrides only). */
export function pruneInheritedWidthLayers(widths: WidthTree): WidthTree {
  const next = cloneWidths(widths)
  const global = (isRecord(next.global) ? next.global : {}) as WidthLayer

  const pageTypes = isRecord(next.page_types) ? { ...next.page_types } : {}
  for (const ptKey of Object.keys(pageTypes)) {
    const raw = pageTypes[ptKey]
    if (!isRecord(raw)) {
      delete pageTypes[ptKey]
      continue
    }
    const ctx: PageWidthContext = { page_type: ptKey, page_slug: ptKey === 'home' ? 'home' : ptKey }
    const widthsBelowPt = cloneWidths({ ...next, page_types: {} })
    for (const k of Object.keys(pageTypes)) {
      if (k === ptKey) continue
      if (isRecord(pageTypes[k])) (widthsBelowPt.page_types as Record<string, unknown>)[k] = pageTypes[k]
    }
    const parent = mergeWidthContext({ widths: widthsBelowPt }, ctx)
    const pageLayer = stripSections(raw)
    const prunedPage = pruneLayerAgainstParent(pageLayer, parent)

    const sections = isRecord(raw.sections) ? (raw.sections as Record<string, WidthLayer>) : {}
    const ptParent = mergeWidthContext(
      { widths: { ...next, page_types: { ...pageTypes, [ptKey]: prunedPage } } },
      ctx,
    )
    const prunedSections = pruneSectionMap(sections, () => ptParent)

    const entry: WidthLayer = { ...prunedPage }
    if (Object.keys(prunedSections).length) entry.sections = prunedSections
    if (isEmptyWidthLayer(entry) && !entry.sections) delete pageTypes[ptKey]
    else pageTypes[ptKey] = entry
  }
  next.page_types = pageTypes

  const pages = isRecord(next.pages) ? { ...next.pages } : {}
  for (const slug of Object.keys(pages)) {
    const raw = pages[slug]
    if (!isRecord(raw)) {
      delete pages[slug]
      continue
    }
    const ctx: PageWidthContext = { page_type: 'page', page_slug: slug }
    const parent = mergeWidthContext({ widths: { ...next, pages: {} } }, ctx)
    const pruned = pruneLayerAgainstParent(raw, parent)
    if (isEmptyWidthLayer(pruned)) delete pages[slug]
    else pages[slug] = pruned
  }
  next.pages = pages

  const sections = isRecord(next.sections) ? (next.sections as Record<string, WidthLayer>) : {}
  next.sections = pruneSectionMap(sections, () => global)

  if (isRecord(next.service_page)) {
    const sp = { ...next.service_page } as WidthLayer
    const spParent = mergeWidthContext(
      { widths: { ...next, service_page: {} } },
      { page_type: 'service', page_slug: 'service' },
    )
    const spSections = isRecord(sp.sections) ? (sp.sections as Record<string, WidthLayer>) : {}
    const spBase = pruneLayerAgainstParent(stripSections(sp), spParent)
    const spSectionsPruned = pruneSectionMap(spSections, () =>
      mergeWidthContext({ widths: { ...next, service_page: spBase } }, { page_type: 'service', page_slug: 'service' }),
    )
    const outSp: WidthLayer = { ...spBase }
    if (Object.keys(spSectionsPruned).length) outSp.sections = spSectionsPruned
    if (isEmptyWidthLayer(outSp) && !outSp.sections) delete next.service_page
    else next.service_page = outSp
  }

  return next
}

export type WidthAdminScope =
  | 'global'
  | 'page_type'
  | 'page_type_section'
  | 'page'
  | 'service_section'
  | 'section'

/** Merged width layer below the current admin scope (for “Inherited” preview). */
export function parentWidthLayerForScope(
  design: Record<string, unknown> | undefined,
  scope: WidthAdminScope,
  opts: {
    selectedType: string
    selectedPage: string
    selectedSection: string
  },
): WidthLayer {
  const widths = isRecord(design?.widths) ? design!.widths! : {}
  const global = (isRecord(widths.global) ? widths.global : {}) as WidthLayer

  if (scope === 'global') return {}

  if (scope === 'page_type') {
    return global
  }

  if (scope === 'page_type_section') {
    const w = cloneWidths(widths)
    const home = isRecord(w.page_types) ? (w.page_types as Record<string, unknown>).home : null
    if (isRecord(home) && isRecord(home.sections)) {
      const { sections: _s, ...rest } = home
      ;(w.page_types as Record<string, unknown>).home = rest
    }
    return mergeWidthContext({ widths: w }, { page_type: 'home', page_slug: 'home' })
  }

  if (scope === 'page') {
    return mergeWidthContext(
      { widths: { ...widths, pages: {} } },
      { page_type: 'page', page_slug: opts.selectedPage },
    )
  }

  if (scope === 'service_section') {
    const w = cloneWidths(widths)
    const sp = isRecord(w.service_page) ? (w.service_page as WidthLayer) : null
    if (isRecord(sp) && isRecord(sp.sections)) {
      const sec = { ...(sp.sections as Record<string, unknown>) }
      delete sec[opts.selectedSection]
      w.service_page = { ...sp, sections: sec }
    }
    return mergeWidthContext({ widths: w }, { page_type: 'service', page_slug: 'service' })
  }

  return mergeWidthContext(design, { page_type: 'home', page_slug: 'home' })
}

export function layerHasOverride(layer: Record<string, unknown>, key: GlobalKey): boolean {
  const v = layer[key]
  return v != null && v !== 'inherit'
}
