/**
 * BottomNav — app-style fixed bottom tab bar, mobile only (see .s2-bottom-nav
 * in global.css, hidden above 768px). Rendered from both Layout (public +
 * auth pages) and SidebarLayout (customer dashboard pages), never for staff/
 * admin — they already have the desktop sidebar nav (ADMIN_NAV) and are not
 * expected to be running the mobile customer experience.
 *
 * Routes used below (/, /services, /dashboard, /dashboard/bookings, /login)
 * all already exist in App.tsx's <Routes> — no new routes introduced here.
 *
 * DYNAMIC "Inquire" TAB (added): on any /service/:slug page — every service,
 * automatically, since ServiceDetailPage.tsx is the one shared component
 * every service renders through, not a per-service special case — an extra
 * tab appears that scrolls to the existing `id="booking-form"` anchor
 * (ServiceDetailPage.tsx's sticky booking wizard wrapper) instead of
 * navigating anywhere. This directly addresses mobile users not scrolling
 * far enough to find the enquiry form. Every other page (including the
 * homepage) keeps exactly the same 4 tabs as before — unchanged.
 */

import React from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useStore } from '@/lib/store'
import { STAFF_ROLES } from '@/lib/constants'

interface BottomNavProps {
  primary: string
}

interface BottomNavTab {
  icon: string
  label: string
  active: boolean
  to?: string       // navigate (rendered as <Link>)
  onClick?: () => void // in-page action, no navigation (rendered as <button>)
}

export function BottomNav({ primary }: BottomNavProps) {
  const location = useLocation()
  const user = useStore((s) => s.user)
  const path = location.pathname

  // Staff/admin use the desktop sidebar (ADMIN_NAV in Layout.tsx) — this bar
  // is the customer/visitor mobile experience only.
  const isStaff = !!(user && STAFF_ROLES.includes(user.s2nri_role))
  if (isStaff) return null

  const accountHref  = user ? '/dashboard' : '/login'
  const bookingsHref = user ? '/dashboard/bookings' : '/login'
  const isServicePage = path.startsWith('/service/')

  const tabs: BottomNavTab[] = [
    {
      to: '/',
      icon: '🏠',
      label: 'Home',
      active: path === '/',
    },
  ]

  if (isServicePage) {
    tabs.push({
      icon: '📝',
      label: 'Inquire',
      active: false,
      onClick: () => {
        const el = document.getElementById('booking-form')
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
      },
    })
  }

  tabs.push({
    to: '/services',
    icon: '🔧',
    label: 'Services',
    active: path.startsWith('/services') || path.startsWith('/service/'),
  })

  if (!isServicePage) {
    tabs.push({
      to: '/contact',
      icon: '💬',
      label: 'Contact',
      active: path === '/contact' || path.startsWith('/contact/'),
    })
  }

  tabs.push(
    {
      to: bookingsHref,
      icon: '📋',
      label: 'Bookings',
      active: path.startsWith('/dashboard/bookings'),
    },
    {
      to: accountHref,
      icon: '👤',
      label: user ? 'Account' : 'Sign In',
      active:
        path === '/dashboard' ||
        path === '/dashboard/profile' ||
        path === '/login' ||
        path === '/register',
    },
  )

  return (
    <nav className="s2-bottom-nav" aria-label="Primary mobile navigation">
      {tabs.map((t) =>
        t.to ? (
          <Link
            key={t.label}
            to={t.to}
            className={`s2-bottom-nav-item${t.active ? ' active' : ''}`}
            style={t.active ? { color: primary } : undefined}
            aria-current={t.active ? 'page' : undefined}
          >
            <span className="s2-bottom-nav-icon" aria-hidden="true">{t.icon}</span>
            <span className="s2-bottom-nav-label">{t.label}</span>
          </Link>
        ) : (
          <button
            key={t.label}
            type="button"
            onClick={t.onClick}
            className="s2-bottom-nav-item"
            style={{ background: 'none', border: 'none', font: 'inherit' }}
          >
            <span className="s2-bottom-nav-icon" aria-hidden="true">{t.icon}</span>
            <span className="s2-bottom-nav-label">{t.label}</span>
          </button>
        )
      )}
    </nav>
  )
}
