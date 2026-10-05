import React from 'react'
import { Link } from 'react-router-dom'
import { CmsElement } from '@/components/public/CmsElement'
import type { Category } from '@/types'

type Props = {
  settings: Record<string, string>
  categories: Category[]
  onSelectCategory: (slug: string) => void
}

export function HomeServiceDiscovery({ settings, categories, onSelectCategory }: Props) {
  const quickCats = categories.slice(0, 8)

  return (
    <section className="s2-home-discovery" aria-labelledby="home-discovery-heading">
      <div className="s2-container s2-section-inner s2-width-standard">
        <div className="s2-home-discovery__head">
          <CmsElement pageId="home" sectionKey="search" elementId="heading" as="h2" className="s2-home-discovery__title">
            {settings.home_search_title || 'Find your service'}
          </CmsElement>
          <CmsElement pageId="home" sectionKey="search" elementId="subtitle" as="p" className="s2-home-discovery__sub">
            {settings.home_search_subtitle || 'Search 44+ NRI services across every category — or jump straight into a domain.'}
          </CmsElement>
        </div>

        <form
          className="s2-home-discovery__search"
          action="/services"
          method="get"
          onSubmit={(e) => {
            e.preventDefault()
            const fd = new FormData(e.currentTarget)
            const q = String(fd.get('q') || '').trim()
            window.location.href = q ? `/services?q=${encodeURIComponent(q)}` : '/services'
          }}
        >
          <CmsElement pageId="home" sectionKey="search" elementId="collection" className="s2-home-discovery__field">
            <span className="s2-home-discovery__field-icon" aria-hidden>🔍</span>
            <input
              type="search"
              name="q"
              className="s2-home-discovery__input"
              placeholder={settings.home_search_placeholder || 'Try OCI renewal, property management, tax filing…'}
              aria-label="Search services"
            />
          </CmsElement>
          <CmsElement pageId="home" sectionKey="search" elementId="primary_button">
            <button type="submit" className="s2-btn s2-btn--primary s2-home-discovery__submit">
              {settings.home_search_button || 'Search services'}
            </button>
          </CmsElement>
        </form>

        {quickCats.length > 0 && (
          <div className="s2-home-discovery__categories" aria-label="Browse by category">
            <p className="s2-home-discovery__categories-label">Popular categories</p>
            <ul className="s2-home-discovery__cat-grid">
              {quickCats.map((cat) => (
                <li key={cat.slug}>
                  <button
                    type="button"
                    className="s2-home-discovery__cat"
                    onClick={() => {
                      onSelectCategory(cat.slug)
                      document.getElementById('home-services-band')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
                    }}
                  >
                    <span className="s2-home-discovery__cat-icon" aria-hidden>{cat.icon || '📋'}</span>
                    <span className="s2-home-discovery__cat-name">{cat.name}</span>
                    <span className="s2-home-discovery__cat-arrow" aria-hidden>→</span>
                  </button>
                </li>
              ))}
            </ul>
            <Link to="/services" className="s2-home-discovery__all">
              View full services directory →
            </Link>
          </div>
        )}
      </div>
    </section>
  )
}
