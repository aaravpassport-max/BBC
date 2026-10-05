/**
 * Layout component — exact match of Q() function from booking-ChxRsZYG.js
 *
 * Renders:
 *   1. Top bar (WhatsApp, phone, currency switcher, lang toggle, sign in/up)
 *   2. Sticky header (logo, mega-menu nav, service request, dashboard, hamburger)
 *   3. {children}
 *   4. Footer (logo, tagline, social, 5-column links, newsletter, copyright)
 */

import React, { useState, useRef, useEffect, useMemo } from 'react'
import { Link, NavLink, useLocation } from 'react-router-dom'
import { useStore } from '@/lib/store'
import { isTemplateSectionHidden } from '@/lib/section-visibility'
import { useLangSwitch } from '@/lib/i18n'
import { api } from '@/lib/api'
import { NAV_MENU } from '@/lib/nav'
import { CURRENCIES, STAFF_ROLES } from '@/lib/constants'
import type { NavItem, Service } from '@/types'
import { BottomNav } from './BottomNav'
import { resolvePrimary } from '@/lib/design-tokens'
import { PageWidthScope } from '@/components/public/PageWidthScope'
import { getRuntimeDesignConfig } from '@/lib/apply-design-config'
import { resolveChromeLayer } from '@/lib/design-resolve'
import { DESIGN_UPDATED_EVENT } from '@/lib/design-live-sync'
import { pageContextFromPath } from '@/lib/width-layout'
import { prefetchForRoute, prefetchPublicRoutesIdle } from '@/lib/prefetch'
import { subscribeResource } from '@/lib/resource-cache'
import { parseFooterNavLinks } from '@/lib/home-content-settings'
import { resolvePublicNavMenu } from '@/lib/nav-menu-parse'
import { CmsElement } from '@/components/public/CmsElement'
import { globalImageFitStyle, sectionImageFitStyle } from '@/lib/image-object-fit'

interface LayoutProps {
  children: React.ReactNode
}

export function Layout({ children }: LayoutProps) {
  const [mobileOpen, setMobileOpen]   = useState(false)
  const [activeMenu, setActiveMenu]   = useState<string | null>(null)
  const [currency, setCurrency]       = useState('USD')
  const [showCurrency, setShowCurrency] = useState(false)
  const [scrolled, setScrolled]       = useState(false)
  const [navItems, setNavItems]       = useState<NavItem[]>(NAV_MENU)
  const navRef = useRef<HTMLDivElement>(null)

  const user     = useStore((s) => s.user)
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const name     = settings.platform_name || 'Services2NRI'
  const whatsapp = settings.platform_whatsapp
  const usPhone  = settings.platform_us_phone || settings.platform_phone
  const logo     = settings.platform_logo_url

  const [lang, setLang] = useLangSwitch()
  const location = useLocation()

  // Close menus on route change
  useEffect(() => { setMobileOpen(false); setActiveMenu(null) }, [location.pathname])

  // Scroll shadow
  useEffect(() => {
    const handler = () => setScrolled(window.scrollY > 10)
    window.addEventListener('scroll', handler, { passive: true })
    return () => window.removeEventListener('scroll', handler)
  }, [])

  // Close mega-menu on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (navRef.current && !navRef.current.contains(e.target as Node)) {
        setActiveMenu(null)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const [footerServices, setFooterServices] = useState<Array<{ name: string; slug: string }>>([])

  useEffect(() => {
    prefetchPublicRoutesIdle()
  }, [])

  const applyNavFromServices = (services: Service[]) => {
    if (!services.length) return
    const slugSet = new Set(services.map((s) => s.slug))
    const nameMap: Record<string, string> = {}
    services.forEach((s) => { nameMap[s.name.toLowerCase().trim()] = s.slug })
    const updated = NAV_MENU.map((item) =>
      item.cols
        ? {
            ...item,
            cols: item.cols
              .map((col) => ({
                ...col,
                items: col.items
                  .map((link) => {
                    if (slugSet.has(link.slug)) return link
                    const found = nameMap[link.label.toLowerCase().trim()]
                    return found ? { ...link, slug: found } : link
                  })
                  .filter((link) => slugSet.has(link.slug)),
              }))
              .filter((col) => col.items.length > 0),
          }
        : item,
    ).filter((item) => !item.cols || item.cols.length > 0)
    setNavItems(updated)
  }

  // Central navigation from service registry (falls back to static NAV_MENU).
  useEffect(() => {
    const navPath = 'navigation/public'
    const cachedNav = api.peekGet<{ menu: NavItem[] }>(navPath)
    if (cachedNav?.menu?.length) setNavItems(cachedNav.menu as NavItem[])

    const navDropdownPath = 'services?surface=nav_dropdown'
    const cachedDropdown = api.peekGet<{ services: Service[] }>(navDropdownPath)
    if (cachedDropdown?.services?.length) applyNavFromServices(cachedDropdown.services)

    void api.getCached<{ menu: NavItem[] }>(navPath)
      .then((data) => {
        if (data.menu?.length) setNavItems(data.menu as NavItem[])
      })
      .catch(() => {
        void api.getCached<{ services: Service[] }>(navDropdownPath)
          .then((data) => applyNavFromServices(data.services || []))
          .catch(() => {})
      })

    const unsubs = [
      subscribeResource('GET', navPath, (fresh) => {
        const row = fresh as { menu?: NavItem[] }
        if (row.menu?.length) setNavItems(row.menu as NavItem[])
      }),
      subscribeResource('GET', navDropdownPath, (fresh) => {
        applyNavFromServices((fresh as { services?: Service[] }).services || [])
      }),
    ]
    return () => unsubs.forEach((off) => off())
  }, [])

  useEffect(() => {
    const footerPath = 'services?surface=footer'
    const applyFooter = (d: { services?: Service[] }) => {
      const list = (d.services || []).slice(0, 8).map((s) => ({ name: s.name, slug: s.slug }))
      if (list.length) setFooterServices(list)
    }
    const cached = api.peekGet<{ services: Service[] }>(footerPath)
    if (cached) applyFooter(cached)
    void api.getCached<{ services: Service[] }>(footerPath).then(applyFooter).catch(() => {})
    return subscribeResource('GET', footerPath, (fresh) => applyFooter(fresh as { services?: Service[] }))
  }, [])

  const isStaff = user && STAFF_ROLES.includes(user.s2nri_role)
  const dashUrl = isStaff ? '/admin' : '/dashboard'

  const [designRevision, setDesignRevision] = useState(0)
  useEffect(() => {
    const bump = () => setDesignRevision((n) => n + 1)
    window.addEventListener(DESIGN_UPDATED_EVENT, bump)
    return () => window.removeEventListener(DESIGN_UPDATED_EVENT, bump)
  }, [])

  const chrome = useMemo(() => {
    const design = getRuntimeDesignConfig()?.design as Record<string, unknown> | undefined
    return resolveChromeLayer(design, pageContextFromPath(location.pathname))
  }, [location.pathname, designRevision])

  const pageCtx = pageContextFromPath(location.pathname)

  const footerQuickLinks = useMemo(
    () =>
      parseFooterNavLinks(settings.footer_quick_links_json, [
        { path: '/about', label: 'About Us' },
        { path: '/contact', label: 'Contact Us' },
        { path: '/how-it-works', label: 'How It Works' },
        { path: '/faq', label: 'FAQ' },
        { path: '/blog', label: 'Blog' },
        { path: '/pricing', label: 'Pricing' },
        { path: '/dashboard/bookings', label: 'Track Order' },
      ]),
    [settings.footer_quick_links_json],
  )

  const publicNavItems = useMemo(
    () => resolvePublicNavMenu(navItems, settings.nav_menu_json),
    [navItems, settings.nav_menu_json],
  )

  const footerLocationLinks = useMemo(
    () =>
      parseFooterNavLinks(settings.footer_locations_json, [
        { path: '/cities/property-management-in-mumbai', label: 'Mumbai' },
        { path: '/cities/property-management-in-delhi', label: 'Delhi' },
        { path: '/cities/property-management-in-bangalore', label: 'Bangalore' },
        { path: '/cities/property-management-in-pune', label: 'Pune' },
        { path: '/cities/property-management-in-hyderabad', label: 'Hyderabad' },
        { path: '/cities/property-management-in-chennai', label: 'Chennai' },
        { path: '/cities/property-management-in-ahmedabad', label: 'Ahmedabad' },
        { path: '/cities/property-management-in-nagpur', label: 'Nagpur' },
      ]),
    [settings.footer_locations_json],
  )

  const wrapClass = [
    's2-page-wrap',
    's2-page-wrap--mobile-shell',
    chrome.show_topbar === false ? 's2-page-wrap--no-topbar' : '',
    chrome.footer_variant === 'minimal' ? 's2-page-wrap--footer-minimal' : '',
    chrome.header_variant === 'compact' ? 's2-page-wrap--header-compact' : '',
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <div
      className={wrapClass}
      data-s2-page-type={pageCtx.page_type}
      data-s2-page-slug={pageCtx.page_slug}
      style={{
        minHeight: '100vh',
        display: 'flex',
        flexDirection: 'column',
        ['--s2-primary' as string]: primary,
        ...globalImageFitStyle(settings as Record<string, string>),
      }}
    >
      {/* ── Top bar ────────────────────────────────────────────────────────── */}
      <div className="s2-site-topbar s2-desktop-only" data-s2-section="topbar">
        <div
          className="s2-layout-header-inner"
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            flexWrap: 'wrap',
            gap: 8,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            {whatsapp && (
              <a
                href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`}
                target="_blank"
                rel="noopener noreferrer"
                style={{ color: '#fff', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 5, opacity: 0.9, fontSize: 12 }}
              >
                💬 WhatsApp: +{String(whatsapp).replace(/\D/g, '')}
              </a>
            )}
            {usPhone && (
              <a href={`tel:${usPhone}`} style={{ color: '#fff', textDecoration: 'none', opacity: 0.9, fontSize: 12 }}>
                📞 {usPhone}
              </a>
            )}
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            {/* Currency switcher */}
            <div style={{ position: 'relative' }}>
              <button
                onClick={() => setShowCurrency((p) => !p)}
                style={{
                  background: 'rgba(255,255,255,.15)',
                  border: '1px solid rgba(255,255,255,.3)',
                  color: '#fff',
                  padding: '3px 10px',
                  borderRadius: 5,
                  fontSize: 12,
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 5,
                }}
              >
                {CURRENCIES.find((c) => c.code === currency)?.flag} {currency} ▾
              </button>
              {showCurrency && (
                <div
                  style={{
                    position: 'absolute',
                    top: '100%',
                    right: 0,
                    background: '#fff',
                    border: '1px solid #e0e0e0',
                    borderRadius: 8,
                    boxShadow: '0 8px 24px rgba(0,0,0,.15)',
                    zIndex: 999,
                    minWidth: 120,
                    marginTop: 4,
                  }}
                >
                  {CURRENCIES.map((c) => (
                    <button
                      key={c.code}
                      onClick={() => { setCurrency(c.code); setShowCurrency(false) }}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        width: '100%',
                        padding: '8px 14px',
                        background: currency === c.code ? `${primary}15` : 'transparent',
                        border: 'none',
                        cursor: 'pointer',
                        fontSize: 13,
                        color: '#333',
                        fontWeight: currency === c.code ? 700 : 400,
                      }}
                    >
                      {c.flag} {c.code} <span style={{ color: '#888', marginLeft: 'auto' }}>{c.symbol}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
            {/* Language toggle */}
            <button
              onClick={() => setLang(lang === 'en' ? 'hi' : 'en')}
              style={{
                background: 'rgba(255,255,255,.15)',
                border: '1px solid rgba(255,255,255,.3)',
                color: '#fff',
                padding: '3px 10px',
                borderRadius: 5,
                fontSize: 12,
                cursor: 'pointer',
              }}
            >
              {lang === 'en' ? '🇮🇳 हिंदी' : '🇺🇸 EN'}
            </button>
            <Link to="/login" style={{ color: '#fff', textDecoration: 'none', fontSize: 12, opacity: 0.9 }}>
              {settings.topbar_sign_in_text || 'Sign In'}
            </Link>
            <Link
              to="/register"
              style={{
                color: primary,
                background: '#fff',
                padding: '3px 12px',
                borderRadius: 5,
                fontSize: 12,
                fontWeight: 700,
                textDecoration: 'none',
              }}
            >
              {settings.topbar_sign_up_text || 'Sign Up'}
            </Link>
          </div>
        </div>
      </div>

      {/* ── Sticky header ─────────────────────────────────────────────────── */}
      <header className={`s2-site-header${scrolled ? ' s2-site-header--scrolled' : ''}`} data-s2-section="header">
        <div className="s2-layout-header-inner s2-site-header__inner">
          {/* Logo */}
          <Link to="/" style={{ textDecoration: 'none', flexShrink: 0, display: 'flex', alignItems: 'center', gap: 10 }}>
            {logo ? (
              <img src={logo} alt={name} style={{ height: 38, objectFit: 'contain' }} />
            ) : (
              <span style={{ fontSize: 20, fontWeight: 900, color: primary, letterSpacing: '-0.5px' }}>{name}</span>
            )}
          </Link>

          {/* Desktop nav */}
          <nav
            ref={navRef}
            className="s2-desktop-nav"
            style={{ display: 'flex', alignItems: 'center', gap: 4, flex: 1 }}
            aria-label="Main navigation"
          >
            {publicNavItems.map((item) => (
              <div key={item.key} style={{ position: 'relative' }}>
                {item.link ? (
                  <NavLink
                    to={item.link}
                    style={({ isActive }) => ({
                      padding: '8px 12px',
                      fontSize: 14,
                      fontWeight: 500,
                      color: isActive ? primary : '#374151',
                      textDecoration: 'none',
                      borderRadius: 6,
                      display: 'flex',
                      alignItems: 'center',
                      gap: 4,
                      background: isActive ? `${primary}10` : 'transparent',
                    })}
                  >
                    {item.label}
                  </NavLink>
                ) : (
                  <button
                    onClick={() => setActiveMenu(activeMenu === item.key ? null : item.key)}
                    style={{
                      padding: '8px 12px',
                      fontSize: 14,
                      fontWeight: 500,
                      color: activeMenu === item.key ? primary : '#374151',
                      background: activeMenu === item.key ? `${primary}10` : 'transparent',
                      border: 'none',
                      borderRadius: 6,
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      gap: 4,
                    }}
                  >
                    {item.label}
                    <span
                      style={{
                        fontSize: 10,
                        opacity: 0.7,
                        transform: activeMenu === item.key ? 'rotate(180deg)' : 'none',
                        transition: 'transform .2s',
                        display: 'inline-block',
                      }}
                    >
                      ▼
                    </span>
                  </button>
                )}

                {/* Mega-menu dropdown */}
                {item.cols && activeMenu === item.key && (
                  <div
                    style={{
                      position: 'absolute',
                      top: 'calc(100% + 8px)',
                      left: 0,
                      background: '#fff',
                      border: '1px solid #EBF0F8',
                      borderRadius: 12,
                      boxShadow: '0 16px 48px rgba(0,0,0,.15)',
                      zIndex: 300,
                      display: 'grid',
                      gridTemplateColumns: `repeat(${item.cols.length}, 1fr)`,
                      gap: 0,
                      minWidth: item.cols.length * 180,
                      padding: 20,
                    }}
                  >
                    {item.cols.map((col, ci) => (
                      <div
                        key={ci}
                        style={{ padding: '0 16px', borderLeft: ci > 0 ? '1px solid #f0f0f0' : 'none' }}
                      >
                        <div
                          style={{
                            fontSize: 11,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: 1.5,
                            color: '#aaa',
                            marginBottom: 10,
                            paddingBottom: 6,
                            borderBottom: `2px solid ${primary}`,
                          }}
                        >
                          {col.heading}
                        </div>
                        {col.items.map((link) => (
                          <Link
                            key={link.slug}
                            to={`/service/${link.slug}`}
                            onClick={() => setActiveMenu(null)}
                            style={{ display: 'block', padding: '6px 0', fontSize: 13, color: '#444', textDecoration: 'none' }}
                            onMouseEnter={(e) => (e.currentTarget.style.color = primary)}
                            onMouseLeave={(e) => (e.currentTarget.style.color = '#444')}
                          >
                            → {link.label}
                          </Link>
                        ))}
                      </div>
                    ))}
                    <div
                      style={{
                        gridColumn: '1 / -1',
                        marginTop: 14,
                        paddingTop: 14,
                        borderTop: '1px solid #f0f0f0',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                      }}
                    >
                      <span style={{ fontSize: 13, color: '#888' }}>
                        {settings.header_nav_mega_hint || 'Browse all 44+ NRI services'}
                      </span>
                      <Link
                        to="/services"
                        onClick={() => setActiveMenu(null)}
                        style={{
                          fontSize: 13,
                          fontWeight: 700,
                          color: primary,
                          textDecoration: 'none',
                          background: `${primary}10`,
                          padding: '6px 14px',
                          borderRadius: 6,
                        }}
                      >
                        {settings.header_nav_view_all_text || 'View All Services →'}
                      </Link>
                    </div>
                  </div>
                )}
              </div>
            ))}

            <span className="s2-desktop-nav" style={{ display: 'flex', gap: 0 }}>
              <NavLink
                to="/about"
                style={({ isActive }) => ({
                  padding: '8px 12px', fontSize: 14, fontWeight: 500,
                  color: isActive ? primary : '#374151', textDecoration: 'none',
                  borderRadius: 6, background: isActive ? `${primary}10` : 'transparent',
                })}
              >{settings.header_nav_about_label || 'About'}</NavLink>
              <NavLink
                to="/contact"
                style={({ isActive }) => ({
                  padding: '8px 12px', fontSize: 14, fontWeight: 500,
                  color: isActive ? primary : '#374151', textDecoration: 'none',
                  borderRadius: 6, background: isActive ? `${primary}10` : 'transparent',
                })}
              >{settings.header_nav_contact_label || 'Contact'}</NavLink>
            </span>
          </nav>

          {/* Right: desktop CTAs + compact mobile actions */}
          <div className="s2-site-header__end">
            <div className="s2-site-header__desktop-actions s2-desktop-only">
              {whatsapp && (
                <CmsElement pageId="home" sectionKey="header" elementId="whatsapp_button">
                  <a
                    href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="s2-header-wa-btn"
                  >
                    {settings.header_whatsapp_label || '💬 WhatsApp'}
                  </a>
                </CmsElement>
              )}
              <CmsElement pageId="home" sectionKey="header" elementId="service_request_button">
                <Link to={settings.header_service_request_url || '/contact'} className="s2-header-outline-btn">
                  {settings.header_service_request_text || 'Service Request'}
                </Link>
              </CmsElement>
              <CmsElement
                pageId="home"
                sectionKey="header"
                elementId="dashboard_button"
                style={user ? undefined : { display: 'none' }}
              >
                <Link to={dashUrl} className="s2-header-primary-btn" tabIndex={user ? 0 : -1} aria-hidden={!user}>
                  {settings.header_dashboard_text || 'Dashboard'}
                </Link>
              </CmsElement>
              <CmsElement
                pageId="home"
                sectionKey="header"
                elementId="sign_in_button"
                style={!user ? undefined : { display: 'none' }}
              >
                <Link to="/login" className="s2-header-primary-btn" tabIndex={!user ? 0 : -1} aria-hidden={!!user}>
                  {settings.header_sign_in_text || 'Sign In'}
                </Link>
              </CmsElement>
            </div>
            {whatsapp && (
              <a
                href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`}
                target="_blank"
                rel="noopener noreferrer"
                className="s2-header-icon-btn s2-mobile-only"
                aria-label="Chat on WhatsApp"
              >
                💬
              </a>
            )}
            <Link to="/contact" className="s2-header-icon-btn s2-mobile-only" aria-label="Contact us">
              📞
            </Link>
            <button
              type="button"
              onClick={() => setMobileOpen((o) => !o)}
              aria-label="Open navigation menu"
              className="s2-mobile-hamburger s2-header-menu-btn"
            >
              {[0, 1, 2].map((i) => (
                <span key={i} className="s2-header-menu-btn__bar" />
              ))}
            </button>
          </div>
        </div>
      </header>

      {/* ── Mobile drawer ──────────────────────────────────────────────────── */}
      {mobileOpen && (
        <div
          style={{
            position: 'fixed', inset: 0, zIndex: 500, background: 'rgba(0,0,0,.5)',
          }}
          onClick={() => setMobileOpen(false)}
        >
          <div
            style={{
              position: 'absolute', top: 0, left: 0, bottom: 0, width: 300,
              background: '#fff', overflowY: 'auto', padding: '24px 20px',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 24 }}>
              <span style={{ fontSize: 18, fontWeight: 900, color: primary }}>{name}</span>
              <button
                onClick={() => setMobileOpen(false)}
                style={{ background: 'none', border: 'none', fontSize: 24, cursor: 'pointer', color: '#374151' }}
              >
                ✕
              </button>
            </div>
            {parseFooterNavLinks(settings.header_mobile_nav_json, [
              { path: '/', label: '🏠 Home' },
              { path: '/services', label: '📋 All Services' },
              { path: '/about', label: '👥 About Us' },
              { path: '/contact', label: '📞 Contact' },
              { path: '/faq', label: '❓ FAQ' },
              { path: '/blog', label: '✍️ Blog' },
              { path: '/pricing', label: '💰 Pricing' },
            ]).map(({ path, label }) => (
              <Link
                key={path}
                to={path}
                onClick={() => setMobileOpen(false)}
                style={{
                  display: 'block', padding: '12px 0', fontSize: 15, color: '#374151',
                  textDecoration: 'none', borderBottom: '1px solid #f0f0f0', fontWeight: 500,
                }}
              >
                {label}
              </Link>
            ))}
            <div className="s2-mobile-drawer__utilities s2-mobile-only">
              {whatsapp && (
                <a
                  href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="s2-mobile-drawer__utility-link"
                >
                  💬 WhatsApp
                </a>
              )}
              {usPhone && (
                <a href={`tel:${usPhone}`} className="s2-mobile-drawer__utility-link">
                  📞 {usPhone}
                </a>
              )}
              <button
                type="button"
                onClick={() => setLang(lang === 'en' ? 'hi' : 'en')}
                className="s2-mobile-drawer__utility-btn"
              >
                {lang === 'en' ? '🇮🇳 हिंदी' : '🇺🇸 English'}
              </button>
            </div>
            <div style={{ marginTop: 20 }}>
              {user ? (
                <Link
                  to={dashUrl}
                  onClick={() => setMobileOpen(false)}
                  style={{
                    display: 'block', background: primary, color: '#fff', padding: '12px 20px',
                    borderRadius: 9, textDecoration: 'none', fontWeight: 700, fontSize: 15, textAlign: 'center',
                  }}
                >
                  Dashboard →
                </Link>
              ) : (
                <>
                  <Link
                    to="/login"
                    onClick={() => setMobileOpen(false)}
                    style={{
                      display: 'block', background: primary, color: '#fff', padding: '12px 20px',
                      borderRadius: 9, textDecoration: 'none', fontWeight: 700, fontSize: 15, textAlign: 'center', marginBottom: 10,
                    }}
                  >
                    Sign In
                  </Link>
                  <Link
                    to="/register"
                    onClick={() => setMobileOpen(false)}
                    style={{
                      display: 'block', background: 'transparent', color: primary, padding: '12px 20px',
                      borderRadius: 9, textDecoration: 'none', fontWeight: 600, fontSize: 15,
                      textAlign: 'center', border: `2px solid ${primary}`,
                    }}
                  >
                    Create Free Account
                  </Link>
                </>
              )}
            </div>
          </div>
        </div>
      )}

      {/* ── Main content ──────────────────────────────────────────────────── */}
      <PageWidthScope>{children}</PageWidthScope>

      {/* ── Footer ────────────────────────────────────────────────────────── */}
      <footer className="s2-site-footer" data-s2-section="footer">
        <div className="s2-layout-footer-inner">
          <div
            className="s2-mobile-stack s2-site-footer__grid"
            style={{
              display: 'grid',
              gridTemplateColumns: '2fr 1fr 1fr 1fr 1fr',
              gap: 40,
              marginBottom: 40,
              flexWrap: 'wrap',
            }}
          >
            {/* Brand */}
            <div>
              <div style={{ marginBottom: 14 }}>
                {logo ? (
                  <img src={logo} alt={name} style={{ height: 36, filter: 'brightness(10)', objectFit: 'contain' }} />
                ) : (
                  <span style={{ fontSize: 22, fontWeight: 900, color: '#fff' }}>{name}</span>
                )}
              </div>
              <p style={{ fontSize: 14, lineHeight: 1.8, margin: '0 0 16px', color: '#6b7280' }}>
                {settings.platform_tagline || 'Your Trusted NRI Service Partner — Expert assistance for all your India needs, from anywhere in the world.'}
              </p>
              <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                {settings.social_facebook && (
                  <a href={settings.social_facebook} target="_blank" rel="noopener noreferrer" style={socialStyle}>f</a>
                )}
                {settings.social_instagram && (
                  <a href={settings.social_instagram} target="_blank" rel="noopener noreferrer" style={socialStyle}>📸</a>
                )}
                {settings.social_twitter && (
                  <a href={settings.social_twitter} target="_blank" rel="noopener noreferrer" style={socialStyle}>𝕏</a>
                )}
                {settings.social_youtube && (
                  <a href={settings.social_youtube} target="_blank" rel="noopener noreferrer" style={socialStyle}>▶</a>
                )}
                {settings.social_linkedin && (
                  <a href={settings.social_linkedin} target="_blank" rel="noopener noreferrer" style={{ ...socialStyle, fontWeight: 700 }}>in</a>
                )}
              </div>
            </div>

            {/* Services */}
            <div>
              <h4 className="s2-site-footer__heading">{settings.footer_col_services_title || 'Services'}</h4>
              {(footerServices.length ? footerServices : [{ name: 'All Services', slug: '' }]).map((s) => (
                <Link
                  key={s.slug || s.name}
                  to={s.slug ? `/service/${s.slug}` : '/services'}
                  className="s2-site-footer__link"
                >
                  {s.name}
                </Link>
              ))}
            </div>

            {/* Quick Links */}
            <div>
              <h4 className="s2-site-footer__heading">{settings.footer_col_quick_title || 'Quick Links'}</h4>
              {footerQuickLinks.map(({ path, label }) => (
                <Link key={path} to={path} className="s2-site-footer__link">
                  {label}
                </Link>
              ))}
            </div>

            {/* Locations */}
            <div>
              <h4 className="s2-site-footer__heading">{settings.footer_col_locations_title || 'Locations'}</h4>
              {footerLocationLinks.map(({ path, label }) => (
                <Link key={path} to={path} className="s2-site-footer__link">
                  {label}
                </Link>
              ))}
            </div>

            {/* Contact */}
            <div>
              <h4 className="s2-site-footer__heading">{settings.footer_col_contact_title || 'Contact'}</h4>
              {settings.platform_email && (
                <p style={{ fontSize: 13, margin: '0 0 10px', color: '#6b7280' }}>
                  ✉ {settings.platform_email}
                </p>
              )}
              {settings.platform_phone && (
                <p style={{ fontSize: 13, margin: '0 0 10px', color: '#6b7280' }}>
                  📞 {settings.platform_phone}
                </p>
              )}
              {settings.platform_us_phone && (
                <p style={{ fontSize: 13, margin: '0 0 10px', color: '#6b7280' }}>
                  🇺🇸 {settings.platform_us_phone}
                </p>
              )}
              {settings.platform_city && (
                <p style={{ fontSize: 12, color: '#4b5563', margin: '12px 0 0', lineHeight: 1.6 }}>
                  📍 {settings.platform_address || settings.platform_city}
                </p>
              )}
            </div>
          </div>

          {/* Newsletter */}
          <div
            className="s2-site-footer__newsletter"
            style={{
              background: 'rgba(255,255,255,.04)',
              borderRadius: 12,
              padding: '24px 28px',
              marginBottom: 32,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 20,
              flexWrap: 'wrap',
            }}
          >
            <div>
              <div style={{ fontWeight: 700, fontSize: 16, color: '#fff', marginBottom: 4 }}>
                Subscribe to Our Newsletter
              </div>
              <div style={{ fontSize: 13, color: '#6b7280' }}>
                Get latest NRI news and service updates directly in your inbox.
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <input
                type="email"
                placeholder="Your email address"
                style={{
                  padding: '10px 16px',
                  borderRadius: 8,
                  border: '1px solid rgba(255,255,255,.15)',
                  background: 'rgba(255,255,255,.08)',
                  color: '#fff',
                  fontSize: 14,
                  outline: 'none',
                  minWidth: 220,
                }}
              />
              <button
                style={{
                  background: primary, color: '#fff', border: 'none',
                  padding: '10px 20px', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: 'pointer', whiteSpace: 'nowrap',
                }}
              >
                Subscribe
              </button>
            </div>
          </div>

          {/* Copyright */}
          <div
            style={{
              borderTop: '1px solid rgba(255,255,255,.08)',
              padding: '20px 0 28px',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              flexWrap: 'wrap',
              gap: 12,
            }}
          >
            <span style={{ fontSize: 13 }}>
              {settings.footer_copyright?.trim() ||
                `© ${new Date().getFullYear()} ${name}. All rights reserved.`}
            </span>
            <div style={{ display: 'flex', gap: 20 }}>
              {[
                ['/terms', 'Terms & Conditions'],
                ['/privacy', 'Privacy Policy'],
                ['/sitemap', 'Sitemap'],
              ].map(([to, label]) => (
                <Link key={to} to={to} style={{ fontSize: 13, color: '#6b7280', textDecoration: 'none' }}>
                  {label}
                </Link>
              ))}
            </div>
          </div>
        </div>
      </footer>

      <BottomNav primary={primary} />
    </div>
  )
}

// ── Styles ────────────────────────────────────────────────────────────────────
const socialStyle: React.CSSProperties = {
  width: 34,
  height: 34,
  background: 'rgba(255,255,255,.08)',
  borderRadius: 7,
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  color: '#9ca3af',
  textDecoration: 'none',
  fontSize: 16,
}

// ── Page hero banner (fe component) ──────────────────────────────────────────
interface PageHeroProps {
  title: string
  subtitle?: string
  bg?: string
  primary?: string
  meta?: { icon?: string; label: string }[]
  /** Design System template id — enables platform hide toggle for this hero band. */
  pageTemplateId?: string
  /** Template section key (catalog). Marketing intros use `intro`; default `hero`. */
  templateSectionKey?: string
  hideSettingKey?: string
}
export function PageHero({ title, subtitle, bg, meta, pageTemplateId, templateSectionKey = 'hero', hideSettingKey }: PageHeroProps) {
  const settings = useStore((s) => s.settings)
  const sectionKey = templateSectionKey === 'intro' ? 'intro' : 'hero'
  if (
    pageTemplateId &&
    isTemplateSectionHidden(settings, pageTemplateId, templateSectionKey, hideSettingKey)
  ) {
    return null
  }
  return (
    <div
      className="s2-page-hero s2-surface-dark s2-hero--premium"
      data-s2-section={sectionKey}
      style={{
        ...sectionImageFitStyle(settings as Record<string, string>, sectionKey),
        ...(bg ? { background: bg } : {}),
      }}
    >
      <div className="s2-container">
        <div className="s2-page-hero__crumb">
          <Link to="/">Home</Link>
          {' › '}
          {title}
        </div>
        <h1 className="s2-page-hero__title">{title}</h1>
        {subtitle && <p className="s2-page-hero__subtitle">{subtitle}</p>}
        {meta && meta.length > 0 && (
          <div className="s2-page-hero__meta">
            {meta.map((m) => (
              <span key={m.label} className="s2-hero-meta-chip">
                {m.icon ? <span aria-hidden>{m.icon}</span> : null}
                {m.label}
              </span>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

// ── Auth layout wrapper (Ye component) ────────────────────────────────────────
interface AuthLayoutProps {
  title: string
  subtitle?: string
  children: React.ReactNode
}
export function AuthLayout({ title, subtitle, children }: AuthLayoutProps) {
  const settings = window.S2NRI_CONFIG?.settings || {}
  const primary  = resolvePrimary(settings)
  const name     = settings.platform_name || 'Services2NRI'

  return (
    <Layout>
      <div
        className="s2-auth-screen"
        style={{
          minHeight: '72vh',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '40px 20px',
          background: '#f9fafb',
        }}
      >
        <div className="s2-auth-screen__inner" style={{ width: '100%', maxWidth: 440 }}>
          <div style={{ textAlign: 'center', marginBottom: '32px' }}>
            <Link to="/" style={{ fontSize: '22px', fontWeight: 800, color: primary, textDecoration: 'none' }}>
              {name}
            </Link>
            <h1 style={{ margin: '12px 0 4px', fontSize: '24px', fontWeight: 700, color: '#111827' }}>{title}</h1>
            {subtitle && <p style={{ margin: 0, color: '#6b7280', fontSize: '15px' }}>{subtitle}</p>}
          </div>
          <Card>{children}</Card>
        </div>
      </div>
    </Layout>
  )
}

// import Card locally to avoid circular dep
function Card({ children }: { children: React.ReactNode }) {
  return (
    <div className="s2-auth-card s2-ui-card" style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: '12px', padding: '32px' }}>
      {children}
    </div>
  )
}

// ── Admin / Portal sidebar layout ($e component) ──────────────────────────────
const ADMIN_NAV = [
  { to: '/admin',             icon: '📊', label: 'Dashboard' },
  { to: '/requests',          icon: '📋', label: 'Requests' },
  { to: '/admin/payments',    icon: '💳', label: 'Payments' },
  { to: '/admin/customers',   icon: '👥', label: 'Customers' },
  { to: '/admin/staff',       icon: '🧑‍💼', label: 'Staff' },
  { to: '/admin/services',    icon: '⚙️', label: 'Services' },
  { to: '/admin/categories',  icon: '📁', label: 'Categories' },
  { to: '/admin/reviews',     icon: '⭐', label: 'Reviews' },
  { to: '/admin/tickets',     icon: '🎫', label: 'Tickets' },
  { to: '/admin/audit-log',   icon: '📝', label: 'Audit Log' },
  { to: '/admin/diagnostics', icon: '🔍', label: 'Diagnostics' },
  { to: '/admin/design',      icon: '🎨', label: 'Design System' },
  { to: '/admin/settings',    icon: '🔧', label: 'Settings' },
  { to: '/admin/homepage',    icon: '🏡', label: 'Homepage Settings' },
  { to: '/admin/media',       icon: '🖼️', label: 'Media' },
  { to: '/admin/faqs',        icon: '❓', label: 'FAQs' },
  { to: '/admin/testimonials',icon: '💬', label: 'Testimonials' },
  { to: '/admin/blog',        icon: '✍️', label: 'Blog Posts' },
  { to: '/admin/pricing',     icon: '💰', label: 'Pricing Plans' },
  { to: '/admin/cities',      icon: '🏙️', label: 'Cities' },
]

// Cross-dashboard links — rendered in the sidebar footer, staff only.
// Never shown in CUSTOMER_NAV / the customer dashboard's SidebarLayout
// render path below (isStaff gates this whole block) — clients must never
// see a way into either admin surface.
const BUILDER_URL = '/s2nri-builder'
const S2NRI_ADMIN_URL = '/s2nri-admin'

const CUSTOMER_NAV = [
  { to: '/dashboard',          icon: '📊', label: 'Dashboard' },
  { to: '/dashboard/bookings', icon: '📋', label: 'My Bookings' },
  { to: '/dashboard/tickets',  icon: '🎫', label: 'Support' },
  { to: '/dashboard/profile',  icon: '👤', label: 'My Profile' },
]

interface SidebarLayoutProps {
  children: React.ReactNode
}
export function SidebarLayout({ children }: SidebarLayoutProps) {
  const [open, setOpen] = useState(false)
  const user     = useStore((s) => s.user)
  const logout   = useStore((s) => s.logout)
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const logo     = settings.platform_logo_url
  const name     = settings.platform_name || 'Services2NRI'
  const location = useLocation()

  useEffect(() => { setOpen(false) }, [location.pathname])

  useEffect(() => {
    if (!open) return
    const prevOverflow = document.body.style.overflow
    const prevTouch = document.body.style.touchAction
    document.body.style.overflow = 'hidden'
    document.body.style.touchAction = 'none'
    return () => {
      document.body.style.overflow = prevOverflow
      document.body.style.touchAction = prevTouch
    }
  }, [open])

  const isStaff = user && STAFF_ROLES.includes(user.s2nri_role)
  const nav = isStaff ? ADMIN_NAV : CUSTOMER_NAV

  const sidebar = (
    <div style={{ width: 240, background: '#1E2D40', height: '100%', display: 'flex', flexDirection: 'column', flexShrink: 0 }}>
      <Link
        to={isStaff ? '/admin' : '/dashboard'}
        style={{
          padding: '20px 18px', borderBottom: '1px solid rgba(255,255,255,.08)',
          textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 10,
        }}
      >
        {logo ? (
          <img src={logo} alt={name} style={{ height: 28, filter: 'brightness(10)', objectFit: 'contain' }} />
        ) : (
          <span style={{ fontWeight: 800, fontSize: 16, color: '#fff' }}>{name}</span>
        )}
        <span
          style={{
            fontSize: 10, background: primary, color: '#fff',
            padding: '2px 7px', borderRadius: 4, fontWeight: 700,
          }}
        >
          {isStaff ? 'ADMIN' : 'MY PORTAL'}
        </span>
      </Link>
      <nav style={{ flex: 1, padding: '10px 0', overflowY: 'auto' }}>
        {nav.map(({ to, icon, label }) => (
          <NavLink
            key={to}
            to={to}
            end={to.split('/').length <= 2}
            onMouseEnter={() => prefetchForRoute(to)}
            onFocus={() => prefetchForRoute(to)}
            style={({ isActive }) => ({
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              padding: '10px 18px',
              textDecoration: 'none',
              fontSize: 14,
              fontWeight: isActive ? 700 : 400,
              color: isActive ? '#fff' : '#9ca3af',
              background: isActive ? `${primary}40` : 'transparent',
              borderLeft: isActive ? `3px solid ${primary}` : '3px solid transparent',
              transition: 'all .15s',
            })}
          >
            <span style={{ fontSize: 16, width: 20, textAlign: 'center' }}>{icon}</span>
            {label}
          </NavLink>
        ))}
      </nav>
      <div style={{ padding: '14px 18px', borderTop: '1px solid rgba(255,255,255,.08)' }}>
        {isStaff && (
          <a href={BUILDER_URL} target="_blank" rel="noopener noreferrer"
            style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', marginBottom: 10, background: 'rgba(255,255,255,.08)', border: '1px solid rgba(255,255,255,.12)', borderRadius: 7, textDecoration: 'none', color: '#e5e7eb', fontSize: 13, fontWeight: 600 }}>
            <span style={{ fontSize: 14 }}>🏗️</span> Page Builder ↗
          </a>
        )}
        {isStaff && (
          <a href={S2NRI_ADMIN_URL} target="_blank" rel="noopener noreferrer"
            style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', marginBottom: 10, background: 'rgba(255,255,255,.08)', border: '1px solid rgba(255,255,255,.12)', borderRadius: 7, textDecoration: 'none', color: '#e5e7eb', fontSize: 13, fontWeight: 600 }}>
            <span style={{ fontSize: 14 }}>🛠️</span> S2NRI Admin ↗
          </a>
        )}
        <div style={{ fontSize: 13, color: '#9ca3af', marginBottom: 8, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
          {user?.display_name || user?.email}
        </div>
        <button
          onClick={logout}
          style={{
            width: '100%', background: 'rgba(255,255,255,.06)',
            border: '1px solid rgba(255,255,255,.1)', color: '#9ca3af',
            padding: 8, borderRadius: 7, cursor: 'pointer', fontSize: 13, fontWeight: 500,
          }}
        >
          Sign Out
        </button>
      </div>
    </div>
  )

  return (
    <div className="s2-dash-shell s2-mobile-app-shell">
      {/* Desktop in-flow sidebar (hidden ≤900px — mobile uses overlay below) */}
      <div className="s2-dash-sidebar">{sidebar}</div>
      {/* Mobile overlay sidebar */}
      {open && (
        <div className="s2-dash-overlay" onClick={() => setOpen(false)}>
          <div className="s2-dash-overlay__panel" onClick={(e) => e.stopPropagation()}>
            {sidebar}
          </div>
        </div>
      )}
      {/* Content */}
      <div className="s2-dash-main">
        {/* Mobile header bar */}
        <div className="s2-mobile-hamburger s2-dash-mobile-header">
          <button
            type="button"
            className="s2-dash-mobile-header__menu-btn"
            onClick={() => setOpen(true)}
            aria-label="Open sidebar"
          >
            ☰
          </button>
          <span className="s2-dash-mobile-header__title">{name}</span>
        </div>
        <div className="s2-dash-content-wrap s2-mobile-app-surface" style={{ flex: 1, padding: '24px 20px' }}>
          {children}
        </div>
      </div>

      <BottomNav
        primary={primary}
        variant={isStaff ? 'admin' : 'customer'}
        onMenuClick={() => setOpen(true)}
      />
    </div>
  )
}
