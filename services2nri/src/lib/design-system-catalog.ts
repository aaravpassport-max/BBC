/**
 * Visual page builder catalog — Page → Section → Content / Design.
 * Maps public routes to section keys and platform setting fields for content.
 */
import { PAGE_TEMPLATES, type PageTemplateDef } from '@/lib/design-page-templates'
import { templateHideSettingKey } from '@/lib/section-visibility'

function applyTemplateHide(pageId: string, sections: SectionCatalogDef[]): SectionCatalogDef[] {
  return sections.map((s) => {
    if (s.isPageScope || s.hideSettingKey) return s
    return { ...s, hideSettingKey: templateHideSettingKey(pageId, s.sectionKey) }
  })
}

export type ContentFieldDef = {
  key: string
  label: string
  type?: 'text' | 'textarea' | 'url'
  placeholder?: string
  hint?: string
  rows?: number
}

/** Typography roles editable per section band (Design tab). */
export const SECTION_TYPOGRAPHY_ROLES = [
  'section_heading',
  'section_subheading',
  'eyebrow',
  'body',
] as const

export type SectionTypographyRole = (typeof SECTION_TYPOGRAPHY_ROLES)[number]

export type DesignSettingFieldDef = {
  key: string
  label: string
  type?: 'text' | 'color'
  placeholder?: string
  hint?: string
}

export type SectionContentPanelId =
  | 'hero_slides'
  | 'why_choose'
  | 'stats_cards'
  | 'services_registry'
  | 'cities_registry'
  | 'testimonials_registry'
  | 'faq_items'
  | 'faq_database'
  | 'press_logos'
  | 'partners_list'
  | 'awards_list'
  | 'hiw_steps'
  | 'pricing_plans'
  | 'blog_posts'
  | 'category_catalog'
  | 'marquee_band'
  | 'service_page_band'
  | 'locations_cities'
  | 'chrome_footer'
  | 'legal_document'
  | 'registry_hub'
  | 'about_story'
  | 'value_cards'
  | 'team_members'
  | 'marketing_cta'
  | 'contact_channels'
  | 'services_directory'
  | 'faq_page_cta'
  | 'pricing_section_head'
  | 'pricing_compare'
  | 'hiw_page_callout'

export type SectionCatalogDef = {
  id: string
  label: string
  /** Width + CSS override key (`data-s2-section`) */
  sectionKey: string
  /** Page-level defaults row (no section band on site) */
  isPageScope?: boolean
  /** Visual list/registry editor in Design System Content tab */
  contentPanel?: SectionContentPanelId
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

const WHY_JSON_HINT =
  'JSON array: [{"icon":"🔒","title":"Secure Platform","sub":"Description…"}]. Up to 12 cards.'

/** Home — full public homepage (every band on the live page). */
const HOME_SECTIONS: SectionCatalogDef[] = [
  {
    id: 'hero',
    label: 'Hero Section',
    sectionKey: 'hero',
    hideSettingKey: 'hide_section_hero',
    contentPanel: 'hero_slides',
    contentFields: [
      { key: 'hero_heading_1', label: 'Heading line 1', placeholder: 'Stay Connected to' },
      { key: 'hero_heading_2', label: 'Heading line 2 (accent)', placeholder: 'INDIA' },
      { key: 'hero_subheading', label: 'Eyebrow / subheading', placeholder: 'Trusted NRI partner' },
      { key: 'hero_description', label: 'Description', type: 'textarea' },
      { key: 'hero_cta_text', label: 'Primary button text', placeholder: 'Browse services' },
      { key: 'hero_cta_url', label: 'Primary button link', type: 'url', placeholder: '/services' },
      { key: 'hero_cta2_text', label: 'Secondary button text', placeholder: 'Get a quote' },
      { key: 'hero_cta2_url', label: 'Secondary button link', type: 'url', placeholder: '/contact' },
      { key: 'hero_banners', label: 'Background slides (URLs)', type: 'textarea', hint: 'Comma-separated image URLs (up to 5).' },
    ],
    designSettingFields: [
      { key: 'hero_overlay_color', label: 'Overlay color', type: 'color' },
      { key: 'hero_overlay_opacity', label: 'Overlay opacity (0–1)', placeholder: '0.55' },
      { key: 'css_hero_minheight', label: 'Min height', placeholder: '520px' },
      { key: 'css_hero_textcolor', label: 'Text color', type: 'color' },
      { key: 'css_hero_bg', label: 'Background (CSS)' },
      { key: 'css_hero_padding', label: 'Padding (legacy)', placeholder: '48px 0', hint: 'Use Spacing below for Desktop / Tablet / Mobile.' },
    ],
  },
  {
    id: 'notice',
    label: 'Notice Bar',
    sectionKey: 'notice',
    hideSettingKey: 'hide_section_notice',
    contentFields: [
      { key: 'home_notice_text', label: 'Notice message', type: 'textarea', placeholder: 'Public notice copy…' },
      { key: 'home_notice_whatsapp_label', label: 'WhatsApp link label', placeholder: 'WhatsApp Us' },
    ],
    designSettingFields: [
      { key: 'css_notice_bg', label: 'Background', placeholder: '#fff8e1' },
      { key: 'css_notice_color', label: 'Text color', type: 'color' },
      { key: 'css_notice_padding', label: 'Padding', placeholder: '12px 16px' },
    ],
  },
  {
    id: 'search',
    label: 'Search Section',
    sectionKey: 'search',
    hideSettingKey: 'hide_section_search',
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
    hideSettingKey: 'hide_section_features',
    contentPanel: 'why_choose',
    contentFields: [
      { key: 'features_eyebrow', label: 'Eyebrow label', placeholder: 'Why Choose Us' },
      { key: 'features_title', label: 'Section heading', placeholder: 'Why Our Customers Love Us' },
      {
        key: 'home_why_choose_json',
        label: 'Feature cards',
        type: 'textarea',
        rows: 14,
        hint: WHY_JSON_HINT,
      },
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
    contentPanel: 'services_registry',
    contentFields: [
      { key: 'services_eyebrow', label: 'Eyebrow label', placeholder: 'What We Offer' },
      { key: 'services_title', label: 'Section heading', placeholder: 'Our Services' },
      { key: 'services_subtitle', label: 'Subtitle', placeholder: 'Expert NRI assistance across categories' },
      { key: 'services_view_all_text', label: 'View all link text', placeholder: 'View All Services →' },
    ],
    designSettingFields: [
      { key: 'css_svc_bg', label: 'Section background', placeholder: '#ffffff' },
      { key: 'css_svc_card_bg', label: 'Card background', placeholder: '#ffffff' },
      { key: 'css_svc_cols', label: 'Grid columns (desktop)', placeholder: '4' },
      { key: 'css_svc_gap', label: 'Grid gap', placeholder: '24px' },
    ],
    adminLink: { label: 'Service Registry', path: '/admin/services' },
  },
  {
    id: 'cities',
    label: 'Cities Grid',
    sectionKey: 'cities',
    hideSettingKey: 'hide_section_cities',
    contentPanel: 'cities_registry',
    contentFields: [
      { key: 'cities_section_title', label: 'Section heading', placeholder: 'Property Management Cities' },
      { key: 'cities_section_subtitle', label: 'Subtitle', placeholder: 'We manage NRI properties across major cities' },
      { key: 'cities_card_eyebrow', label: 'Card eyebrow', placeholder: 'Property Services in' },
    ],
    designSettingFields: [
      { key: 'css_cities_bg', label: 'Background', placeholder: '#f8fafc' },
      { key: 'css_cities_padding', label: 'Padding', placeholder: '64px 0' },
    ],
    adminLink: { label: 'Manage cities', path: '/admin/cities' },
  },
  {
    id: 'stats',
    label: 'Stats Counter',
    sectionKey: 'stats',
    hideSettingKey: 'hide_section_stats',
    contentPanel: 'stats_cards',
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
    designSettingFields: [
      { key: 'css_stats_bg', label: 'Background', type: 'color' },
      { key: 'css_stats_color', label: 'Text color', type: 'color' },
      { key: 'css_stats_padding', label: 'Padding', placeholder: '32px 0' },
    ],
  },
  {
    id: 'tagline',
    label: 'Tagline Strip',
    sectionKey: 'tagline',
    hideSettingKey: 'hide_section_tagline',
    contentFields: [{ key: 'home_tagline', label: 'Quote text', type: 'textarea' }],
    designSettingFields: [
      { key: 'css_tagline_bg', label: 'Background' },
      { key: 'css_tagline_color', label: 'Text color', type: 'color' },
      { key: 'css_tagline_size', label: 'Font size', placeholder: '1.25rem' },
    ],
  },
  {
    id: 'testimonials',
    label: 'Testimonials',
    sectionKey: 'testimonials',
    hideSettingKey: 'hide_section_testimonials',
    contentFields: [
      { key: 'testimonials_eyebrow', label: 'Eyebrow label', placeholder: 'Client Testimonials' },
      { key: 'testimonials_title', label: 'Section heading', placeholder: 'What Our Customers Say' },
      { key: 'google_rating', label: 'Google rating display', placeholder: '4.9' },
      { key: 'google_review_count', label: 'Google review count', placeholder: '500+' },
    ],
    contentPanel: 'testimonials_registry',
    adminLink: { label: 'Manage testimonial entries', path: '/admin/testimonials' },
    designSettingFields: [
      { key: 'css_testimonials_bg', label: 'Background', placeholder: '#ffffff' },
      { key: 'css_testimonials_padding', label: 'Padding', placeholder: '64px 0' },
    ],
  },
  {
    id: 'process',
    label: 'How It Works',
    sectionKey: 'process',
    hideSettingKey: 'hide_section_how',
    contentPanel: 'hiw_steps',
    contentFields: [
      { key: 'hiw_eyebrow', label: 'Eyebrow label', placeholder: 'Simple Process' },
      { key: 'hiw_title', label: 'Section heading', placeholder: 'How It Works' },
      { key: 'hiw_step1_title', label: 'Step 1 title' },
      { key: 'hiw_step1_desc', label: 'Step 1 description', type: 'textarea' },
      { key: 'hiw_step2_title', label: 'Step 2 title' },
      { key: 'hiw_step2_desc', label: 'Step 2 description', type: 'textarea' },
      { key: 'hiw_step3_title', label: 'Step 3 title' },
      { key: 'hiw_step3_desc', label: 'Step 3 description', type: 'textarea' },
      { key: 'hiw_step4_title', label: 'Step 4 title' },
      { key: 'hiw_step4_desc', label: 'Step 4 description', type: 'textarea' },
      { key: 'hiw_step5_title', label: 'Step 5 title' },
      { key: 'hiw_step5_desc', label: 'Step 5 description', type: 'textarea' },
      { key: 'hiw_step6_title', label: 'Step 6 title' },
      { key: 'hiw_step6_desc', label: 'Step 6 description', type: 'textarea' },
      { key: 'hiw_footer_link_text', label: 'Footer link text', placeholder: 'Learn more about the full process →' },
    ],
    designSettingFields: [
      { key: 'css_how_bg', label: 'Background', placeholder: '#f1f5f9' },
      { key: 'css_how_cols', label: 'Columns (desktop)', placeholder: '3' },
      { key: 'css_how_gap', label: 'Grid gap', placeholder: '24px' },
    ],
  },
  {
    id: 'press',
    label: 'As Featured In',
    sectionKey: 'press',
    hideSettingKey: 'hide_section_press',
    contentPanel: 'press_logos',
    contentFields: [
      { key: 'home_press_label', label: 'Section label', placeholder: 'As Featured In' },
      {
        key: 'home_press_json',
        label: 'Press logos / names',
        type: 'textarea',
        rows: 6,
        hint: '[{"name":"Inc42","brand":"inc42"}] or comma-separated names',
      },
    ],
    designSettingFields: [{ key: 'css_press_bg', label: 'Background' }],
  },
  {
    id: 'partners',
    label: 'Partners Strip',
    sectionKey: 'partners',
    hideSettingKey: 'hide_section_partners',
    contentPanel: 'partners_list',
    contentFields: [
      { key: 'home_partners_label', label: 'Section label', placeholder: 'Our Partners' },
      {
        key: 'home_partners_json',
        label: 'Partner names',
        type: 'textarea',
        rows: 4,
        hint: 'JSON array of strings or comma-separated names',
      },
    ],
    designSettingFields: [{ key: 'css_partners_bg', label: 'Background' }],
  },
  {
    id: 'about',
    label: 'About Band',
    sectionKey: 'about',
    hideSettingKey: 'hide_section_about',
    contentFields: [
      { key: 'about_eyebrow', label: 'Eyebrow', placeholder: 'About Us' },
      { key: 'about_heading', label: 'Heading' },
      { key: 'about_text', label: 'Primary body', type: 'textarea' },
      { key: 'about_text_secondary', label: 'Secondary body', type: 'textarea' },
      { key: 'about_image_url', label: 'Image URL', type: 'url' },
      { key: 'about_video_url', label: 'Video URL', type: 'url' },
      { key: 'about_cta_text', label: 'Primary button', placeholder: 'Know More →' },
      { key: 'about_whatsapp_cta', label: 'WhatsApp button', placeholder: '💬 Chat with Us' },
    ],
    designSettingFields: [
      { key: 'css_about_bg', label: 'Background', placeholder: '#ffffff' },
      { key: 'css_about_padding', label: 'Padding', placeholder: '64px 0' },
    ],
  },
  {
    id: 'awards',
    label: 'Awards',
    sectionKey: 'awards',
    hideSettingKey: 'hide_section_awards',
    contentPanel: 'awards_list',
    contentFields: [
      { key: 'home_awards_label', label: 'Section label', placeholder: 'Awards We Have Received' },
      {
        key: 'home_awards_json',
        label: 'Award badges',
        type: 'textarea',
        rows: 6,
        hint: '[{"emoji":"🏆","text":"#startupindia","variant":"orange"}]',
      },
    ],
    designSettingFields: [{ key: 'css_awards_bg', label: 'Background' }],
  },
  {
    id: 'faq',
    label: 'FAQ',
    sectionKey: 'faq',
    hideSettingKey: 'hide_section_faq',
    contentPanel: 'faq_items',
    contentFields: [
      { key: 'faq_section_eyebrow', label: 'Eyebrow label', placeholder: 'FAQ' },
      { key: 'faq_section_title', label: 'Section heading', placeholder: "Let's Clear All The Doubts!" },
      {
        key: 'home_faq_json',
        label: 'FAQ items (homepage)',
        type: 'textarea',
        rows: 12,
        hint: '[{"q":"Question?","a":"Answer."}] — used when API FAQs empty',
      },
      { key: 'faq_footer_link_text', label: 'Footer link', placeholder: 'View All FAQs →' },
    ],
    adminLink: { label: 'Manage FAQ database', path: '/admin/faqs' },
    designSettingFields: [{ key: 'css_faq_bg', label: 'Background' }],
  },
  {
    id: 'newsletter',
    label: 'CTA Section',
    sectionKey: 'newsletter',
    hideSettingKey: 'hide_section_newsletter',
    contentFields: [
      { key: 'newsletter_title', label: 'Heading', placeholder: 'Subscribe to Our Newsletter' },
      { key: 'newsletter_subtitle', label: 'Supporting text', type: 'textarea' },
      { key: 'newsletter_placeholder', label: 'Email placeholder', placeholder: 'Your email address' },
      { key: 'newsletter_button', label: 'Button text', placeholder: 'Subscribe' },
    ],
    designSettingFields: [
      { key: 'css_newsletter_bg', label: 'Background (CSS)' },
      { key: 'css_newsletter_padding', label: 'Padding', placeholder: '48px 20px' },
    ],
  },
  {
    id: 'app',
    label: 'Download App',
    sectionKey: 'app',
    hideSettingKey: 'hide_section_app',
    contentFields: [
      { key: 'app_eyebrow', label: 'Eyebrow label', placeholder: 'Mobile App' },
      { key: 'app_title', label: 'Heading', placeholder: 'Download Our App' },
      { key: 'app_subtitle', label: 'Description', type: 'textarea' },
      { key: 'app_playstore_url', label: 'Google Play URL', type: 'url' },
      { key: 'app_appstore_url', label: 'Apple App Store URL', type: 'url' },
    ],
    designSettingFields: [{ key: 'css_app_bg', label: 'Background override' }],
  },
  {
    id: 'locations',
    label: 'Locations Row',
    sectionKey: 'locations',
    hideSettingKey: 'hide_section_locations',
    contentPanel: 'locations_cities',
    contentFields: [
      { key: 'home_locations_label', label: 'Section label', placeholder: 'Locations' },
    ],
    adminLink: { label: 'Manage cities', path: '/admin/cities' },
  },
  {
    id: 'footer',
    label: 'Footer',
    sectionKey: 'footer',
    contentPanel: 'chrome_footer',
    contentFields: [
      { key: 'footer_copyright', label: 'Copyright line' },
      { key: 'platform_name', label: 'Brand name (footer)' },
      { key: 'platform_tagline', label: 'Tagline (footer)' },
      { key: 'footer_col_services_title', label: 'Column: Services heading', placeholder: 'Services' },
      { key: 'footer_col_quick_title', label: 'Column: Quick links heading', placeholder: 'Quick Links' },
      { key: 'footer_col_locations_title', label: 'Column: Locations heading', placeholder: 'Locations' },
      { key: 'footer_col_contact_title', label: 'Column: Contact heading', placeholder: 'Contact' },
    ],
    contentNote: 'Header/footer chrome defaults: Site Foundation → Header & footer.',
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
        {
          id: 'hero',
          label: 'Hero / Header',
          sectionKey: 'hero',
          contentPanel: 'services_directory',
          contentFields: [
            { key: 'services_page_title', label: 'Page title', placeholder: 'Our Services' },
            { key: 'services_page_subtitle', label: 'Subtitle', type: 'textarea' },
            {
              key: 'services_hero_meta_json',
              label: 'Hero meta chips JSON',
              type: 'textarea',
              rows: 3,
              hint: '[{"icon":"🌐","label":"Trusted by NRIs worldwide"}]',
            },
          ],
          adminLink: { label: 'Service Registry', path: '/admin/services' },
        },
        {
          id: 'directory',
          label: 'Search & directory',
          sectionKey: 'directory',
          contentPanel: 'services_directory',
          contentFields: [
            { key: 'services_subtitle', label: 'Directory intro line', placeholder: 'Browse by category or search the full catalog.' },
            { key: 'services_search_placeholder', label: 'Search input placeholder', placeholder: 'Search services…' },
            { key: 'services_show_search', label: 'Show search bar (1 = yes, 0 = no)', placeholder: '1' },
            { key: 'services_show_category_filter', label: 'Show category sidebar (1/0)', placeholder: '1' },
            { key: 'services_per_page', label: 'Cards per page (optional)', placeholder: '24' },
          ],
          adminLink: { label: 'Categories', path: '/admin/categories' },
        },
      ]
      break
    case 'category':
      sections = [
        {
          id: 'hero',
          label: 'Category header',
          sectionKey: 'hero',
          contentFields: [
            { key: 'services_page_title', label: 'Fallback title', placeholder: 'Services' },
            { key: 'services_page_subtitle', label: 'Fallback subtitle', type: 'textarea' },
          ],
        },
        {
          id: 'directory',
          label: 'Service grid',
          sectionKey: 'directory',
          contentPanel: 'category_catalog',
          contentFields: [
            { key: 'services_subtitle', label: 'Directory intro line' },
            { key: 'services_show_search', label: 'Show search (1/0)', placeholder: '1' },
          ],
          adminLink: { label: 'Service Registry', path: '/admin/services' },
        },
      ]
      break
    case 'service':
      sections = [
        {
          id: 'marquee',
          label: 'Announcement bar',
          sectionKey: 'marquee',
          contentPanel: 'marquee_band',
          contentFields: [
            { key: 'marquee_show', label: 'Show marquee (1/0)', placeholder: '1' },
            { key: 'marquee_text', label: 'Marquee text', type: 'textarea' },
            { key: 'marquee_speed', label: 'Scroll speed (seconds)', placeholder: '40' },
            { key: 'marquee_bg', label: 'Background colour', placeholder: '#1E2D40' },
            { key: 'marquee_color', label: 'Text colour', placeholder: '#ffffff' },
          ],
        },
        {
          id: 'hero',
          label: 'Service hero',
          sectionKey: 'hero',
          contentPanel: 'service_page_band',
          contentFields: [
            {
              key: 'service_hero_meta_secure',
              label: 'Default secure chip (when service has no custom meta)',
              placeholder: '🔒 Secure & encrypted',
            },
            {
              key: 'service_hero_price_prefix',
              label: 'Price chip prefix',
              placeholder: '💰 From ',
            },
          ],
          contentNote: 'Hero image, title, and CTAs are per-service in the Service Registry page builder.',
          adminLink: { label: 'Edit in Service Registry', path: '/admin/services' },
        },
        {
          id: 'trust_badges',
          label: 'Trust badges',
          sectionKey: 'trust_badges',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'description',
          label: 'Description & prose',
          sectionKey: 'description',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'features',
          label: 'Why choose block',
          sectionKey: 'features',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'process',
          label: 'Process steps',
          sectionKey: 'process',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'documents',
          label: 'Documents & eligibility',
          sectionKey: 'documents',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'benefits',
          label: 'Benefits list',
          sectionKey: 'benefits',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'faq',
          label: 'FAQ block',
          sectionKey: 'faq',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Edit service FAQs', path: '/admin/services' },
        },
        {
          id: 'pricing',
          label: 'Pricing / charges',
          sectionKey: 'pricing',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service pricing fields', path: '/admin/services' },
        },
        {
          id: 'testimonials',
          label: 'Testimonials',
          sectionKey: 'testimonials',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'related',
          label: 'Related services',
          sectionKey: 'related',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'cta',
          label: 'Bottom CTA band',
          sectionKey: 'cta',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'cms',
          label: 'Rich text / notes blocks',
          sectionKey: 'cms',
          contentPanel: 'service_page_band',
          adminLink: { label: 'Service page builder', path: '/admin/services' },
        },
        {
          id: 'wizard',
          label: 'Booking wizard (sidebar)',
          sectionKey: 'wizard',
          contentPanel: 'service_page_band',
          contentFields: [
            { key: 'service_wizard_tag_ssl', label: 'Trust tag (SSL)', placeholder: '🔒 SSL' },
            { key: 'service_wizard_tag_quote', label: 'Trust tag (quote SLA)', placeholder: '📧 Quote 24h' },
            { key: 'service_wizard_upload_hint', label: 'Upload hint', placeholder: 'PDF, JPG, PNG · Max 10 MB each' },
            { key: 'service_wizard_intro_prefix', label: 'Intro line prefix', placeholder: "You're booking:" },
            { key: 'service_mobile_cta_label', label: 'Mobile sticky CTA', placeholder: 'Start Request' },
            {
              key: 'service_why_fallback_json',
              label: 'Why choose cards JSON (when no CMS section)',
              type: 'textarea',
              rows: 8,
              hint: '[{"icon":"🔐","t":"Secure Platform","d":"…"}]',
            },
            { key: 'service_why_title', label: 'Why choose heading', placeholder: 'Why Choose {siteName}?' },
          ],
          adminLink: { label: 'Form builder', path: '/admin/services' },
        },
      ]
      break
    case 'contact':
      sections = [
        {
          id: 'intro',
          label: 'Page Hero',
          sectionKey: 'intro',
          contentFields: [
            { key: 'contact_page_title', label: 'Hero title', placeholder: 'Contact Us' },
            { key: 'contact_page_subtitle', label: 'Hero subtitle', type: 'textarea' },
            {
              key: 'contact_hero_meta_json',
              label: 'Hero meta chips JSON',
              type: 'textarea',
              rows: 3,
              hint: '[{"icon":"💬","label":"WhatsApp support"}]',
            },
          ],
        },
        {
          id: 'contact',
          label: 'Contact form',
          sectionKey: 'contact',
          contentPanel: 'contact_channels',
          contentFields: [
            { key: 'contact_title', label: 'Left column heading', placeholder: 'Get in Touch' },
            { key: 'contact_form_title', label: 'Form title', placeholder: 'Send Us a Message' },
            { key: 'contact_email', label: 'Display email (overrides platform default)' },
            { key: 'contact_phone', label: 'Display phone (overrides platform default)' },
            { key: 'contact_address', label: 'Display address', type: 'textarea' },
            { key: 'contact_hours_title', label: 'Business hours card title', placeholder: 'Business Hours' },
            {
              key: 'contact_hours_json',
              label: 'Business hours JSON',
              type: 'textarea',
              rows: 4,
              hint: '[["Mon – Fri","9:00 AM – 8:00 PM IST"],["Sunday","Emergency Support Only"]]',
            },
            { key: 'contact_field_name_placeholder', label: 'Name field placeholder', placeholder: 'Full Name' },
            { key: 'contact_field_email_placeholder', label: 'Email placeholder', placeholder: 'Email Address' },
            { key: 'contact_field_phone_placeholder', label: 'Phone placeholder', placeholder: 'Phone / WhatsApp' },
            { key: 'contact_field_subject_placeholder', label: 'Subject placeholder', placeholder: 'Subject' },
            { key: 'contact_field_message_placeholder', label: 'Message placeholder', placeholder: 'Your message…' },
            { key: 'contact_form_submit_text', label: 'Submit button', placeholder: 'Send Message →' },
            { key: 'contact_success_title', label: 'Success heading', placeholder: 'Message Sent!' },
            { key: 'contact_success_body', label: 'Success body', type: 'textarea' },
            { key: 'contact_failed_title', label: 'Error heading', placeholder: "Couldn't send your message" },
            { key: 'contact_failed_body', label: 'Error body', type: 'textarea' },
          ],
          adminLink: { label: 'Platform contact defaults', path: '/admin/settings' },
        },
      ]
      break
    case 'faq':
      sections = [
        {
          id: 'intro',
          label: 'Page Hero',
          sectionKey: 'intro',
          contentFields: [
            { key: 'faq_page_title', label: 'Hero title', placeholder: 'Frequently Asked Questions' },
            { key: 'faq_page_subtitle', label: 'Hero subtitle', type: 'textarea' },
            {
              key: 'faq_hero_meta_json',
              label: 'Hero meta chips JSON',
              type: 'textarea',
              rows: 3,
              hint: '[{"icon":"⚡","label":"Fast responses"},{"icon":"🛡️","label":"Transparent process"}]',
            },
          ],
        },
        {
          id: 'faq',
          label: 'FAQ accordion',
          sectionKey: 'faq',
          contentPanel: 'faq_database',
          contentFields: [
            { key: 'faq_section_title', label: 'Section heading (fallback when API empty)' },
          ],
          adminLink: { label: 'Manage FAQ items', path: '/admin/faqs' },
        },
        {
          id: 'faq_cta',
          label: 'Still have questions?',
          sectionKey: 'faq_cta',
          contentPanel: 'faq_page_cta',
          contentFields: [
            { key: 'faq_cta_title', label: 'Card heading', placeholder: 'Still have questions?' },
            { key: 'faq_cta_body', label: 'Supporting text', type: 'textarea' },
            { key: 'faq_cta_button', label: 'Button label', placeholder: 'Contact Us →' },
          ],
        },
      ]
      break
    case 'pricing':
      sections = [
        {
          id: 'intro',
          label: 'Page Hero',
          sectionKey: 'intro',
          contentFields: [
            { key: 'pricing_page_title', label: 'Hero title', placeholder: 'Pricing' },
            { key: 'pricing_page_subtitle', label: 'Hero subtitle', type: 'textarea' },
          ],
        },
        {
          id: 'pricing',
          label: 'Plans table',
          sectionKey: 'pricing',
          contentPanel: 'pricing_plans',
          contentFields: [
            { key: 'pricing_grid_eyebrow', label: 'Section eyebrow', placeholder: 'Pricing plans' },
            { key: 'pricing_grid_title', label: 'Section heading', placeholder: 'Choose the right level of support' },
            { key: 'pricing_grid_subtitle', label: 'Section subtitle', type: 'textarea' },
          ],
          adminLink: { label: 'Manage plans', path: '/admin/pricing' },
        },
        {
          id: 'compare',
          label: 'Comparison table',
          sectionKey: 'compare',
          contentPanel: 'pricing_compare',
          contentFields: [
            { key: 'pricing_compare_title', label: 'Comparison heading', placeholder: 'Compare plans side by side' },
            { key: 'pricing_compare_subtitle', label: 'Comparison intro', type: 'textarea' },
            { key: 'pricing_compare_brand_title', label: 'Brand compare section title', placeholder: 'NRIWAY vs. Traditional Agents' },
            { key: 'pricing_compare_brand_subtitle', label: 'Brand compare subtitle', type: 'textarea' },
            { key: 'pricing_compare_brand_name', label: 'Your brand column header', placeholder: 'NRIWAY' },
            { key: 'pricing_compare_other_name', label: 'Competitor column header', placeholder: 'Traditional Agents' },
            {
              key: 'pricing_compare_brand_rows_json',
              label: 'Brand comparison rows JSON',
              type: 'textarea',
              rows: 10,
              hint: '[["Feature","Us","Them"],["Transparent pricing","✅","❌"]]',
            },
          ],
          adminLink: { label: 'Manage plans', path: '/admin/pricing' },
        },
        {
          id: 'consultation',
          label: 'Consultation CTA',
          sectionKey: 'consultation',
          contentFields: [
            { key: 'pricing_consultation_title', label: 'CTA heading', placeholder: 'Book a Free Consultation' },
            { key: 'pricing_consultation_subtitle', label: 'CTA body', type: 'textarea' },
            { key: 'pricing_consultation_button', label: 'CTA button', placeholder: 'Book Free Consultation →' },
            { key: 'pricing_consultation_url', label: 'CTA link', type: 'url', placeholder: '/contact' },
          ],
        },
      ]
      break
    case 'about':
      sections = [
        {
          id: 'intro',
          label: 'Page Hero',
          sectionKey: 'intro',
          contentFields: [
            { key: 'about_page_title', label: 'Hero title', placeholder: 'About Us' },
            { key: 'about_page_subtitle', label: 'Hero subtitle', type: 'textarea' },
            {
              key: 'about_hero_meta_json',
              label: 'Hero meta chips JSON',
              type: 'textarea',
              rows: 4,
              hint: '[{"icon":"🌍","label":"50+ countries"},{"icon":"👥","label":"10,000+ clients"}]',
            },
          ],
        },
        {
          id: 'about',
          label: 'Story section',
          sectionKey: 'about',
          contentPanel: 'about_story',
          contentFields: [
            { key: 'about_eyebrow', label: 'Eyebrow label', placeholder: 'Our Story' },
            { key: 'about_heading', label: 'Heading' },
            { key: 'about_text', label: 'Primary body', type: 'textarea' },
            { key: 'about_text_secondary', label: 'Secondary body', type: 'textarea' },
            { key: 'about_image_url', label: 'Image URL', type: 'url' },
            { key: 'about_video_url', label: 'Video URL', type: 'url' },
            { key: 'about_highlights_json', label: 'Highlight stats JSON', type: 'textarea', rows: 4, hint: '[["10,000+","Clients Served"],["44+","Services"]]' },
          ],
        },
        {
          id: 'values',
          label: 'Core values',
          sectionKey: 'values',
          contentPanel: 'value_cards',
          contentFields: [
            { key: 'about_values_title', label: 'Section heading', placeholder: 'Our Core Values' },
            { key: 'about_values_json', label: 'Value cards JSON', type: 'textarea', rows: 10, hint: '[{"icon":"🔒","t":"Trust","d":"…"}]' },
          ],
        },
        {
          id: 'team',
          label: 'Team grid',
          sectionKey: 'team',
          contentPanel: 'team_members',
          contentFields: [
            { key: 'about_team_title', label: 'Section heading', placeholder: 'Meet Our Team' },
            { key: 'about_team_json', label: 'Team members JSON', type: 'textarea', rows: 10 },
          ],
        },
        {
          id: 'cta',
          label: 'Bottom CTA',
          sectionKey: 'cta',
          contentPanel: 'marketing_cta',
          contentFields: [
            { key: 'about_cta_title', label: 'Heading', placeholder: 'Ready to Get Started?' },
            { key: 'about_cta_subtitle', label: 'Subtitle', type: 'textarea' },
            { key: 'about_cta_primary_text', label: 'Primary button text', placeholder: 'Explore Services' },
            { key: 'about_cta_primary_url', label: 'Primary button link', type: 'url', placeholder: '/services' },
            { key: 'about_cta_secondary_text', label: 'Secondary button text', placeholder: 'Create Free Account' },
            { key: 'about_cta_secondary_url', label: 'Secondary button link', type: 'url', placeholder: '/register' },
          ],
        },
      ]
      break
    case 'how-it-works':
      sections = [
        {
          id: 'intro',
          label: 'Page Hero',
          sectionKey: 'intro',
          contentFields: [
            { key: 'hiw_page_title', label: 'Hero title', placeholder: 'How It Works' },
            { key: 'hiw_page_subtitle', label: 'Hero subtitle', type: 'textarea' },
            {
              key: 'hiw_hero_meta_json',
              label: 'Hero meta chips JSON',
              type: 'textarea',
              rows: 3,
              hint: '[{"icon":"📝","label":"Submit online"}]',
            },
          ],
        },
        {
          id: 'process',
          label: 'Steps',
          sectionKey: 'process',
          contentPanel: 'hiw_steps',
          contentFields: [
            { key: 'hiw_page_steps_json', label: 'Steps JSON', type: 'textarea', rows: 14, hint: '[{"n":1,"icon":"🔍","t":"Title","d":"Desc"}]' },
          ],
        },
        {
          id: 'hiw_cta',
          label: 'Bottom callout',
          sectionKey: 'hiw_cta',
          contentPanel: 'hiw_page_callout',
          contentFields: [
            { key: 'hiw_page_cta_title', label: 'Callout heading' },
            { key: 'hiw_page_cta_subtitle', label: 'Callout subtitle', type: 'textarea' },
          ],
        },
      ]
      break
    case 'blog':
      sections = [
        {
          id: 'hero',
          label: 'Blog header',
          sectionKey: 'hero',
          contentFields: [
            { key: 'blog_hero_eyebrow', label: 'Eyebrow label', placeholder: 'NRI Knowledge Hub' },
            { key: 'blog_hero_title', label: 'Hero heading', placeholder: 'Expert Guides for NRIs Living Abroad' },
            { key: 'blog_hero_subtitle', label: 'Hero subtitle', type: 'textarea' },
            { key: 'blog_search_placeholder', label: 'Search placeholder', placeholder: 'Search articles…' },
            { key: 'seo_title', label: 'SEO title (document head)', placeholder: 'Blog & Insights' },
            { key: 'seo_description', label: 'SEO meta description', type: 'textarea' },
          ],
        },
        {
          id: 'feed',
          label: 'Article feed',
          sectionKey: 'feed',
          contentPanel: 'blog_posts',
          contentFields: [
            {
              key: 'blog_categories_json',
              label: 'Filter chips JSON',
              type: 'textarea',
              rows: 3,
              hint: '["All","Immigration","Property"] — leave empty for default set',
            },
          ],
          adminLink: { label: 'Blog posts', path: '/admin/blog' },
        },
      ]
      break
    case 'city':
      sections = [
        {
          id: 'hero',
          label: 'City hero',
          sectionKey: 'hero',
          contentPanel: 'cities_registry',
          contentFields: [{ key: 'cities_section_title', label: 'Fallback hero title' }],
          adminLink: { label: 'City Manager', path: '/admin/cities' },
        },
        {
          id: 'content',
          label: 'Local content',
          sectionKey: 'content',
          contentPanel: 'cities_registry',
          adminLink: { label: 'City Manager', path: '/admin/cities' },
        },
      ]
      break
    case 'terms':
    case 'privacy':
      sections = [
        {
          id: 'intro',
          label: 'Legal header',
          sectionKey: 'intro',
          contentFields: [
            { key: 'seo_title', label: 'Page title (SEO)' },
            { key: 'seo_description', label: 'Meta description', type: 'textarea' },
          ],
        },
        {
          id: 'content',
          label: 'Document body',
          sectionKey: 'content',
          contentPanel: 'legal_document',
          contentFields: [
            { key: 'custom_css_global', label: 'Legal page CSS override', type: 'textarea', rows: 4 },
          ],
        },
      ]
      break
    case 'visa':
    case 'country':
      sections = [
        {
          id: 'hero',
          label: 'Hub hero',
          sectionKey: 'hero',
          contentFields: [
            { key: 'platform_tagline', label: 'Hub eyebrow / tagline' },
            { key: 'seo_title', label: 'Hub title (SEO)' },
          ],
        },
        {
          id: 'content',
          label: 'Hub content',
          sectionKey: 'content',
          contentPanel: 'services_registry',
          adminLink: { label: 'Service Registry', path: '/admin/services' },
        },
      ]
      break
    default:
      sections = [MARKETING_INTRO]
  }
  return [PAGE_DEFAULTS, ...applyTemplateHide(t.id, sections)]
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
