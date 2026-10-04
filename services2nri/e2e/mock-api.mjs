/** Minimal REST mocks for Playwright smoke tests. */

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

  const routes = {
    'GET settings/public': { settings: windowLikeSettings() },
    'GET navigation/public': { menu: { cols: [] }, flat: [] },
    'GET services': { services: mockServicesForCategory('property'), categories: [] },
    'GET categories': { categories: MOCK_CATEGORIES },
    'GET testimonials': { testimonials: [{ id: 1, name: 'Test User', quote: 'Great service.', rating: 5 }] },
    'GET services/complete-property-management': MOCK_SERVICE,
    'GET services/complete-property-management/sections': { sections: [] },
    'GET faqs': { faqs: [{ id: 1, q: 'Test?', a: 'Yes.' }] },
    'GET pricing-plans': { plans: [] },
    'GET cities': { cities: [] },
    'GET blog': { posts: [] },
    'POST contact': { ok: true },
  };

  if (key.startsWith('GET services/') && key.endsWith('/sections')) {
    return { sections: [] };
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
