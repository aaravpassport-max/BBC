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

export type DesignSettingFieldDef = {
  key: string
  label: string
  type?: 'text' | 'color'
  placeholder?: string
  hint?: string
}

export type SectionCatalogDef = {
  id: string
  label: string
  /** Width + CSS override key (`data-s2-section`) */
  sectionKey: string
  /** Page-level defaults row (no section band on site) */
  isPageScope?: boolean
  contentFields?: ContentFieldDef[]
  designSettingFields?: DesignSettingFieldDef[]
  /** Platform setting: hide_section_* (0 = visible) */
  hideSettingKey?: string
  contentNote?: string
  adminLink?: { label: string; path: string }
}

export type PageCatalogDef = {
  id: string
  label: string
  previewPath: string
  widthPageType?: string
  widthPageSlug?: string
  /** Shown under Page defaults → Content */
  pageContentFields?: ContentFieldDef[]
  sections: SectionCatalogDef[]
}

const PAGE_DEFAULTS: SectionCatalogDef = {
  id: '_page',
  label: 'Page defaults',
  sectionKey: '_page',
  isPageScope: true,
  contentNote:
    'Page-wide styling inherits Site Foundation first. Overrides here apply to every section on this page unless a section has its own override.',
}

/** Home — exact Page → Section list requested for the public homepage builder. */
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
      { key: 'hero_cta_text', label: 'Primary button text', placeholder: 'Browse services' },
      { key: 'hero_cta_url', label: 'Primary button link', type: 'url', placeholder: '/services' },
      { key: 'hero_cta2_text', label: 'Secondary button text', placeholder: 'Get a quote' },
      { key: 'hero_cta2_url', label: 'Secondary button link', type: 'url', placeholder: '/contact' },
      { key: 'hero_banners', label: 'Background slides (URLs)', type: 'textarea', hint: 'Comma-separated image URLs (up to 5). Leave blank for default gradient slides.' },
    ],
    designSettingFields: [
      { key: 'hero_overlay_color', label: 'Overlay color', type: 'color', placeholder: '#0f172a' },
      { key: 'hero_overlay_opacity', label: 'Overlay opacity (0–1)', placeholder: '0.55' },
      { key: 'css_hero_minheight', label: 'Min height', placeholder: '520px' },
      { key: 'css_hero_textcolor', label: 'Text color', type: 'color' },
      { key: 'css_hero_bg', label: 'Background (CSS)', placeholder: 'linear-gradient(...)' },
      { key: 'css_hero_padding', label: 'Padding', placeholder: '48px 0' },
    ],
  },
  {
    id: 'search',
    label: 'Search Section',
    sectionKey: 'search',
    contentFields: [
      { key: 'home_search_title', label: 'Heading', placeholder: 'Find your service' },
      { key: 'home_search_subtitle', label: 'Supporting text', placeholder: 'Search 44+ NRI services' },
      { key: 'home_search_placeholder', label: 'Input placeholder', placeholder: 'Search services…' },
      { key: 'home_search_button', label: 'Button label', placeholder: 'Search' },
    ],
    designSettingFields: [
      { key: 'css_search_bg', label: 'Background', placeholder: '#f8fafc' },
      { key: 'css_search_padding', label: 'Padding', placeholder: '24px 0' },
    ],
  },
  {
    id: 'features',
    label: 'Why Choose Us',
    sectionKey: 'features',
    contentFields: [
      { key: 'features_eyebrow', label: 'Eyebrow label', placeholder: 'Why Choose Us' },
      { key: 'features_title', label: 'Section heading', placeholder: 'Why Our Customers Love Us' },
    ],
    designSettingFields: [
      { key: 'css_features_bg', label: 'Background', placeholder: '#ffffff' },
      { key: 'css_features_padding', label: 'Padding', placeholder: '64px 0' },
    ],
  },
  {
    id: 'home_services',
    label: 'Services Section',
    sectionKey: 'home_services',
    hideSettingKey: 'hide_section_services',
    contentFields: [
      { key: 'services_eyebrow', label: 'Eyebrow label', placeholder: 'What We Offer' },
      { key: 'services_title', label: 'Section heading', placeholder: 'Our Services' },
      { key: 'services_subtitle', label: 'Subtitle', placeholder: 'Expert NRI assistance across categories' },
    ],
    designSettingFields: [
      { key: 'css_svc_bg', label: 'Section background', placeholder: '#ffffff' },
      { key: 'css_svc_card_bg', label: 'Card background', placeholder: '#ffffff' },
      { key: 'css_svc_cols', label: 'Grid columns (desktop)', placeholder: '4' },
      { key: 'css_svc_gap', label: 'Grid gap', placeholder: '24px' },
    ],
    contentNote: 'Service names and cards come from the Service Registry. Use Design → visibility to hide the whole block.',
  },
  {
    id: 'process',
    label: 'How It Works',
    sectionKey: 'process',
    hideSettingKey: 'hide_section_how',
    contentFields: [
      { key: 'hiw_title', label: 'Section heading', placeholder: 'How It Works' },
      { key: 'hiw_step1_title', label: 'Step 1 title' },
      { key: 'hiw_step1_desc', label: 'Step 1 description', type: 'textarea' },
      { key: 'hiw_step2_title', label: 'Step 2 title' },
      { key: 'hiw_step2_desc', label: 'Step 2 description', type: 'textarea' },
      { key: 'hiw_step3_title', label: 'Step 3 title' },
      { key: 'hiw_step3_desc', label: 'Step 3 description', type: 'textarea' },
      { key: 'hiw_step4_title', label: 'Step 4 title' },
      { key: 'hiw_step4_desc', label: 'Step 4 description', type: 'textarea' },
    ],
    designSettingFields: [
      { key: 'css_how_bg', label: 'Background', placeholder: '#f1f5f9' },
      { key: 'css_how_cols', label: 'Columns (desktop)', placeholder: '4' },
      { key: 'css_how_gap', label: 'Grid gap', placeholder: '24px' },
    ],
  },
  {
    id: 'app',
    label: 'Download App',
    sectionKey: 'app',
    contentFields: [
      { key: 'app_eyebrow', label: 'Eyebrow label', placeholder: 'Mobile App' },
      { key: 'app_title', label: 'Heading', placeholder: 'Download Our App' },
      { key: 'app_subtitle', label: 'Description', type: 'textarea' },
      { key: 'app_playstore_url', label: 'Google Play URL', type: 'url' },
      { key: 'app_appstore_url', label: 'Apple App Store URL', type: 'url' },
    ],
  },
  {
    id: 'testimonials',
    label: 'Testimonials',
    sectionKey: 'testimonials',
    contentFields: [
      { key: 'testimonials_title', label: 'Section heading', placeholder: 'What Our Customers Say' },
      { key: 'testimonials_eyebrow', label: 'Eyebrow label', placeholder: 'Client Testimonials' },
    ],
    adminLink: { label: 'Manage testimonial entries', path: '/admin/testimonials' },
  },
  {
    id: 'faq',
    label: 'FAQ',
    sectionKey: 'faq',
    contentFields: [
      { key: 'faq_section_eyebrow', label: 'Eyebrow label', placeholder: 'FAQ' },
      { key: 'faq_section_title', label: 'Section heading', placeholder: "Let's Clear All The Doubts!" },
    ],
    adminLink: { label: 'Manage FAQ items', path: '/admin/faqs' },
  },
  {
    id: 'newsletter',
    label: 'CTA Section',
    sectionKey: 'newsletter',
    contentFields: [
      { key: 'newsletter_title', label: 'Heading', placeholder: 'Subscribe to Our Newsletter' },
      { key: 'newsletter_subtitle', label: 'Supporting text', type: 'textarea' },
      { key: 'newsletter_placeholder', label: 'Email placeholder', placeholder: 'Your email address' },
      { key: 'newsletter_button', label: 'Button text', placeholder: 'Subscribe' },
    ],
  },
  {
    id: 'footer',
    label: 'Footer',
    sectionKey: 'footer',
    contentFields: [
      { key: 'footer_copyright', label: 'Copyright line' },
    ],
    contentNote: 'Global header/footer chrome defaults live under Site Foundation → Header & footer. Section colors here override only the footer band.',
  },
]

const MARKETING_INTRO: SectionCatalogDef = {
  id: 'intro',
  label: 'Page Hero / Intro',
  sectionKey: 'intro',
  contentFields: [
    { key: 'contact_title', label: 'Page heading (when applicable)', placeholder: 'Contact us' },
  ],
}

function sectionsForTemplate(t: PageTemplateDef): SectionCatalogDef[] {
  let sections: SectionCatalogDef[]
  switch (t.id) {
    case 'home':
      sections = HOME_SECTIONS
      break
    case 'services':
      sections = [
        { id: 'hero', label: 'Hero / Header', sectionKey: 'hero', contentFields: [{ key: 'services_title', label: 'Page title', placeholder: 'Our Services' }] },
        { id: 'directory', label: 'Search & directory', sectionKey: 'directory', contentFields: [{ key: 'services_subtitle', label: 'Subtitle' }] },
      ]
      break
    case 'category':
      sections = [
        { id: 'hero', label: 'Category header', sectionKey: 'hero' },
        { id: 'directory', label: 'Service grid', sectionKey: 'directory' },
      ]
      break
    case 'service':
      sections = [
        { id: 'hero', label: 'Service hero', sectionKey: 'hero' },
        { id: 'wizard', label: 'Booking wizard', sectionKey: 'wizard' },
        { id: 'faq', label: 'FAQ block', sectionKey: 'faq', adminLink: { label: 'Edit service FAQs', path: '/admin/services' } },
        { id: 'pricing', label: 'Pricing / charges', sectionKey: 'pricing' },
      ]
      break
    case 'contact':
      sections = [
        MARKETING_INTRO,
        {
          id: 'contact',
          label: 'Contact form',
          sectionKey: 'contact',
          contentFields: [
            { key: 'contact_title', label: 'Heading', placeholder: 'Get in touch' },
            { key: 'contact_email', label: 'Display email' },
            { key: 'contact_phone', label: 'Display phone' },
            { key: 'contact_address', label: 'Address', type: 'textarea' },
          ],
        },
      ]
      break
    case 'faq':
      sections = [
        {
          id: 'faq',
          label: 'FAQ accordion',
          sectionKey: 'faq',
          contentFields: [
            { key: 'faq_section_title', label: 'Page heading', placeholder: 'Frequently asked questions' },
          ],
          adminLink: { label: 'Manage FAQ items', path: '/admin/faqs' },
        },
      ]
      break
    case 'pricing':
      sections = [MARKETING_INTRO, { id: 'pricing', label: 'Plans table', sectionKey: 'pricing' }, { id: 'compare', label: 'Comparison table', sectionKey: 'compare' }]
      break
    case 'about':
      sections = [
        MARKETING_INTRO,
        {
          id: 'about',
          label: 'Story section',
          sectionKey: 'about',
          contentFields: [
            { key: 'about_heading', label: 'Heading' },
            { key: 'about_text', label: 'Body', type: 'textarea' },
            { key: 'about_image_url', label: 'Image URL', type: 'url' },
            { key: 'about_video_url', label: 'Video URL', type: 'url' },
          ],
        },
      ]
      break
    case 'how-it-works':
      sections = [
        MARKETING_INTRO,
        {
          id: 'process',
          label: 'Steps',
          sectionKey: 'process',
          contentFields: [
            { key: 'hiw_title', label: 'Section heading' },
            { key: 'hiw_step1_title', label: 'Step 1 title' },
            { key: 'hiw_step1_desc', label: 'Step 1 description', type: 'textarea' },
            { key: 'hiw_step2_title', label: 'Step 2 title' },
            { key: 'hiw_step2_desc', label: 'Step 2 description', type: 'textarea' },
            { key: 'hiw_step3_title', label: 'Step 3 title' },
            { key: 'hiw_step3_desc', label: 'Step 3 description', type: 'textarea' },
            { key: 'hiw_step4_title', label: 'Step 4 title' },
            { key: 'hiw_step4_desc', label: 'Step 4 description', type: 'textarea' },
          ],
        },
      ]
      break
    case 'blog':
      sections = [{ id: 'hero', label: 'Blog header', sectionKey: 'hero' }, { id: 'feed', label: 'Article feed', sectionKey: 'feed' }]
      break
    case 'city':
      sections = [{ id: 'hero', label: 'City hero', sectionKey: 'hero' }, { id: 'content', label: 'Local content', sectionKey: 'content' }]
      break
    case 'terms':
    case 'privacy':
      sections = [{ id: 'intro', label: 'Legal header', sectionKey: 'intro' }, { id: 'content', label: 'Document body', sectionKey: 'content' }]
      break
    case 'visa':
    case 'country':
      sections = [{ id: 'hero', label: 'Hub hero', sectionKey: 'hero' }, { id: 'content', label: 'Hub content', sectionKey: 'content' }]
      break
    default:
      sections = [MARKETING_INTRO]
  }
  return [PAGE_DEFAULTS, ...sections]
}

export const DESIGN_PAGE_CATALOG: PageCatalogDef[] = PAGE_TEMPLATES.map((t) => ({
  id: t.id,
  label: t.label,
  previewPath: t.previewPath,
  widthPageType: t.widthPageType,
  widthPageSlug: t.widthPageSlug,
  pageContentFields:
    t.id === 'contact'
      ? [{ key: 'contact_title', label: 'Default contact heading' }]
      : undefined,
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
