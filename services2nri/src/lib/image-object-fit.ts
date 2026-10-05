import type { CSSProperties } from 'react'

/** Valid CSS object-fit values exposed in Design System controls. */
export const IMAGE_OBJECT_FIT_OPTIONS = [
  'fill',
  'cover',
  'contain',
  'none',
  'scale-down',
] as const

export type ImageObjectFit = (typeof IMAGE_OBJECT_FIT_OPTIONS)[number]

export const GLOBAL_IMAGE_FIT_SETTING_KEY = 'css_global_image_object_fit'

/** Platform setting key per public `data-s2-section` band. */
export const SECTION_IMAGE_FIT_SETTING_KEYS: Record<string, string> = {
  hero: 'css_hero_image_object_fit',
  home_services: 'css_svc_image_object_fit',
  cities: 'css_cities_image_object_fit',
  testimonials: 'css_testimonials_image_object_fit',
  about: 'css_about_image_object_fit',
  directory: 'css_services_dir_image_object_fit',
  intro: 'css_marketing_intro_image_object_fit',
  team: 'css_team_image_object_fit',
}

export const SERVICE_HERO_IMAGE_FIT_KEY = 'css_service_hero_image_object_fit'

export function normalizeImageObjectFit(raw: string | undefined | null): ImageObjectFit | '' {
  const v = String(raw ?? '').trim().toLowerCase()
  if (!v) return ''
  return (IMAGE_OBJECT_FIT_OPTIONS as readonly string[]).includes(v) ? (v as ImageObjectFit) : ''
}

export function resolveImageObjectFit(
  settings: Record<string, string>,
  sectionKey?: string,
): ImageObjectFit {
  if (sectionKey) {
    const settingKey = SECTION_IMAGE_FIT_SETTING_KEYS[sectionKey]
    if (settingKey) {
      const section = normalizeImageObjectFit(settings[settingKey])
      if (section) return section
    }
  }
  const global = normalizeImageObjectFit(settings[GLOBAL_IMAGE_FIT_SETTING_KEY])
  return global || 'fill'
}

/** Map object-fit to background-size for hero slide backgrounds. */
export function imageFitToBackgroundSize(fit: ImageObjectFit): string {
  switch (fit) {
    case 'fill':
      return '100% 100%'
    case 'cover':
      return 'cover'
    case 'contain':
      return 'contain'
    case 'none':
      return 'auto'
    case 'scale-down':
      return 'contain'
    default:
      return '100% 100%'
  }
}

export function sectionImageFitStyle(
  settings: Record<string, string>,
  sectionKey: string,
  settingKeyOverride?: string,
): CSSProperties {
  let fit: ImageObjectFit
  if (settingKeyOverride) {
    fit = normalizeImageObjectFit(settings[settingKeyOverride]) || resolveImageObjectFit(settings)
  } else {
    fit = resolveImageObjectFit(settings, sectionKey)
  }
  const vars: Record<string, string> = {
    '--s2-section-image-object-fit': fit,
  }
  if (sectionKey === 'hero') {
    vars['--s2-hero-bg-size'] = imageFitToBackgroundSize(fit)
  }
  return vars as CSSProperties
}

export function globalImageFitStyle(settings: Record<string, string>): CSSProperties {
  const fit = resolveImageObjectFit(settings)
  return { '--s2-image-object-fit': fit } as CSSProperties
}

export function imageFitPlatformField(settingKey: string, label = 'Image fit'): {
  key: string
  label: string
  type: 'select'
  options: string[]
  placeholder?: string
  hint?: string
} {
  return {
    key: settingKey,
    label,
    type: 'select',
    options: [...IMAGE_OBJECT_FIT_OPTIONS],
    placeholder: 'fill',
    hint: 'How images fill their frame. Default is fill; use cover or contain when you need cropping or letterboxing.',
  }
}

export const ALL_IMAGE_FIT_PUBLIC_KEYS: string[] = [
  GLOBAL_IMAGE_FIT_SETTING_KEY,
  SERVICE_HERO_IMAGE_FIT_KEY,
  ...Object.values(SECTION_IMAGE_FIT_SETTING_KEYS),
]
