/** Minimal REST mocks for Playwright smoke tests. */

const MOCK_SECTIONS = [
  {
    id: 1,
    type: 'trust_badges',
    title: 'Trust',
    sort_order: 1,
    is_visible: 1,
    content: {
      badges: [
        { icon: '⭐', value: '4.9', label: 'Rating' },
        { icon: '👥', value: '500+', label: 'Reviews' },
      ],
    },
  },
  {
    id: 2,
    type: 'description',
    title: 'Overview',
    sort_order: 2,
    is_visible: 1,
    content: { heading: 'Complete Guide', html: '<p>Full property management for NRIs.</p>' },
  },
  {
    id: 3,
    type: 'process',
    title: 'Process',
    sort_order: 3,
    is_visible: 1,
    content: {
      steps: [{ title: 'Submit', desc: 'Fill the wizard.' }, { title: 'Quote', desc: 'Within 24 hours.' }],
    },
  },
  {
    id: 4,
    type: 'charges',
    title: 'Fees',
    sort_order: 4,
    is_visible: 1,
    content: {
      heading: 'Charges',
      rows: [{ label: 'Service fee', amount: '₹5,000' }],
      note: 'Final quote after document review',
    },
  },
  {
    id: 5,
    type: 'faq',
    title: 'FAQ',
    sort_order: 5,
    is_visible: 1,
    content: { items: [{ question: 'How long?', answer: '7–14 days typical.' }] },
  },
  {
    id: 6,
    type: 'testimonials',
    title: 'Reviews',
    sort_order: 6,
    is_visible: 1,
    content: {
      items: [{ name: 'Priya S.', text: 'Excellent end-to-end support.', rating: 5 }],
    },
  },
  {
    id: 7,
    type: 'why_choose',
    title: 'Why us',
    sort_order: 7,
    is_visible: 1,
    content: {
      cards: [{ icon: '🔐', title: 'Secure', desc: 'Encrypted uploads.' }],
    },
  },
  {
    id: 8,
    type: 'cta',
    title: 'CTA',
    sort_order: 8,
    is_visible: 1,
    content: {
      headline: 'Ready to start?',
      sub: 'Get a free quote today.',
      btn: 'Get Quote',
      url: '#booking-form',
    },
  },
]

const MOCK_SERVICE = {
  service: {
    id: 1,
    slug: 'complete-property-management',
    name: 'Property Management',
    short_desc: 'End-to-end property management for NRIs.',
    description: 'Full service description for e2e.',
    category_name: 'Property',
    category_slug: 'property',
    turnaround: '7–14 days',
    price_range: '₹5,000+',
    icon: '🏠',
    form_schema: [
      { key: 'need', label: 'What do you need?', type: 'text', step: 1, required: true },
      { key: 'email', label: 'Email', type: 'email', step: 98, required: true },
    ],
    required_docs: ['PAN copy', 'Property papers'],
  },
};

const MOCK_CATEGORIES = [
  { id: 1, slug: 'property', name: 'Property', icon: '🏠', color: '#4A6FA5' },
  { id: 2, slug: 'financial', name: 'Financial', icon: '💰', color: '#7b1fa2' },
  { id: 3, slug: 'immigration', name: 'Immigration', icon: '✈️', color: '#c62828' },
  { id: 4, slug: 'education', name: 'Education', icon: '🎓', color: '#00695c' },
]

const ORIGIN = 'http://127.0.0.1:4173'

function imgFor(slug) {
  return `${ORIGIN}/?s2nri_img=${slug.includes('tax') ? 'tax' : slug.includes('property') ? 'property' : 'apostille'}`
}

function mockServicesForCategory(slug) {
  const base = MOCK_SERVICE.service
  const cat = MOCK_CATEGORIES.find((c) => c.slug === slug)
  return [
    {
      ...base,
      id: 1,
      slug: `complete-${slug}-management`,
      name: `${cat?.name || slug} Management`,
      category_slug: slug,
      category_name: cat?.name || slug,
      color: cat?.color,
      image_url: imgFor(slug),
    },
    {
      ...base,
      id: 2,
      slug: `${slug}-assistance`,
      name: `${cat?.name || slug} Assistance`,
      category_slug: slug,
      category_name: cat?.name || slug,
      color: cat?.color,
      image_url: imgFor(slug),
    },
  ]
}

function mockDirectoryCatalog() {
  let id = 1
  const out = []
  for (const cat of MOCK_CATEGORIES) {
    for (const svc of mockServicesForCategory(cat.slug)) {
      out.push({ ...svc, id: id++ })
    }
  }
  return out
}

function mockDirectoryCategories() {
  return MOCK_CATEGORIES.map((c) => ({
    ...c,
    service_count: mockServicesForCategory(c.slug).length,
  }))
}

export function handleMockApi(pathname, method) {
  const path = pathname.replace(/^\/mock-api\/?/, '').replace(/^\//, '').split('?')[0];
  const key = `${method} ${path}`;

  const stripped = pathname.replace(/^\/mock-api\/?/, '').replace(/^\//, '')
  const qmark = stripped.indexOf('?')
  const q = new URLSearchParams(qmark >= 0 ? stripped.slice(qmark + 1) : '')
  const surface = q.get('surface') || ''

  if (method === 'GET' && path === 'categories') {
    if (surface === 'directory') {
      return { categories: mockDirectoryCategories() }
    }
    return { categories: MOCK_CATEGORIES }
  }
  if (method === 'GET' && path === 'services') {
    if (surface === 'directory' || surface === 'search') {
      const all = mockDirectoryCatalog()
      const term = (q.get('search') || '').toLowerCase()
      const filtered = term
        ? all.filter((s) => s.name.toLowerCase().includes(term) || s.slug.includes(term))
        : all
      return { services: filtered, categories: [] }
    }
    const cat = q.get('category') || 'property'
    return { services: mockServicesForCategory(cat), categories: [] }
  }
  if (method === 'GET' && path === 'cities') {
    return {
      cities: [
        { name: 'Pune', slug: 'pune' },
        { name: 'Mumbai', slug: 'mumbai' },
        { name: 'Delhi', slug: 'delhi' },
        { name: 'Bangalore', slug: 'bangalore' },
      ],
    }
  }

  if (method === 'GET' && path.startsWith('bookings')) {
    const idMatch = path.match(/^bookings\/(\d+)/)
    if (idMatch) {
      const id = Number(idMatch[1])
      return {
        booking: {
          id,
          booking_ref: `BK-E2E-${String(id).padStart(3, '0')}`,
          service_name: 'Property Management',
          status: 'submitted',
          created_at: '2026-01-15 10:00:00',
          field_data: {},
          messages: [],
          documents: [],
          quote: null,
        },
      }
    }
    return {
      rows: [
        {
          id: 1,
          booking_ref: 'BK-E2E-001',
          service_name: 'Property Management',
          status: 'submitted',
          created_at: '2026-01-15 10:00:00',
        },
      ],
      total: 1,
    }
  }
  if (method === 'GET' && path === 'profile') {
    return {
      id: 1,
      email: 'customer@e2e.test',
      name: 'E2E Customer',
      profile: { phone: '', whatsapp: '', country: 'USA' },
    }
  }
  if (method === 'GET' && path.startsWith('notifications')) {
    return { rows: [], total: 0 }
  }
  if (method === 'GET' && path === 'tickets') {
    return {
      tickets: [
        {
          id: 1,
          subject: 'E2E support ticket',
          status: 'open',
          created_at: '2026-01-10 12:00:00',
        },
      ],
    }
  }

  if (method === 'GET' && path.startsWith('admin/bookings')) {
    return {
      rows: [
        {
          id: 1,
          booking_ref: 'BK-E2E-001',
          customer_name: 'E2E Customer',
          service_name: 'Property Management',
          status: 'submitted',
          qual_status: 'qualified',
          created_at: '2026-01-15 10:00:00',
        },
      ],
      total: 1,
    }
  }
  if (method === 'GET' && path === 'admin/settings') {
    return { settings: { platform_name: { value: 'Services2NRI E2E' }, primary_color: { value: '#4A6FA5' } } }
  }
  if (method === 'GET' && path === 'admin/settings') {
    return {
      settings: {
        platform_name: { value: 'Services2NRI E2E' },
        primary_color: { value: '#4A6FA5' },
        hero_heading_1: { value: 'Stay Connected to' },
        hero_heading_2: { value: 'INDIA' },
        custom_css_homepage: { value: '' },
      },
    }
  }
  if (method === 'PUT' && path === 'admin/settings') {
    return { ok: true }
  }
  if (method === 'GET' && path === 'admin/services') {
    return {
      services: [
        {
          id: 1,
          name: 'Property Management',
          slug: 'complete-property-management',
          icon: '🏠',
          category_name: 'Property',
        },
      ],
    }
  }
  if (method === 'GET' && path.match(/^admin\/services\/\d+\/form-fields$/)) {
    return { fields: [{ id: 1, field_key: 'notes', label: 'Notes', field_type: 'textarea', step: 1, sort_order: 1, is_active: true }] }
  }
  if (method === 'GET' && path.match(/^admin\/services\/\d+\/sections$/)) {
    return { sections: [{ id: 1, type: 'description', title: 'Overview', sort_order: 1, is_visible: 1, content: {} }] }
  }

  if (method === 'GET' && path === 'admin/design') {
    return {
      config: {
        colors: { primary: '#4A6FA5' },
        spacing: {},
        fonts: {},
        typography: {},
        radius: {},
        shadow: {},
        motion: {},
        breakpoints: { sm: 640, md: 768, lg: 1024 },
        components: {},
        overrides: {},
        chrome: {},
      },
      presets: { modern: { label: 'Modern', description: 'Default' } },
      fonts: { library: [] },
      revision: 'e2e-1',
    }
  }
  if (method === 'GET' && path === 'admin/service-registry') {
    return { services: [], categories: [], cities: [], surfaces: {}, city_surfaces: {} }
  }
  if (method === 'GET' && path === 'admin/analytics/dashboard') {
    return { stats: { total_bookings: 1 }, recent: [], trends: {} }
  }

  const routes = {
    'GET settings/public': { settings: windowLikeSettings() },
    'GET navigation/public': { menu: { cols: [] }, flat: [] },
    'GET services': { services: mockServicesForCategory('property'), categories: [] },
    'GET categories': { categories: MOCK_CATEGORIES },
    'GET testimonials': { testimonials: [{ id: 1, name: 'Test User', quote: 'Great service.', rating: 5 }] },
    'GET services/complete-property-management': MOCK_SERVICE,
    'GET services/complete-property-management/sections': { sections: MOCK_SECTIONS },
    'GET faqs': { faqs: [{ id: 1, q: 'Test?', a: 'Yes.' }] },
    'GET pricing-plans': { plans: [] },
    'GET cities': { cities: [] },
    'GET blog': { posts: [] },
    'POST contact': { ok: true },
  };

  if (key.startsWith('GET services/') && key.endsWith('/sections')) {
    return { sections: MOCK_SECTIONS };
  }
  if (key.startsWith('GET services/') && !key.includes('/sections')) {
    return MOCK_SERVICE;
  }

  return routes[key] ?? { ok: true, settings: windowLikeSettings() };
}

function windowLikeSettings() {
  return {
    platform_name: 'Services2NRI',
    primary_color: '#4A6FA5',
    platform_whatsapp: '919876543210',
    google_rating: '4.9',
    google_review_count: '10,000+',
    hero_heading_1: 'Stay Connected to',
    hero_heading_2: 'INDIA',
    hero_subheading: 'Without the Paperwork Stress',
    stat_1_number: '10,000+',
    stat_1_label: 'Happy Clients',
  };
}
