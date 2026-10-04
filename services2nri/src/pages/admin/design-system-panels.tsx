/**
 * Sub-panels for the centralized Design & Service Registry admin UI.
 */
import React from 'react'
import { api } from '@/lib/api'
import { HexColorField, PxTokenField, parsePx } from './design-admin-fields'
import {
  PAGE_TEMPLATES,
  readNestedString,
  templateChromePath,
  templateTypographyPath,
  type PageTemplateDef,
} from '@/lib/design-page-templates'
import { WIDTH_PAGE_TYPES } from '@/lib/width-layout'
import type { WidthLayoutFocus } from './width-layout-panel'

type PatchFn = (path: string[], value: unknown) => void

const TYPO_ROLES = [
  'page_title', 'h1', 'h2', 'h3', 'section_heading', 'section_subheading', 'eyebrow',
  'body', 'body_lg', 'body_sm', 'caption', 'nav', 'button', 'card_title', 'card_desc',
  'label', 'breadcrumb', 'badge', 'cta_heading', 'footer_heading',
] as const

export function TypographyRolesEditor({
  typography,
  patch,
}: {
  typography: Record<string, Record<string, string>>
  patch: PatchFn
}) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      {TYPO_ROLES.map((role) => {
        const t = typography[role] || {}
        return (
          <fieldset key={role} style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
            <legend style={{ fontWeight: 700, padding: '0 8px' }}>{role.replace(/_/g, ' ')}</legend>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 12 }}>
              {(['size_desktop', 'size_tablet', 'size_mobile'] as const).map((key) => (
                <PxTokenField
                  key={key}
                  label={key.replace(/_/g, ' ')}
                  value={t[key] || ''}
                  onChange={(v) => patch(['typography', role, key], parsePx(v))}
                />
              ))}
              {(['font_weight', 'line_height', 'letter_spacing'] as const).map((key) => (
                <label key={key} style={{ fontSize: 12 }}>
                  {key.replace(/_/g, ' ')}
                  <input
                    type="text"
                    value={t[key] || ''}
                    onChange={(e) => patch(['typography', role, key], e.target.value)}
                    style={{ display: 'block', width: '100%', marginTop: 4, padding: 6, borderRadius: 6, border: '1px solid #E2E8F0' }}
                  />
                </label>
              ))}
              <HexColorField
                label="color"
                value={String(t.color || '').startsWith('#') ? String(t.color) : ''}
                onChange={(v) => patch(['typography', role, 'color'], v || '{colors.body}')}
              />
            </div>
          </fieldset>
        )
      })}
    </div>
  )
}

export function TokenGroupEditor({
  title,
  basePath,
  tokens,
  patch,
  keys,
  unit = 'px',
}: {
  title: string
  basePath: string[]
  tokens: Record<string, string>
  patch: PatchFn
  keys: string[]
  unit?: 'px' | 'text'
}) {
  return (
    <div>
      <h3 style={{ marginTop: 0 }}>{title}</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 12 }}>
        {keys.map((key) =>
          unit === 'px' ? (
            <PxTokenField
              key={key}
              label={key.replace(/_/g, ' ')}
              value={tokens[key] || ''}
              onChange={(v) => patch([...basePath, key], parsePx(v))}
            />
          ) : (
            <label key={key} style={{ fontSize: 13 }}>
              {key.replace(/_/g, ' ')}
              <input
                type="text"
                value={tokens[key] || ''}
                onChange={(e) => patch([...basePath, key], e.target.value)}
                style={{ display: 'block', width: '100%', marginTop: 4, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
              />
            </label>
          )
        )}
      </div>
    </div>
  )
}

export function ComponentsEditor({
  components,
  patch,
}: {
  components: Record<string, string>
  patch: PatchFn
}) {
  const keys = Object.keys(components).length ? Object.keys(components) : [
    'button_primary_bg', 'button_primary_color', 'button_radius',
    'card_radius', 'input_radius', 'input_border', 'card_shadow',
  ]
  return (
    <TokenGroupEditor
      title="Component tokens"
      basePath={['components']}
      tokens={components}
      patch={patch}
      keys={keys}
    />
  )
}

export function OverridesEditor({
  overrides,
  patch,
}: {
  overrides: Record<string, Record<string, unknown>>
  patch: PatchFn
}) {
  const pageTypes = (overrides.page_types || {}) as Record<string, unknown>
  const pages = (overrides.pages || {}) as Record<string, unknown>
  const sections = (overrides.sections || {}) as Record<string, unknown>

  const addKey = (bucket: 'page_types' | 'pages' | 'sections', key: string) => {
    if (!key.trim()) return
    patch(['overrides', bucket, key.trim()], { colors: { primary: '{colors.primary}' } })
  }

  return (
    <div style={{ display: 'grid', gap: 24 }}>
      <p style={{ color: '#64748B', margin: 0 }}>
        Override inheritance: Global → page type → page slug → section id. JSON patches merge shallowly per level.
      </p>
      {(
        [
          ['page_types', 'Page types (home, service, blog…)', pageTypes],
          ['pages', 'Page slugs', pages],
          ['sections', 'Section ids', sections],
        ] as const
      ).map(([bucket, label, map]) => (
        <div key={bucket} style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
          <h3 style={{ marginTop: 0 }}>{label}</h3>
          {Object.keys(map).length === 0 && <p style={{ color: '#94A3B8', fontSize: 13 }}>No overrides yet.</p>}
          {Object.entries(map).map(([k, val]) => (
            <details key={k} style={{ marginBottom: 8 }}>
              <summary style={{ cursor: 'pointer', fontWeight: 600 }}>{k}</summary>
              <textarea
                defaultValue={JSON.stringify(val, null, 2)}
                rows={6}
                style={{ width: '100%', marginTop: 8, fontFamily: 'monospace', fontSize: 12 }}
                onBlur={(e) => {
                  try {
                    patch(['overrides', bucket, k], JSON.parse(e.target.value))
                  } catch {
                    /* keep previous */
                  }
                }}
              />
            </details>
          ))}
          <form
            onSubmit={(e) => {
              e.preventDefault()
              const fd = new FormData(e.currentTarget)
              addKey(bucket, String(fd.get('key') || ''))
              e.currentTarget.reset()
            }}
            style={{ display: 'flex', gap: 8, marginTop: 12 }}
          >
            <input name="key" placeholder="New key…" style={{ flex: 1, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }} />
            <button type="submit" className="s2-btn s2-btn--outline">Add override</button>
          </form>
        </div>
      ))}
    </div>
  )
}

export type RegistryService = {
  id: number
  name: string
  slug: string
  public_status?: string
  direct_url_behavior?: string
  availability?: string
  visibility_rules?: Record<string, boolean | string>
  is_featured?: number
  is_popular?: number
}

export type RegistryCategory = {
  id: number
  name: string
  slug: string
  public_status?: string
  visibility_rules?: Record<string, boolean | string>
  hide_when_empty_children?: number
}

export function SurfaceVisibilityMatrix({
  surfaces,
  rules,
  onChange,
}: {
  surfaces: Record<string, string>
  rules: Record<string, boolean | string>
  onChange: (next: Record<string, boolean | string>) => void
}) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 10 }}>
      {Object.entries(surfaces).map(([key, label]) => {
        const val = rules[key]
        const isDirect = key === 'direct_url'
        return (
          <label key={key} style={{ fontSize: 12, display: 'flex', flexDirection: 'column', gap: 4 }}>
            {label}
            {isDirect ? (
              <select
                value={typeof val === 'string' ? val : 'active'}
                onChange={(e) => onChange({ ...rules, [key]: e.target.value })}
                style={{ padding: 8, borderRadius: 8 }}
              >
                {['active', 'not_found', 'redirect_directory', 'redirect_home', 'unavailable_page'].map((b) => (
                  <option key={b} value={b}>{b.replace(/_/g, ' ')}</option>
                ))}
              </select>
            ) : (
              <select
                value={val === false ? 'off' : 'on'}
                onChange={(e) => onChange({ ...rules, [key]: e.target.value === 'on' })}
                style={{ padding: 8, borderRadius: 8 }}
              >
                <option value="on">Visible</option>
                <option value="off">Hidden on surface</option>
              </select>
            )}
          </label>
        )
      })}
    </div>
  )
}

export function CategoryRegistryPanel({
  categories,
  surfaces,
  onSave,
}: {
  categories: RegistryCategory[]
  surfaces: Record<string, string>
  onSave: (id: number, body: Record<string, unknown>) => Promise<void>
}) {
  const [selected, setSelected] = React.useState<number | null>(null)
  const cat = categories.find((c) => c.id === selected)

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.2fr', gap: 20 }}>
      <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, maxHeight: 420, overflow: 'auto' }}>
        {categories.map((c) => (
          <button
            key={c.id}
            type="button"
            onClick={() => setSelected(c.id)}
            style={{
              display: 'block',
              width: '100%',
              textAlign: 'left',
              padding: '12px 16px',
              border: 'none',
              borderBottom: '1px solid #F1F5F9',
              background: selected === c.id ? '#EBF0F8' : '#fff',
              cursor: 'pointer',
            }}
          >
            <strong>{c.name}</strong>
            <span style={{ float: 'right', fontSize: 12, color: '#64748B' }}>{c.public_status || 'published'}</span>
          </button>
        ))}
      </div>
      <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
        {cat ? (
          <CategoryEditor cat={cat} surfaces={surfaces} onSave={onSave} />
        ) : (
          <p style={{ color: '#94A3B8' }}>Select a category to edit visibility.</p>
        )}
      </div>
    </div>
  )
}

export function NavMenuEditor({
  onSaved,
}: {
  onSaved: (msg: string) => void
}) {
  const [structure, setStructure] = React.useState('')
  const [preview, setPreview] = React.useState<Array<{ label: string; cols?: unknown[] }>>([])
  const [loading, setLoading] = React.useState(true)
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    api.get<{ structure: unknown[]; menu: unknown[] }>('admin/navigation')
      .then((data) => {
        setStructure(JSON.stringify(data.structure || [], null, 2))
        setPreview((data.menu || []) as Array<{ label: string; cols?: unknown[] }>)
        setLoading(false)
      })
      .catch(() => setLoading(false))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const parsed = JSON.parse(structure)
      if (!Array.isArray(parsed)) throw new Error('Structure must be a JSON array')
      const res = await api.put<{ menu: unknown[] }>('admin/navigation', { structure: parsed })
      setPreview((res.menu || []) as Array<{ label: string; cols?: unknown[] }>)
      onSaved('Navigation structure saved. Mega-menu updates on next public load.')
    } catch (e: unknown) {
      onSaved(e instanceof Error ? e.message : 'Invalid JSON — fix structure before saving.')
    } finally {
      setSaving(false)
    }
  }

  const resetDefault = async () => {
    const data = await api.get<{ default: unknown[] }>('admin/navigation')
    setStructure(JSON.stringify(data.default || [], null, 2))
  }

  if (loading) return <p>Loading navigation…</p>

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1.2fr 1fr', gap: 20 }}>
      <div>
        <p style={{ color: '#64748B', fontSize: 13 }}>
          Edit mega-menu columns and service slug links. Visibility still flows through ServiceRegistry (hidden services are omitted automatically).
        </p>
        <textarea
          value={structure}
          onChange={(e) => setStructure(e.target.value)}
          rows={22}
          style={{ width: '100%', fontFamily: 'monospace', fontSize: 12, padding: 12, borderRadius: 8, border: '1px solid #E2E8F0' }}
        />
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          <button type="button" className="s2-btn s2-btn--primary" disabled={saving} onClick={save}>
            {saving ? 'Saving…' : 'Save navigation'}
          </button>
          <button type="button" className="s2-btn s2-btn--outline" onClick={resetDefault}>
            Load default JSON
          </button>
        </div>
      </div>
      <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16, maxHeight: 520, overflow: 'auto' }}>
        <h3 style={{ marginTop: 0 }}>Live preview (after registry filter)</h3>
        {preview.length === 0 && <p style={{ color: '#94A3B8' }}>No visible menu groups.</p>}
        {preview.map((item) => (
          <div key={item.label} style={{ marginBottom: 16 }}>
            <strong>{item.label}</strong>
            {(item.cols as Array<{ heading: string; items: Array<{ label: string }> }> | undefined)?.map((col) => (
              <div key={col.heading} style={{ marginLeft: 12, marginTop: 8 }}>
                <div style={{ fontSize: 12, color: '#64748B' }}>{col.heading}</div>
                <ul style={{ margin: '4px 0 0 16px', fontSize: 13 }}>
                  {(col.items || []).slice(0, 6).map((link) => (
                    <li key={link.label}>{link.label}</li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}

function CategoryEditor({
  cat,
  surfaces,
  onSave,
}: {
  cat: RegistryCategory
  surfaces: Record<string, string>
  onSave: (id: number, body: Record<string, unknown>) => Promise<void>
}) {
  const [status, setStatus] = React.useState(cat.public_status || 'published')
  const [rules, setRules] = React.useState<Record<string, boolean | string>>(cat.visibility_rules || {})
  const [hideEmpty, setHideEmpty] = React.useState(Boolean(cat.hide_when_empty_children ?? 1))

  React.useEffect(() => {
    setStatus(cat.public_status || 'published')
    setRules(cat.visibility_rules || {})
    setHideEmpty(Boolean(cat.hide_when_empty_children ?? 1))
  }, [cat.id, cat.public_status, cat.visibility_rules, cat.hide_when_empty_children])

  return (
    <div>
      <h3 style={{ marginTop: 0 }}>{cat.name}</h3>
      <label style={{ fontSize: 13, fontWeight: 600 }}>Publication status</label>
      <select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '100%', padding: 10, borderRadius: 8, marginBottom: 12 }}>
        {['published', 'hidden', 'draft', 'disabled', 'coming_soon'].map((st) => (
          <option key={st} value={st}>{st}</option>
        ))}
      </select>
      <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginBottom: 16 }}>
        <input type="checkbox" checked={hideEmpty} onChange={(e) => setHideEmpty(e.target.checked)} />
        Hide category when no visible child services
      </label>
      <SurfaceVisibilityMatrix surfaces={surfaces} rules={rules} onChange={setRules} />
      <button
        type="button"
        className="s2-btn s2-btn--primary"
        style={{ marginTop: 16 }}
        onClick={() => onSave(cat.id, { public_status: status, visibility_rules: rules, hide_when_empty_children: hideEmpty ? 1 : 0 })}
      >
        Save category visibility
      </button>
    </div>
  )
}

export type RegistryCity = {
  id: number
  name: string
  slug: string
  public_status?: string
  visibility_rules?: Record<string, boolean | string>
}

export function CityRegistryPanel({
  cities,
  surfaces,
  onSave,
}: {
  cities: RegistryCity[]
  surfaces: Record<string, string>
  onSave: (id: number, body: Record<string, unknown>) => Promise<void>
}) {
  const [selected, setSelected] = React.useState<number | null>(null)
  const city = cities.find((c) => c.id === selected)
  const [status, setStatus] = React.useState('published')
  const [rules, setRules] = React.useState<Record<string, boolean | string>>({})

  React.useEffect(() => {
    if (!city) return
    setStatus(city.public_status || 'published')
    setRules(city.visibility_rules || {})
  }, [city?.id, city?.public_status, city?.visibility_rules])

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.2fr', gap: 20 }}>
      <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, maxHeight: 420, overflow: 'auto' }}>
        {cities.map((c) => (
          <button key={c.id} type="button" onClick={() => setSelected(c.id)} style={{ display: 'block', width: '100%', textAlign: 'left', padding: '12px 16px', border: 'none', borderBottom: '1px solid #F1F5F9', background: selected === c.id ? '#EBF0F8' : '#fff', cursor: 'pointer' }}>
            <strong>{c.name}</strong>
            <span style={{ float: 'right', fontSize: 12, color: '#64748B' }}>{c.public_status || 'published'}</span>
          </button>
        ))}
      </div>
      <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
        {city ? (
          <>
            <h3 style={{ marginTop: 0 }}>{city.name}</h3>
            <select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '100%', padding: 10, borderRadius: 8, marginBottom: 12 }}>
              {['published', 'hidden', 'draft', 'disabled', 'coming_soon'].map((st) => (
                <option key={st} value={st}>{st}</option>
              ))}
            </select>
            <SurfaceVisibilityMatrix surfaces={surfaces} rules={rules} onChange={setRules} />
            <button type="button" className="s2-btn s2-btn--primary" style={{ marginTop: 16 }} onClick={() => onSave(city.id, { public_status: status, visibility_rules: rules })}>
              Save city visibility
            </button>
          </>
        ) : (
          <p style={{ color: '#94A3B8' }}>Select a city.</p>
        )}
      </div>
    </div>
  )
}

const TEMPLATE_COLOR_KEYS = ['primary', 'background', 'heading', 'accent'] as const

function templateOverridePath(t: PageTemplateDef, key: string): string[] {
  if (t.overridePageSlug) return ['overrides', 'pages', t.overridePageSlug, 'colors', key]
  const pt = t.overridePageType || t.widthPageType || t.id
  return ['overrides', 'page_types', pt, 'colors', key]
}

function readTemplateColor(overrides: Record<string, Record<string, unknown>>, t: PageTemplateDef, key: string): string {
  if (t.overridePageSlug) {
    const pages = (overrides.pages || {}) as Record<string, Record<string, unknown>>
    return String(((pages[t.overridePageSlug]?.colors || {}) as Record<string, string>)[key] || '')
  }
  const pageTypes = (overrides.page_types || {}) as Record<string, Record<string, unknown>>
  const pt = t.overridePageType || t.widthPageType || t.id
  return String(((pageTypes[pt]?.colors || {}) as Record<string, string>)[key] || '')
}

export function SiteChromePanel({
  chrome,
  patch,
}: {
  chrome: Record<string, unknown>
  patch: PatchFn
}) {
  const global = (chrome.global || {}) as Record<string, string | boolean>
  return (
    <div style={{ display: 'grid', gap: 20, maxWidth: 900 }}>
      <p style={{ margin: 0, fontSize: 13, color: '#64748B' }}>
        Global header, top bar, and footer defaults. Override per template under <strong>Page Templates</strong>.
      </p>
      <label style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
        <input
          type="checkbox"
          checked={global.show_topbar !== false}
          onChange={(e) => patch(['chrome', 'global', 'show_topbar'], e.target.checked)}
        />
        Show top bar (WhatsApp / sign in)
      </label>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 12 }}>
        <HexColorField label="Top bar background" value={String(global.topbar_bg || '')} onChange={(v) => patch(['chrome', 'global', 'topbar_bg'], v)} />
        <HexColorField label="Top bar text" value={String(global.topbar_text || '')} onChange={(v) => patch(['chrome', 'global', 'topbar_text'], v)} />
        <HexColorField label="Header background" value={String(global.header_bg || '')} onChange={(v) => patch(['chrome', 'global', 'header_bg'], v)} />
        <HexColorField label="Footer background" value={String(global.footer_bg || '')} onChange={(v) => patch(['chrome', 'global', 'footer_bg'], v)} />
        <HexColorField label="Footer text" value={String(global.footer_text || '')} onChange={(v) => patch(['chrome', 'global', 'footer_text'], v)} />
        <PxTokenField label="Header height" value={String(global.header_height_px || '64')} onChange={(v) => patch(['chrome', 'global', 'header_height_px'], parsePx(v))} />
      </div>
      <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 13 }}>
          Header style
          <select
            value={String(global.header_variant || 'standard')}
            onChange={(e) => patch(['chrome', 'global', 'header_variant'], e.target.value)}
            style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, minWidth: 160 }}
          >
            <option value="standard">Standard</option>
            <option value="compact">Compact</option>
          </select>
        </label>
        <label style={{ fontSize: 13 }}>
          Footer style
          <select
            value={String(global.footer_variant || 'full')}
            onChange={(e) => patch(['chrome', 'global', 'footer_variant'], e.target.value)}
            style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, minWidth: 160 }}
          >
            <option value="full">Full columns</option>
            <option value="minimal">Minimal (copyright only)</option>
          </select>
        </label>
      </div>
    </div>
  )
}

export function PageTemplatesPanel({
  overrides,
  widths,
  chrome,
  patch,
  onOpenWidth,
  onPreviewPath,
}: {
  overrides: Record<string, Record<string, unknown>>
  widths: Record<string, unknown>
  chrome: Record<string, unknown>
  patch: PatchFn
  onOpenWidth: (focus: WidthLayoutFocus) => void
  onPreviewPath?: (path: string) => void
}) {
  const chromeRoot = chrome as Record<string, unknown>
  return (
    <div style={{ display: 'grid', gap: 24 }}>
      <p style={{ margin: 0, color: '#64748B', maxWidth: 720, fontSize: 13 }}>
        Control each public template individually: brand colors per template, quick page max width, then open{' '}
        <strong>Width &amp; Layout</strong> for full responsive section control. Changes apply on publish; SPA routes update live after hard refresh once.
      </p>
      <div style={{ display: 'grid', gap: 16 }}>
        {PAGE_TEMPLATES.map((t) => {
          const wPageTypes = ((widths.page_types || {}) as Record<string, Record<string, unknown>>)
          const wPages = ((widths.pages || {}) as Record<string, Record<string, unknown>>)
          let pageMax = ''
          if (t.widthPageSlug && wPages[t.widthPageSlug]?.page_max) {
            const pm = wPages[t.widthPageSlug].page_max
            pageMax = typeof pm === 'object' && pm !== null ? String((pm as Record<string, string>).desktop || '') : String(pm || '')
          } else if (t.widthPageType && wPageTypes[t.widthPageType]?.page_max) {
            const pm = wPageTypes[t.widthPageType].page_max
            pageMax = typeof pm === 'object' && pm !== null ? String((pm as Record<string, string>).desktop || '') : String(pm || '')
          } else if (t.widthSlugAlias && wPageTypes[t.widthSlugAlias]?.page_max) {
            const pm = wPageTypes[t.widthSlugAlias].page_max
            pageMax = typeof pm === 'object' && pm !== null ? String((pm as Record<string, string>).desktop || '') : String(pm || '')
          }

          const setPageMax = (px: string) => {
            const layer = { desktop: parsePx(px), laptop: parsePx(px), tablet: '94%', mobile: '100%' }
            if (t.widthPageSlug) {
              patch(['widths', 'pages', t.widthPageSlug, 'page_max'], layer)
            } else if (t.widthSlugAlias) {
              patch(['widths', 'page_types', t.widthSlugAlias, 'page_max'], layer)
            } else if (t.widthPageType) {
              patch(['widths', 'page_types', t.widthPageType, 'page_max'], layer)
            }
          }

          return (
            <fieldset key={t.id} style={{ border: '1px solid #E2E8F0', borderRadius: 14, padding: 18, margin: 0 }}>
              <legend style={{ fontWeight: 800, padding: '0 8px' }}>{t.label}</legend>
              <p style={{ margin: '0 0 12px', fontSize: 12, color: '#64748B' }}>{t.description}</p>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>
                {TEMPLATE_COLOR_KEYS.map((key) => (
                  <HexColorField
                    key={key}
                    label={key.replace(/_/g, ' ')}
                    hint={readTemplateColor(overrides, t, key) ? '' : 'Empty = inherit global'}
                    value={readTemplateColor(overrides, t, key)}
                    onChange={(v) => patch(templateOverridePath(t, key), v || undefined)}
                  />
                ))}
                <PxTokenField label="Page max (desktop)" value={pageMax} onChange={setPageMax} />
                <PxTokenField
                  label="Page title size"
                  value={readNestedString({ overrides }, templateTypographyPath(t, 'page_title', 'size_desktop'))}
                  onChange={(v) => patch(templateTypographyPath(t, 'page_title', 'size_desktop'), parsePx(v))}
                />
                <PxTokenField
                  label="Body text size"
                  value={readNestedString({ overrides }, templateTypographyPath(t, 'body', 'size_desktop'))}
                  onChange={(v) => patch(templateTypographyPath(t, 'body', 'size_desktop'), parsePx(v))}
                />
                <HexColorField
                  label="Footer background"
                  hint="Template-only footer"
                  value={readNestedString({ chrome: chromeRoot }, templateChromePath(t, 'footer_bg'))}
                  onChange={(v) => patch(templateChromePath(t, 'footer_bg'), v || undefined)}
                />
                <label style={{ fontSize: 13 }}>
                  Footer layout
                  <select
                    value={readNestedString({ chrome: chromeRoot }, templateChromePath(t, 'footer_variant')) || 'inherit'}
                    onChange={(e) => {
                      const val = e.target.value
                      patch(templateChromePath(t, 'footer_variant'), val === 'inherit' ? undefined : val)
                    }}
                    style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
                  >
                    <option value="inherit">Inherit global</option>
                    <option value="full">Full</option>
                    <option value="minimal">Minimal</option>
                  </select>
                </label>
              </div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                <button
                  type="button"
                  className="s2-btn s2-btn--sm s2-btn--outline"
                  onClick={() => {
                    if (t.widthPageSlug) {
                      onOpenWidth({ scope: 'page', selectedPage: t.widthPageSlug })
                    } else if (t.widthPageType === 'service') {
                      onOpenWidth({ scope: 'service_section', selectedSection: 'hero' })
                    } else {
                      onOpenWidth({ scope: 'page_type', selectedType: t.widthSlugAlias || t.widthPageType || t.id })
                    }
                  }}
                >
                  Width &amp; sections →
                </button>
                {onPreviewPath && (
                  <button type="button" className="s2-btn s2-btn--sm s2-btn--ghost" onClick={() => onPreviewPath(t.previewPath)}>
                    Preview route
                  </button>
                )}
              </div>
            </fieldset>
          )
        })}
      </div>
      <p style={{ fontSize: 12, color: '#64748B' }}>
        Width page types in advanced panel: {WIDTH_PAGE_TYPES.join(', ')}.
      </p>
    </div>
  )
}

export function FontAssignPanel({
  fonts,
  fontRoles,
  onAssign,
}: {
  fonts: Array<{ id: string; name: string; category: string }>
  fontRoles: Record<string, string>
  onAssign: (role: string, fontId: string) => void
}) {
  const [role, setRole] = React.useState('heading')
  return (
    <div>
      <p style={{ color: '#64748B' }}>Click a font to assign it to the selected role.</p>
      <select value={role} onChange={(e) => setRole(e.target.value)} style={{ marginBottom: 12, padding: 8, borderRadius: 8 }}>
        {['heading', 'body', 'ui', 'button'].map((r) => (
          <option key={r} value={r}>{r} (current: {fontRoles[r] || '—'})</option>
        ))}
      </select>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 8, maxHeight: 320, overflow: 'auto' }}>
        {fonts.slice(0, 60).map((f) => (
          <button key={f.id} type="button" onClick={() => onAssign(role, f.id)} style={{ textAlign: 'left', padding: 10, borderRadius: 8, border: fontRoles[role] === f.id ? '2px solid var(--s2-primary, #4A6FA5)' : '1px solid #E2E8F0', background: '#fff', cursor: 'pointer' }}>
            <div style={{ fontWeight: 700 }}>{f.name}</div>
            <div style={{ fontSize: 11, color: '#64748B' }}>{f.category}</div>
          </button>
        ))}
      </div>
    </div>
  )
}

export function GuidedOverrideWizard({
  overrides,
  patch,
}: {
  overrides: Record<string, Record<string, unknown>>
  patch: PatchFn
}) {
  const [scope, setScope] = React.useState<'page_types' | 'pages' | 'sections'>('page_types')
  const [key, setKey] = React.useState('home')
  const [primary, setPrimary] = React.useState('')
  const bucket = (overrides[scope] || {}) as Record<string, Record<string, unknown>>
  const cur = bucket[key] || {}

  React.useEffect(() => {
    const c = ((cur.colors || {}) as Record<string, string>).primary || ''
    setPrimary(c)
  }, [scope, key, cur])

  const apply = () => {
    const next = { ...cur, colors: { ...((cur.colors || {}) as object), primary: primary || '{colors.primary}' } }
    patch(['overrides', scope, key], next)
  }

  return (
    <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
      <h3 style={{ marginTop: 0 }}>Guided override</h3>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        <select value={scope} onChange={(e) => setScope(e.target.value as typeof scope)} style={{ padding: 8, borderRadius: 8 }}>
          <option value="page_types">Page type</option>
          <option value="pages">Page slug</option>
          <option value="sections">Section id</option>
        </select>
        <input value={key} onChange={(e) => setKey(e.target.value)} placeholder="Key e.g. home or hero" style={{ flex: 1, minWidth: 160, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }} />
      </div>
      <label style={{ fontSize: 13 }}>Primary color override</label>
      <input type="color" value={primary.startsWith('#') ? primary : '#4A6FA5'} onChange={(e) => setPrimary(e.target.value)} style={{ display: 'block', margin: '8px 0 12px' }} />
      <button type="button" className="s2-btn s2-btn--primary" onClick={apply}>Apply override patch</button>
      <p style={{ fontSize: 12, color: '#64748B' }}>Advanced JSON editing remains under the Overrides tab.</p>
    </div>
  )
}

export function LiveSitePreviewFrame({ path = '/' }: { path?: string }) {
  const base = (typeof window !== 'undefined' && (window.S2NRI_CONFIG?.spaBase || window.location.origin)) || ''
  const route = path.startsWith('/') ? path : `/${path}`
  const src = `${base.replace(/\/$/, '')}${route}?s2nri_preview=${Date.now()}`
  return (
    <div>
      <p style={{ color: '#64748B', fontSize: 13 }}>
        Live public route <code>{route}</code> — publish design first, then refresh the frame.
      </p>
      <iframe title="Public site preview" src={src} style={{ width: '100%', height: 640, border: '1px solid #E2E8F0', borderRadius: 12, background: '#fff' }} />
    </div>
  )
}

export const SERVICE_ICON_LIBRARY = ['📋', '🏠', '✈️', '🎓', '💰', '⚖️', '📄', '🛂', '🌍', '🔐', '🏦', '📝'] as const

export function IconLibraryPanel() {
  return (
    <div>
      <p style={{ color: '#64748B' }}>Standard icon set for services and categories (emoji). Use in admin Services → Icon field.</p>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
        {SERVICE_ICON_LIBRARY.map((icon) => (
          <span key={icon} style={{ fontSize: 28, padding: 12, background: '#F8FAFC', borderRadius: 8, border: '1px solid #E2E8F0' }}>{icon}</span>
        ))}
      </div>
    </div>
  )
}
