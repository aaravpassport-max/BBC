/**
 * Canonical `data-s2-element` ids on the public site ↔ catalog setting keys.
 * Single source of truth for Elements tab grouping, hide_el_* keys, and design paths.
 */
import type { ContentFieldDef } from '@/lib/design-system-catalog'

export type CanonicalElement = { id: string; label: string }

/** Public DOM elements per page + section (must match CmsElement markers). */
export const CANONICAL_SECTION_ELEMENTS: Record<string, Record<string, CanonicalElement[]>> = {
  home: {
    hero: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Main heading' },
      { id: 'body', label: 'Description' },
      { id: 'primary_button', label: 'Primary button' },
      { id: 'secondary_button', label: 'Secondary button' },
    ],
    notice: [
      { id: 'body', label: 'Notice text' },
      { id: 'link', label: 'WhatsApp link' },
    ],
    search: [
      { id: 'collection', label: 'Search field' },
      { id: 'primary_button', label: 'Search button' },
    ],
    home_services: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'subtitle', label: 'Subtitle' },
      { id: 'collection', label: 'Category tabs' },
      { id: 'grid', label: 'Service cards grid' },
      { id: 'link', label: 'View all link' },
    ],
    cities: [
      { id: 'heading', label: 'Section heading' },
      { id: 'subtitle', label: 'Subtitle' },
      { id: 'collection', label: 'City cards' },
    ],
    stats: [{ id: 'collection', label: 'Stat cards' }],
    tagline: [{ id: 'body', label: 'Quote text' }],
    features: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'collection', label: 'Feature cards' },
    ],
    testimonials: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'collection', label: 'Testimonial cards' },
    ],
    process: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'step_card', label: 'Step cards (shared look)' },
      ...([1, 2, 3, 4, 5, 6] as const).flatMap((n) => [
        { id: `step_${n}`, label: `Step ${n} card` },
        { id: `step_${n}_icon`, label: `Step ${n} icon` },
        { id: `step_${n}_title`, label: `Step ${n} title` },
        { id: `step_${n}_desc`, label: `Step ${n} description` },
      ]),
      { id: 'collection', label: 'Steps grid layout' },
      { id: 'link', label: 'Footer link' },
    ],
    press: [
      { id: 'eyebrow', label: 'Strip label' },
      { id: 'collection', label: 'Logo row' },
    ],
    partners: [
      { id: 'eyebrow', label: 'Strip label' },
      { id: 'collection', label: 'Partner logos' },
    ],
    about: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'body', label: 'Primary body' },
      { id: 'body_secondary', label: 'Secondary body' },
      { id: 'primary_button', label: 'Primary CTA' },
      { id: 'secondary_button', label: 'WhatsApp CTA' },
    ],
    awards: [
      { id: 'eyebrow', label: 'Strip label' },
      { id: 'collection', label: 'Award badges' },
    ],
    faq: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'collection', label: 'FAQ list' },
      { id: 'link', label: 'Footer link' },
    ],
    newsletter: [
      { id: 'heading', label: 'Title' },
      { id: 'body', label: 'Supporting text' },
      { id: 'collection', label: 'Email field' },
      { id: 'primary_button', label: 'Subscribe button' },
    ],
    app: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Title' },
      { id: 'body', label: 'Subtitle' },
      { id: 'collection', label: 'Store badges' },
    ],
    locations: [
      { id: 'eyebrow', label: 'Strip label' },
      { id: 'collection', label: 'City pills' },
    ],
    header: [
      { id: 'whatsapp_button', label: 'WhatsApp button' },
      { id: 'service_request_button', label: 'Service request button' },
      { id: 'dashboard_button', label: 'Dashboard button' },
      { id: 'sign_in_button', label: 'Sign in button' },
    ],
  },
  blog: {
    hero: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Page title' },
      { id: 'subtitle', label: 'Subtitle' },
    ],
  },
  service: {
    marquee: [{ id: 'body', label: 'Marquee text' }],
    hero: [
      { id: 'badge', label: 'Category badge' },
      { id: 'heading', label: 'Hero title' },
      { id: 'subtitle', label: 'Hero subtitle' },
      { id: 'primary_button', label: 'Primary CTA' },
      { id: 'secondary_button', label: 'Secondary CTA' },
      { id: 'meta_chips', label: 'Meta chips' },
    ],
    wizard: [
      { id: 'service_summary', label: 'Wizard header' },
      { id: 'step_tracker', label: 'Step tracker' },
      { id: 'step_heading', label: 'Active step title' },
    ],
  },
}

/** Explicit setting key → DOM element id (overrides inference). */
const FIELD_ELEMENT_MAP: Record<string, string> = {
  hero_subheading: 'eyebrow',
  hero_heading_1: 'heading',
  hero_heading_2: 'heading',
  hero_description: 'body',
  hero_cta_text: 'primary_button',
  hero_cta_url: 'primary_button',
  hero_cta2_text: 'secondary_button',
  hero_cta2_url: 'secondary_button',
  home_notice_text: 'body',
  home_notice_whatsapp_label: 'link',
  home_tagline: 'body',
  home_press_label: 'eyebrow',
  home_partners_label: 'eyebrow',
  home_awards_label: 'eyebrow',
  home_locations_label: 'eyebrow',
  about_text: 'body',
  about_text_secondary: 'body_secondary',
  about_cta_text: 'primary_button',
  about_whatsapp_cta: 'secondary_button',
  newsletter_title: 'heading',
  newsletter_subtitle: 'body',
  app_subtitle: 'body',
  faq_footer_link_text: 'link',
  hiw_footer_link_text: 'link',
  hiw_eyebrow: 'eyebrow',
  hiw_title: 'heading',
  css_how_bg: 'collection',
  css_how_cols: 'collection',
  css_how_gap: 'collection',
  css_how_card_bg: 'step_card',
  css_how_card_border: 'step_card',
  css_how_icon_bg: 'step_card',
  css_how_icon_color: 'step_card',
  css_how_card_title_color: 'step_card',
  css_how_card_desc_color: 'step_card',
  services_view_all_text: 'link',
  header_whatsapp_label: 'whatsapp_button',
  header_service_request_text: 'service_request_button',
  header_service_request_url: 'service_request_button',
  header_sign_in_text: 'sign_in_button',
  header_dashboard_text: 'dashboard_button',
}

export function canonicalElementsForSection(pageId: string, sectionKey: string): CanonicalElement[] {
  return CANONICAL_SECTION_ELEMENTS[pageId]?.[sectionKey] ?? []
}

export function resolveCatalogElementId(
  pageId: string,
  sectionKey: string,
  field: ContentFieldDef | { key: string; elementId?: string },
  index = 0,
): string {
  if (field.elementId) return field.elementId
  const mapped = FIELD_ELEMENT_MAP[field.key]
  if (mapped) return mapped

  const key = field.key
  const hiwStep = key.match(/^hiw_step(\d+)_(title|desc|icon)$/)
  if (hiwStep) {
    const n = hiwStep[1]
    const part = hiwStep[2]
    if (part === 'title') return `step_${n}_title`
    if (part === 'desc') return `step_${n}_desc`
    return `step_${n}_icon`
  }
  if (key.includes('eyebrow')) return 'eyebrow'
  if (key.includes('subheading')) return 'eyebrow'
  if (key.endsWith('_label') && !key.includes('nav_')) return 'eyebrow'
  if (key.includes('about_text_secondary')) return 'body_secondary'
  if (key.includes('whatsapp') && (key.includes('cta') || key.includes('label'))) {
    return key.includes('header') ? 'whatsapp_button' : key.includes('notice') ? 'link' : 'secondary_button'
  }
  if (key.includes('cta2') || key.includes('_secondary')) return 'secondary_button'
  if (key.includes('footer_link') || key.includes('view_all_text')) return 'link'
  if (key.includes('subtitle') || key.endsWith('_sub')) {
    if (key.includes('newsletter') || key.startsWith('app_')) return 'body'
    return 'subtitle'
  }
  if ((key.includes('heading') || key.endsWith('_title')) && !key.includes('page')) return 'heading'
  if (key.includes('description')) return 'body'
  if (key.includes('_text') && !key.includes('button') && !key.includes('link')) return 'body'
  if (key.includes('button') || key.includes('_cta')) return 'primary_button'
  if (key.includes('_url') || key.includes('_link')) return 'link'
  if (key.includes('_json')) return 'collection'
  if (key.startsWith('css_')) {
    const parts = key.replace(/^css_/, '').split('_').filter(Boolean)
    return parts.slice(-2).join('_') || 'section_surface'
  }

  const canonical = canonicalElementsForSection(pageId, sectionKey)
  if (canonical.length === 1) return canonical[0].id

  return `field_${index}`
}

export function elementNodeLabel(pageId: string, sectionKey: string, elementId: string, fallback: string): string {
  const hit = canonicalElementsForSection(pageId, sectionKey).find((e) => e.id === elementId)
  return hit?.label || fallback
}
