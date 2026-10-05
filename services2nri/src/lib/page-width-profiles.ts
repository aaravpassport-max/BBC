/**
 * Human-friendly width controls per public page type (maps to widths.* design config).
 */
import type { GlobalKey } from '@/pages/admin/width-layout-shared'

export type WidthProfileScope = 'page' | 'section'

export type SimpleWidthControlDef = {
  id: string
  label: string
  hint: string
  widthKey: GlobalKey
  scope: WidthProfileScope
  /** When scope=section, which band (e.g. hero on home). */
  sectionKey?: string
  showResponsive?: boolean
}

export type PageWidthProfile = {
  pageTypeKey: string
  title: string
  subtitle: string
  previewUrl: string
  controls: SimpleWidthControlDef[]
}

const HOME_CONTROLS: SimpleWidthControlDef[] = [
  {
    id: 'home_hero',
    label: 'Hero width',
    hint: 'How wide the homepage hero band feels on desktop — edge-to-edge or contained.',
    widthKey: 'section_standard',
    scope: 'section',
    sectionKey: 'hero',
    showResponsive: true,
  },
  {
    id: 'home_main',
    label: 'Main page shell',
    hint: 'Overall homepage container (header alignment, main grid).',
    widthKey: 'page_max',
    scope: 'page',
    showResponsive: true,
  },
  {
    id: 'home_sections',
    label: 'Default section width',
    hint: 'Most homepage bands (services, FAQ, testimonials).',
    widthKey: 'section_standard',
    scope: 'page',
    showResponsive: true,
  },
  {
    id: 'home_content',
    label: 'Reading / text column',
    hint: 'Long copy blocks inside sections.',
    widthKey: 'content_max',
    scope: 'page',
    showResponsive: true,
  },
  {
    id: 'home_gutter',
    label: 'Left & right spacing',
    hint: 'Breathing room on the sides of the page on each device.',
    widthKey: 'padding_x',
    scope: 'page',
    showResponsive: true,
  },
  {
    id: 'home_wide',
    label: 'Wide bands',
    hint: 'Dense grids or comparison rows when you need extra width.',
    widthKey: 'section_wide',
    scope: 'page',
    showResponsive: false,
  },
]

const SERVICE_CONTROLS: SimpleWidthControlDef[] = [
  {
    id: 'svc_hero',
    label: 'Service hero width',
    hint: 'Top hero on individual service pages.',
    widthKey: 'section_standard',
    scope: 'section',
    sectionKey: 'hero',
    showResponsive: true,
  },
  {
    id: 'svc_content',
    label: 'Service content width',
    hint: 'Main article / description column.',
    widthKey: 'content_max',
    scope: 'page',
    showResponsive: true,
  },
  {
    id: 'svc_blocks',
    label: 'Content blocks',
    hint: 'Feature grids, requirements, pricing tables.',
    widthKey: 'section_standard',
    scope: 'page',
    showResponsive: true,
  },
  {
    id: 'svc_sidebar',
    label: 'Sidebar / wizard column',
    hint: 'Booking wizard and sticky side panels.',
    widthKey: 'inner_max',
    scope: 'page',
    showResponsive: true,
  },
  {
    id: 'svc_gutter',
    label: 'Page side spacing',
    hint: 'Horizontal inset around the service page content.',
    widthKey: 'padding_x',
    scope: 'page',
    showResponsive: true,
  },
]

const DIRECTORY_CONTROLS: SimpleWidthControlDef[] = [
  {
    id: 'dir_hero',
    label: 'Page hero width',
    widthKey: 'section_standard',
    scope: 'section',
    sectionKey: 'hero',
    hint: 'Intro band at the top of listing pages.',
    showResponsive: true,
  },
  {
    id: 'dir_grid',
    label: 'Cards & grid width',
    widthKey: 'section_wide',
    scope: 'page',
    hint: 'Service cards, directory results, category tiles.',
    showResponsive: true,
  },
  {
    id: 'dir_shell',
    label: 'Page shell',
    widthKey: 'page_max',
    scope: 'page',
    hint: 'Maximum width of the listing page.',
    showResponsive: true,
  },
  {
    id: 'dir_gutter',
    label: 'Side spacing',
    widthKey: 'padding_x',
    scope: 'page',
    hint: 'Horizontal inset on mobile and desktop.',
    showResponsive: true,
  },
]

const MARKETING_CONTROLS: SimpleWidthControlDef[] = [
  {
    id: 'mkt_hero',
    label: 'Hero width',
    widthKey: 'section_standard',
    scope: 'section',
    sectionKey: 'hero',
    hint: 'Top intro band for About, Contact, FAQ, etc.',
    showResponsive: true,
  },
  {
    id: 'mkt_body',
    label: 'Main content width',
    widthKey: 'content_max',
    scope: 'page',
    hint: 'Primary reading column.',
    showResponsive: true,
  },
  {
    id: 'mkt_shell',
    label: 'Page shell',
    widthKey: 'page_max',
    scope: 'page',
    hint: 'Overall page container.',
    showResponsive: true,
  },
  {
    id: 'mkt_gutter',
    label: 'Side spacing',
    widthKey: 'padding_x',
    scope: 'page',
    hint: 'Left and right padding.',
    showResponsive: true,
  },
]

const PROFILES: Record<string, PageWidthProfile> = {
  home: {
    pageTypeKey: 'home',
    title: 'Homepage layout & width',
    subtitle: 'Control how wide each part of the homepage feels — without touching CSS.',
    previewUrl: '/',
    controls: HOME_CONTROLS,
  },
  service: {
    pageTypeKey: 'service',
    title: 'Service page layout & width',
    subtitle: 'Hero, content column, and booking sidebar widths for service detail pages.',
    previewUrl: '/service/passport-renewal',
    controls: SERVICE_CONTROLS,
  },
  services: {
    pageTypeKey: 'services',
    title: 'Services directory layout & width',
    subtitle: 'Listing hero, card grid, and page shell for /services.',
    previewUrl: '/services',
    controls: DIRECTORY_CONTROLS,
  },
  category: {
    pageTypeKey: 'category',
    title: 'Category page layout & width',
    subtitle: 'Category listing hero and card grid widths.',
    previewUrl: '/services/passport',
    controls: DIRECTORY_CONTROLS,
  },
  blog: {
    pageTypeKey: 'blog',
    title: 'Blog layout & width',
    subtitle: 'Article feed and reading column widths.',
    previewUrl: '/blog',
    controls: MARKETING_CONTROLS,
  },
  city: {
    pageTypeKey: 'city',
    title: 'City landing layout & width',
    subtitle: 'Geo landing hero and local content column.',
    previewUrl: '/cities/dubai',
    controls: MARKETING_CONTROLS,
  },
  page: {
    pageTypeKey: 'page',
    title: 'Marketing page layout & width',
    subtitle: 'About, Contact, FAQ, and similar static pages.',
    previewUrl: '/about',
    controls: MARKETING_CONTROLS,
  },
  listing: {
    pageTypeKey: 'listing',
    title: 'Directory layout & width',
    subtitle: 'Search results and directory-style listings.',
    previewUrl: '/services',
    controls: DIRECTORY_CONTROLS,
  },
}

export function widthProfileForPageType(pageType: string | undefined, pageSlug?: string): PageWidthProfile {
  const key = pageType || 'page'
  if (PROFILES[key]) return PROFILES[key]
  if (pageSlug && PROFILES[pageSlug]) return PROFILES[pageSlug]
  return {
    pageTypeKey: key,
    title: 'Page layout & width',
    subtitle: 'Container and section widths for this public page type.',
    previewUrl: '/',
    controls: MARKETING_CONTROLS,
  }
}

export function widthLayerBasePath(
  pageType: string,
  pageSlug: string | undefined,
  control: SimpleWidthControlDef,
): string[] {
  if (control.scope === 'section' && control.sectionKey) {
    if (pageType === 'home' && control.sectionKey === 'hero') {
      return ['widths', 'page_types', 'home', 'sections', control.sectionKey]
    }
    return ['widths', 'page_types', pageType, 'sections', control.sectionKey]
  }
  if (pageSlug) return ['widths', 'pages', pageSlug]
  return ['widths', 'page_types', pageType]
}
