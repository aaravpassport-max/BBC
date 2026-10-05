/**
 * Global mobile bottom tab bar (≤768px). One component, three configs:
 * public site, customer portal, and admin dashboard.
 */

import React from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useStore } from '@/lib/store'
import {
  IconAccount,
  IconBookings,
  IconContact,
  IconDashboard,
  IconDesign,
  IconHome,
  IconInquire,
  IconMenu,
  IconRequests,
  IconServices,
  IconSupport,
} from './bottom-nav-icons'

export type BottomNavVariant = 'public' | 'customer' | 'admin'

interface BottomNavProps {
  primary: string
  variant?: BottomNavVariant
  onMenuClick?: () => void
}

interface BottomNavTab {
  icon: React.ReactNode
  label: string
  active: boolean
  to?: string
  onClick?: () => void
}

export function BottomNav({ primary, variant = 'public', onMenuClick }: BottomNavProps) {
  const location = useLocation()
  const user = useStore((s) => s.user)
  const path = location.pathname

  const tabs: BottomNavTab[] = []

  if (variant === 'admin') {
    tabs.push(
      {
        to: '/admin',
        icon: <IconDashboard />,
        label: 'Home',
        active: path === '/admin',
      },
      {
        to: '/admin/requests',
        icon: <IconRequests />,
        label: 'Requests',
        active:
          path.startsWith('/admin/requests') ||
          path.startsWith('/admin/bookings'),
      },
      {
        to: '/admin/services',
        icon: <IconServices />,
        label: 'Services',
        active: path.startsWith('/admin/services') || path.startsWith('/admin/categories'),
      },
      {
        to: '/admin/design',
        icon: <IconDesign />,
        label: 'Design',
        active: path.startsWith('/admin/design'),
      },
      {
        icon: <IconMenu />,
        label: 'Menu',
        active: false,
        onClick: () => onMenuClick?.(),
      },
    )
  } else if (variant === 'customer') {
    tabs.push(
      {
        to: '/dashboard',
        icon: <IconDashboard />,
        label: 'Home',
        active: path === '/dashboard',
      },
      {
        to: '/dashboard/bookings',
        icon: <IconBookings />,
        label: 'Bookings',
        active: path.startsWith('/dashboard/bookings'),
      },
      {
        to: '/services',
        icon: <IconServices />,
        label: 'Services',
        active: path.startsWith('/services') || path.startsWith('/service/'),
      },
      {
        to: '/dashboard/tickets',
        icon: <IconSupport />,
        label: 'Support',
        active: path.startsWith('/dashboard/tickets'),
      },
      {
        to: '/dashboard/profile',
        icon: <IconAccount />,
        label: 'Profile',
        active: path === '/dashboard/profile',
      },
    )
  } else {
    const accountHref = user ? '/dashboard' : '/login'
    const bookingsHref = user ? '/dashboard/bookings' : '/login'
    const isServicePage = path.startsWith('/service/')

    tabs.push({
      to: '/',
      icon: <IconHome />,
      label: 'Home',
      active: path === '/',
    })

    if (isServicePage) {
      tabs.push({
        icon: <IconInquire />,
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
      icon: <IconServices />,
      label: 'Services',
      active: path.startsWith('/services') || path.startsWith('/service/'),
    })

    if (!isServicePage) {
      tabs.push({
        to: '/contact',
        icon: <IconContact />,
        label: 'Contact',
        active: path === '/contact' || path.startsWith('/contact/'),
      })
    }

    tabs.push(
      {
        to: bookingsHref,
        icon: <IconBookings />,
        label: 'Bookings',
        active: path.startsWith('/dashboard/bookings'),
      },
      {
        to: accountHref,
        icon: <IconAccount />,
        label: user ? 'Account' : 'Sign In',
        active:
          path === '/dashboard' ||
          path === '/dashboard/profile' ||
          path === '/login' ||
          path === '/register',
      },
    )
  }

  return (
    <nav
      className={`s2-bottom-nav s2-bottom-nav--${variant}`}
      aria-label={
        variant === 'admin'
          ? 'Admin mobile navigation'
          : variant === 'customer'
            ? 'Portal mobile navigation'
            : 'Primary mobile navigation'
      }
    >
      {tabs.map((t) =>
        t.to ? (
          <Link
            key={t.label}
            to={t.to}
            className={`s2-bottom-nav-item${t.active ? ' active' : ''}`}
            style={t.active ? { color: primary } : undefined}
            aria-current={t.active ? 'page' : undefined}
          >
            <span className="s2-bottom-nav-icon">{t.icon}</span>
            <span className="s2-bottom-nav-label">{t.label}</span>
          </Link>
        ) : (
          <button
            key={t.label}
            type="button"
            onClick={t.onClick}
            className="s2-bottom-nav-item"
          >
            <span className="s2-bottom-nav-icon">{t.icon}</span>
            <span className="s2-bottom-nav-label">{t.label}</span>
          </button>
        ),
      )}
    </nav>
  )
}
