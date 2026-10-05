/** Platform section show/hide — `1` = hidden, `0` or empty = visible. */

export function sectionHidden(settings: Record<string, string>, hideSettingKey?: string): boolean {
  if (!hideSettingKey) return false
  return String(settings[hideSettingKey] ?? '0') === '1'
}

export function sanitizeTemplatePageId(pageId: string): string {
  return pageId.replace(/-/g, '_')
}

/** Design System hide key for a page template band (non-home). */
export function templateHideSettingKey(pageId: string, sectionKey: string): string {
  return `hide_tmpl_${sanitizeTemplatePageId(pageId)}_${sectionKey}`
}

export function isTemplateSectionHidden(
  settings: Record<string, string>,
  pageId: string,
  sectionKey: string,
  hideSettingKey?: string,
): boolean {
  const key = hideSettingKey || templateHideSettingKey(pageId, sectionKey)
  return sectionHidden(settings, key)
}

/** Map service CMS section type → design-system sectionKey for template hide flags. */
export const SERVICE_CMS_TYPE_TO_SECTION_KEY: Record<string, string> = {
  trust_badges: 'trust_badges',
  why_choose: 'features',
  features: 'features',
  highlights: 'features',
  description: 'description',
  security: 'description',
  text: 'cms',
  notes: 'cms',
  charges: 'pricing',
  faq: 'faq',
  cta: 'cta',
  process: 'process',
  benefits: 'benefits',
  documents: 'documents',
  eligibility: 'documents',
  testimonials: 'testimonials',
  related: 'related',
}

export function serviceCmsSectionHidden(
  settings: Record<string, string>,
  sectionType: string,
): boolean {
  const sk = SERVICE_CMS_TYPE_TO_SECTION_KEY[sectionType]
  if (!sk) return false
  return isTemplateSectionHidden(settings, 'service', sk)
}
