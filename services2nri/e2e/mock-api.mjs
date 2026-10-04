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

export function handleMockApi(pathname, method) {
  const path = pathname.replace(/^\/mock-api\/?/, '').replace(/^\//, '').split('?')[0];
  const key = `${method} ${path}`;

  const routes = {
    'GET settings/public': { settings: windowLikeSettings() },
    'GET navigation/public': { menu: { cols: [] }, flat: [] },
    'GET services': { services: [MOCK_SERVICE.service], categories: [] },
    'GET categories': { categories: [{ id: 1, slug: 'property', name: 'Property', icon: '🏠' }] },
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
    platform_name: 'Services2NRI E2E',
    primary_color: '#4A6FA5',
    platform_whatsapp: '919876543210',
    google_rating: '4.9',
    google_review_count: '10,000+',
  };
}
