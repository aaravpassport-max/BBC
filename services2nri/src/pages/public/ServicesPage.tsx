/**
 * ServicesPage — directory with registry-filtered catalog
 */

import React, { useState, useEffect } from 'react'
import { cssVars } from '@/lib/design-tokens'
import { Link, useParams } from 'react-router-dom'
import { Layout } from '@/components/layout/Layout'
import { api } from '@/lib/api'
import { getServiceImage } from '@/lib/images'
import type { Category, Service } from '@/types'

export function ServicesPage() {
  const [categories, setCategories] = useState<Category[]>([])
  const [services,   setServices]   = useState<Service[]>([])
  const [search,     setSearch]     = useState('')
  const [activeSlug, setActiveSlug] = useState('')
  const [loading,    setLoading]    = useState(true)
  const [loadError,  setLoadError]  = useState(false)
  const [searchResults, setSearchResults] = useState<Service[] | null>(null)

  const { categorySlug } = useParams<{ categorySlug?: string }>()

  const load = () => {
    setLoading(true)
    setLoadError(false)
    Promise.all([
      api.get<{ categories: Category[] }>('categories?surface=directory'),
      api.get<{ services: Service[] }>('services?surface=directory'),
    ])
      .then(([catsData, svcsData]) => {
        setCategories(catsData.categories || [])
        setServices(svcsData.services || [])
        if (categorySlug) setActiveSlug(categorySlug)
        setLoading(false)
      })
      .catch(() => { setLoading(false); setLoadError(true) })
  }

  useEffect(() => { load() }, [categorySlug])

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
  const filtered = catalog.filter((s) => !activeSlug || s.category_slug === activeSlug)

  return (
    <Layout>
      <div className="s2-services-hero" data-s2-section="hero">
        <div className="s2-container">
          <h1 className="s2-dir-hero__title">All NRI Services</h1>
          <p className="s2-dir-hero__sub">
            Expert assistance across {categories.length || 8} categories — 44+ services for NRIs worldwide
          </p>
          <input
            type="search"
            className="s2-dir-search"
            placeholder="Search services (e.g. OCI Card, Transcript, Power of Attorney)…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            aria-label="Search services"
          />
        </div>
      </div>

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

        <div className="s2-dir-main">
          {loading ? (
            <div className="s2-dir-grid">
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
            <div className="s2-dir-grid">
              {filtered.map((svc) => (
                <Link key={svc.id} to={`/service/${svc.slug}`} className="s2-dir-card">
                  <div className="s2-dir-card__img-wrap">
                    <img
                      src={svc.image_url || getServiceImage(svc.slug)}
                      alt={svc.name}
                      loading="lazy"
                    />
                    <div
                      className="s2-dir-card__badge"
                      style={svc.color ? cssVars({ 's2-dir-badge-bg': svc.color }) : undefined}
                    >
                      {svc.category_name}
                    </div>
                  </div>
                  <div className="s2-dir-card__body">
                    <h3 className="s2-dir-card__title">{svc.name}</h3>
                    <p className="s2-dir-card__desc">{(svc.short_desc || '').slice(0, 100)}...</p>
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
    </Layout>
  )
}
