import { SECTION_DESIGN_COLOR_KEYS, SECTION_TYPOGRAPHY_ROLES, type SectionCatalogDef } from '@/lib/design-system-catalog'

type PatchFn = (path: string[], value: unknown) => void

export function clearSectionDesignConfig(sectionKey: string, patch: PatchFn): void {
  if (!sectionKey || sectionKey === '_page') return
  SECTION_DESIGN_COLOR_KEYS.forEach((colorKey) => {
    patch(['overrides', 'sections', sectionKey, 'colors', colorKey], null)
  })
  SECTION_TYPOGRAPHY_ROLES.forEach((role) => {
    ;(['size_desktop', 'size_tablet', 'size_mobile', 'font_weight', 'line_height', 'color'] as const).forEach((field) => {
      patch(['overrides', 'sections', sectionKey, 'typography', role, field], null)
    })
  })
}

export function contentAndDesignKeysForSection(section: SectionCatalogDef, pageContentFields?: SectionCatalogDef['contentFields']): string[] {
  const keys: string[] = []
  if (section.isPageScope && pageContentFields) {
    pageContentFields.forEach((f) => keys.push(f.key))
  } else {
    section.contentFields?.forEach((f) => keys.push(f.key))
  }
  section.designSettingFields?.forEach((f) => keys.push(f.key))
  if (section.hideSettingKey) keys.push(section.hideSettingKey)
  return keys
}
