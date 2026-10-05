/**
 * Reference-style list editors inside band Content tab (cards, registry previews).
 */
import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/lib/api'
import type { SectionCatalogDef } from '@/lib/design-system-catalog'
import {
  parseAboutHighlights,
  parseAwardBadges,
  parseHiwSteps,
  parseHomeFaqPairs,
  parseLogoChips,
  parseTeamMembers,
  parseValueCards,
  parseWhyChooseCards,
  type AwardBadge,
  type FaqPair,
  type HighlightPair,
  type HiwStepPage,
  type LogoChip,
  type TeamMember,
  type ValueCard,
  type WhyChooseCard,
} from '@/lib/home-content-settings'
import type { SectionContentPanelId } from '@/lib/design-system-catalog'
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

const HIW_STEP_KEYS = [1, 2, 3, 4, 5, 6].flatMap((n) => [`hiw_step${n}_title`, `hiw_step${n}_desc`])

const RICH_FIELD_EXCLUDE: Record<string, string[]> = {
  hero_slides: ['hero_banners'],
  why_choose: ['home_why_choose_json'],
  stats_cards: ['stat_1_number', 'stat_1_label', 'stat_2_number', 'stat_2_label', 'stat_3_number', 'stat_3_label', 'stat_4_number', 'stat_4_label'],
  press_logos: ['home_press_json'],
  partners_list: ['home_partners_json'],
  awards_list: ['home_awards_json'],
  faq_items: ['home_faq_json'],
  hiw_steps: ['hiw_page_steps_json', ...HIW_STEP_KEYS],
  marquee_band: ['marquee_show', 'marquee_text', 'marquee_speed', 'marquee_bg', 'marquee_color', 'marquee_pause_hover'],
  about_story: [
    'about_eyebrow',
    'about_heading',
    'about_text',
    'about_text_secondary',
    'about_image_url',
    'about_video_url',
    'about_highlights_json',
  ],
  value_cards: ['about_values_title', 'about_values_json'],
  team_members: ['about_team_title', 'about_team_json'],
  marketing_cta: ['about_cta_title', 'about_cta_subtitle'],
  contact_channels: ['contact_title', 'contact_form_title', 'contact_email', 'contact_phone', 'contact_address'],
  faq_page_cta: ['faq_cta_title', 'faq_cta_body', 'faq_cta_button'],
  pricing_plans: ['pricing_grid_eyebrow', 'pricing_grid_title', 'pricing_grid_subtitle'],
  pricing_compare: ['pricing_compare_title', 'pricing_compare_subtitle'],
  hiw_page_callout: ['hiw_page_cta_title', 'hiw_page_cta_subtitle'],
  services_directory: ['services_show_search', 'services_show_category_filter', 'services_per_page'],
}

export function resolveContentPanel(section: SectionCatalogDef): SectionContentPanelId | undefined {
  if (section.contentPanel) return section.contentPanel
  if ((section.contentFields?.length ?? 0) > 0) return undefined
  return section.adminLink ? 'registry_hub' : 'registry_hub'
}

export function richPanelExcludes(contentPanel?: SectionContentPanelId): Set<string> {
  if (!contentPanel) return new Set()
  return new Set(RICH_FIELD_EXCLUDE[contentPanel] || [])
}

export function richPanelSaveKeys(contentPanel?: SectionContentPanelId): string[] {
  switch (contentPanel) {
    case 'hero_slides':
      return ['hero_banners']
    case 'why_choose':
      return ['home_why_choose_json']
    case 'stats_cards':
      return [1, 2, 3, 4].flatMap((n) => [`stat_${n}_number`, `stat_${n}_label`])
    case 'press_logos':
      return ['home_press_json']
    case 'partners_list':
      return ['home_partners_json']
    case 'awards_list':
      return ['home_awards_json']
    case 'faq_items':
      return ['home_faq_json']
    case 'hiw_steps':
      return ['hiw_page_steps_json', ...HIW_STEP_KEYS]
    case 'marquee_band':
      return ['marquee_show', 'marquee_text', 'marquee_speed', 'marquee_bg', 'marquee_color', 'marquee_pause_hover']
    case 'about_story':
      return [
        'about_eyebrow',
        'about_heading',
        'about_text',
        'about_text_secondary',
        'about_image_url',
        'about_video_url',
        'about_highlights_json',
      ]
    case 'value_cards':
      return ['about_values_title', 'about_values_json']
    case 'team_members':
      return ['about_team_title', 'about_team_json']
    case 'hiw_page_callout':
      return ['hiw_page_cta_title', 'hiw_page_cta_subtitle']
    case 'faq_page_cta':
      return ['faq_cta_title', 'faq_cta_body', 'faq_cta_button']
    case 'pricing_plans':
      return ['pricing_grid_eyebrow', 'pricing_grid_title', 'pricing_grid_subtitle']
    case 'pricing_compare':
      return ['pricing_compare_title', 'pricing_compare_subtitle']
    case 'contact_channels':
      return ['contact_title', 'contact_form_title', 'contact_email', 'contact_phone', 'contact_address']
    case 'marketing_cta':
      return ['about_cta_title', 'about_cta_subtitle']
    default:
      return []
  }
}

function FaqItemsEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const [items, setItems] = useState<FaqPair[]>(() => parseHomeFaqPairs(readAdminSetting(settings, 'home_faq_json'), []))
  useEffect(() => {
    setItems(parseHomeFaqPairs(readAdminSetting(settings, 'home_faq_json'), []))
  }, [settings.home_faq_json])

  const sync = (next: FaqPair[]) => {
    setItems(next)
    onChange('home_faq_json', JSON.stringify(next, null, 2))
  }
  const list = items.length ? items : [{ q: '', a: '' }]

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">FAQ items</h4>
      <p className="s2-band-design-group__lead">Shown on the homepage when the live FAQ database is empty. Database entries take priority on the FAQ page.</p>
      <FaqRegistryPreview compact />
      <div className="s2-band-list">
        {list.map((item, i) => (
          <ListItemShell
            key={i}
            index={i}
            title={item.q || `Question ${i + 1}`}
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
            <label className="s2-band-field">
              <span className="s2-band-field__label">Question</span>
              <input className="s2-band-input" value={item.q} onChange={(e) => {
                const n = [...list]
                n[i] = { ...n[i], q: e.target.value }
                sync(n)
              }} />
            </label>
            <label className="s2-band-field">
              <span className="s2-band-field__label">Answer</span>
              <textarea className="s2-band-input" rows={3} value={item.a} onChange={(e) => {
                const n = [...list]
                n[i] = { ...n[i], a: e.target.value }
                sync(n)
              }} />
            </label>
          </ListItemShell>
        ))}
      </div>
      {list.length < 20 && (
        <button type="button" className="s2-band-add-btn" onClick={() => sync([...list, { q: '', a: '' }])}>
          + Add FAQ
        </button>
      )}
    </section>
  )
}

function LogoChipsEditor({
  jsonKey,
  title,
  settings,
  onChange,
  stringListFallback,
}: {
  jsonKey: string
  title: string
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
  stringListFallback?: boolean
}) {
  const raw = readAdminSetting(settings, jsonKey)
  const [chips, setChips] = useState<LogoChip[]>(() => parseLogoChips(raw, []))
  useEffect(() => {
    setChips(parseLogoChips(readAdminSetting(settings, jsonKey), []))
  }, [settings, jsonKey])

  const sync = (next: LogoChip[]) => {
    setChips(next)
    onChange(jsonKey, JSON.stringify(next, null, 2))
  }
  const list = chips.length ? chips : [{ name: '' }]

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">{title}</h4>
      <div className="s2-band-list">
        {list.map((chip, i) => (
          <ListItemShell
            key={i}
            index={i}
            title={chip.name || `Item ${i + 1}`}
            canRemove={list.length > 1}
            onRemove={() => sync(list.filter((_, j) => j !== i))}
          >
            <div className="s2-band-field-grid s2-band-field-grid--cols-2">
              <label className="s2-band-field">
                <span className="s2-band-field__label">Name</span>
                <input
                  className="s2-band-input"
                  value={chip.name}
                  onChange={(e) => {
                    const n = [...list]
                    n[i] = { ...n[i], name: e.target.value }
                    sync(n)
                  }}
                />
              </label>
              {!stringListFallback && (
                <label className="s2-band-field">
                  <span className="s2-band-field__label">Brand slug (optional)</span>
                  <input
                    className="s2-band-input"
                    value={chip.brand ?? ''}
                    onChange={(e) => {
                      const n = [...list]
                      n[i] = { ...n[i], brand: e.target.value || undefined }
                      sync(n)
                    }}
                  />
                </label>
              )}
            </div>
          </ListItemShell>
        ))}
      </div>
      <button type="button" className="s2-band-add-btn" onClick={() => sync([...list, { name: '' }])}>
        + Add item
      </button>
    </section>
  )
}

function AwardsEditor({ settings, onChange }: { settings: Record<string, string>; onChange: (key: string, value: string) => void }) {
  const [badges, setBadges] = useState<AwardBadge[]>(() => parseAwardBadges(readAdminSetting(settings, 'home_awards_json'), []))
  useEffect(() => {
    setBadges(parseAwardBadges(readAdminSetting(settings, 'home_awards_json'), []))
  }, [settings.home_awards_json])

  const sync = (next: AwardBadge[]) => {
    setBadges(next)
    onChange('home_awards_json', JSON.stringify(next, null, 2))
  }
  const list = badges.length ? badges : [{ emoji: '🏆', text: '', variant: 'orange' as const }]

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Award badges</h4>
      <div className="s2-band-list">
        {list.map((b, i) => (
          <ListItemShell key={i} index={i} title={b.text || `Badge ${i + 1}`} canRemove={list.length > 1} onRemove={() => sync(list.filter((_, j) => j !== i))}>
            <div className="s2-band-field-grid s2-band-field-grid--cols-3">
              <label className="s2-band-field">
                <span className="s2-band-field__label">Emoji</span>
                <input className="s2-band-input" value={b.emoji} onChange={(e) => {
                  const n = [...list]
                  n[i] = { ...n[i], emoji: e.target.value }
                  sync(n)
                }} />
              </label>
              <label className="s2-band-field">
                <span className="s2-band-field__label">Label</span>
                <input className="s2-band-input" value={b.text} onChange={(e) => {
                  const n = [...list]
                  n[i] = { ...n[i], text: e.target.value }
                  sync(n)
                }} />
              </label>
              <label className="s2-band-field s2-band-field--select">
                <span className="s2-band-field__label">Style</span>
                <select
                  className="s2-band-select"
                  value={b.variant ?? 'orange'}
                  onChange={(e) => {
                    const n = [...list]
                    n[i] = { ...n[i], variant: e.target.value === 'primary' ? 'primary' : 'orange' }
                    sync(n)
                  }}
                >
                  <option value="orange">Orange</option>
                  <option value="primary">Primary</option>
                </select>
              </label>
            </div>
          </ListItemShell>
        ))}
      </div>
      <button type="button" className="s2-band-add-btn" onClick={() => sync([...list, { emoji: '🏆', text: '', variant: 'orange' }])}>
        + Add badge
      </button>
    </section>
  )
}

function HiWPageStepsListEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const [steps, setSteps] = useState<HiwStepPage[]>(() =>
    parseHiwSteps(readAdminSetting(settings, 'hiw_page_steps_json'), [{ n: 1, icon: '🔍', t: '', d: '' }]),
  )
  useEffect(() => {
    setSteps(parseHiwSteps(readAdminSetting(settings, 'hiw_page_steps_json'), [{ n: 1, icon: '🔍', t: '', d: '' }]))
  }, [settings.hiw_page_steps_json])

  const syncSteps = (next: HiwStepPage[]) => {
    setSteps(next)
    onChange('hiw_page_steps_json', JSON.stringify(next, null, 2))
  }
  const list = steps.length ? steps : [{ n: 1, icon: '✓', t: '', d: '' }]

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Process steps</h4>
      <p className="s2-band-design-group__lead">Shown as a vertical timeline on the How It Works page.</p>
      <div className="s2-band-list">
        {list.map((step, i) => (
          <ListItemShell
            key={i}
            index={i}
            title={step.t || `Step ${step.n}`}
            canRemove={list.length > 1}
            onRemove={() => syncSteps(list.filter((_, j) => j !== i).map((s, idx) => ({ ...s, n: idx + 1 })))}
            onMoveUp={
              i > 0
                ? () => {
                    const n = [...list]
                    ;[n[i - 1], n[i]] = [n[i], n[i - 1]]
                    syncSteps(n.map((s, idx) => ({ ...s, n: idx + 1 })))
                  }
                : undefined
            }
            onMoveDown={
              i < list.length - 1
                ? () => {
                    const n = [...list]
                    ;[n[i], n[i + 1]] = [n[i + 1], n[i]]
                    syncSteps(n.map((s, idx) => ({ ...s, n: idx + 1 })))
                  }
                : undefined
            }
          >
            <div className="s2-band-field-grid s2-band-field-grid--cols-3">
              <label className="s2-band-field">
                <span className="s2-band-field__label">Icon</span>
                <input
                  className="s2-band-input"
                  value={step.icon}
                  onChange={(e) => {
                    const n = [...list]
                    n[i] = { ...n[i], icon: e.target.value }
                    syncSteps(n)
                  }}
                />
              </label>
              <label className="s2-band-field">
                <span className="s2-band-field__label">Step #</span>
                <input
                  className="s2-band-input"
                  value={String(step.n)}
                  onChange={(e) => {
                    const n = [...list]
                    n[i] = { ...n[i], n: Number(e.target.value) || i + 1 }
                    syncSteps(n)
                  }}
                />
              </label>
            </div>
            <label className="s2-band-field">
              <span className="s2-band-field__label">Title</span>
              <input
                className="s2-band-input"
                value={step.t}
                onChange={(e) => {
                  const n = [...list]
                  n[i] = { ...n[i], t: e.target.value }
                  syncSteps(n)
                }}
              />
            </label>
            <label className="s2-band-field">
              <span className="s2-band-field__label">Description</span>
              <textarea
                className="s2-band-input"
                rows={3}
                value={step.d}
                onChange={(e) => {
                  const n = [...list]
                  n[i] = { ...n[i], d: e.target.value }
                  syncSteps(n)
                }}
              />
            </label>
          </ListItemShell>
        ))}
      </div>
      {list.length < 8 && (
        <button
          type="button"
          className="s2-band-add-btn"
          onClick={() => syncSteps([...list, { n: list.length + 1, icon: '✓', t: '', d: '' }])}
        >
          + Add step
        </button>
      )}
    </section>
  )
}

function HiWStepsEditor({
  section,
  settings,
  onChange,
}: {
  section: SectionCatalogDef
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const useJson = section.contentFields?.some((f) => f.key === 'hiw_page_steps_json')
  if (useJson) {
    return <HiWPageStepsListEditor settings={settings} onChange={onChange} />
  }

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">How it works — steps</h4>
      <div className="s2-band-list">
        {[1, 2, 3, 4, 5, 6].map((n, idx) => (
          <ListItemShell key={n} index={idx} title={readAdminSetting(settings, `hiw_step${n}_title`) || `Step ${n}`}>
            <label className="s2-band-field">
              <span className="s2-band-field__label">Title</span>
              <input
                className="s2-band-input"
                value={readAdminSetting(settings, `hiw_step${n}_title`)}
                onChange={(e) => onChange(`hiw_step${n}_title`, e.target.value)}
              />
            </label>
            <label className="s2-band-field">
              <span className="s2-band-field__label">Description</span>
              <textarea
                className="s2-band-input"
                rows={2}
                value={readAdminSetting(settings, `hiw_step${n}_desc`)}
                onChange={(e) => onChange(`hiw_step${n}_desc`, e.target.value)}
              />
            </label>
          </ListItemShell>
        ))}
      </div>
    </section>
  )
}

function MarqueeBandEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const fields: { key: string; label: string; type?: 'textarea' }[] = [
    { key: 'marquee_show', label: 'Show marquee (1 = yes, 0 = no)' },
    { key: 'marquee_text', label: 'Marquee message', type: 'textarea' },
    { key: 'marquee_speed', label: 'Animation duration (seconds)' },
    { key: 'marquee_bg', label: 'Background colour' },
    { key: 'marquee_color', label: 'Text colour' },
    { key: 'marquee_pause_hover', label: 'Pause on hover (1/0)' },
  ]
  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Announcement marquee</h4>
      <div className="s2-band-content-fields">
        {fields.map((f) => (
          <label key={f.key} className="s2-band-field">
            <span className="s2-band-field__label">{f.label}</span>
            {f.type === 'textarea' ? (
              <textarea className="s2-band-input" rows={2} value={readAdminSetting(settings, f.key)} onChange={(e) => onChange(f.key, e.target.value)} />
            ) : (
              <input className="s2-band-input" value={readAdminSetting(settings, f.key)} onChange={(e) => onChange(f.key, e.target.value)} />
            )}
          </label>
        ))}
      </div>
    </section>
  )
}

function ServicePageBandPanel({ section }: { section: SectionCatalogDef }) {
  const copy: Record<string, string> = {
    hero: 'Hero copy, imagery, and CTAs are edited per service in the Page Builder.',
    wizard: 'Booking form fields and steps are configured in the service Form Builder.',
    features: 'Why-choose cards and proof points live on each service page.',
    faq: 'Service-specific FAQs are managed inside the service record.',
    pricing: 'Quote rules and pricing copy are part of the service definition.',
    cta: 'Bottom call-to-action bands are part of the service page layout.',
  }
  return (
    <section className="s2-band-design-group">
      <div className="s2-band-registry-head">
        <div>
          <h4 className="s2-band-design-group__title">{section.label}</h4>
          <p className="s2-band-design-group__lead">{copy[section.sectionKey] || 'This band is rendered from the active service page template.'}</p>
        </div>
        <Link to={section.adminLink?.path || '/admin/services'} className="s2-btn s2-btn--outline s2-btn--sm">
          {(section.adminLink?.label || 'Service Registry')} →
        </Link>
      </div>
      <ServicesRegistryPreview />
    </section>
  )
}

function FaqRegistryPreview({ compact }: { compact?: boolean }) {
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState<Array<{ id: number; question: string }>>([])
  useEffect(() => {
    api
      .get<{ faqs: Array<Record<string, unknown>> }>('admin/faqs')
      .then((d) => setRows((d.faqs || []).slice(0, compact ? 4 : 8).map((f) => ({ id: Number(f.id), question: String(f.question || '') }))))
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }, [compact])
  if (compact) {
    return rows.length ? (
      <p className="s2-band-field__hint s2-band-field__hint--block">Live database: {rows.length}+ entries (first: “{rows[0]?.question.slice(0, 48)}…”).</p>
    ) : null
  }
  return (
    <RegistryPreview title="FAQ database" lead="Published FAQs from the manager appear on the FAQ page automatically." adminPath="/admin/faqs" adminLabel="FAQ Manager" loading={loading} empty={rows.length === 0}>
      <ul className="s2-band-simple-list">
        {rows.map((f) => (
          <li key={f.id}>{f.question}</li>
        ))}
      </ul>
    </RegistryPreview>
  )
}

function TestimonialsRegistryPreview() {
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState<Array<{ id: number; name: string; text: string }>>([])
  useEffect(() => {
    api
      .get<{ testimonials: Array<Record<string, unknown>> }>('admin/testimonials')
      .then((d) =>
        setRows(
          (d.testimonials || []).slice(0, 6).map((t) => ({
            id: Number(t.id),
            name: String(t.name || ''),
            text: String(t.text || t.quote || '').slice(0, 120),
          })),
        ),
      )
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }, [])
  return (
    <RegistryPreview title="Testimonial entries" lead="Quotes shown in the slider come from the testimonial manager." adminPath="/admin/testimonials" adminLabel="Testimonials" loading={loading} empty={rows.length === 0}>
      <div className="s2-band-list">
        {rows.map((t, i) => (
          <div key={t.id} className="s2-band-list-item">
            <div className="s2-band-list-item__head">
              <span className="s2-band-list-item__index">{i + 1}</span>
              <span className="s2-band-list-item__title">{t.name}</span>
            </div>
            <div className="s2-band-list-item__body">
              <p className="s2-band-field__hint">{t.text}{t.text.length >= 120 ? '…' : ''}</p>
            </div>
          </div>
        ))}
      </div>
    </RegistryPreview>
  )
}

function PricingPlansPreview() {
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState<Array<{ id: number; name: string; price: string }>>([])
  useEffect(() => {
    api
      .get<{ plans: Array<Record<string, unknown>> }>('admin/pricing-plans')
      .then((d) => setRows((d.plans || []).map((p) => ({ id: Number(p.id), name: String(p.name || ''), price: String(p.price || '') }))))
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }, [])
  return (
    <RegistryPreview title="Pricing plans" lead="Plan cards and comparison rows are loaded from Pricing Plans." adminPath="/admin/pricing" adminLabel="Pricing Plans" loading={loading} empty={rows.length === 0}>
      <div className="s2-band-registry-grid">
        {rows.map((p) => (
          <div key={p.id} className="s2-band-registry-card">
            <div className="s2-band-registry-card__name">{p.name}</div>
            <div className="s2-band-field__hint">{p.price}</div>
          </div>
        ))}
      </div>
    </RegistryPreview>
  )
}

function BlogPostsPreview() {
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState<Array<{ id: number; title: string }>>([])
  useEffect(() => {
    api
      .get<{ posts: Array<Record<string, unknown>> }>('admin/blog')
      .then((d) => setRows((d.posts || []).slice(0, 8).map((p) => ({ id: Number(p.id), title: String(p.title || '') }))))
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }, [])
  return (
    <RegistryPreview title="Blog articles" lead="The feed lists published posts from Blog Posts admin." adminPath="/admin/blog" adminLabel="Blog Posts" loading={loading} empty={rows.length === 0}>
      <ul className="s2-band-simple-list">
        {rows.map((p) => (
          <li key={p.id}>{p.title}</li>
        ))}
      </ul>
    </RegistryPreview>
  )
}

function CategoriesRegistryPreview() {
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState<Array<{ slug: string; name: string; count: number }>>([])
  useEffect(() => {
    Promise.all([
      api.get<{ categories: Array<Record<string, unknown>> }>('admin/categories'),
      api.get<{ services: Array<Record<string, unknown>> }>('admin/services?per_page=200'),
    ])
      .then(([c, s]) => {
        const services = s.services || []
        setRows(
          (c.categories || []).map((cat) => {
            const slug = String(cat.slug || '')
            const count = services.filter((x) => String(x.category_slug || x.category) === slug).length
            return { slug, name: String(cat.name || ''), count }
          }),
        )
      })
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }, [])
  return (
    <RegistryPreview title="Category directory" lead="Each category tab and its services come from Categories + Service Registry." adminPath="/admin/categories" adminLabel="Categories" loading={loading} empty={rows.length === 0}>
      <div className="s2-band-registry-grid">
        {rows.map((c) => (
          <div key={c.slug} className="s2-band-registry-card">
            <div className="s2-band-registry-card__name">{c.name}</div>
            <div className="s2-band-field__hint">{c.count} services</div>
          </div>
        ))}
      </div>
    </RegistryPreview>
  )
}

function BandTextArea({
  label,
  hint,
  value,
  rows,
  onChange,
}: {
  label: string
  hint?: string
  value: string
  rows?: number
  onChange: (v: string) => void
}) {
  return (
    <label className="s2-band-field">
      <span className="s2-band-field__label">{label}</span>
      {hint && <span className="s2-band-field__hint">{hint}</span>}
      <textarea className="s2-band-input" rows={rows ?? 3} value={value} onChange={(e) => onChange(e.target.value)} />
    </label>
  )
}

function BandTextInput({
  label,
  hint,
  value,
  placeholder,
  onChange,
}: {
  label: string
  hint?: string
  value: string
  placeholder?: string
  onChange: (v: string) => void
}) {
  return (
    <label className="s2-band-field">
      <span className="s2-band-field__label">{label}</span>
      {hint && <span className="s2-band-field__hint">{hint}</span>}
      <input className="s2-band-input" value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
    </label>
  )
}

function AboutStoryPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const [highlights, setHighlights] = useState<HighlightPair[]>(() =>
    parseAboutHighlights(readAdminSetting(settings, 'about_highlights_json'), [
      { value: '10,000+', label: 'Clients Served' },
      { value: '44+', label: 'Services' },
      { value: '50+', label: 'Cities' },
    ]),
  )
  useEffect(() => {
    setHighlights(
      parseAboutHighlights(readAdminSetting(settings, 'about_highlights_json'), [
        { value: '10,000+', label: 'Clients Served' },
        { value: '44+', label: 'Services' },
        { value: '50+', label: 'Cities' },
      ]),
    )
  }, [settings.about_highlights_json])

  const syncHighlights = (next: HighlightPair[]) => {
    setHighlights(next)
    onChange(
      'about_highlights_json',
      JSON.stringify(
        next.map((h) => [h.value, h.label]),
        null,
        2,
      ),
    )
  }

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">About story block</h4>
      <p className="s2-band-design-group__lead">Split layout with image or video and highlight stat tiles.</p>
      <div className="s2-band-content-fields">
        <BandTextInput label="Eyebrow" value={readAdminSetting(settings, 'about_eyebrow')} placeholder="Our Story" onChange={(v) => onChange('about_eyebrow', v)} />
        <BandTextInput label="Heading" value={readAdminSetting(settings, 'about_heading')} onChange={(v) => onChange('about_heading', v)} />
        <BandTextArea label="Primary body" value={readAdminSetting(settings, 'about_text')} rows={4} onChange={(v) => onChange('about_text', v)} />
        <BandTextArea label="Secondary body" value={readAdminSetting(settings, 'about_text_secondary')} rows={3} onChange={(v) => onChange('about_text_secondary', v)} />
        <BandTextInput label="Image URL" value={readAdminSetting(settings, 'about_image_url')} onChange={(v) => onChange('about_image_url', v)} />
        <BandTextInput label="Video URL (optional)" value={readAdminSetting(settings, 'about_video_url')} onChange={(v) => onChange('about_video_url', v)} />
      </div>
      <h5 className="s2-band-design-group__subtitle">Highlight stats</h5>
      <div className="s2-band-stats-grid">
        {highlights.map((h, i) => (
          <div key={i} className="s2-band-stats-grid__cell">
            <input
              className="s2-band-input"
              placeholder="10,000+"
              value={h.value}
              onChange={(e) => {
                const n = [...highlights]
                n[i] = { ...n[i], value: e.target.value }
                syncHighlights(n)
              }}
            />
            <input
              className="s2-band-input"
              placeholder="Label"
              value={h.label}
              onChange={(e) => {
                const n = [...highlights]
                n[i] = { ...n[i], label: e.target.value }
                syncHighlights(n)
              }}
            />
          </div>
        ))}
      </div>
    </section>
  )
}

function ValueCardsEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const [cards, setCards] = useState<ValueCard[]>(() => parseValueCards(readAdminSetting(settings, 'about_values_json'), []))
  useEffect(() => {
    setCards(parseValueCards(readAdminSetting(settings, 'about_values_json'), []))
  }, [settings.about_values_json])

  const sync = (next: ValueCard[]) => {
    setCards(next)
    onChange('about_values_json', JSON.stringify(next, null, 2))
  }
  const list = cards.length ? cards : [{ icon: '✓', t: '', d: '' }]

  return (
    <section className="s2-band-design-group">
      <BandTextInput
        label="Section heading"
        value={readAdminSetting(settings, 'about_values_title')}
        placeholder="Our Core Values"
        onChange={(v) => onChange('about_values_title', v)}
      />
      <div className="s2-band-list">
        {list.map((card, i) => (
          <ListItemShell
            key={i}
            index={i}
            title={card.t || `Value ${i + 1}`}
            canRemove={list.length > 1}
            onRemove={() => sync(list.filter((_, j) => j !== i))}
          >
            <BandTextInput label="Icon" value={card.icon} onChange={(v) => {
              const n = [...list]
              n[i] = { ...n[i], icon: v }
              sync(n)
            }} />
            <BandTextInput label="Title" value={card.t} onChange={(v) => {
              const n = [...list]
              n[i] = { ...n[i], t: v }
              sync(n)
            }} />
            <BandTextArea label="Description" value={card.d} rows={2} onChange={(v) => {
              const n = [...list]
              n[i] = { ...n[i], d: v }
              sync(n)
            }} />
          </ListItemShell>
        ))}
      </div>
      {list.length < 8 && (
        <button type="button" className="s2-band-add-btn" onClick={() => sync([...list, { icon: '✓', t: '', d: '' }])}>
          + Add value card
        </button>
      )}
    </section>
  )
}

function TeamMembersEditor({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const [members, setMembers] = useState<TeamMember[]>(() => parseTeamMembers(readAdminSetting(settings, 'about_team_json'), []))
  useEffect(() => {
    setMembers(parseTeamMembers(readAdminSetting(settings, 'about_team_json'), []))
  }, [settings.about_team_json])

  const sync = (next: TeamMember[]) => {
    setMembers(next)
    onChange('about_team_json', JSON.stringify(next, null, 2))
  }
  const list = members.length ? members : [{ name: '', role: '', bio: '' }]

  return (
    <section className="s2-band-design-group">
      <BandTextInput
        label="Section heading"
        value={readAdminSetting(settings, 'about_team_title')}
        placeholder="Meet Our Team"
        onChange={(v) => onChange('about_team_title', v)}
      />
      <div className="s2-band-list">
        {list.map((m, i) => (
          <ListItemShell key={i} index={i} title={m.name || `Team member ${i + 1}`} canRemove={list.length > 1} onRemove={() => sync(list.filter((_, j) => j !== i))}>
            <BandTextInput label="Name" value={m.name} onChange={(v) => {
              const n = [...list]
              n[i] = { ...n[i], name: v }
              sync(n)
            }} />
            <BandTextInput label="Role" value={m.role || ''} onChange={(v) => {
              const n = [...list]
              n[i] = { ...n[i], role: v }
              sync(n)
            }} />
            <BandTextInput label="Photo URL" value={m.img || ''} onChange={(v) => {
              const n = [...list]
              n[i] = { ...n[i], img: v }
              sync(n)
            }} />
            <BandTextArea label="Bio" value={m.bio || ''} rows={2} onChange={(v) => {
              const n = [...list]
              n[i] = { ...n[i], bio: v }
              sync(n)
            }} />
          </ListItemShell>
        ))}
      </div>
      {list.length < 12 && (
        <button type="button" className="s2-band-add-btn" onClick={() => sync([...list, { name: '', role: '', bio: '' }])}>
          + Add team member
        </button>
      )}
    </section>
  )
}

function MarketingCtaPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const title = readAdminSetting(settings, 'about_cta_title') || 'Ready to Get Started?'
  const sub = readAdminSetting(settings, 'about_cta_subtitle')
  return (
    <>
      <section className="s2-band-design-group">
        <h4 className="s2-band-design-group__title">Bottom conversion band</h4>
        <p className="s2-band-design-group__lead">Dark full-width CTA with primary and secondary buttons (routes are fixed in theme).</p>
        <div className="s2-band-content-fields">
          <BandTextInput label="Heading" value={readAdminSetting(settings, 'about_cta_title')} onChange={(v) => onChange('about_cta_title', v)} />
          <BandTextArea label="Subtitle" value={readAdminSetting(settings, 'about_cta_subtitle')} rows={2} onChange={(v) => onChange('about_cta_subtitle', v)} />
        </div>
      </section>
      <div className="s2-band-preview-strip s2-band-preview-strip--dark" aria-hidden>
        <strong>{title}</strong>
        {sub && <span>{sub}</span>}
      </div>
    </>
  )
}

function ContactChannelsPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const email = readAdminSetting(settings, 'contact_email') || readAdminSetting(settings, 'platform_email')
  const phone = readAdminSetting(settings, 'contact_phone') || readAdminSetting(settings, 'platform_phone')
  const address =
    readAdminSetting(settings, 'contact_address') ||
    readAdminSetting(settings, 'platform_address') ||
    readAdminSetting(settings, 'platform_city')

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Contact channels &amp; form</h4>
      <p className="s2-band-design-group__lead">
        Leave email, phone, or address empty to inherit Site Foundation defaults. The form posts to the live contact API.
      </p>
      <div className="s2-band-content-fields">
        <BandTextInput label="Left column heading" value={readAdminSetting(settings, 'contact_title')} placeholder="Get in Touch" onChange={(v) => onChange('contact_title', v)} />
        <BandTextInput label="Form title" value={readAdminSetting(settings, 'contact_form_title')} placeholder="Send Us a Message" onChange={(v) => onChange('contact_form_title', v)} />
        <BandTextInput label="Display email" hint={!readAdminSetting(settings, 'contact_email') && email ? `Live: ${email} (from platform)` : undefined} value={readAdminSetting(settings, 'contact_email')} onChange={(v) => onChange('contact_email', v)} />
        <BandTextInput label="Display phone" value={readAdminSetting(settings, 'contact_phone')} onChange={(v) => onChange('contact_phone', v)} />
        <BandTextArea label="Display address" value={readAdminSetting(settings, 'contact_address')} rows={2} onChange={(v) => onChange('contact_address', v)} />
      </div>
      <p className="s2-band-field__hint s2-band-field__hint--block">
        Preview — WhatsApp uses <code>platform_whatsapp</code> from Site Foundation.
        {address ? ` Address shown: ${address.slice(0, 80)}${address.length > 80 ? '…' : ''}` : ''}
      </p>
    </section>
  )
}

function ServicesDirectoryPanel({
  section,
  settings,
  onChange,
}: {
  section: SectionCatalogDef
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const isDirectory = section.sectionKey === 'directory'
  return (
    <>
      <section className="s2-band-design-group">
        <h4 className="s2-band-design-group__title">{isDirectory ? 'Directory behaviour' : 'Services page hero'}</h4>
        <p className="s2-band-design-group__lead">
          Cards and categories are loaded from Service Registry. Titles below control the hero and intro copy on /services.
        </p>
        {isDirectory && (
          <div className="s2-band-content-fields">
            <BandTextInput label="Intro line under hero" value={readAdminSetting(settings, 'services_subtitle')} onChange={(v) => onChange('services_subtitle', v)} />
            <BandTextInput label="Show search (1/0)" value={readAdminSetting(settings, 'services_show_search')} placeholder="1" onChange={(v) => onChange('services_show_search', v)} />
            <BandTextInput label="Show category sidebar (1/0)" value={readAdminSetting(settings, 'services_show_category_filter')} placeholder="1" onChange={(v) => onChange('services_show_category_filter', v)} />
            <BandTextInput label="Cards per page" value={readAdminSetting(settings, 'services_per_page')} placeholder="24" onChange={(v) => onChange('services_per_page', v)} />
          </div>
        )}
      </section>
      <CategoriesRegistryPreview />
      <ServicesRegistryPreview />
    </>
  )
}

function FaqPageCtaPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Support callout</h4>
      <p className="s2-band-design-group__lead">Card shown below the FAQ accordion on /faq.</p>
      <div className="s2-band-content-fields">
        <BandTextInput label="Heading" value={readAdminSetting(settings, 'faq_cta_title')} placeholder="Still have questions?" onChange={(v) => onChange('faq_cta_title', v)} />
        <BandTextArea label="Body" value={readAdminSetting(settings, 'faq_cta_body')} rows={2} onChange={(v) => onChange('faq_cta_body', v)} />
        <BandTextInput label="Button label" value={readAdminSetting(settings, 'faq_cta_button')} placeholder="Contact Us →" onChange={(v) => onChange('faq_cta_button', v)} />
      </div>
    </section>
  )
}

function PricingSectionPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  return (
    <>
      <section className="s2-band-design-group">
        <h4 className="s2-band-design-group__title">Plans section header</h4>
        <div className="s2-band-content-fields">
          <BandTextInput label="Eyebrow" value={readAdminSetting(settings, 'pricing_grid_eyebrow')} placeholder="Pricing plans" onChange={(v) => onChange('pricing_grid_eyebrow', v)} />
          <BandTextInput label="Heading" value={readAdminSetting(settings, 'pricing_grid_title')} onChange={(v) => onChange('pricing_grid_title', v)} />
          <BandTextArea label="Subtitle" value={readAdminSetting(settings, 'pricing_grid_subtitle')} rows={2} onChange={(v) => onChange('pricing_grid_subtitle', v)} />
        </div>
      </section>
      <PricingPlansPreview />
    </>
  )
}

function PricingComparePanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  return (
    <>
      <section className="s2-band-design-group">
        <h4 className="s2-band-design-group__title">Comparison table</h4>
        <p className="s2-band-design-group__lead">
          Row labels use the built-in feature matrix; plan columns follow active Pricing plans. Edit plan features in Pricing admin.
        </p>
        <div className="s2-band-content-fields">
          <BandTextInput label="Section heading" value={readAdminSetting(settings, 'pricing_compare_title')} placeholder="Compare plans" onChange={(v) => onChange('pricing_compare_title', v)} />
          <BandTextArea label="Intro" value={readAdminSetting(settings, 'pricing_compare_subtitle')} rows={2} onChange={(v) => onChange('pricing_compare_subtitle', v)} />
        </div>
      </section>
      <PricingPlansPreview />
    </>
  )
}

function HiWPageCalloutPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Consultation callout</h4>
      <p className="s2-band-design-group__lead">Shown after the step timeline on How It Works.</p>
      <div className="s2-band-content-fields">
        <BandTextInput label="Heading" value={readAdminSetting(settings, 'hiw_page_cta_title')} onChange={(v) => onChange('hiw_page_cta_title', v)} />
        <BandTextArea label="Subtitle" value={readAdminSetting(settings, 'hiw_page_cta_subtitle')} rows={2} onChange={(v) => onChange('hiw_page_cta_subtitle', v)} />
      </div>
    </section>
  )
}

function ChromeFooterPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Site footer columns</h4>
      <p className="s2-band-design-group__lead">
        Column headings and link lists below. Services column still pulls featured services from the registry when configured.
      </p>
      <div className="s2-band-content-fields">
        <BandTextArea
          label="Quick links JSON"
          value={readAdminSetting(settings, 'footer_quick_links_json')}
          rows={8}
          onChange={(v) => onChange('footer_quick_links_json', v)}
          hint='[["/about","About Us"],["/blog","Blog"]]'
        />
        <BandTextArea
          label="Location links JSON"
          value={readAdminSetting(settings, 'footer_locations_json')}
          rows={8}
          onChange={(v) => onChange('footer_locations_json', v)}
          hint='[["Mumbai","/cities/property-management-in-mumbai"]]'
        />
      </div>
    </section>
  )
}

function ChromeHeaderPanel({
  settings,
  onChange,
}: {
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const navPreview = (() => {
    try {
      const raw = readAdminSetting(settings, 'nav_menu_json')
      if (!raw.trim()) return 'Using live navigation from Service Registry (navigation/public) when saved empty.'
      const parsed = JSON.parse(raw) as unknown
      if (!Array.isArray(parsed)) return 'Invalid JSON — must be an array of menu items.'
      return `${parsed.length} top-level item(s) in CMS override. Saves apply site-wide on the public header.`
    } catch {
      return 'Invalid JSON — fix syntax to preview.'
    }
  })()

  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Header actions</h4>
      <p className="s2-band-design-group__lead">Primary header buttons and top-bar auth labels (site-wide).</p>
      <div className="s2-band-content-fields">
        <BandTextInput label="WhatsApp button" value={readAdminSetting(settings, 'header_whatsapp_label')} onChange={(v) => onChange('header_whatsapp_label', v)} />
        <BandTextInput label="Service request label" value={readAdminSetting(settings, 'header_service_request_text')} onChange={(v) => onChange('header_service_request_text', v)} />
        <BandTextInput label="Service request URL" value={readAdminSetting(settings, 'header_service_request_url')} onChange={(v) => onChange('header_service_request_url', v)} />
        <BandTextInput label="Sign in label" value={readAdminSetting(settings, 'header_sign_in_text')} onChange={(v) => onChange('header_sign_in_text', v)} />
        <BandTextInput label="Dashboard label" value={readAdminSetting(settings, 'header_dashboard_text')} onChange={(v) => onChange('header_dashboard_text', v)} />
        <BandTextInput label="Top bar sign in" value={readAdminSetting(settings, 'topbar_sign_in_text')} onChange={(v) => onChange('topbar_sign_in_text', v)} />
        <BandTextInput label="Top bar sign up" value={readAdminSetting(settings, 'topbar_sign_up_text')} onChange={(v) => onChange('topbar_sign_up_text', v)} />
        <BandTextInput label="About nav label" value={readAdminSetting(settings, 'header_nav_about_label')} onChange={(v) => onChange('header_nav_about_label', v)} />
        <BandTextInput label="Contact nav label" value={readAdminSetting(settings, 'header_nav_contact_label')} onChange={(v) => onChange('header_nav_contact_label', v)} />
        <BandTextInput label="Mega menu hint" value={readAdminSetting(settings, 'header_nav_mega_hint')} onChange={(v) => onChange('header_nav_mega_hint', v)} />
        <BandTextInput label="View all services" value={readAdminSetting(settings, 'header_nav_view_all_text')} onChange={(v) => onChange('header_nav_view_all_text', v)} />
        <BandTextArea
          label="Navigation menu JSON"
          value={readAdminSetting(settings, 'nav_menu_json')}
          rows={12}
          onChange={(v) => onChange('nav_menu_json', v)}
          hint='[{"label":"Link","key":"x","link":"/about"}] — overrides API when non-empty'
        />
        <BandTextArea
          label="Mobile drawer links JSON"
          value={readAdminSetting(settings, 'header_mobile_nav_json')}
          rows={5}
          onChange={(v) => onChange('header_mobile_nav_json', v)}
          hint='[["/","🏠 Home"],["/pricing","💰 Pricing"]]'
        />
        <p className="s2-band-design-group__lead" style={{ marginTop: 0 }}>{navPreview}</p>
      </div>
    </section>
  )
}

function LegalDocumentPanel() {
  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">Legal document</h4>
      <p className="s2-band-design-group__lead">
        Body copy for Terms and Privacy ships with the theme. Use CSS override below for typography tweaks, or edit the React source when you need full legal rewrites.
      </p>
    </section>
  )
}

function RegistryHubPanel({ section }: { section: SectionCatalogDef }) {
  const path = section.adminLink?.path || '/admin/services'
  const label = section.adminLink?.label || 'Open admin screen'
  if (path.includes('faqs')) return <FaqRegistryPreview />
  if (path.includes('testimonials')) return <TestimonialsRegistryPreview />
  if (path.includes('pricing')) return <PricingPlansPreview />
  if (path.includes('blog')) return <BlogPostsPreview />
  if (path.includes('cities')) return <CitiesRegistryPreview />
  if (path.includes('categories')) return <CategoriesRegistryPreview />
  if (path.includes('services')) return <ServicesRegistryPreview />
  return (
    <RegistryPreview title={section.label} lead="Manage entries on the linked admin screen." adminPath={path} adminLabel={label} loading={false} empty={false}>
      <p className="s2-band-field__hint">Use the button above to add or edit live content for this band.</p>
    </RegistryPreview>
  )
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
  const panel = section.contentPanel ?? resolveContentPanel(section)
  switch (panel) {
    case 'hero_slides':
      return <HeroSlidesEditor settings={settings} onChange={onChange} />
    case 'why_choose':
      return <WhyChooseEditor settings={settings} onChange={onChange} />
    case 'stats_cards':
      return <StatsCardsEditor settings={settings} onChange={onChange} />
    case 'services_registry':
      return <ServicesRegistryPreview />
    case 'cities_registry':
    case 'locations_cities':
      return <CitiesRegistryPreview />
    case 'testimonials_registry':
      return <TestimonialsRegistryPreview />
    case 'faq_items':
      return <FaqItemsEditor settings={settings} onChange={onChange} />
    case 'faq_database':
      return <FaqRegistryPreview />
    case 'press_logos':
      return <LogoChipsEditor jsonKey="home_press_json" title="Press & media logos" settings={settings} onChange={onChange} />
    case 'partners_list':
      return <LogoChipsEditor jsonKey="home_partners_json" title="Partner names" settings={settings} onChange={onChange} stringListFallback />
    case 'awards_list':
      return <AwardsEditor settings={settings} onChange={onChange} />
    case 'hiw_steps':
      return <HiWStepsEditor section={section} settings={settings} onChange={onChange} />
    case 'marquee_band':
      return <MarqueeBandEditor settings={settings} onChange={onChange} />
    case 'service_page_band':
      return <ServicePageBandPanel section={section} />
    case 'about_story':
      return <AboutStoryPanel settings={settings} onChange={onChange} />
    case 'value_cards':
      return <ValueCardsEditor settings={settings} onChange={onChange} />
    case 'team_members':
      return <TeamMembersEditor settings={settings} onChange={onChange} />
    case 'marketing_cta':
      return <MarketingCtaPanel settings={settings} onChange={onChange} />
    case 'contact_channels':
      return <ContactChannelsPanel settings={settings} onChange={onChange} />
    case 'services_directory':
      return <ServicesDirectoryPanel section={section} settings={settings} onChange={onChange} />
    case 'faq_page_cta':
      return <FaqPageCtaPanel settings={settings} onChange={onChange} />
    case 'pricing_plans':
      return <PricingSectionPanel settings={settings} onChange={onChange} />
    case 'pricing_compare':
      return <PricingComparePanel settings={settings} onChange={onChange} />
    case 'hiw_page_callout':
      return <HiWPageCalloutPanel settings={settings} onChange={onChange} />
    case 'blog_posts':
      return <BlogPostsPreview />
    case 'category_catalog':
      return <CategoriesRegistryPreview />
    case 'chrome_footer':
      return <ChromeFooterPanel settings={settings} onChange={onChange} />
    case 'chrome_header':
      return <ChromeHeaderPanel settings={settings} onChange={onChange} />
    case 'legal_document':
      return <LegalDocumentPanel />
    case 'registry_hub':
      return <RegistryHubPanel section={section} />
    default:
      return null
  }
}
