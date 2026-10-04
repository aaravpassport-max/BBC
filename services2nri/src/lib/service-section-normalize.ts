import type { ServiceSection, SectionContent } from '@/types'

function parseContent(raw: ServiceSection['content']): SectionContent {
  if (raw == null) return {}
  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw) as SectionContent
      return parsed && typeof parsed === 'object' ? parsed : {}
    } catch {
      return { html: raw }
    }
  }
  return raw
}

/** Align builder/API JSON shapes with SectionRenderer expectations. */
export function normalizeServiceSection(sec: ServiceSection): ServiceSection {
  const type = sec.type
  const r = { ...parseContent(sec.content) } as Record<string, unknown>

  if (type === 'faq' && Array.isArray(r.items)) {
    r.items = (r.items as Array<Record<string, string>>).map((item) => ({
      q: item.q || item.question || '',
      a: item.a || item.answer || '',
    }))
  }

  if (type === 'testimonials' && Array.isArray(r.items)) {
    r.items = (r.items as Array<Record<string, unknown>>).map((item) => ({
      name: String(item.name || 'Customer'),
      quote: String(item.quote || item.text || item.review || ''),
      rating: typeof item.rating === 'number' ? item.rating : undefined,
      location: item.location ? String(item.location) : undefined,
    }))
  }

  if (type === 'process' && Array.isArray(r.steps)) {
    r.steps = (r.steps as Array<Record<string, string>>).map((step) => ({
      title: step.title || step.name || '',
      desc: step.desc || step.description || '',
    }))
  }

  if ((type === 'documents' || type === 'eligibility' || type === 'notes') && Array.isArray(r.items)) {
    r.items = (r.items as unknown[]).map((item) =>
      typeof item === 'string' ? item : String((item as Record<string, string>).text || (item as Record<string, string>).label || ''),
    )
  }

  if ((type === 'benefits' || type === 'features' || type === 'highlights') && Array.isArray(r.items)) {
    r.items = (r.items as Array<Record<string, unknown>>).map((item) => ({
      icon: item.icon ? String(item.icon) : undefined,
      text: item.text ? String(item.text) : undefined,
      title: String(item.title || item.text || ''),
      desc: String(item.desc || item.description || ''),
    }))
  }

  if (type === 'why_choose' && Array.isArray(r.cards)) {
    r.cards = (r.cards as Array<Record<string, string>>).map((c) => ({
      icon: c.icon || '⭐',
      title: c.title || c.name || '',
      desc: c.desc || c.description || '',
    }))
  }

  if (type === 'trust_badges' && Array.isArray(r.badges)) {
    r.badges = (r.badges as Array<Record<string, string>>).map((b) => ({
      icon: b.icon || '⭐',
      value: b.value || b.val || '',
      label: b.label || '',
    }))
  }

  if (type === 'charges') {
    const rows = (r.rows || r.line_items || r.fees) as unknown
    if (Array.isArray(rows)) r.rows = rows
  }

  return { ...sec, content: r as SectionContent }
}

const SECTION_CONTENT_DEFAULTS: Partial<Record<ServiceSection['type'], SectionContent>> = {
  process: {
    steps: [
      { title: 'Submit Request', desc: 'Fill the form on this page. No login needed.' },
      { title: 'Document Review', desc: 'Our expert team reviews your submission within 24 hours.' },
      { title: 'Get Quote', desc: 'Receive a detailed, itemised quote.' },
    ],
  },
  faq: { items: [{ q: 'How long does it take?', a: 'Typically 7–15 business days depending on document completeness.' }] },
  testimonials: { items: [{ name: 'Customer', text: 'Professional, transparent service from start to finish.', rating: 5 }] },
  documents: { items: ['Valid ID proof', 'Supporting documents as listed in your quote'] },
  eligibility: { items: ['Non-Resident Indian (NRI) or OCI holder'] },
  benefits: { items: [{ icon: '✅', text: 'Expert handling end-to-end' }] },
  features: { items: [{ icon: '⭐', title: 'Dedicated support', desc: 'Track progress in your dashboard.' }] },
  trust_badges: {
    badges: [
      { icon: '⭐', value: '4.9', label: 'Google Rating' },
      { icon: '👥', value: '10,000+', label: 'Happy Customers' },
    ],
  },
}

export function hydrateEmptySection(sec: ServiceSection): ServiceSection {
  const normalized = normalizeServiceSection(sec)
  if (sectionHasRenderableBody(normalized)) return normalized
  const defaults = SECTION_CONTENT_DEFAULTS[normalized.type]
  if (!defaults) return normalized
  return normalizeServiceSection({
    ...normalized,
    content: { ...defaults, ...normalized.content } as SectionContent,
  })
}

export function sectionHasRenderableBody(sec: ServiceSection): boolean {
  const r = sec.content as Record<string, unknown>
  const type = sec.type

  switch (type) {
    case 'trust_badges':
      return Array.isArray(r.badges) && r.badges.length > 0
    case 'why_choose':
      return Array.isArray(r.cards) && r.cards.length > 0
    case 'process':
      return Array.isArray(r.steps) && r.steps.length > 0
    case 'faq':
      return Array.isArray(r.items) && r.items.length > 0
    case 'benefits':
    case 'features':
    case 'highlights':
      return Array.isArray(r.items) && r.items.length > 0
    case 'documents':
    case 'eligibility':
    case 'notes':
      return Array.isArray(r.items) && r.items.length > 0
    case 'testimonials':
      return Array.isArray(r.items) && r.items.length > 0
    case 'related':
      return Array.isArray(r.slugs) && r.slugs.length > 0
    case 'cta':
      return !!(r.headline || r.btn || sec.title)
    case 'description':
    case 'security':
    case 'text':
    case 'charges':
      return !!(r.html || r.heading || sec.title)
    default:
      return !!(r.html || sec.title)
  }
}
