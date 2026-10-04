/**
 * App router — exact match of Tr() component in compiled app.js
 *
 * Includes:
 *   - Zn: global CSS injector (injects into <head> once)
 *   - wr: scroll-to-top on route change
 *   - Tr: notification loader on login
 *   - jr: ErrorBoundary class component
 *   - All 48 routes in exact order from compiled bundle
 *   - Lazy loading for admin chunk (all /admin/* routes)
 *   - 404 fallback page
 */

import React, { Suspense, lazy, useEffect, useState, Component } from 'react'
import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom'

// ── Import styles ─────────────────────────────────────────────────────────────
import './styles/global.css'
import { applyDesignConfig } from '@/lib/apply-design-config'

// ── Public pages ──────────────────────────────────────────────────────────────
import { HomePage }          from './pages/public/HomePage'
import { ServicesPage }      from './pages/public/ServicesPage'
import { ServiceDetailPage } from './pages/public/ServiceDetailPage'
import {
  AboutPage, ContactPage, HowItWorksPage, FAQPage, PricingPage,
  BlogListPage, BlogDetailPage, TermsPage, PrivacyPage, CityPage,
} from './pages/public/index'

// ── Auth pages ────────────────────────────────────────────────────────────────
import { LoginPage, RegisterPage, ForgotPasswordPage, AuthGuard } from './pages/auth/index'

// ── Customer dashboard ────────────────────────────────────────────────────────
import {
  CustomerPortal,
  CustomerDashboard,
  BookingListPage,
  BookingDetailPage,
  NewBookingPage,
  ProfilePage,
  TicketsPage,
} from './pages/customer/index'

// ── Admin portal ──────────────────────────────────────────────────────────────
import { AdminPortal } from './pages/admin/index'

// ── Lazy admin pages (split chunk) ────────────────────────────────────────────
const AdminDashboard         = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminDashboard })))
const AdminBookingList       = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminBookingList })))
const AdminBookingDetail     = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminBookingDetail })))
const AdminPayments          = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminPayments })))
const AdminSettings          = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminSettings })))
const AdminDesignSystem      = lazy(() => import('./pages/admin/design-system').then((m) => ({ default: m.AdminDesignSystem })))
const AdminCustomers         = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminCustomers })))
const AdminCustomerDetail    = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminCustomerDetail })))
const AdminStaff             = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminStaff })))
const AdminServices          = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminServices })))
const AdminServicePageBuilder = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminServicePageBuilder })))
const AdminCategories        = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminCategories })))
const AdminReviews           = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminReviews })))
const AdminTickets           = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminTickets })))
const AdminAuditLog          = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminAuditLog })))
const DiagnosticsPage        = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.DiagnosticsPage })))
const AdminHomepage          = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminHomepage })))
const AdminMedia             = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminMedia })))
const AdminFAQs              = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminFAQs })))
const AdminTestimonials      = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminTestimonials })))
const AdminBlog              = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminBlog })))
const AdminPricing           = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminPricing })))
const AdminCities            = lazy(() => import('./pages/admin/index').then((m) => ({ default: m.AdminCities })))

import { useStore } from './lib/store'
import { STAFF_ROLES } from './lib/constants'

// ── Scroll to top on route change (wr component) ──────────────────────────────
function ScrollToTop() {
  const { pathname } = useLocation()
  useEffect(() => { window.scrollTo(0, 0) }, [pathname])
  return null
}

// ── Load notifications when user logs in (Tr's useEffect) ────────────────────
function NotificationLoader() {
  const user       = useStore((s) => s.user)
  const loadNotifs = useStore((s) => s.loadNotifications)
  useEffect(() => {
    if (user && !STAFF_ROLES.includes(user.s2nri_role)) {
      loadNotifs()
    }
  }, [user?.wp_id])
  return null
}

// ── CookieConsentBanner ─────────────────────────────────────────────────────
// ADDED: previously no cookie consent mechanism existed at all, while
// SEO.php loaded Google Analytics and Meta Pixel unconditionally whenever
// configured — the latter directly contradicting this site's own Privacy
// Policy ("We use no advertising or tracking cookies"). This banner sets a
// real, server-readable cookie (not just a client-side flag) that
// SEO.php now checks before printing either tracking script at all, so
// declining genuinely prevents them from ever loading, not just hides a
// banner. Kept consistent with the exact language already in the Privacy
// Policy's "Cookies and Tracking" section.
function CookieConsentBanner() {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const hasConsent = document.cookie.split('; ').some((c) => c.startsWith('s2nri_cookie_consent='))
    if (!hasConsent) setVisible(true)
  }, [])

  const setConsent = (value: 'accepted' | 'declined') => {
    // 180-day expiry — a real, persistent choice, not a per-session ask.
    const maxAge = 60 * 60 * 24 * 180
    document.cookie = `s2nri_cookie_consent=${value}; path=/; max-age=${maxAge}; SameSite=Lax`
    setVisible(false)
    // A reload is needed for 'accepted' so the NEXT page request (read
    // server-side by SEO.php) actually includes the new cookie and can
    // load the tracking scripts — the current page already rendered
    // without them, which is the correct, compliant behavior (no
    // tracking before consent). 'declined' needs no reload since nothing
    // further needs to load.
    if (value === 'accepted') window.location.reload()
  }

  if (!visible) return null
  return (
    <div role="region" aria-label="Cookie consent" style={{
      position: 'fixed', bottom: 0, left: 0, right: 0, zIndex: 9999,
      background: '#1E2D40', color: '#fff', padding: '16px 20px',
      display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'center', gap: 16,
      boxShadow: '0 -2px 12px rgba(0,0,0,.15)',
    }}>
      <p style={{ margin: 0, fontSize: 13, lineHeight: 1.5, maxWidth: 640, flex: '1 1 320px' }}>
        We use strictly necessary cookies to keep you logged in and prevent fraud, and — only with your consent — anonymised Google Analytics to understand site usage. We do not use advertising or tracking cookies.{' '}
        <a href="/privacy" style={{ color: '#9fc6ff', textDecoration: 'underline' }}>Learn more</a>
      </p>
      <div style={{ display: 'flex', gap: 10, flexShrink: 0 }}>
        <button onClick={() => setConsent('declined')} style={{ background: 'transparent', border: '1px solid #ffffff55', color: '#fff', padding: '9px 18px', borderRadius: 8, fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>Decline</button>
        <button onClick={() => setConsent('accepted')} style={{ background: '#4A6FA5', border: 'none', color: '#fff', padding: '9px 18px', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>Accept</button>
      </div>
    </div>
  )
}

// ── ErrorBoundary (jr component) ──────────────────────────────────────────────
interface ErrorBoundaryState { error: Error | null }
class ErrorBoundary extends Component<{ children: React.ReactNode }, ErrorBoundaryState> {
  state: ErrorBoundaryState = { error: null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    console.error('[S2NRI]', error.message, info?.componentStack?.split('\n')[1])
  }

  render() {
    if (this.state.error) {
      const primary = (window.S2NRI_CONFIG?.settings?.primary_color) || '#4A6FA5'
      return (
        <div style={{ padding: '60px 20px', textAlign: 'center', fontFamily: 'system-ui, sans-serif' }}>
          <div style={{ fontSize: 48, marginBottom: 16 }}>⚠️</div>
          <h2 style={{ color: '#111827', marginBottom: 8 }}>Something went wrong</h2>
          <p style={{ color: '#6b7280', maxWidth: 400, margin: '0 auto 24px', fontSize: 14 }}>
            {String(this.state.error?.message || 'Unexpected error')}
          </p>
          <button
            onClick={() => { this.setState({ error: null }); window.location.href = '/' }}
            style={{ padding: '10px 24px', background: primary, color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontWeight: 600, marginRight: 8 }}
          >
            Go Home
          </button>
          <button
            onClick={() => window.location.reload()}
            style={{ padding: '10px 24px', background: '#f3f4f6', color: '#374151', border: 'none', borderRadius: 8, cursor: 'pointer', fontWeight: 600 }}
          >
            Reload
          </button>
        </div>
      )
    }
    return this.props.children
  }
}

// ── 404 page ──────────────────────────────────────────────────────────────────
function NotFoundPage() {
  const primary = (window.S2NRI_CONFIG?.settings?.primary_color) || '#4A6FA5'
  return (
    <div style={{ textAlign: 'center', padding: '120px 20px', fontFamily: 'system-ui, sans-serif' }}>
      <div style={{ fontSize: 96, fontWeight: 900, color: '#f3f4f6', lineHeight: 1 }}>404</div>
      <h2 style={{ fontSize: 24, color: '#374151', margin: '16px 0 8px' }}>Page not found</h2>
      <p style={{ color: '#6b7280', marginBottom: 28 }}>The page you're looking for doesn't exist or has been moved.</p>
      <a href="/" style={{ background: primary, color: '#fff', padding: '13px 30px', borderRadius: 9, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}>
        ← Go Home
      </a>
    </div>
  )
}

// ── Router ────────────────────────────────────────────────────────────────────
export function App() {
  // Read the basePath injected by Portal.php (e.g. '/s2nri-admin' or '/portal').
  // BrowserRouter needs this so route matching strips the prefix:
  // URL /s2nri-admin/requests → Router sees /requests → matches /admin/requests
  // Without basename, /s2nri-admin/requests never matches any /admin/* route.
  const basename = (window.S2NRI_CONFIG?.basePath || '').replace(/\/$/, '') || undefined

  useEffect(() => {
    applyDesignConfig()
  }, [])

  return (
    <ErrorBoundary>
      <BrowserRouter basename={basename}>
        <ScrollToTop />
        <NotificationLoader />
        <CookieConsentBanner />
        <Routes>
          {/* ── Public ────────────────────────────────────────────────────── */}
          <Route path="/"              element={<HomePage />} />
          <Route path="/services"      element={<ServicesPage />} />
          <Route path="/services/:categorySlug" element={<ServicesPage />} />
          <Route path="/service/:slug" element={<ServiceDetailPage />} />
          <Route path="/about"         element={<AboutPage />} />
          <Route path="/contact"       element={<ContactPage />} />
          <Route path="/how-it-works"  element={<HowItWorksPage />} />
          <Route path="/faq"           element={<FAQPage />} />
          <Route path="/pricing"       element={<PricingPage />} />
          <Route path="/blog"          element={<BlogListPage />} />
          <Route path="/blog/:slug"    element={<BlogDetailPage />} />
          <Route path="/terms"         element={<TermsPage />} />
          <Route path="/privacy"       element={<PrivacyPage />} />
          <Route path="/cities/:city"  element={<CityPage />} />
          <Route path="/sitemap"       element={<Navigate to="/" replace />} />

          {/* ── Auth (redirect if already logged in) ──────────────────────── */}
          <Route path="/login"           element={<AuthGuard><LoginPage /></AuthGuard>} />
          <Route path="/register"        element={<AuthGuard><RegisterPage /></AuthGuard>} />
          <Route path="/forgot-password" element={<AuthGuard><ForgotPasswordPage /></AuthGuard>} />

          {/* ── Customer dashboard ────────────────────────────────────────── */}
          <Route path="/dashboard" element={<CustomerPortal><CustomerDashboard /></CustomerPortal>} />
          <Route path="/dashboard/bookings" element={<CustomerPortal><BookingListPage /></CustomerPortal>} />
          <Route path="/dashboard/bookings/new" element={<CustomerPortal><NewBookingPage /></CustomerPortal>} />
          <Route path="/dashboard/bookings/:id" element={<CustomerPortal><BookingDetailPage /></CustomerPortal>} />
          <Route path="/dashboard/profile" element={<CustomerPortal><ProfilePage /></CustomerPortal>} />
          <Route path="/dashboard/tickets" element={<CustomerPortal><TicketsPage /></CustomerPortal>} />

          {/* ── Admin ─────────────────────────────────────────────────────── */}
          <Route path="/admin" element={<AdminPortal><Suspense fallback={null}><AdminDashboard /></Suspense></AdminPortal>} />

          {/* Requests — accessible as both:
              /s2nri-admin/requests      (basename strips prefix → Router sees /requests)
              /s2nri-admin/admin/requests (legacy, Router sees /admin/requests) */}
          <Route path="/requests"       element={<AdminPortal><Suspense fallback={null}><AdminBookingList /></Suspense></AdminPortal>} />
          <Route path="/requests/:id"   element={<AdminPortal><Suspense fallback={null}><AdminBookingDetail /></Suspense></AdminPortal>} />
          <Route path="/admin/requests"     element={<AdminPortal><Suspense fallback={null}><AdminBookingList /></Suspense></AdminPortal>} />
          <Route path="/admin/requests/:id" element={<AdminPortal><Suspense fallback={null}><AdminBookingDetail /></Suspense></AdminPortal>} />
          <Route path="/admin/bookings"     element={<AdminPortal><Suspense fallback={null}><AdminBookingList /></Suspense></AdminPortal>} />
          <Route path="/admin/bookings/:id" element={<AdminPortal><Suspense fallback={null}><AdminBookingDetail /></Suspense></AdminPortal>} />

          <Route path="/admin/payments"    element={<AdminPortal><Suspense fallback={null}><AdminPayments /></Suspense></AdminPortal>} />
          <Route path="/admin/customers"   element={<AdminPortal><Suspense fallback={null}><AdminCustomers /></Suspense></AdminPortal>} />
          <Route path="/admin/customers/:id" element={<AdminPortal><Suspense fallback={null}><AdminCustomerDetail /></Suspense></AdminPortal>} />
          <Route path="/admin/design"      element={<AdminPortal><Suspense fallback={null}><AdminDesignSystem /></Suspense></AdminPortal>} />
          <Route path="/admin/settings"    element={<AdminPortal><Suspense fallback={null}><AdminSettings /></Suspense></AdminPortal>} />
          <Route path="/admin/staff"       element={<AdminPortal><Suspense fallback={null}><AdminStaff /></Suspense></AdminPortal>} />
          <Route path="/admin/services"    element={<AdminPortal><Suspense fallback={null}><AdminServices /></Suspense></AdminPortal>} />
          <Route path="/admin/services/:id/builder" element={<AdminPortal><Suspense fallback={null}><AdminServicePageBuilder /></Suspense></AdminPortal>} />
          <Route path="/admin/categories"  element={<AdminPortal><Suspense fallback={null}><AdminCategories /></Suspense></AdminPortal>} />
          <Route path="/admin/reviews"     element={<AdminPortal><Suspense fallback={null}><AdminReviews /></Suspense></AdminPortal>} />
          <Route path="/admin/tickets"     element={<AdminPortal><Suspense fallback={null}><AdminTickets /></Suspense></AdminPortal>} />
          <Route path="/admin/audit-log"   element={<AdminPortal><Suspense fallback={null}><AdminAuditLog /></Suspense></AdminPortal>} />
          <Route path="/admin/diagnostics" element={<AdminPortal><Suspense fallback={null}><DiagnosticsPage /></Suspense></AdminPortal>} />
          <Route path="/admin/homepage"    element={<AdminPortal><Suspense fallback={null}><AdminHomepage /></Suspense></AdminPortal>} />
          <Route path="/admin/media"       element={<AdminPortal><Suspense fallback={null}><AdminMedia /></Suspense></AdminPortal>} />
          <Route path="/admin/faqs"        element={<AdminPortal><Suspense fallback={null}><AdminFAQs /></Suspense></AdminPortal>} />
          <Route path="/admin/testimonials" element={<AdminPortal><Suspense fallback={null}><AdminTestimonials /></Suspense></AdminPortal>} />
          <Route path="/admin/blog"        element={<AdminPortal><Suspense fallback={null}><AdminBlog /></Suspense></AdminPortal>} />
          <Route path="/admin/pricing"     element={<AdminPortal><Suspense fallback={null}><AdminPricing /></Suspense></AdminPortal>} />
          <Route path="/admin/cities"      element={<AdminPortal><Suspense fallback={null}><AdminCities /></Suspense></AdminPortal>} />

          {/* ── 404 ──────────────────────────────────────────────────────── */}
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </BrowserRouter>
    </ErrorBoundary>
  )
}
