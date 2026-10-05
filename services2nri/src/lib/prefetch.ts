import { api } from '@/lib/api'
import { fetchResource, isCacheFresh } from '@/lib/resource-cache'

/** Prefetch GET endpoints for likely next navigation (non-blocking). */
const ROUTE_API_MAP: Record<string, string[]> = {
  '/': [
    'categories?surface=homepage',
    'cities?surface=homepage',
    'testimonials',
  ],
  '/about': [],
  '/contact': [],
  '/how-it-works': [],
  '/faq': ['faqs'],
  '/pricing': ['pricing-plans'],
  '/blog': ['blog?per_page=20'],
  '/terms': [],
  '/privacy': [],
  '/services': ['categories?surface=directory', 'services?surface=directory'],
  '/dashboard': ['bookings?per_page=5', 'notifications?per_page=20'],
  '/dashboard/bookings': ['bookings?per_page=20&page=1'],
  '/dashboard/tickets': ['tickets?per_page=20'],
  '/dashboard/profile': ['profile'],
  '/admin': ['admin/analytics/dashboard'],
  '/admin/requests': ['admin/bookings?per_page=20&page=1'],
  '/admin/bookings': ['admin/bookings?per_page=20&page=1'],
  '/admin/payments': ['admin/payments?per_page=50&status=pending'],
  '/admin/customers': ['admin/customers?per_page=50'],
  '/admin/services': ['admin/services?per_page=200', 'admin/categories'],
  '/admin/categories': ['admin/categories'],
  '/admin/staff': ['admin/staff'],
  '/admin/reviews': ['admin/reviews?status=pending&per_page=50'],
  '/admin/tickets': ['admin/tickets?status=open&per_page=50'],
  '/admin/settings': ['admin/settings'],
  '/admin/audit-log': ['admin/audit-log?per_page=50&page=1'],
  '/service/birth-certificate': ['services/birth-certificate', 'services/birth-certificate/sections'],
}

/** Warm common marketing routes after first paint (does not block UI). */
const IDLE_PUBLIC_ROUTES = ['/services', '/blog', '/faq', '/pricing'] as const

let idlePrefetchStarted = false

export function prefetchApiGet(path: string): void {
  if (isCacheFresh('GET', path, 90_000)) return
  void fetchResource('GET', path, () => api.get(path), { ttl: 120_000, persist: true }).catch(() => {})
}

export function prefetchForRoute(internalPath: string): void {
  const clean = internalPath.split('?')[0].replace(/\/$/, '') || '/'
  const exact = ROUTE_API_MAP[clean]
  if (exact) {
    exact.forEach(prefetchApiGet)
    return
  }
  if (clean.startsWith('/service/')) {
    const slug = clean.slice('/service/'.length)
    if (slug) {
      prefetchApiGet(`services/${slug}`)
      prefetchApiGet(`services/${slug}/sections`)
    }
    return
  }
  if (clean.startsWith('/blog/') && clean.length > '/blog/'.length) {
    const slug = clean.slice('/blog/'.length)
    if (slug) {
      prefetchApiGet(`blog/${slug}`)
      prefetchApiGet('blog?per_page=4')
    }
    return
  }
  if (clean.startsWith('/cities/')) {
    prefetchApiGet('cities?surface=homepage')
    return
  }
  if (clean.startsWith('/services/') && clean.length > '/services/'.length) {
    prefetchApiGet('categories?surface=directory')
    prefetchApiGet('services?surface=directory')
    return
  }
  if (clean.startsWith('/admin/customers/')) {
    prefetchApiGet(`admin/customers/${clean.split('/').pop()}`)
    return
  }
  if (clean.match(/\/admin\/(requests|bookings)\/\d+/)) {
    const id = clean.split('/').pop()
    prefetchApiGet(`admin/bookings/${id}`)
  }
}

export function prefetchPublicRoutesIdle(): void {
  if (idlePrefetchStarted) return
  idlePrefetchStarted = true
  const run = () => {
    IDLE_PUBLIC_ROUTES.forEach(prefetchForRoute)
  }
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 4000 })
  } else {
    window.setTimeout(run, 1200)
  }
}
