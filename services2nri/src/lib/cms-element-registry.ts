/**
 * Canonical `data-s2-element` ids on the public site ↔ catalog setting keys.
 * Single source of truth for Elements tab grouping, hide_el_* keys, and design paths.
 */
import type { ContentFieldDef } from '@/lib/design-system-catalog'
import {
  AWARD_ITEM_PARTS,
  FAQ_ITEM_PARTS,
  FEATURE_ITEM_PARTS,
  HOME_LIST_LIMITS,
  CITY_GRID_ITEM_PARTS,
  PARTNER_ITEM_PARTS,
  PRESS_ITEM_PARTS,
  SERVICE_DIRECTORY_ITEM_PARTS,
  SERVICE_GRID_ITEM_PARTS,
  STAT_ITEM_PARTS,
  TESTIMONIAL_ITEM_PARTS,
  listItemCanonical,
} from '@/lib/cms-home-list-fields'
import {
  BRAND_ROW_ITEM_PARTS,
  HIGHLIGHT_ITEM_PARTS,
  HOURS_ITEM_PARTS,
  MARKETING_LIST_LIMITS,
  PAGE_STEP_ITEM_PARTS,
  PLAN_ITEM_PARTS,
  TEAM_ITEM_PARTS,
  VALUE_ITEM_PARTS,
  marketingListItemCanonical,
} from '@/lib/cms-marketing-list-fields'

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
      { id: 'heading', label: 'Section heading' },
      { id: 'subtitle', label: 'Supporting text' },
      { id: 'collection', label: 'Search field' },
      { id: 'primary_button', label: 'Search button' },
    ],
    home_services: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'subtitle', label: 'Subtitle' },
      { id: 'collection', label: 'Category tabs' },
      { id: 'grid', label: 'Service cards grid layout' },
      { id: 'service_card', label: 'Service cards (shared look)' },
      ...listItemCanonical('service', HOME_LIST_LIMITS.serviceGrid, 'Service', SERVICE_GRID_ITEM_PARTS),
      { id: 'link', label: 'View all link' },
    ],
    cities: [
      { id: 'heading', label: 'Section heading' },
      { id: 'subtitle', label: 'Subtitle' },
      { id: 'collection', label: 'City cards grid layout' },
      { id: 'city_card', label: 'City cards (shared look)' },
      ...listItemCanonical('city', HOME_LIST_LIMITS.cityGrid, 'City', CITY_GRID_ITEM_PARTS),
    ],
    stats: [
      { id: 'stat_item', label: 'Stat items (shared look)' },
      ...listItemCanonical('stat', HOME_LIST_LIMITS.stats, 'Stat', STAT_ITEM_PARTS),
      { id: 'collection', label: 'Stats grid layout' },
    ],
    tagline: [{ id: 'body', label: 'Quote text' }],
    features: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'feature_card', label: 'Feature cards (shared look)' },
      ...listItemCanonical('feature', HOME_LIST_LIMITS.features, 'Feature', FEATURE_ITEM_PARTS),
      { id: 'collection', label: 'Feature grid layout' },
    ],
    testimonials: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'testimonial_card', label: 'Testimonial cards (shared look)' },
      ...listItemCanonical('testimonial', HOME_LIST_LIMITS.testimonials, 'Testimonial', TESTIMONIAL_ITEM_PARTS),
      { id: 'collection', label: 'Testimonials carousel' },
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
      { id: 'press_chip', label: 'Press chips (shared look)' },
      ...listItemCanonical('press', HOME_LIST_LIMITS.press, 'Press logo', PRESS_ITEM_PARTS),
      { id: 'collection', label: 'Logo row layout' },
    ],
    partners: [
      { id: 'eyebrow', label: 'Strip label' },
      { id: 'partner_chip', label: 'Partner chips (shared look)' },
      ...listItemCanonical('partner', HOME_LIST_LIMITS.partners, 'Partner', PARTNER_ITEM_PARTS),
      { id: 'collection', label: 'Partner row layout' },
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
      { id: 'award_badge', label: 'Award badges (shared look)' },
      ...listItemCanonical('award', HOME_LIST_LIMITS.awards, 'Award', AWARD_ITEM_PARTS),
      { id: 'collection', label: 'Awards row layout' },
    ],
    faq: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'faq_item', label: 'FAQ items (shared look)' },
      ...listItemCanonical('faq', HOME_LIST_LIMITS.faq, 'FAQ', FAQ_ITEM_PARTS),
      { id: 'collection', label: 'FAQ list layout' },
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
  about: {
    about: [
      { id: 'eyebrow', label: 'Eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'body', label: 'Primary body' },
      { id: 'body_secondary', label: 'Secondary body' },
      { id: 'highlight_tile', label: 'Highlight tiles (shared look)' },
      ...marketingListItemCanonical('highlight', MARKETING_LIST_LIMITS.highlights, 'Highlight', HIGHLIGHT_ITEM_PARTS),
      { id: 'primary_button', label: 'Services CTA' },
      { id: 'secondary_button', label: 'WhatsApp CTA' },
    ],
    values: [
      { id: 'heading', label: 'Section heading' },
      { id: 'value_card', label: 'Value cards (shared look)' },
      ...marketingListItemCanonical('value', MARKETING_LIST_LIMITS.values, 'Value', VALUE_ITEM_PARTS),
      { id: 'collection', label: 'Values grid layout' },
    ],
    team: [
      { id: 'heading', label: 'Section heading' },
      { id: 'team_card', label: 'Team cards (shared look)' },
      ...marketingListItemCanonical('team_member', MARKETING_LIST_LIMITS.team, 'Team member', TEAM_ITEM_PARTS),
      { id: 'collection', label: 'Team grid layout' },
    ],
    cta: [
      { id: 'heading', label: 'CTA heading' },
      { id: 'body', label: 'CTA subtitle' },
      { id: 'primary_button', label: 'Primary button' },
      { id: 'secondary_button', label: 'Secondary button' },
    ],
  },
  pricing: {
    pricing: [
      { id: 'eyebrow', label: 'Section eyebrow' },
      { id: 'heading', label: 'Section heading' },
      { id: 'subtitle', label: 'Section subtitle' },
      { id: 'plan_card', label: 'Plan cards (shared look)' },
      ...marketingListItemCanonical('plan', MARKETING_LIST_LIMITS.pricingPlans, 'Plan', PLAN_ITEM_PARTS),
      { id: 'collection', label: 'Plans grid layout' },
    ],
    compare: [
      { id: 'heading', label: 'Comparison heading' },
      { id: 'subtitle', label: 'Comparison intro' },
      { id: 'brand_compare_row', label: 'Brand compare rows (shared look)' },
      ...marketingListItemCanonical(
        'brand_row',
        MARKETING_LIST_LIMITS.brandCompare,
        'Compare row',
        BRAND_ROW_ITEM_PARTS,
      ),
      { id: 'collection', label: 'Compare table layout' },
    ],
    consultation: [
      { id: 'heading', label: 'CTA heading' },
      { id: 'body', label: 'CTA body' },
      { id: 'primary_button', label: 'CTA button' },
    ],
  },
  'how-it-works': {
    process: [
      { id: 'step_card', label: 'Step cards (shared look)' },
      ...marketingListItemCanonical('page_step', MARKETING_LIST_LIMITS.hiwPageSteps, 'Step', PAGE_STEP_ITEM_PARTS),
      { id: 'collection', label: 'Timeline layout' },
    ],
    hiw_cta: [
      { id: 'heading', label: 'Callout heading' },
      { id: 'body', label: 'Callout subtitle' },
      { id: 'primary_button', label: 'Browse services button' },
      { id: 'link', label: 'Contact link' },
    ],
  },
  contact: {
    contact: [
      { id: 'heading', label: 'Left column heading' },
      { id: 'hours_card', label: 'Business hours card' },
      ...marketingListItemCanonical('hours', MARKETING_LIST_LIMITS.contactHours, 'Hours row', HOURS_ITEM_PARTS),
      { id: 'form_heading', label: 'Form title' },
      { id: 'collection', label: 'Contact form fields' },
    ],
  },
  faq: {
    faq: [
      { id: 'faq_item', label: 'FAQ accordion items (shared look)' },
      { id: 'collection', label: 'FAQ list layout' },
    ],
    faq_cta: [
      { id: 'heading', label: 'Support callout heading' },
      { id: 'body', label: 'Support callout body' },
      { id: 'primary_button', label: 'Contact button' },
    ],
  },
  services: {
    hero: [
      { id: 'heading', label: 'Page title' },
      { id: 'subtitle', label: 'Subtitle' },
      { id: 'search', label: 'Search field' },
      { id: 'meta_chips', label: 'Meta chips row' },
    ],
    directory: [
      { id: 'intro', label: 'Directory intro line' },
      { id: 'sidebar', label: 'Category sidebar' },
      { id: 'grid', label: 'Cards grid layout' },
      { id: 'service_card', label: 'Directory cards (shared look)' },
      ...listItemCanonical(
        'service',
        HOME_LIST_LIMITS.serviceDirectory,
        'Service',
        SERVICE_DIRECTORY_ITEM_PARTS,
      ),
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
  home_why_choose_json: 'collection',
  home_faq_json: 'collection',
  home_press_json: 'collection',
  home_partners_json: 'collection',
  home_awards_json: 'collection',
  about_values_json: 'collection',
  about_team_json: 'collection',
  about_highlights_json: 'collection',
  hiw_page_steps_json: 'collection',
  pricing_compare_brand_rows_json: 'collection',
  contact_hours_json: 'collection',
  services_page_title: 'heading',
  services_page_subtitle: 'subtitle',
  services_search_placeholder: 'search',
  services_subtitle: 'intro',
  css_stats_bg: 'collection',
  css_stats_color: 'stat_item',
  css_stats_padding: 'collection',
  css_features_bg: 'collection',
  css_features_padding: 'collection',
  css_press_bg: 'collection',
  css_partners_bg: 'collection',
  css_awards_bg: 'collection',
  css_faq_bg: 'collection',
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

  const hiwPageStep = key.match(/^hiw_page_step(\d+)_(title|desc|icon)$/)
  if (hiwPageStep) {
    const n = hiwPageStep[1]
    const part = hiwPageStep[2]
    if (part === 'title') return `page_step_${n}_title`
    if (part === 'desc') return `page_step_${n}_desc`
    return `page_step_${n}_icon`
  }

  const valueN = key.match(/^value_(\d+)_(icon|title|desc)$/)
  if (valueN) return `value_${valueN[1]}_${valueN[2]}`

  const teamN = key.match(/^team_(\d+)_(name|role|bio|img)$/)
  if (teamN) {
    const n = teamN[1]
    if (teamN[2] === 'img') return `team_member_${n}_photo`
    return `team_member_${n}_${teamN[2]}`
  }

  const highlightN = key.match(/^about_highlight_(\d+)_(value|label)$/)
  if (highlightN) return `highlight_${highlightN[1]}_${highlightN[2]}`

  const brandRowN = key.match(/^pricing_brand_row_(\d+)_(feature|us|them)$/)
  if (brandRowN) return `brand_row_${brandRowN[1]}_${brandRowN[2]}`

  const hoursN = key.match(/^contact_hours_(\d+)_(day|hours)$/)
  if (hoursN) return `hours_${hoursN[1]}_${hoursN[2]}`

  const statN = key.match(/^stat_(\d+)_(number|label)$/)
  if (statN) return statN[2] === 'number' ? `stat_${statN[1]}_value` : `stat_${statN[1]}_label`

  const featureN = key.match(/^feature_(\d+)_(icon|title|sub)$/)
  if (featureN) {
    const n = featureN[1]
    if (featureN[2] === 'icon') return `feature_${n}_icon`
    if (featureN[2] === 'title') return `feature_${n}_title`
    return `feature_${n}_desc`
  }

  const faqN = key.match(/^faq_(\d+)_(q|a)$/)
  if (faqN) return faqN[2] === 'q' ? `faq_${faqN[1]}_question` : `faq_${faqN[1]}_answer`

  const pressN = key.match(/^press_(\d+)_(name|brand)$/)
  if (pressN) return pressN[2] === 'name' ? `press_${pressN[1]}_title` : `press_${pressN[1]}_brand`

  const partnerN = key.match(/^partner_(\d+)_name$/)
  if (partnerN) return `partner_${partnerN[1]}_title`

  const awardN = key.match(/^award_(\d+)_(emoji|text|variant)$/)
  if (awardN) {
    const n = awardN[1]
    if (awardN[2] === 'emoji') return `award_${n}_icon`
    if (awardN[2] === 'text') return `award_${n}_title`
    return `award_${n}_variant`
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
  if (key.includes('subtitle') || (key.endsWith('_sub') && !/^feature_\d+_sub$/.test(key))) {
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
