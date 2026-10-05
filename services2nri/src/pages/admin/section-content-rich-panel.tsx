/**
 * Reference-style list editors inside band Content tab (cards, registry previews).
 */
import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/lib/api'
import type { SectionCatalogDef } from '@/lib/design-system-catalog'
import { parseWhyChooseCards, type WhyChooseCard } from '@/lib/home-content-settings'
import { readAdminSetting } from '@/lib/settings-admin'

function parseBannerUrls(raw: string): string[] {
  if (!raw.trim()) return ['']
  return raw
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .slice(0, 5)
}

function joinBannerUrls(urls: string[]): string {
  return urls.map((u) => u.trim()).filter(Boolean).join(', ')
}

function ListItemShell({
  index,
  title,
  children,
  onMoveUp,
  onMoveDown,
  onRemove,
  canRemove,
}: {
  index: number
  title: string
  children: React.ReactNode
  onMoveUp?: () => void
  onMoveDown?: () => void
  onRemove?: () => void
  canRemove?: boolean
}) {
  return (
    <div className="s2-band-list-item">
      <div className="s2-band-list-item__head">
        <span className="s2-band-list-item__grip" aria-hidden>
          ⠿
        </span>
        <span className="s2-band-list-item__index">{index + 1}</span>
        <span className="s2-band-list-item__title">{title}</span>
        <div className="s2-band-list-item__actions">
          {onMoveUp && (
            <button type="button" className="s2-band-link-btn" onClick={onMoveUp}>
              Move up
            </button>
          )}
          {onMoveDown && (
            <button type="button" className="s2-band-link-btn" onClick={onMoveDown}>
              Move down
            </button>
          )}
          {canRemove && onRemove && (
            <button type="button" className="s2-band-link-btn s2-band-link-btn--danger" onClick={onRemove}>
              Remove
            </button>
          )}
        </div>
      </div>
      <div className="s2-band-list-item__body">{children}</div>
    </div>
  )
}

function HeroSlidesEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const raw = readAdminSetting(settings, 'hero_banners')
  const [urls, setUrls] = useState(() => {
    const parsed = parseBannerUrls(raw)
    return parsed.length ? parsed : ['']
  })

  useEffect(() => {
    const parsed = parseBannerUrls(readAdminSetting(settings, 'hero_banners'))
    setUrls(parsed.length ? parsed : [''])
  }, [settings.hero_banners])

  const sync = (next: string[]) => {
    setUrls(next)
    onChange('hero_banners', joinBannerUrls(next))
  }

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Banner slides</h4>
      <p className="s2-band-design-group__lead">Up to 5 background images. Leave empty for built-in gradient slides.</p>
      <div className="s2-band-list">
        {urls.map((url, i) => (
          <ListItemShell
            key={i}
            index={i}
            title={url ? 'Slide image' : 'New slide'}
            canRemove={urls.length > 1}
            onRemove={() => sync(urls.filter((_, j) => j !== i))}
            onMoveUp={
              i > 0
                ? () => {
                    const n = [...urls]
                    ;[n[i - 1], n[i]] = [n[i], n[i - 1]]
                    sync(n)
                  }
                : undefined
            }
            onMoveDown={i < urls.length - 1 ? () => {
              const n = [...urls]
              ;[n[i], n[i + 1]] = [n[i + 1], n[i]]
              sync(n)
            } : undefined}
          >
            <label className="s2-band-field">
              <span className="s2-band-field__label">Image URL</span>
              <input
                type="text"
                className="s2-band-input"
                value={url}
                placeholder="https://…/banner.jpg"
                onChange={(e) => {
                  const n = [...urls]
                  n[i] = e.target.value
                  sync(n)
                }}
              />
            </label>
            {url && (
              <div className="s2-band-list-item__thumb">
                <img src={url} alt="" loading="lazy" onError={(e) => { (e.target as HTMLImageElement).style.display = 'none' }} />
              </div>
            )}
          </ListItemShell>
        ))}
      </div>
      {urls.length < 5 && (
        <button type="button" className="s2-band-add-btn" onClick={() => sync([...urls, ''])}>
          + Add slide
        </button>
      )}
    </section>
  )
}

function WhyChooseEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const [cards, setCards] = useState<WhyChooseCard[]>(() =>
    parseWhyChooseCards(readAdminSetting(settings, 'home_why_choose_json'), []),
  )

  useEffect(() => {
    setCards(parseWhyChooseCards(readAdminSetting(settings, 'home_why_choose_json'), []))
  }, [settings.home_why_choose_json])

  const sync = (next: WhyChooseCard[]) => {
    setCards(next)
    onChange('home_why_choose_json', JSON.stringify(next, null, 2))
  }

  const list = cards.length ? cards : [{ icon: '✓', title: '', sub: '' }]

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Feature cards</h4>
      <p className="s2-band-design-group__lead">Why Choose Us — icon, title, and short description per card.</p>
      <div className="s2-band-list">
        {list.map((card, i) => (
          <ListItemShell
            key={i}
            index={i}
            title={card.title || `Card ${i + 1}`}
            canRemove={list.length > 1}
            onRemove={() => sync(list.filter((_, j) => j !== i))}
            onMoveUp={
              i > 0
                ? () => {
                    const n = [...list]
                    ;[n[i - 1], n[i]] = [n[i], n[i - 1]]
                    sync(n)
                  }
                : undefined
            }
            onMoveDown={
              i < list.length - 1
                ? () => {
                    const n = [...list]
                    ;[n[i], n[i + 1]] = [n[i + 1], n[i]]
                    sync(n)
                  }
                : undefined
            }
          >
            <div className="s2-band-field-grid s2-band-field-grid--cols-3">
              <label className="s2-band-field">
                <span className="s2-band-field__label">Icon</span>
                <input
                  className="s2-band-input"
                  value={card.icon}
                  onChange={(e) => {
                    const n = [...list]
                    n[i] = { ...n[i], icon: e.target.value }
                    sync(n)
                  }}
                />
              </label>
              <label className="s2-band-field">
                <span className="s2-band-field__label">Title</span>
                <input
                  className="s2-band-input"
                  value={card.title}
                  onChange={(e) => {
                    const n = [...list]
                    n[i] = { ...n[i], title: e.target.value }
                    sync(n)
                  }}
                />
              </label>
              <label className="s2-band-field">
                <span className="s2-band-field__label">Description</span>
                <input
                  className="s2-band-input"
                  value={card.sub}
                  onChange={(e) => {
                    const n = [...list]
                    n[i] = { ...n[i], sub: e.target.value }
                    sync(n)
                  }}
                />
              </label>
            </div>
          </ListItemShell>
        ))}
      </div>
      {list.length < 12 && (
        <button type="button" className="s2-band-add-btn" onClick={() => sync([...list, { icon: '★', title: '', sub: '' }])}>
          + Add card
        </button>
      )}
    </section>
  )
}

function StatsCardsEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Trust stats</h4>
      <div className="s2-band-stats-grid">
        {[1, 2, 3, 4].map((n) => (
          <div key={n} className="s2-band-stats-grid__cell">
            <span className="s2-band-field__label">Stat {n}</span>
            <input
              className="s2-band-input"
              placeholder="10,000+"
              value={readAdminSetting(settings, `stat_${n}_number`)}
              onChange={(e) => onChange(`stat_${n}_number`, e.target.value)}
            />
            <input
              className="s2-band-input"
              placeholder="Label"
              value={readAdminSetting(settings, `stat_${n}_label`)}
              onChange={(e) => onChange(`stat_${n}_label`, e.target.value)}
            />
          </div>
        ))}
      </div>
    </section>
  )
}

function RegistryPreview({
  title,
  lead,
  adminPath,
  adminLabel,
  loading,
  empty,
  children,
}: {
  title: string
  lead: string
  adminPath: string
  adminLabel: string
  loading: boolean
  empty: boolean
  children: React.ReactNode
}) {
  return (
    <section className="s2-band-design-group">
      <div className="s2-band-registry-head">
        <div>
          <h4 className="s2-band-design-group__title">{title}</h4>
          <p className="s2-band-design-group__lead">{lead}</p>
        </div>
        <Link to={adminPath} className="s2-btn s2-btn--outline s2-btn--sm">
          {adminLabel} →
        </Link>
      </div>
      {loading ? <p className="s2-band-field__hint">Loading…</p> : empty ? <p className="s2-band-field__hint">No items yet — use the link above to add entries.</p> : children}
    </section>
  )
}

function ServicesRegistryPreview() {
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState<Array<{ id: number; name: string; slug: string; is_active: number; image_url?: string }>>([])

  useEffect(() => {
    api
      .get<{ services: Array<Record<string, unknown>> }>('admin/services?per_page=12')
      .then((d) => {
        const list = (d.services || [])
          .filter((s) => Number(s.is_active) === 1)
          .slice(0, 8)
          .map((s) => ({
            id: Number(s.id),
            name: String(s.name || ''),
            slug: String(s.slug || ''),
            is_active: Number(s.is_active),
            image_url: s.image_url ? String(s.image_url) : undefined,
          }))
        setRows(list)
      })
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }, [])

  return (
    <RegistryPreview
      title="Service cards on homepage"
      lead="Tabs and cards come from Service Registry and Categories. Edit titles above; manage cards in the registry."
      adminPath="/admin/services"
      adminLabel="Service Registry"
      loading={loading}
      empty={rows.length === 0}
    >
      <div className="s2-band-registry-grid">
        {rows.map((s) => (
          <div key={s.id} className="s2-band-registry-card">
            {s.image_url ? <img src={s.image_url} alt="" className="s2-band-registry-card__img" /> : <div className="s2-band-registry-card__img s2-band-registry-card__img--empty">▦</div>}
            <div className="s2-band-registry-card__name">{s.name}</div>
            <Link to={`/admin/services`} className="s2-band-link-btn">
              Edit in registry
            </Link>
          </div>
        ))}
      </div>
    </RegistryPreview>
  )
}

function CitiesRegistryPreview() {
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState<Array<{ id: number; name: string; slug: string; image_url?: string }>>([])

  useEffect(() => {
    api
      .get<{ cities: Array<Record<string, unknown>> }>('admin/cities')
      .then((d) => {
        setRows(
          (d.cities || []).slice(0, 8).map((c) => ({
            id: Number(c.id),
            name: String(c.name || ''),
            slug: String(c.slug || ''),
            image_url: c.image_url ? String(c.image_url) : undefined,
          })),
        )
      })
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }, [])

  return (
    <RegistryPreview
      title="City tiles"
      lead="City names, images, and SEO pages are managed in City Manager. Section headings are edited below."
      adminPath="/admin/cities"
      adminLabel="City Manager"
      loading={loading}
      empty={rows.length === 0}
    >
      <div className="s2-band-registry-grid">
        {rows.map((c) => (
          <div key={c.id} className="s2-band-registry-card">
            {c.image_url ? <img src={c.image_url} alt="" className="s2-band-registry-card__img" /> : <div className="s2-band-registry-card__img s2-band-registry-card__img--empty">📍</div>}
            <div className="s2-band-registry-card__name">{c.name}</div>
          </div>
        ))}
      </div>
    </RegistryPreview>
  )
}

const RICH_FIELD_EXCLUDE: Record<string, string[]> = {
  hero_slides: ['hero_banners'],
  why_choose: ['home_why_choose_json'],
  stats_cards: ['stat_1_number', 'stat_1_label', 'stat_2_number', 'stat_2_label', 'stat_3_number', 'stat_3_label', 'stat_4_number', 'stat_4_label'],
}

export function richPanelExcludes(contentPanel?: SectionCatalogDef['contentPanel']): Set<string> {
  if (!contentPanel) return new Set()
  return new Set(RICH_FIELD_EXCLUDE[contentPanel] || [])
}

/** Settings keys owned by the rich content panel (must be included on save). */
export function richPanelSaveKeys(contentPanel?: SectionCatalogDef['contentPanel']): string[] {
  switch (contentPanel) {
    case 'hero_slides':
      return ['hero_banners']
    case 'why_choose':
      return ['home_why_choose_json']
    case 'stats_cards':
      return [1, 2, 3, 4].flatMap((n) => [`stat_${n}_number`, `stat_${n}_label`])
    default:
      return []
  }
}

export function SectionContentRichPanel({
  section,
  settings,
  onChange,
}: {
  section: SectionCatalogDef
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  switch (section.contentPanel) {
    case 'hero_slides':
      return <HeroSlidesEditor settings={settings} onChange={onChange} />
    case 'why_choose':
      return <WhyChooseEditor settings={settings} onChange={onChange} />
    case 'stats_cards':
      return <StatsCardsEditor settings={settings} onChange={onChange} />
    case 'services_registry':
      return <ServicesRegistryPreview />
    case 'cities_registry':
      return <CitiesRegistryPreview />
    default:
      return null
  }
}
