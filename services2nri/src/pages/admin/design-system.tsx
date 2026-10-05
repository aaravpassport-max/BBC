/**
 * Admin — centralized Design & Style System for all public-facing pages.
 */
import React, { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import {
  OverridesEditor,
  SurfaceVisibilityMatrix,
  CategoryRegistryPanel,
  CityRegistryPanel,
  NavMenuEditor,
  GuidedOverrideWizard,
  IconLibraryPanel,
  type RegistryService,
  type RegistryCategory,
  type RegistryCity,
} from './design-system-panels'
import { broadcastDesignSaved } from '@/lib/design-live-sync'
import { AdminScreen } from '@/components/admin/AdminMobileUi'
import { ensurePatchPath, normalizeAdminDesignConfig, prepareDesignConfigForSave } from '@/lib/design-admin-config'
import { DesignSystemBuilder } from './design-system-builder'

type DesignConfig = Record<string, unknown>

type ToolsTab = 'Navigation' | 'Icons' | 'Service Registry' | 'Categories' | 'Cities' | 'Advanced overrides'

export function AdminDesignSystem() {
  const [toolsTab, setToolsTab] = useState<ToolsTab | null>(null)
  const [config, setConfig] = useState<DesignConfig | null>(null)
  const [presets, setPresets] = useState<Record<string, { label: string; description: string }>>({})
  const [fonts, setFonts] = useState<Array<{ id: string; name: string; category: string; pairing: string }>>([])
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

  const overrides = (config?.overrides || {}) as Record<string, Record<string, unknown>>

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

  const publishBtn = (
    <button type="button" onClick={save} disabled={saving} className="s2-btn s2-btn--primary">
      {saving ? 'Saving…' : 'Publish design'}
    </button>
  )
  return (
    <AdminScreen sticky={publishBtn}>
    <div className="s2-design-system-page s2-design-system-body">
      <div className="s2-design-system-body-inner">
      <header className="s2-ds-page-header">
        <div>
          <h1>Design &amp; Style System</h1>
          <p className="s2-ds-page-header__lead">
            Enterprise page builder: choose a page and section, then edit <strong>Content</strong> or <strong>Design</strong>.
            Global tokens live under <strong>Site Foundation</strong>; section overrides inherit until you change them.
          </p>
        </div>
        <div className="s2-design-system-header-actions--desktop">{publishBtn}</div>
      </header>
      {message && (
        <div className="s2-alert s2-alert--info" style={{ marginTop: 16, padding: 12 }}>
          {message}
        </div>
      )}

      <DesignSystemBuilder
        config={config}
        patch={patch}
        presets={presets}
        fonts={fonts}
        applyPreset={(id) => applyPreset(id, 'theme')}
        saving={saving}
        previewPath={previewPath}
        setPreviewPath={setPreviewPath}
        publishDesign={save}
        toolsSlot={
          <>
            <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 0.06, color: '#94A3B8', marginBottom: 8 }}>
              Platform tools
            </div>
            {(['Navigation', 'Icons', 'Service Registry', 'Categories', 'Cities', 'Advanced overrides'] as const).map((t) => (
              <button
                key={t}
                type="button"
                onClick={() => setToolsTab(toolsTab === t ? null : t)}
                style={{
                  display: 'block',
                  width: '100%',
                  textAlign: 'left',
                  padding: '8px 12px',
                  marginBottom: 4,
                  borderRadius: 8,
                  border: 'none',
                  background: toolsTab === t ? '#F1F5F9' : 'transparent',
                  fontSize: 13,
                  cursor: 'pointer',
                }}
              >
                {t}
              </button>
            ))}
          </>
        }
      />

      {toolsTab === 'Advanced overrides' && (
        <div style={{ marginTop: 24, borderTop: '1px solid #E2E8F0', paddingTop: 24 }}>
          <GuidedOverrideWizard overrides={overrides} patch={patch} />
          <OverridesEditor overrides={overrides} patch={patch} />
        </div>
      )}

      {toolsTab === 'Navigation' && (
        <div style={{ marginTop: 24 }}>
          <NavMenuEditor onSaved={(msg) => setMessage(msg)} />
        </div>
      )}

      {toolsTab === 'Icons' && (
        <div style={{ marginTop: 24 }}>
          <IconLibraryPanel />
        </div>
      )}

      {toolsTab === 'Service Registry' && (
        <div>
          <p style={{ color: '#64748B', maxWidth: 720 }}>
            Hide or publish a service once — navigation, homepage, search, forms, footer, and related sections update automatically.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.2fr', gap: 20, marginTop: 16 }}>
            <div className="s2-design-system-panel" style={{ border: '1px solid #E2E8F0', borderRadius: 12, maxHeight: 400, overflow: 'auto' }}>
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
            <div className="s2-design-system-panel" style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
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

      {toolsTab === 'Categories' && (
        <div style={{ marginTop: 24 }}>
          <CategoryRegistryPanel categories={categories} surfaces={surfaces} onSave={saveCategory} />
        </div>
      )}

      {toolsTab === 'Cities' && (
        <div style={{ marginTop: 24 }}>
          <CityRegistryPanel cities={cities} surfaces={citySurfaces} onSave={saveCity} />
        </div>
      )}
      </div>
    </div>
    </AdminScreen>
  )
}
