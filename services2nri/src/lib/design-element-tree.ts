/**
 * Page → Section → Element model for the Design System CMS.
 * Maps catalog fields to inspectable elements (content, design, layout, visibility).
 */
import type { ContentFieldDef, DesignSettingFieldDef, SectionCatalogDef } from '@/lib/design-system-catalog'
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

function slugFromSettingKey(key: string): string {
  const base = key.replace(/^css_/, '').replace(/_json$/, '')
  const parts = base.split('_').filter(Boolean)
  return parts.slice(-2).join('_') || base || 'field'
}

function labelFromKey(key: string): string {
  return key
    .replace(/^css_/, '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function inferElementId(field: ContentFieldDef | DesignSettingFieldDef, index: number): string {
  if (field.key.includes('eyebrow')) return 'eyebrow'
  if (field.key.includes('subtitle') || field.key.endsWith('_sub')) return 'subtitle'
  if (field.key.includes('heading') || field.key.endsWith('_title') && !field.key.includes('page')) return 'heading'
  if (field.key.includes('description') || field.key.includes('_text') && !field.key.includes('button')) return 'body'
  if (field.key.includes('cta2') || field.key.includes('_secondary')) return 'secondary_button'
  if (field.key.includes('button') || field.key.includes('_cta')) return 'primary_button'
  if (field.key.includes('_url') || field.key.includes('_link')) return 'link'
  if (field.key.includes('image') || field.key.includes('_img')) return 'image'
  if (field.key.includes('icon')) return 'icon'
  if (field.key.includes('_json')) return 'collection'
  if (field.key.startsWith('css_')) return slugFromSettingKey(field.key)
  return slugFromSettingKey(field.key) || `element_${index}`
}

function pushControl(
  buckets: Map<string, CmsElementNode>,
  elementId: string,
  elementLabel: string,
  control: CmsElementControl,
) {
  const existing = buckets.get(elementId)
  if (existing) {
    existing.controls.push(control)
    return
  }
  buckets.set(elementId, {
    id: elementId,
    label: elementLabel,
    controls: [control],
  })
}

export function buildSectionElementTree(
  pageId: string,
  section: SectionCatalogDef,
): CmsElementNode[] {
  const buckets = new Map<string, CmsElementNode>()

  ;(section.contentFields || []).forEach((field, index) => {
    const elementId = inferElementId(field, index)
    pushControl(buckets, elementId, field.label.split('(')[0].trim() || labelFromKey(elementId), {
      id: `${elementId}__${field.key}`,
      label: field.label,
      group: 'content',
      settingKey: field.key,
      field,
      hideSettingKey: `hide_el_${pageId.replace(/-/g, '_')}_${section.sectionKey}_${elementId}`,
    })
  })

  ;(section.designSettingFields || []).forEach((field, index) => {
    const elementId = field.key.startsWith('css_') ? 'section_surface' : inferElementId(field, index)
    pushControl(buckets, elementId, 'Section styling', {
      id: `design__${field.key}`,
      label: field.label,
      group: field.key.includes('padding') || field.key.includes('gap') || field.key.includes('cols') ? 'layout' : 'design',
      settingKey: field.key,
      field,
      designPath: ['overrides', 'sections', section.sectionKey, 'legacy', field.key],
    })
  })

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

  for (const node of buckets.values()) {
    if (node.id.startsWith('_')) continue
    for (const sf of styleFieldsForElement(node.id)) {
      node.controls.push({
        id: `${node.id}__style__${sf.key}`,
        label: sf.label,
        group: 'design',
        designPath: elementStylePath(section.sectionKey, node.id, sf.key),
        elementStyleKey: sf.key,
      })
    }
  }

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

  return Array.from(buckets.values())
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
