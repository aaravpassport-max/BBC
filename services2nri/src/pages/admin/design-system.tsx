/**
 * Admin — centralized Design & Style System for all public-facing pages.
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import {
  TypographyRolesEditor,
  TokenGroupEditor,
  ComponentsEditor,
  OverridesEditor,
  SurfaceVisibilityMatrix,
  CategoryRegistryPanel,
  NavMenuEditor,
  type RegistryService,
  type RegistryCategory,
} from './design-system-panels'

type DesignConfig = Record<string, unknown>

const TABS = [
  'Global', 'Typography', 'Fonts', 'Colors', 'Spacing', 'Buttons', 'Cards', 'Forms',
  'Containers', 'Borders', 'Shadows', 'Motion', 'Responsive', 'Overrides',
  'Presets', 'Preview', 'Navigation', 'Service Registry', 'Categories',
] as const

type Tab = (typeof TABS)[number]

const COLOR_KEYS = [
  'primary', 'primary_hover', 'primary_active', 'secondary', 'secondary_hover',
  'accent', 'accent_hover', 'background', 'surface', 'surface_alt', 'card',
  'border', 'divider', 'heading', 'body', 'muted', 'placeholder', 'link', 'link_hover',
  'success', 'warning', 'error', 'info', 'disabled', 'overlay', 'shadow',
]

export function AdminDesignSystem() {
  const [tab, setTab] = useState<Tab>('Global')
  const [config, setConfig] = useState<DesignConfig | null>(null)
  const [presets, setPresets] = useState<Record<string, { label: string; description: string }>>({})
  const [fonts, setFonts] = useState<Array<{ id: string; name: string; category: string; pairing: string }>>([])
  const [fontQuery, setFontQuery] = useState('')
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [registry, setRegistry] = useState<RegistryService[]>([])
  const [categories, setCategories] = useState<RegistryCategory[]>([])
  const [surfaces, setSurfaces] = useState<Record<string, string>>({})
  const [selectedSvc, setSelectedSvc] = useState<number | null>(null)
  const [impact, setImpact] = useState<Record<string, unknown> | null>(null)
  const [svcRules, setSvcRules] = useState<Record<string, boolean | string>>({})
  const [svcStatus, setSvcStatus] = useState('published')
  const [svcDirect, setSvcDirect] = useState('active')
  const [svcFeatured, setSvcFeatured] = useState(false)
  const [svcPopular, setSvcPopular] = useState(false)

  const load = useCallback(async () => {
    const data = await api.get<{
      config: DesignConfig
      presets: Record<string, { label: string; description: string }>
      fonts: { library: Array<{ id: string; name: string; category: string; pairing: string }> }
    }>('admin/design')
    setConfig(data.config)
    setPresets(data.presets || {})
    setFonts(data.fonts?.library || [])
    const reg = await api.get<{
      services: RegistryService[]
      categories: RegistryCategory[]
      surfaces: Record<string, string>
    }>('admin/service-registry')
    setRegistry(reg.services || [])
    setCategories(reg.categories || [])
    setSurfaces(reg.surfaces || {})
  }, [])

  useEffect(() => { load().catch(() => setMessage('Failed to load design system')) }, [load])

  const colors = (config?.colors || {}) as Record<string, string>
  const spacing = (config?.spacing || {}) as Record<string, string>
  const fontRoles = (config?.fonts || {}) as Record<string, string>
  const typography = (config?.typography || {}) as Record<string, Record<string, string>>
  const radius = (config?.radius || {}) as Record<string, string>
  const shadow = (config?.shadow || {}) as Record<string, string>
  const motion = (config?.motion || {}) as Record<string, string | boolean>
  const breakpoints = (config?.breakpoints || {}) as Record<string, number>
  const components = (config?.components || {}) as Record<string, string>
  const overrides = (config?.overrides || {}) as Record<string, Record<string, unknown>>

  const filteredFonts = useMemo(() => {
    const q = fontQuery.toLowerCase().trim()
    if (!q) return fonts.slice(0, 40)
    return fonts.filter(
      (f) =>
        f.name.toLowerCase().includes(q) ||
        f.category.toLowerCase().includes(q) ||
        f.pairing.toLowerCase().includes(q)
    )
  }, [fonts, fontQuery])

  const patch = (path: string[], value: unknown) => {
    setConfig((prev) => {
      if (!prev) return prev
      const next = JSON.parse(JSON.stringify(prev)) as DesignConfig
      let cur: Record<string, unknown> = next
      for (let i = 0; i < path.length - 1; i++) {
        const k = path[i]
        if (typeof cur[k] !== 'object' || cur[k] === null) cur[k] = {}
        cur = cur[k] as Record<string, unknown>
      }
      cur[path[path.length - 1]] = value
      return next
    })
  }

  const save = async () => {
    if (!config) return
    setSaving(true)
    setMessage('')
    try {
      await api.put('admin/design', config)
      setMessage('Design system saved. Refresh the public site to see changes.')
    } catch (e: unknown) {
      setMessage(e instanceof Error ? e.message : 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  const applyPreset = async (id: string) => {
    setSaving(true)
    try {
      const res = await api.post<{ config: DesignConfig }>('admin/design/preset', { preset: id })
      setConfig(res.config)
      setMessage(`Preset "${presets[id]?.label || id}" applied.`)
    } catch (e: unknown) {
      setMessage(e instanceof Error ? e.message : 'Preset failed')
    } finally {
      setSaving(false)
    }
  }

  const selectService = (s: RegistryService) => {
    setSelectedSvc(s.id)
    setSvcStatus(s.public_status || 'published')
    setSvcDirect(s.direct_url_behavior || 'active')
    setSvcFeatured(Boolean(s.is_featured))
    setSvcPopular(Boolean(s.is_popular))
    setSvcRules((s.visibility_rules || {}) as Record<string, boolean | string>)
    loadImpact(s.id)
  }

  const loadImpact = async (id: number) => {
    const data = await api.get<Record<string, unknown>>(`admin/services/${id}/visibility-impact`)
    setImpact(data)
  }

  const saveServiceVisibility = async () => {
    if (!selectedSvc) return
    await api.patch(`admin/services/${selectedSvc}/visibility`, {
      public_status: svcStatus,
      direct_url_behavior: svcDirect,
      visibility_rules: svcRules,
      is_featured: svcFeatured,
      is_popular: svcPopular,
    })
    await load()
    await loadImpact(selectedSvc)
    setMessage('Service visibility updated — navigation and forms sync automatically.')
  }

  const saveCategory = async (id: number, body: Record<string, unknown>) => {
    await api.patch(`admin/categories/${id}/visibility`, body)
    await load()
    setMessage('Category visibility saved.')
  }

  if (!config) {
    return <div style={{ padding: 24 }}>Loading design system…</div>
  }

  return (
    <div style={{ padding: '24px 28px', maxWidth: 1200 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 26 }}>Design &amp; Style System</h1>
          <p style={{ color: '#64748B', marginTop: 8, maxWidth: 640 }}>
            Central tokens for typography, colors, spacing, and components on every public page.
            Changes inherit: Global → page type → page → section.
          </p>
        </div>
        <button type="button" onClick={save} disabled={saving} className="s2-btn s2-btn--primary">
          {saving ? 'Saving…' : 'Publish design'}
        </button>
      </div>
      {message && (
        <div className="s2-alert s2-alert--info" style={{ marginTop: 16, padding: 12 }}>
          {message}
        </div>
      )}

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 20, marginBottom: 20 }}>
        {TABS.map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => setTab(t)}
            style={{
              padding: '8px 14px',
              borderRadius: 999,
              border: tab === t ? '2px solid var(--s2-primary, #4A6FA5)' : '1px solid #E2E8F0',
              background: tab === t ? '#EBF0F8' : '#fff',
              fontWeight: tab === t ? 700 : 500,
              cursor: 'pointer',
              fontSize: 13,
            }}
          >
            {t}
          </button>
        ))}
      </div>

      {tab === 'Colors' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 16 }}>
          {COLOR_KEYS.map((key) => (
            <label key={key} style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
              <span style={{ fontWeight: 600 }}>{key.replace(/_/g, ' ')}</span>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <input
                  type="color"
                  value={(colors[key] || '#000000').startsWith('#') ? colors[key] : '#4A6FA5'}
                  onChange={(e) => patch(['colors', key], e.target.value)}
                />
                <input
                  type="text"
                  value={colors[key] || ''}
                  onChange={(e) => patch(['colors', key], e.target.value)}
                  style={{ flex: 1, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
                />
              </div>
            </label>
          ))}
        </div>
      )}

      {tab === 'Spacing' && (
        <TokenGroupEditor title="Spacing scale" basePath={['spacing']} tokens={spacing} patch={patch} keys={Object.keys(spacing)} />
      )}

      {tab === 'Fonts' && (
        <div>
          <p style={{ color: '#64748B' }}>100 curated Google Fonts — search by name, category, or pairing.</p>
          <input
            type="search"
            placeholder="Search fonts…"
            value={fontQuery}
            onChange={(e) => setFontQuery(e.target.value)}
            style={{ width: '100%', maxWidth: 400, padding: 10, borderRadius: 8, border: '1px solid #E2E8F0', marginBottom: 16 }}
          />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12, maxHeight: 360, overflow: 'auto' }}>
            {filteredFonts.map((f) => (
              <div key={f.id} style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 12 }}>
                <div style={{ fontWeight: 700 }}>{f.name}</div>
                <div style={{ fontSize: 12, color: '#64748B' }}>{f.category}</div>
                <div style={{ fontSize: 11, color: '#94A3B8', marginTop: 4 }}>{f.pairing}</div>
              </div>
            ))}
          </div>
        </div>
      )}

      {tab === 'Global' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 16 }}>
          <label style={{ fontSize: 13 }}>
            Active preset id
            <input type="text" value={String(config.preset || '')} readOnly style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8, background: '#F8FAFC' }} />
          </label>
          {(['heading', 'body', 'ui', 'button'] as const).map((role) => (
            <label key={role} style={{ fontSize: 13 }}>
              <span style={{ fontWeight: 600 }}>{role} font</span>
              <select
                value={fontRoles[role] || ''}
                onChange={(e) => patch(['fonts', role], e.target.value)}
                style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8 }}
              >
                {fonts.map((f) => (
                  <option key={f.id} value={f.id}>{f.name}</option>
                ))}
              </select>
            </label>
          ))}
        </div>
      )}

      {tab === 'Typography' && <TypographyRolesEditor typography={typography} patch={patch} />}

      {(tab === 'Buttons' || tab === 'Cards' || tab === 'Forms') && (
        <ComponentsEditor components={components} patch={patch} />
      )}

      {tab === 'Containers' && (
        <TokenGroupEditor
          title="Layout containers"
          basePath={['spacing']}
          tokens={spacing}
          patch={patch}
          keys={['container_max', 'content_max', 'page_margin', 'section_y', 'section_y_mobile', 'grid_gap', 'element', 'card']}
        />
      )}

      {tab === 'Borders' && (
        <TokenGroupEditor title="Border radius" basePath={['radius']} tokens={radius} patch={patch} keys={Object.keys(radius)} />
      )}

      {tab === 'Shadows' && (
        <TokenGroupEditor title="Elevation" basePath={['shadow']} tokens={shadow} patch={patch} keys={Object.keys(shadow)} />
      )}

      {tab === 'Motion' && (
        <div style={{ display: 'grid', gap: 16, maxWidth: 480 }}>
          <label style={{ fontSize: 13 }}>
            Transition duration
            <input type="text" value={String(motion.duration || '')} onChange={(e) => patch(['motion', 'duration'], e.target.value)} style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }} />
          </label>
          <label style={{ fontSize: 13 }}>
            Easing
            <input type="text" value={String(motion.ease || '')} onChange={(e) => patch(['motion', 'ease'], e.target.value)} style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }} />
          </label>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
            <input type="checkbox" checked={Boolean(motion.reduce_motion)} onChange={(e) => patch(['motion', 'reduce_motion'], e.target.checked)} />
            Respect prefers-reduced-motion on public site
          </label>
        </div>
      )}

      {tab === 'Responsive' && (
        <TokenGroupEditor
          title="Breakpoints (px)"
          basePath={['breakpoints']}
          tokens={Object.fromEntries(Object.entries(breakpoints).map(([k, v]) => [k, String(v)]))}
          patch={(path, val) => patch(path, Number(val))}
          keys={Object.keys(breakpoints)}
        />
      )}

      {tab === 'Overrides' && <OverridesEditor overrides={overrides} patch={patch} />}

      {tab === 'Presets' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 16 }}>
          {Object.entries(presets).map(([id, p]) => (
            <div key={id} className="s2-card" style={{ border: '1px solid #E2E8F0', borderRadius: 16, padding: 20 }}>
              <h3 style={{ margin: '0 0 8px' }}>{p.label}</h3>
              <p style={{ fontSize: 13, color: '#64748B', minHeight: 40 }}>{p.description}</p>
              <button type="button" onClick={() => applyPreset(id)} className="s2-btn s2-btn--secondary">
                Apply preset
              </button>
            </div>
          ))}
        </div>
      )}

      {tab === 'Preview' && (
        <div className="s2-ds" style={{ border: '1px solid #E2E8F0', borderRadius: 16, padding: 28, background: colors.background || '#fff' }}>
          <p className="s2-t-eyebrow">Live preview</p>
          <h2 className="s2-t-section-heading" style={{ marginTop: 8 }}>Premium public components</h2>
          <p className="s2-t-body" style={{ maxWidth: 520 }}>Buttons, cards, alerts, and form fields consume the same CSS variables as the live site.</p>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', margin: '20px 0' }}>
            <button type="button" className="s2-btn s2-btn--primary">Primary</button>
            <button type="button" className="s2-btn s2-btn--secondary">Secondary</button>
            <button type="button" className="s2-btn s2-btn--outline">Outline</button>
          </div>
          <div className="s2-card s2-card--service s2-animate-hover" style={{ maxWidth: 360, marginBottom: 16 }}>
            <h4 className="s2-card__title">Service card</h4>
            <p className="s2-card__desc">Apostille, attestation, and document services for NRIs worldwide.</p>
            <span className="s2-badge s2-badge--coming-soon">Coming soon</span>
          </div>
          <div className="s2-field" style={{ maxWidth: 360 }}>
            <label className="s2-label">Email</label>
            <input className="s2-input" placeholder="you@example.com" />
          </div>
          <div className="s2-alert s2-alert--success" style={{ maxWidth: 480, marginTop: 16 }}>Success — your design tokens are active.</div>
        </div>
      )}

      {tab === 'Navigation' && (
        <NavMenuEditor onSaved={(msg) => setMessage(msg)} />
      )}

      {tab === 'Service Registry' && (
        <div>
          <p style={{ color: '#64748B', maxWidth: 720 }}>
            Hide or publish a service once — navigation, homepage, search, forms, footer, and related sections update automatically.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.2fr', gap: 20, marginTop: 16 }}>
            <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, maxHeight: 400, overflow: 'auto' }}>
              {registry.map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => selectService(s)}
                  style={{
                    display: 'block',
                    width: '100%',
                    textAlign: 'left',
                    padding: '12px 16px',
                    border: 'none',
                    borderBottom: '1px solid #F1F5F9',
                    background: selectedSvc === s.id ? '#EBF0F8' : '#fff',
                    cursor: 'pointer',
                  }}
                >
                  <strong>{s.name}</strong>
                  <span style={{ float: 'right', fontSize: 12, color: '#64748B' }}>{s.public_status || 'published'}</span>
                </button>
              ))}
            </div>
            <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
              {selectedSvc ? (
                <>
                  <h3 style={{ marginTop: 0 }}>Visibility</h3>
                  <label style={{ fontSize: 13, fontWeight: 600 }}>Publication status</label>
                  <select value={svcStatus} onChange={(e) => setSvcStatus(e.target.value)} style={{ width: '100%', padding: 10, borderRadius: 8, marginBottom: 12 }}>
                    {['published', 'hidden', 'draft', 'disabled', 'coming_soon'].map((st) => (
                      <option key={st} value={st}>{st}</option>
                    ))}
                  </select>
                  <label style={{ fontSize: 13, fontWeight: 600 }}>Direct URL behavior</label>
                  <select value={svcDirect} onChange={(e) => setSvcDirect(e.target.value)} style={{ width: '100%', padding: 10, borderRadius: 8, marginBottom: 12 }}>
                    {['not_found', 'redirect_directory', 'redirect_home', 'unavailable_page', 'active'].map((b) => (
                      <option key={b} value={b}>{b.replace(/_/g, ' ')}</option>
                    ))}
                  </select>
                  <div style={{ display: 'flex', gap: 16, marginBottom: 12 }}>
                    <label style={{ fontSize: 13 }}><input type="checkbox" checked={svcFeatured} onChange={(e) => setSvcFeatured(e.target.checked)} /> Featured on homepage</label>
                    <label style={{ fontSize: 13 }}><input type="checkbox" checked={svcPopular} onChange={(e) => setSvcPopular(e.target.checked)} /> Popular badge</label>
                  </div>
                  <h4 style={{ margin: '16px 0 8px' }}>Per-surface matrix</h4>
                  <SurfaceVisibilityMatrix surfaces={surfaces} rules={svcRules} onChange={setSvcRules} />
                  <button type="button" className="s2-btn s2-btn--primary" style={{ marginTop: 16 }} onClick={saveServiceVisibility}>
                    Save service visibility
                  </button>
                  {impact && (
                    <div style={{ fontSize: 13, color: '#334155', marginTop: 16 }}>
                      <p><strong>Currently visible on:</strong></p>
                      <ul>
                        {((impact.currently_visible as string[]) || []).map((x) => (
                          <li key={x}>{x}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                </>
              ) : (
                <p style={{ color: '#94A3B8' }}>Select a service to preview impact.</p>
              )}
            </div>
          </div>
        </div>
      )}

      {tab === 'Categories' && (
        <CategoryRegistryPanel categories={categories} surfaces={surfaces} onSave={saveCategory} />
      )}
    </div>
  )
}
