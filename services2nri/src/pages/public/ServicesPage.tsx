/**
 * ServicesPage — exact match of st() in compiled app.js
 *
 * Features:
 *   - Full-width gradient hero with search input
 *   - Mobile chip filter row (.mobile-filter)
 *   - Desktop sidebar category list (.s2-services-sidebar)
 *   - Skeleton loading state (6 placeholder cards)
 *   - Empty state with "Clear Filters" button
 *   - Service cards with image, category badge, turnaround badge
 *   - Reads :categorySlug from URL param to pre-select category
 */

import React, { useState, useEffect } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Layout } from '@/components/layout/Layout'
import { useStore } from '@/lib/store'
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

  const primary = useStore((s) => s.settings).primary_color || '#4A6FA5'
  const { categorySlug } = useParams<{ categorySlug?: string }>()

  const load = () => {
    setLoading(true)
    setLoadError(false)
    Promise.all([
      api.get<{ categories: Category[] }>('categories'),
      api.get<{ services: Service[] }>('services'),
    ])
      .then(([catsData, svcsData]) => {
        setCategories(catsData.categories || [])
        setServices(svcsData.services || [])
        if (categorySlug) setActiveSlug(categorySlug)
        setLoading(false)
      })
      // FIXED (was previously silent): this is the PUBLIC services
      // catalog — the page prospective customers browse to see what's
      // offered. A failed fetch previously rendered as "No services
      // found... Try a different search term" — actively implying the
      // VISITOR searched wrong, when the server actually failed to
      // respond. A visitor seeing an apparently-empty business during a
      // launch-day traffic spike would simply leave, with no indication
      // anything was wrong on the site's end.
      .catch(() => { setLoading(false); setLoadError(true) })
  }

  useEffect(() => { load() }, [categorySlug])

  // Client-side filter (category + search)
  const filtered = services.filter((s) => {
    const matchCat   = !activeSlug || s.category_slug === activeSlug
    const matchSearch =
      !search ||
      s.name.toLowerCase().includes(search.toLowerCase()) ||
      (s.short_desc || '').toLowerCase().includes(search.toLowerCase())
    return matchCat && matchSearch
  })

  return (
    <Layout>
      {/* ── Hero with search ── */}
      <div style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, color: '#fff', padding: '52px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <h1 style={{ fontSize: 'clamp(24px, 4vw, 42px)', fontWeight: 900, margin: '0 0 8px' }}>All NRI Services</h1>
          <p style={{ opacity: 0.85, fontSize: 16, margin: '0 0 28px' }}>
            Expert assistance across {categories.length || 8} categories — 44+ services for NRIs worldwide
          </p>
          <input
            type="search"
            placeholder="Search services (e.g. OCI Card, Transcript, Power of Attorney)…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            aria-label="Search services"
            style={{
              width: '100%', maxWidth: 560,
              padding: '14px 20px', borderRadius: 10, fontSize: 15,
              border: 'none', outline: 'none', fontFamily: 'inherit',
              boxShadow: '0 4px 20px rgba(0,0,0,.2)',
            }}
          />
        </div>
      </div>

      {/* ── Mobile chip filter ── */}
      <div className="mobile-filter" style={{ maxWidth: 1200, margin: '0 auto', padding: '16px 20px 0' }}>
        <div style={{ display: 'flex', gap: 8, overflowX: 'auto', paddingBottom: 4 }}>
          <button
            onClick={() => setActiveSlug('')}
            style={chipStyle('' === activeSlug, primary)}
          >
            All
          </button>
          {categories.map((cat) => (
            <button
              key={cat.id}
              onClick={() => setActiveSlug(activeSlug === cat.slug ? '' : cat.slug)}
              style={chipStyle(activeSlug === cat.slug, primary)}
            >
              <span>{cat.icon}</span>
              {cat.name}
            </button>
          ))}
        </div>
      </div>

      {/* ── Main layout: sidebar + cards ── */}
      <div className="s2-services-layout" style={{ maxWidth: 1200, margin: '0 auto', padding: '20px 20px 40px' }}>

        {/* Desktop sidebar */}
        <aside className="s2-services-sidebar">
          <h3 style={{ fontSize: 12, fontWeight: 700, color: '#888', textTransform: 'uppercase', letterSpacing: 2, margin: '0 0 14px' }}>
            Categories
          </h3>
          <button
            onClick={() => setActiveSlug('')}
            style={sidebarBtnStyle(activeSlug === '', primary)}
          >
            All Services ({services.length})
          </button>
          {categories.map((cat) => (
            <button
              key={cat.id}
              onClick={() => setActiveSlug(activeSlug === cat.slug ? '' : cat.slug)}
              style={sidebarBtnStyle(activeSlug === cat.slug, primary)}
            >
              <span style={{ fontSize: 18 }}>{cat.icon}</span>
              <span style={{ flex: 1 }}>{cat.name}</span>
              <span style={{ fontSize: 12, color: '#aaa' }}>{cat.service_count || 0}</span>
            </button>
          ))}
        </aside>

        {/* Cards area */}
        <div style={{ flex: 1, minWidth: 0 }}>
          {loading ? (
            /* Skeleton */
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 20 }}>
              {[1, 2, 3, 4, 5, 6].map((n) => (
                <div key={n} style={{ borderRadius: 12, overflow: 'hidden', background: '#F5F7FA', height: 320 }}>
                  <div style={{ height: 180, background: '#EBF0F8' }} />
                  <div style={{ padding: 16 }}>
                    <div style={{ height: 16, background: '#EBF0F8', borderRadius: 4, marginBottom: 8, width: '70%' }} />
                    <div style={{ height: 12, background: '#f0f0f0', borderRadius: 4, marginBottom: 6 }} />
                    <div style={{ height: 12, background: '#f0f0f0', borderRadius: 4, width: '85%' }} />
                  </div>
                </div>
              ))}
            </div>
          ) : loadError ? (
            /* Load error — distinct from "genuinely no results for this search/filter" */
            <div style={{ textAlign: 'center', padding: '60px 20px', color: '#888' }}>
              <div style={{ fontSize: 60, marginBottom: 16 }}>⚠️</div>
              <h3 style={{ fontSize: 20, fontWeight: 700, color: '#1E2D40', margin: '0 0 8px' }}>Couldn't load services</h3>
              <p style={{ margin: '0 0 20px' }}>Something went wrong on our end. Please try again.</p>
              <button
                onClick={load}
                style={{ background: primary, color: '#fff', border: 'none', padding: '10px 24px', borderRadius: 8, fontWeight: 700, cursor: 'pointer', fontSize: 14 }}
              >
                Retry
              </button>
            </div>
          ) : filtered.length === 0 ? (
            /* Empty state */
            <div style={{ textAlign: 'center', padding: '60px 20px', color: '#888' }}>
              <div style={{ fontSize: 60, marginBottom: 16 }}>🔍</div>
              <h3 style={{ fontSize: 20, fontWeight: 700, color: '#1E2D40', margin: '0 0 8px' }}>No services found</h3>
              <p style={{ margin: '0 0 20px' }}>Try a different search term or browse all categories.</p>
              <button
                onClick={() => { setSearch(''); setActiveSlug('') }}
                style={{ background: primary, color: '#fff', border: 'none', padding: '10px 24px', borderRadius: 8, fontWeight: 700, cursor: 'pointer', fontSize: 14 }}
              >
                Clear Filters
              </button>
            </div>
          ) : (
            /* Service cards */
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 20 }}>
              {filtered.map((svc) => (
                <Link
                  key={svc.id}
                  to={`/service/${svc.slug}`}
                  style={{ textDecoration: 'none', borderRadius: 12, overflow: 'hidden', background: '#fff', boxShadow: '0 2px 12px rgba(0,0,0,.07)', display: 'flex', flexDirection: 'column', transition: 'transform .2s, box-shadow .2s' }}
                  onMouseEnter={(e) => { e.currentTarget.style.transform = 'translateY(-4px)'; e.currentTarget.style.boxShadow = '0 8px 28px rgba(0,0,0,.14)' }}
                  onMouseLeave={(e) => { e.currentTarget.style.transform = ''; e.currentTarget.style.boxShadow = '0 2px 12px rgba(0,0,0,.07)' }}
                >
                  {/* Image */}
                  <div style={{ height: 175, overflow: 'hidden', position: 'relative' }}>
                    <img
                      src={svc.image_url || getServiceImage(svc.slug)}
                      alt={svc.name}
                      loading="lazy"
                      style={{ width: '100%', height: '100%', objectFit: 'cover', transition: 'transform .4s' }}
                      onMouseEnter={(e) => (e.currentTarget.style.transform = 'scale(1.06)')}
                      onMouseLeave={(e) => (e.currentTarget.style.transform = '')}
                    />
                    <div
                      style={{
                        position: 'absolute', top: 10, right: 10,
                        background: svc.color || primary,
                        color: '#fff', padding: '3px 10px', borderRadius: 99, fontSize: 11, fontWeight: 600,
                      }}
                    >
                      {svc.category_name}
                    </div>
                  </div>

                  {/* Body */}
                  <div style={{ padding: '16px 18px 18px', flex: 1, display: 'flex', flexDirection: 'column' }}>
                    <h3 style={{ fontSize: 16, fontWeight: 700, color: '#1E2D40', margin: '0 0 6px' }}>{svc.name}</h3>
                    <p style={{ fontSize: 13, color: '#666', lineHeight: 1.6, margin: '0 0 14px', flex: 1 }}>
                      {(svc.short_desc || '').slice(0, 100)}...
                    </p>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                      <span style={{ fontSize: 13, fontWeight: 600, color: primary }}>View Details →</span>
                      {(svc.turnaround_days || svc.turnaround) && (
                        <span style={{ fontSize: 11, background: '#e8f5e9', color: '#2e7d32', padding: '2px 8px', borderRadius: 10, fontWeight: 600 }}>
                          {svc.turnaround_days || svc.turnaround} Days
                        </span>
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

// ── Style helpers ─────────────────────────────────────────────────────────────
function chipStyle(active: boolean, primary: string): React.CSSProperties {
  return {
    padding: '7px 16px',
    borderRadius: 99,
    border: `1px solid ${active ? primary : '#e0e0e0'}`,
    background: active ? primary : '#fff',
    color: active ? '#fff' : '#555',
    fontWeight: active ? 700 : 500,
    fontSize: 13,
    cursor: 'pointer',
    whiteSpace: 'nowrap',
    flexShrink: 0,
    display: 'flex',
    alignItems: 'center',
    gap: 6,
  }
}

function sidebarBtnStyle(active: boolean, primary: string): React.CSSProperties {
  return {
    display: 'flex',
    alignItems: 'center',
    gap: 10,
    width: '100%',
    textAlign: 'left',
    padding: '10px 14px',
    background: active ? `${primary}15` : 'transparent',
    color: active ? primary : '#444',
    fontWeight: active ? 700 : 500,
    border: 'none',
    borderRadius: 9,
    cursor: 'pointer',
    marginBottom: 4,
    fontSize: 14,
    borderLeft: active ? `3px solid ${primary}` : '3px solid transparent',
    transition: 'all .15s',
  }
}
