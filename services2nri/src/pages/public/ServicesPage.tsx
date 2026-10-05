/**
 * ServicesPage — directory with registry-filtered catalog
 */

import React, { useState, useEffect } from 'react'
import { cssVars } from '@/lib/design-tokens'
import { Link, useParams } from 'react-router-dom'
import { Layout } from '@/components/layout/Layout'
import { useStore } from '@/lib/store'
import { api } from '@/lib/api'
import { useResource } from '@/lib/useResource'
import { getServiceImage } from '@/lib/images'
import type { Category, Service } from '@/types'
import { isTemplateSectionHidden } from '@/lib/section-visibility'
import { parseHeroMetaJson } from '@/lib/page-hero-meta'

function settingFlag(raw: string | undefined, defaultOn = true): boolean {
  if (raw === undefined || raw === '') return defaultOn
  return raw !== '0'
}

export function ServicesPage() {
  const settings = useStore((s) => s.settings)
  const showSearch = settingFlag(settings.services_show_search, true)
  const showCategoryFilter = settingFlag(settings.services_show_category_filter, true)
  const [search,     setSearch]     = useState('')
  const [activeSlug, setActiveSlug] = useState('')
  const [searchResults, setSearchResults] = useState<Service[] | null>(null)

  const { categorySlug } = useParams<{ categorySlug?: string }>()

  const {
    data: catsData,
    error: catsError,
    isInitialLoad: catsInitial,
    refresh: refreshCats,
  } = useResource(
    'categories?surface=directory',
    () => api.get<{ categories: Category[] }>('categories?surface=directory'),
    { persist: true, ttl: 120_000 },
  )
  const {
    data: svcsData,
    error: svcsError,
    isInitialLoad: svcsInitial,
    refresh: refreshSvcs,
  } = useResource(
    'services?surface=directory',
    () => api.get<{ services: Service[] }>('services?surface=directory'),
    { persist: true, ttl: 120_000 },
  )

  const categories = catsData?.categories ?? []
  const services = svcsData?.services ?? []
  const loadError = catsError || svcsError
  const showSkeleton =
    categories.length === 0 && services.length === 0 && (catsInitial || svcsInitial)

  useEffect(() => {
    if (categorySlug) setActiveSlug(categorySlug)
  }, [categorySlug])

  const load = () => {
    refreshCats(true)
    refreshSvcs(true)
  }

  useEffect(() => {
    const q = search.trim()
    if (!q) {
      setSearchResults(null)
      return
    }
    const timer = window.setTimeout(() => {
      api.get<{ services: Service[] }>(`services?surface=search&search=${encodeURIComponent(q)}`)
        .then((r) => setSearchResults(r.services || []))
        .catch(() => setSearchResults([]))
    }, 280)
    return () => window.clearTimeout(timer)
  }, [search])

  const catalog = searchResults ?? services
  const filteredRaw = catalog.filter((s) => !activeSlug || s.category_slug === activeSlug)
  const perPage = parseInt(settings.services_per_page || '', 10)
  const filtered =
    perPage > 0 && !searchResults ? filteredRaw.slice(0, perPage) : filteredRaw

  const formatDesc = (text: string, max = 100) => {
    const t = (text || '').trim()
    if (!t) return 'Expert NRI assistance — view details for scope and turnaround.'
    if (t.length <= max) return t
    return `${t.slice(0, max).trim()}…`
  }

  const hideHero = isTemplateSectionHidden(settings, 'services', 'hero')
  const hideDirectory = isTemplateSectionHidden(settings, 'services', 'directory')

  return (
    <Layout>
      <div className="s2-services-page">
        {!hideHero && (
        <section className="s2-services-hero s2-surface-dark s2-hero--premium" data-s2-section="hero">
          <div className="s2-container">
            <h1 className="s2-dir-hero__title">{settings.services_page_title || settings.services_title || 'All NRI Services'}</h1>
            <p className="s2-dir-hero__sub">
              {settings.services_page_subtitle ||
                `Expert assistance across ${categories.length || 8} categories — 44+ services for NRIs worldwide`}
            </p>
            {showSearch && (
              <input
                type="search"
                className="s2-dir-search"
                placeholder="Search services (e.g. OCI Card, Transcript, Power of Attorney)…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                aria-label="Search services"
              />
            )}
            <div className="s2-page-hero__meta">
              {parseHeroMetaJson(settings.services_hero_meta_json, [
                { icon: '🌐', label: 'Trusted by NRIs worldwide' },
                { icon: '⚡', label: 'Quote within 24 hours' },
                { icon: '🔒', label: 'Secure document handling' },
              ]).map((m) => (
                <span key={m.label} className="s2-hero-meta-chip">
                  {m.icon ? <span aria-hidden>{m.icon}</span> : null}
                  {m.label}
                </span>
              ))}
            </div>
          </div>
        </section>
        )}

        {!hideDirectory && (
        <div className="s2-services-body s2-surface-light">
      <div className="mobile-filter s2-container s2-dir-mobile-filter">
        <div className="s2-dir-chips">
          <button type="button" className={`s2-dir-chip${activeSlug === '' ? ' s2-dir-chip--active' : ''}`} onClick={() => setActiveSlug('')}>
            All
          </button>
          {categories.map((cat) => (
            <button
              key={cat.id}
              type="button"
              className={`s2-dir-chip${activeSlug === cat.slug ? ' s2-dir-chip--active' : ''}`}
              onClick={() => setActiveSlug(activeSlug === cat.slug ? '' : cat.slug)}
            >
              <span>{cat.icon}</span>
              {cat.name}
            </button>
          ))}
        </div>
      </div>

      <div className="s2-services-layout s2-container" data-s2-section="directory">
        {showCategoryFilter && (
        <aside className="s2-services-sidebar">
          <h3 className="s2-dir-sidebar__label">Categories</h3>
          <button
            type="button"
            className={`s2-dir-sidebar-btn${activeSlug === '' ? ' s2-dir-sidebar-btn--active' : ''}`}
            onClick={() => setActiveSlug('')}
          >
            All Services ({services.length})
          </button>
          {categories.map((cat) => (
            <button
              key={cat.id}
              type="button"
              className={`s2-dir-sidebar-btn${activeSlug === cat.slug ? ' s2-dir-sidebar-btn--active' : ''}`}
              onClick={() => setActiveSlug(activeSlug === cat.slug ? '' : cat.slug)}
            >
              <span className="s2-dir-sidebar-btn__icon">{cat.icon}</span>
              <span className="s2-dir-sidebar-btn__name">{cat.name}</span>
              <span className="s2-dir-sidebar-btn__count">{cat.service_count || 0}</span>
            </button>
          ))}
        </aside>
        )}

        <div className="s2-dir-main">
          {settings.services_subtitle && (
            <p className="s2-t-body s2-dir-intro" style={{ marginBottom: 16 }}>
              {settings.services_subtitle}
            </p>
          )}
          {showSkeleton ? (
            <div className="s2-dir-grid s2-stagger">
              {[1, 2, 3, 4, 5, 6].map((n) => (
                <div key={n} className="s2-dir-skeleton">
                  <div className="s2-dir-skeleton__img" />
                  <div className="s2-dir-skeleton__line s2-dir-skeleton__line--title" />
                  <div className="s2-dir-skeleton__line s2-dir-skeleton__line--sub" />
                </div>
              ))}
            </div>
          ) : loadError ? (
            <div className="s2-dir-state">
              <div className="s2-dir-state__emoji" aria-hidden>⚠️</div>
              <h3 className="s2-t-h3">Couldn't load services</h3>
              <p className="s2-t-body s2-dir-state__text">Something went wrong on our end. Please try again.</p>
              <button type="button" onClick={load} className="s2-btn s2-btn--primary">Retry</button>
            </div>
          ) : filtered.length === 0 ? (
            <div className="s2-dir-state">
              <div className="s2-dir-state__emoji" aria-hidden>🔍</div>
              <h3 className="s2-t-h3">No services found</h3>
              <p className="s2-t-body s2-dir-state__text">Try a different search term or browse all categories.</p>
              <button type="button" onClick={() => { setSearch(''); setActiveSlug('') }} className="s2-btn s2-btn--primary">
                Clear Filters
              </button>
            </div>
          ) : (
            <div className="s2-dir-grid s2-stagger">
              {filtered.map((svc) => (
                <Link key={svc.id} to={`/service/${svc.slug}`} className="s2-dir-card">
                  <div className="s2-dir-card__img-wrap">
                    <img
                      src={svc.image_url || getServiceImage(svc.slug)}
                      alt={svc.name}
                      loading="lazy"
                    />
                    {svc.category_name && (
                      <div
                        className="s2-dir-card__badge"
                        style={svc.color ? cssVars({ 's2-dir-badge-bg': svc.color }) : undefined}
                      >
                        {svc.category_name}
                      </div>
                    )}
                  </div>
                  <div className="s2-dir-card__body">
                    <h3 className="s2-dir-card__title">{svc.name}</h3>
                    <p className="s2-dir-card__desc">{formatDesc(svc.short_desc || '')}</p>
                    <div className="s2-dir-card__foot">
                      <span className="s2-dir-card__link">View Details →</span>
                      {(svc.turnaround_days || svc.turnaround) && (
                        <span className="s2-dir-card__turnaround">{svc.turnaround_days || svc.turnaround} Days</span>
                      )}
                    </div>
                  </div>
                </Link>
              ))}
            </div>
          )}
        </div>
      </div>
        </div>
        )}
      </div>
    </Layout>
  )
}
