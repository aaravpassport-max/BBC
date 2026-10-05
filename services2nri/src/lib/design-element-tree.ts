/**
 * Page → Section → Element model for the Design System CMS.
 * Maps catalog fields to inspectable elements (content, design, layout, visibility).
 */
import type { ContentFieldDef, DesignSettingFieldDef, SectionCatalogDef } from '@/lib/design-system-catalog'
import {
  canonicalElementsForSection,
  elementNodeLabel,
  resolveCatalogElementId,
} from '@/lib/cms-element-registry'
import { sectionElementPresets } from '@/lib/section-element-presets'
import { templateHideSettingKey } from '@/lib/section-visibility'
import { elementStylePath, styleFieldsForElement } from '@/lib/element-style-fields'

export type ElementControlGroup = 'content' | 'design' | 'layout' | 'visibility' | 'responsive'

export type CmsElementControl = {
  id: string
  label: string
  group: ElementControlGroup
  /** Platform settings key (content / legacy CSS on settings). */
  settingKey?: string
  field?: ContentFieldDef | DesignSettingFieldDef
  /** Design JSON path under saved design config. */
  designPath?: string[]
  /** Per-element visibility (optional). */
  hideSettingKey?: string
  /** Element styler field metadata (Design group). */
  elementStyleKey?: string
}

export type CmsElementNode = {
  id: string
  label: string
  description?: string
  controls: CmsElementControl[]
}

function labelFromKey(key: string): string {
  return key
    .replace(/^css_/, '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function pushControl(
  buckets: Map<string, CmsElementNode>,
  elementId: string,
  elementLabel: string,
  control: CmsElementControl,
) {
  const existing = buckets.get(elementId)
  if (existing) {
    if (existing.label === elementId || existing.label.startsWith('field_')) {
      existing.label = elementLabel
    }
    existing.controls.push(control)
    return
  }
  buckets.set(elementId, {
    id: elementId,
    label: elementLabel,
    controls: [control],
  })
}

function ensureCanonicalNodes(
  pageId: string,
  sectionKey: string,
  buckets: Map<string, CmsElementNode>,
): void {
  for (const el of canonicalElementsForSection(pageId, sectionKey)) {
    if (!buckets.has(el.id)) {
      buckets.set(el.id, { id: el.id, label: el.label, controls: [] })
    } else {
      const node = buckets.get(el.id)!
      if (!node.label || node.label === el.id) node.label = el.label
    }
  }
}

function attachElementStyleControls(
  pageId: string,
  sectionKey: string,
  buckets: Map<string, CmsElementNode>,
): void {
  const canonical = canonicalElementsForSection(pageId, sectionKey)
  const canonicalIds = new Set(canonical.map((e) => e.id))
  const styleTargetIds =
    canonicalIds.size > 0
      ? [...buckets.keys()].filter((id) => canonicalIds.has(id))
      : [...buckets.keys()].filter((id) => !id.startsWith('_') && !id.startsWith('field_'))

  for (const elementId of styleTargetIds) {
    const node = buckets.get(elementId)
    if (!node) continue
    const existingStyleIds = new Set(
      node.controls.filter((c) => c.group === 'design' && c.elementStyleKey).map((c) => c.elementStyleKey),
    )
    for (const sf of styleFieldsForElement(elementId)) {
      if (existingStyleIds.has(sf.key)) continue
      node.controls.push({
        id: `${elementId}__style__${sf.key}`,
        label: sf.label,
        group: 'design',
        designPath: elementStylePath(sectionKey, elementId, sf.key),
        elementStyleKey: sf.key,
      })
    }
  }
}

export function buildSectionElementTree(
  pageId: string,
  section: SectionCatalogDef,
): CmsElementNode[] {
  const buckets = new Map<string, CmsElementNode>()

  ;(section.contentFields || []).forEach((field, index) => {
    const elementId = resolveCatalogElementId(pageId, section.sectionKey, field, index)
    const label = elementNodeLabel(
      pageId,
      section.sectionKey,
      elementId,
      field.label.split('(')[0].trim() || labelFromKey(elementId),
    )
    pushControl(buckets, elementId, label, {
      id: `${elementId}__${field.key}`,
      label: field.label,
      group: 'content',
      settingKey: field.key,
      field,
      hideSettingKey: `hide_el_${pageId.replace(/-/g, '_')}_${section.sectionKey}_${elementId}`,
    })
  })

  ;(section.designSettingFields || []).forEach((field, index) => {
    const elementId = resolveCatalogElementId(pageId, section.sectionKey, field, index)
    const nodeLabel = elementNodeLabel(pageId, section.sectionKey, elementId, field.label)
    pushControl(buckets, elementId, nodeLabel, {
      id: `design__${field.key}`,
      label: field.label,
      group: field.key.includes('padding') || field.key.includes('gap') || field.key.includes('cols') ? 'layout' : 'design',
      settingKey: field.key,
      field,
      designPath: ['overrides', 'sections', section.sectionKey, 'legacy', field.key],
    })
  })

  ensureCanonicalNodes(pageId, section.sectionKey, buckets)

  if (section.hideSettingKey) {
    pushControl(buckets, '_section', 'Entire section', {
      id: 'section_visibility',
      label: 'Section visibility',
      group: 'visibility',
      settingKey: section.hideSettingKey,
    })
  } else if (!section.isPageScope && section.sectionKey !== '_page') {
    pushControl(buckets, '_section', 'Entire section', {
      id: 'section_visibility',
      label: 'Section visibility',
      group: 'visibility',
      settingKey: templateHideSettingKey(pageId, section.sectionKey),
    })
  }

  pushControl(buckets, '_layout', 'Layout & width', {
    id: 'width_layout',
    label: 'Width & responsive layout',
    group: 'layout',
    designPath: ['widths', 'sections', section.sectionKey],
  })

  pushControl(buckets, '_responsive', 'Responsive typography', {
    id: 'typography_responsive',
    label: 'Heading / body sizes (desktop · tablet · mobile)',
    group: 'responsive',
    designPath: ['overrides', 'sections', section.sectionKey, 'typography'],
  })

  for (const preset of sectionElementPresets(pageId, section.sectionKey)) {
    const existing = buckets.get(preset.id)
    if (existing) {
      if (preset.description && !existing.description) existing.description = preset.description
      continue
    }
    buckets.set(preset.id, {
      id: preset.id,
      label: preset.label,
      description: preset.description,
      controls: preset.controls,
    })
  }

  attachElementStyleControls(pageId, section.sectionKey, buckets)

  const canonical = canonicalElementsForSection(pageId, section.sectionKey)
  const order = canonical.length
    ? [...canonical.map((c) => c.id), '_section', '_layout', '_responsive']
    : null

  const nodes = Array.from(buckets.values())
  if (!order) return nodes

  const rank = new Map(order.map((id, i) => [id, i]))
  return nodes.sort((a, b) => {
    const ra = rank.has(a.id) ? rank.get(a.id)! : 500 + a.label.localeCompare(b.label)
    const rb = rank.has(b.id) ? rank.get(b.id)! : 500 + b.label.localeCompare(a.label)
    return ra - rb || a.label.localeCompare(b.label)
  })
}

/** Collect all platform setting keys declared for a page in the catalog. */
export function collectPageSettingKeys(sections: SectionCatalogDef[], pageContentFields?: ContentFieldDef[]): string[] {
  const keys = new Set<string>()
  for (const f of pageContentFields || []) keys.add(f.key)
  for (const s of sections) {
    for (const f of s.contentFields || []) keys.add(f.key)
    for (const f of s.designSettingFields || []) keys.add(f.key)
    if (s.hideSettingKey) keys.add(s.hideSettingKey)
  }
  return [...keys]
}
