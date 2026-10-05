import React, { useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { CmsElement } from '@/components/public/CmsElement'
import { getServiceImage } from '@/lib/images'
import type { Category, Service } from '@/types'

const VISIBLE_SLOTS = 4

type Props = {
  settings: Record<string, string>
  categories: Category[]
  activeCategory: string
  onCategoryChange: (slug: string) => void
  services: Service[]
  categoryNameMap: Record<string, string>
}

export function HomeServicesShowcase({
  settings,
  categories,
  activeCategory,
  onCategoryChange,
  services,
  categoryNameMap,
}: Props) {
  const panelRef = useRef<HTMLDivElement>(null)
  const activeTileRef = useRef<HTMLButtonElement | null>(null)
  const [panelMinHeight, setPanelMinHeight] = useState<number | undefined>(undefined)
  const [fadeKey, setFadeKey] = useState(0)

  const slots: (Service | null)[] = [...services.slice(0, VISIBLE_SLOTS)]
  while (slots.length < VISIBLE_SLOTS) slots.push(null)

  useLayoutEffect(() => {
    const el = panelRef.current
    if (!el) return
    const h = el.offsetHeight
    if (h > 0) setPanelMinHeight((prev) => (prev && h < prev ? prev : h))
  }, [activeCategory, services, fadeKey])

  const handleCategory = (slug: string) => {
    if (slug === activeCategory) return
    setFadeKey((k) => k + 1)
    onCategoryChange(slug)
  }

  useLayoutEffect(() => {
    activeTileRef.current?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' })
  }, [activeCategory])

  const cols = settings.css_svc_cols
    ? Math.min(6, Math.max(1, parseInt(settings.css_svc_cols, 10) || 4))
    : undefined

  const activeName = categoryNameMap[activeCategory] || 'this category'

  return (
    <div className="s2-home-svc-showcase" id="home-services-band">
      <div className="s2-home-section-head s2-public-section-head">
        <CmsElement pageId="home" sectionKey="home_services" elementId="eyebrow" as="p" className="s2-t-eyebrow">
          {settings.services_eyebrow || 'What We Offer'}
        </CmsElement>
        <CmsElement pageId="home" sectionKey="home_services" elementId="heading" as="h2" className="s2-t-section-heading">
          {settings.services_title || 'Our Services'}
        </CmsElement>
        {(settings.services_subtitle || 'Expert NRI assistance across 8 service categories') && (
          <CmsElement pageId="home" sectionKey="home_services" elementId="subtitle" as="p" className="s2-t-body s2-public-section-head__sub">
            {settings.services_subtitle || 'Expert NRI assistance across 8 service categories'}
          </CmsElement>
        )}
      </div>

      <CmsElement pageId="home" sectionKey="home_services" elementId="collection" className="s2-home-svc-categories">
        <div className="s2-home-svc-categories__toolbar">
          <p className="s2-home-svc-categories__lead">
            Browse by category — currently showing <strong>{activeName}</strong>
          </p>
          <Link to={`/services${activeCategory ? `/${activeCategory}` : ''}`} className="s2-home-svc-categories__all-link">
            Full category →
          </Link>
        </div>

        <label className="s2-home-svc-categories__mobile-select">
          <span className="s2-home-svc-categories__mobile-label">Category</span>
          <select
            value={activeCategory}
            aria-label="Choose service category"
            onChange={(e) => handleCategory(e.target.value)}
          >
            {categories.map((cat) => (
              <option key={cat.slug} value={cat.slug}>
                {cat.name}
                {cat.service_count != null && cat.service_count > 0 ? ` (${cat.service_count})` : ''}
              </option>
            ))}
          </select>
        </label>

        <div className="s2-home-svc-cat-grid" role="tablist" aria-label="Service categories">
          {categories.map((cat) => {
            const selected = activeCategory === cat.slug
            return (
              <button
                key={cat.slug}
                ref={selected ? activeTileRef : undefined}
                type="button"
                role="tab"
                aria-selected={selected}
                className={`s2-home-svc-cat-tile${selected ? ' is-active' : ''}`}
                onClick={() => handleCategory(cat.slug)}
              >
                <span className="s2-home-svc-cat-tile__icon" aria-hidden>
                  {cat.icon || '📁'}
                </span>
                <span className="s2-home-svc-cat-tile__name">{cat.name}</span>
                {cat.service_count != null && cat.service_count > 0 ? (
                  <span className="s2-home-svc-cat-tile__count">{cat.service_count} services</span>
                ) : null}
              </button>
            )
          })}
        </div>
      </CmsElement>

      <CmsElement pageId="home" sectionKey="home_services" elementId="service_card">
        <div className="s2-home-svc-panel-shell" style={panelMinHeight ? { minHeight: panelMinHeight } : undefined}>
          <CmsElement pageId="home" sectionKey="home_services" elementId="grid">
            <div
              ref={panelRef}
              key={`${activeCategory}-${fadeKey}`}
              className="s2-svc-grid s2-home-svc-grid s2-home-svc-panel--fade-in"
              role="tabpanel"
              aria-label={`Services in ${activeName}`}
              style={{
                ...(cols ? { gridTemplateColumns: `repeat(${cols}, 1fr)` } : {}),
                ...(settings.css_svc_gap ? { gap: settings.css_svc_gap } : {}),
              }}
            >
              {slots.map((svc, i) => {
                const n = i + 1
                if (!svc) {
                  return <div key={`empty-${n}`} className="s2-home-svc-card s2-home-svc-card--placeholder" aria-hidden />
                }
                const img =
                  svc.image_url ||
                  (svc as Service & { img?: string }).img ||
                  getServiceImage(svc.slug || activeCategory)
                const desc = (svc.short_desc || '').slice(0, 110) + ((svc.short_desc || '').length > 110 ? '…' : '')
                return (
                  <CmsElement
                    key={svc.id || `${activeCategory}-${n}`}
                    pageId="home"
                    sectionKey="home_services"
                    elementId={`service_${n}`}
                    className="s2-card s2-card--service s2-card--media-bleed s2-home-svc-card s2-animate-hover"
                    style={settings.css_svc_card_bg ? { background: settings.css_svc_card_bg } : undefined}
                  >
                    <CmsElement pageId="home" sectionKey="home_services" elementId={`service_${n}_media`} className="s2-home-svc-card__media">
                      <img src={img} alt="" loading="lazy" />
                    </CmsElement>
                    <div className="s2-home-svc-card__body">
                      <CmsElement pageId="home" sectionKey="home_services" elementId={`service_${n}_title`} as="h3" className="s2-home-svc-card__title">
                        {svc.name}
                      </CmsElement>
                      <CmsElement pageId="home" sectionKey="home_services" elementId={`service_${n}_desc`} as="p" className="s2-home-svc-card__desc">
                        {desc}
                      </CmsElement>
                      <CmsElement pageId="home" sectionKey="home_services" elementId={`service_${n}_cta`}>
                        <Link to={`/service/${svc.slug}`} className="s2-home-svc-card__link">
                          View service <span aria-hidden>→</span>
                        </Link>
                      </CmsElement>
                    </div>
                  </CmsElement>
                )
              })}
            </div>
          </CmsElement>
        </div>
      </CmsElement>

      <CmsElement pageId="home" sectionKey="home_services" elementId="link" className="s2-home-section-cta">
        <Link to="/services" className="s2-home-text-link">
          {settings.services_view_all_text || 'View All Services →'}
        </Link>
      </CmsElement>
    </div>
  )
}
