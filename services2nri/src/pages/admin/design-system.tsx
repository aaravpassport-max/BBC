/**
 * Admin — centralized Design & Style System for all public-facing pages.
 */
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api } from '@/lib/api'
import {
  TypographyRolesEditor,
  TokenGroupEditor,
  ComponentsEditor,
  OverridesEditor,
  SurfaceVisibilityMatrix,
  CategoryRegistryPanel,
  CityRegistryPanel,
  NavMenuEditor,
  PageTemplatesPanel,
  SiteChromePanel,
  FontAssignPanel,
  GuidedOverrideWizard,
  LiveSitePreviewFrame,
  IconLibraryPanel,
  type RegistryService,
  type RegistryCategory,
  type RegistryCity,
} from './design-system-panels'
import { WidthLayoutPanel, type WidthLayoutFocus } from './width-layout-panel'
import { HexColorField, HexAlphaColorField } from './design-admin-fields'
import { broadcastDesignSaved } from '@/lib/design-live-sync'
import { ensurePatchPath, normalizeAdminDesignConfig, prepareDesignConfigForSave } from '@/lib/design-admin-config'

type DesignConfig = Record<string, unknown>

const TABS = [
  'Global', 'Site Chrome', 'Page Templates', 'Typography', 'Fonts', 'Colors', 'Spacing', 'Layout Studio', 'Components',
  'Containers', 'Borders', 'Shadows', 'Motion', 'Responsive', 'Overrides',
  'Presets', 'Preview', 'Live Site', 'Navigation', 'Icons',
  'Service Registry', 'Categories', 'Cities',
] as const

type Tab = (typeof TABS)[number]

const COLOR_KEYS_HEX = [
  'primary', 'primary_hover', 'primary_active', 'secondary', 'secondary_hover',
  'accent', 'accent_hover', 'background', 'surface', 'surface_alt', 'card',
  'border', 'divider', 'heading', 'body', 'muted', 'placeholder', 'link', 'link_hover',
  'success', 'warning', 'error', 'info', 'disabled',
] as const

const COLOR_KEYS_ALPHA = ['overlay', 'shadow'] as const

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
  const [cities, setCities] = useState<RegistryCity[]>([])
  const [surfaces, setSurfaces] = useState<Record<string, string>>({})
  const [citySurfaces, setCitySurfaces] = useState<Record<string, string>>({})
  const [selectedSvc, setSelectedSvc] = useState<number | null>(null)
  const [impact, setImpact] = useState<Record<string, unknown> | null>(null)
  const [svcRules, setSvcRules] = useState<Record<string, boolean | string>>({})
  const [svcStatus, setSvcStatus] = useState('published')
  const [svcDirect, setSvcDirect] = useState('active')
  const [svcFeatured, setSvcFeatured] = useState(false)
  const [svcPopular, setSvcPopular] = useState(false)
  const [widthFocus, setWidthFocus] = useState<WidthLayoutFocus | null>(null)
  const [previewPath, setPreviewPath] = useState('/')
  const [designRevision, setDesignRevision] = useState('')
  const configRef = useRef<DesignConfig | null>(null)

  const load = useCallback(async (opts?: { registry?: boolean }) => {
    const data = await api.get<{
      config: DesignConfig
      revision?: string
      presets: Record<string, { label: string; description: string }>
      fonts: { library: Array<{ id: string; name: string; category: string; pairing: string }> }
    }>('admin/design')
    const normalized = prepareDesignConfigForSave(data.config)
    configRef.current = normalized
    setConfig(normalized)
    setDesignRevision(data.revision || '')
    setPresets(data.presets || {})
    setFonts(data.fonts?.library || [])
    if (opts?.registry !== false) {
      const reg = await api.get<{
        services: RegistryService[]
        categories: RegistryCategory[]
        cities: RegistryCity[]
        surfaces: Record<string, string>
        city_surfaces: Record<string, string>
      }>('admin/service-registry')
      setRegistry(reg.services || [])
      setCategories(reg.categories || [])
      setCities(reg.cities || [])
      setSurfaces(reg.surfaces || {})
      setCitySurfaces(reg.city_surfaces || {})
    }
    return data
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
  const chrome = (config?.chrome || {}) as Record<string, unknown>

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
      const next = normalizeAdminDesignConfig(JSON.parse(JSON.stringify(prev)) as DesignConfig)
      const cur = ensurePatchPath(next, path)
      const leaf = path[path.length - 1]
      if (value === undefined) {
        delete cur[leaf]
      } else if (value === null) {
        cur[leaf] = null
      } else {
        cur[leaf] = value
      }
      configRef.current = next
      return next
    })
  }

  const save = async () => {
    const payload = configRef.current
    if (!payload) return
    const revisionBefore = designRevision
    setSaving(true)
    setMessage('')
    try {
      const body = prepareDesignConfigForSave(payload)
      configRef.current = body
      const res = await api.put<{ ok?: boolean; config: DesignConfig; revision?: string }>('admin/design', body)
      if (res.ok === false) {
        throw new Error('Server did not confirm save.')
      }
      const normalized = res.config ? normalizeAdminDesignConfig(res.config) : null
      if (normalized) {
        configRef.current = normalized
        setConfig(normalized)
      }
      const reloaded = await load({ registry: false })
      const storedRevision = reloaded.revision || res.revision || ''
      if (revisionBefore && storedRevision && revisionBefore === storedRevision) {
        setMessage(
          'Publish finished, but the stored revision did not change — no new data was written. Change a value and publish again, or reinstall the latest plugin build if this persists.',
        )
      } else {
        broadcastDesignSaved()
        setMessage(
          storedRevision
            ? `Design saved successfully (revision ${storedRevision.slice(0, 8)}). Values are stored — safe to reload this page.`
            : 'Design saved successfully. Values are stored — safe to reload this page.',
        )
      }
    } catch (e: unknown) {
      setMessage(e instanceof Error ? e.message : 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  const applyPreset = async (id: string, mode: 'theme' | 'factory' = 'theme') => {
    const label = presets[id]?.label || id
    const confirmMsg = `Apply "${label}" to the full public site look? This updates colors, fonts, typography, spacing, radius, shadows, components, site chrome, global widths, and clears per-page template overrides.`
    if (!window.confirm(confirmMsg)) {
      return
    }
    setSaving(true)
    try {
      const res = await api.post<{ config: DesignConfig }>('admin/design/preset', { preset: id, mode })
      const normalized = normalizeAdminDesignConfig(res.config)
      configRef.current = normalized
      setConfig(normalized)
      broadcastDesignSaved()
      setMessage(`Preset "${label}" applied site-wide — open or switch to a public tab to see it live (no hard refresh).`)
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

  const saveCity = async (id: number, body: Record<string, unknown>) => {
    await api.patch(`admin/cities/${id}/visibility`, body)
    await load()
    setMessage('City visibility saved.')
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
        <div style={{ display: 'grid', gap: 24 }}>
          <p style={{ margin: 0, fontSize: 13, color: '#64748B', maxWidth: 640 }}>
            Brand and UI colors use <strong>#HEX</strong> codes. Overlay and shadow support hex + opacity (saved as 8-digit hex).
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 16 }}>
            {COLOR_KEYS_HEX.map((key) => (
              <HexColorField
                key={key}
                label={key.replace(/_/g, ' ')}
                value={colors[key] || ''}
                onChange={(v) => patch(['colors', key], v)}
              />
            ))}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 16 }}>
            {COLOR_KEYS_ALPHA.map((key) => (
              <HexAlphaColorField
                key={key}
                label={key.replace(/_/g, ' ')}
                value={colors[key] || ''}
                onChange={(v) => patch(['colors', key], v)}
              />
            ))}
          </div>
        </div>
      )}

      {tab === 'Spacing' && (
        <TokenGroupEditor title="Spacing scale (px)" basePath={['spacing']} tokens={spacing} patch={patch} keys={Object.keys(spacing)} unit="px" />
      )}

      {tab === 'Fonts' && (
        <div style={{ display: 'grid', gap: 24 }}>
          <FontAssignPanel
            fonts={filteredFonts}
            fontRoles={fontRoles}
            onAssign={(role, fontId) => patch(['fonts', role], fontId)}
          />
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

      {tab === 'Components' && (
        <div style={{ display: 'grid', gap: 16 }}>
          <p style={{ margin: 0, fontSize: 13, color: '#64748B', maxWidth: 720 }}>
            Maps to public CSS variables <code>--s2-btn-*</code>, <code>--s2-card-radius</code>, <code>--s2-input-radius</code>.
            Use <code>{'{colors.primary}'}</code> or <code>#HEX</code> values.
          </p>
          <ComponentsEditor components={components} patch={patch} />
        </div>
      )}

      {tab === 'Site Chrome' && (
        <SiteChromePanel chrome={chrome} patch={patch} />
      )}

      {tab === 'Page Templates' && (
        <PageTemplatesPanel
          overrides={overrides}
          widths={(config.widths || {}) as Record<string, unknown>}
          chrome={chrome}
          patch={patch}
          onOpenWidth={(focus) => {
            setWidthFocus(focus)
            setTab('Layout Studio')
          }}
          onPreviewPath={(path) => {
            setPreviewPath(path)
            setTab('Live Site')
          }}
        />
      )}

      {tab === 'Layout Studio' && config && (
        <WidthLayoutPanel config={config} patch={patch} focus={widthFocus} />
      )}

      {tab === 'Containers' && (
        <TokenGroupEditor
          title="Layout containers (legacy spacing — prefer Layout Studio)"
          basePath={['spacing']}
          tokens={spacing}
          patch={patch}
          keys={['container_max', 'content_max', 'page_margin', 'section_y', 'section_y_mobile', 'grid_gap', 'element', 'card']}
        />
      )}

      {tab === 'Borders' && (
        <TokenGroupEditor title="Border radius (px)" basePath={['radius']} tokens={radius} patch={patch} keys={Object.keys(radius)} unit="px" />
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

      {tab === 'Overrides' && (
        <div style={{ display: 'grid', gap: 24 }}>
          <GuidedOverrideWizard overrides={overrides} patch={patch} />
          <OverridesEditor overrides={overrides} patch={patch} />
        </div>
      )}

      {tab === 'Presets' && (
        <div style={{ display: 'grid', gap: 20 }}>
          <p style={{ margin: 0, fontSize: 13, color: '#64748B', maxWidth: 720 }}>
            Each preset applies a complete public-site look: palette, typography, spacing, radius, shadows, buttons/cards, header/topbar/footer chrome, global widths, and clears per-template color overrides so every page matches.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 16 }}>
            {Object.entries(presets).map(([id, p]) => (
              <div key={id} className="s2-card" style={{ border: '1px solid #E2E8F0', borderRadius: 16, padding: 20 }}>
                <h3 style={{ margin: '0 0 8px' }}>{p.label}</h3>
                <p style={{ fontSize: 13, color: '#64748B', minHeight: 48 }}>{p.description}</p>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  <button type="button" onClick={() => applyPreset(id, 'theme')} className="s2-btn s2-btn--primary" disabled={saving}>
                    Apply site-wide look
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {tab === 'Live Site' && <LiveSitePreviewFrame path={previewPath} />}

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

      {tab === 'Icons' && <IconLibraryPanel />}

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

      {tab === 'Cities' && (
        <CityRegistryPanel cities={cities} surfaces={citySurfaces} onSave={saveCity} />
      )}
    </div>
  )
}
