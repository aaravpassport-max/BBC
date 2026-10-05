/**
 * Visual page builder catalog — Page → Section → Content / Design.
 * Maps public routes to section keys and platform setting fields for content.
 */
import { PAGE_TEMPLATES, type PageTemplateDef } from '@/lib/design-page-templates'

export type ContentFieldDef = {
  key: string
  label: string
  type?: 'text' | 'textarea' | 'url'
  placeholder?: string
  hint?: string
}

export type SectionCatalogDef = {
  id: string
  label: string
  /** Width + CSS override key (`data-s2-section`) */
  sectionKey: string
  contentFields?: ContentFieldDef[]
  /** Platform setting: hide_section_* (0 = visible) */
  hideSettingKey?: string
  contentNote?: string
}

export type PageCatalogDef = {
  id: string
  label: string
  previewPath: string
  widthPageType?: string
  widthPageSlug?: string
  sections: SectionCatalogDef[]
}

const HOME_SECTIONS: SectionCatalogDef[] = [
  {
    id: 'hero',
    label: 'Hero Section',
    sectionKey: 'hero',
    hideSettingKey: 'hide_section_hero',
    contentFields: [
      { key: 'hero_heading_1', label: 'Heading line 1', placeholder: 'Stay Connected to' },
      { key: 'hero_heading_2', label: 'Heading line 2 (accent)', placeholder: 'INDIA' },
      { key: 'hero_subheading', label: 'Subheading', placeholder: 'Without the Paperwork Stress' },
      { key: 'hero_description', label: 'Description', type: 'textarea' },
      { key: 'hero_banners', label: 'Banner image URLs', type: 'textarea', hint: 'Comma-separated URLs (up to 5). Leave blank for gradient banners.' },
    ],
  },
  {
    id: 'search',
    label: 'Search Section',
    sectionKey: 'home_services',
    contentNote: 'Service search and category tabs live in the Services block below. Style the band here; edit labels in Services Section.',
  },
  {
    id: 'features',
    label: 'Why Choose Us',
    sectionKey: 'features',
    contentNote: 'Card copy is managed in Homepage Builder → About / CMS sections, or via service marketing content.',
  },
  {
    id: 'home_services',
    label: 'Services Section',
    sectionKey: 'home_services',
    hideSettingKey: 'hide_section_services',
    contentNote: 'Service names and tabs come from the Service Registry. Toggle visibility here under Design → Show on homepage.',
  },
  {
    id: 'process',
    label: 'How It Works',
    sectionKey: 'process',
    hideSettingKey: 'hide_section_how',
  },
  {
    id: 'app',
    label: 'Download App',
    sectionKey: 'app',
    contentFields: [
      { key: 'app_playstore_url', label: 'Google Play URL', type: 'url' },
      { key: 'app_appstore_url', label: 'Apple App Store URL', type: 'url' },
    ],
  },
  {
    id: 'testimonials',
    label: 'Testimonials',
    sectionKey: 'testimonials',
    contentNote: 'Edit testimonial entries under Admin → Testimonials. This section controls layout and colors.',
  },
  {
    id: 'faq',
    label: 'FAQ',
    sectionKey: 'faq',
    contentNote: 'FAQ items are managed under Admin → FAQs.',
  },
  {
    id: 'newsletter',
    label: 'CTA / Newsletter',
    sectionKey: 'newsletter',
  },
  {
    id: 'footer',
    label: 'Footer',
    sectionKey: 'footer',
    contentNote: 'Footer links and legal copy inherit Site Foundation → Site Chrome. Content fields for platform name/tagline are under Brand.',
  },
  {
    id: 'stats',
    label: 'Stats Counter',
    sectionKey: 'stats',
    hideSettingKey: 'hide_section_stats',
    contentFields: [
      { key: 'stat_1_number', label: 'Stat 1 number' },
      { key: 'stat_1_label', label: 'Stat 1 label' },
      { key: 'stat_2_number', label: 'Stat 2 number' },
      { key: 'stat_2_label', label: 'Stat 2 label' },
      { key: 'stat_3_number', label: 'Stat 3 number' },
      { key: 'stat_3_label', label: 'Stat 3 label' },
      { key: 'stat_4_number', label: 'Stat 4 number' },
      { key: 'stat_4_label', label: 'Stat 4 label' },
    ],
  },
  {
    id: 'cities',
    label: 'Cities Grid',
    sectionKey: 'cities',
  },
  {
    id: 'about',
    label: 'About Band',
    sectionKey: 'about',
    contentFields: [
      { key: 'about_heading', label: 'Heading' },
      { key: 'about_text', label: 'Body text', type: 'textarea' },
      { key: 'about_video_url', label: 'Video URL', type: 'url' },
      { key: 'about_image_url', label: 'Image URL', type: 'url' },
      { key: 'home_tagline', label: 'Tagline strip (below stats)' },
    ],
  },
]

const MARKETING_INTRO: SectionCatalogDef = {
  id: 'intro',
  label: 'Page Hero / Intro',
  sectionKey: 'intro',
  contentNote: 'Headings often come from platform settings on About/Contact; edit page-specific copy in Homepage Builder or page CMS when available.',
}

function sectionsForTemplate(t: PageTemplateDef): SectionCatalogDef[] {
  switch (t.id) {
    case 'home':
      return HOME_SECTIONS
    case 'services':
      return [
        { id: 'hero', label: 'Hero / Header', sectionKey: 'hero' },
        { id: 'directory', label: 'Search & directory', sectionKey: 'directory' },
      ]
    case 'service':
      return [
        { id: 'hero', label: 'Service hero', sectionKey: 'hero' },
        { id: 'wizard', label: 'Booking wizard', sectionKey: 'wizard' },
        { id: 'faq', label: 'FAQ block', sectionKey: 'faq' },
        { id: 'pricing', label: 'Pricing / charges', sectionKey: 'pricing' },
      ]
    case 'contact':
      return [MARKETING_INTRO, { id: 'contact', label: 'Contact form', sectionKey: 'contact' }]
    case 'faq':
      return [{ id: 'faq', label: 'FAQ accordion', sectionKey: 'faq' }]
    case 'pricing':
      return [
        MARKETING_INTRO,
        { id: 'pricing', label: 'Plans table', sectionKey: 'pricing' },
        { id: 'compare', label: 'Comparison table', sectionKey: 'compare' },
      ]
    case 'about':
      return [MARKETING_INTRO, { id: 'about', label: 'Story section', sectionKey: 'about' }]
    case 'how-it-works':
      return [MARKETING_INTRO, { id: 'process', label: 'Steps', sectionKey: 'process' }]
    default:
      return [MARKETING_INTRO]
  }
}

export const DESIGN_PAGE_CATALOG: PageCatalogDef[] = PAGE_TEMPLATES.map((t) => ({
  id: t.id,
  label: t.label,
  previewPath: t.previewPath,
  widthPageType: t.widthPageType,
  widthPageSlug: t.widthPageSlug,
  sections: sectionsForTemplate(t),
}))

export const SECTION_DESIGN_COLOR_KEYS = [
  'background',
  'surface',
  'heading',
  'body',
  'muted',
  'primary',
  'border',
] as const

export type FoundationPanelId =
  | 'colors'
  | 'typography'
  | 'fonts'
  | 'components'
  | 'spacing'
  | 'layout'
  | 'borders'
  | 'shadows'
  | 'motion'
  | 'responsive'
  | 'chrome'
  | 'presets'

export const FOUNDATION_NAV: { id: FoundationPanelId; label: string; hint: string }[] = [
  { id: 'colors', label: 'Brand colors', hint: 'Default palette for every page' },
  { id: 'typography', label: 'Typography', hint: 'Global text roles' },
  { id: 'fonts', label: 'Fonts', hint: 'Heading, body, UI, button' },
  { id: 'components', label: 'Buttons & cards', hint: 'Global component tokens' },
  { id: 'spacing', label: 'Spacing scale', hint: 'Section rhythm defaults' },
  { id: 'layout', label: 'Width & containers', hint: 'Site foundation layout' },
  { id: 'borders', label: 'Border radius', hint: 'Corner system' },
  { id: 'shadows', label: 'Shadows', hint: 'Elevation defaults' },
  { id: 'motion', label: 'Motion', hint: 'Transitions & reduced motion' },
  { id: 'responsive', label: 'Breakpoints', hint: 'Responsive defaults' },
  { id: 'chrome', label: 'Header & footer', hint: 'Site chrome defaults' },
  { id: 'presets', label: 'Presets', hint: 'Apply a full-site look' },
]
