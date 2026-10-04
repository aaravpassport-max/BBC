/**
 * Admin — centralized Design & Style System for all public-facing pages.
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'

type DesignConfig = Record<string, unknown>

const TABS = [
  'Global', 'Typography', 'Fonts', 'Colors', 'Spacing', 'Buttons', 'Cards', 'Forms',
  'Presets', 'Preview', 'Service Registry',
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
  const [registry, setRegistry] = useState<Array<{ id: number; name: string; slug: string; public_status?: string }>>([])
  const [selectedSvc, setSelectedSvc] = useState<number | null>(null)
  const [impact, setImpact] = useState<Record<string, unknown> | null>(null)

  const load = useCallback(async () => {
    const data = await api.get<{
      config: DesignConfig
      presets: Record<string, { label: string; description: string }>
      fonts: { library: Array<{ id: string; name: string; category: string; pairing: string }> }
    }>('admin/design')
    setConfig(data.config)
    setPresets(data.presets || {})
    setFonts(data.fonts?.library || [])
    const reg = await api.get<{ services: Array<{ id: number; name: string; slug: string; public_status?: string }> }>(
      'admin/service-registry'
    )
    setRegistry(reg.services || [])
  }, [])

  useEffect(() => { load().catch(() => setMessage('Failed to load design system')) }, [load])

  const colors = (config?.colors || {}) as Record<string, string>
  const spacing = (config?.spacing || {}) as Record<string, string>
  const fontRoles = (config?.fonts || {}) as Record<string, string>

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

  const loadImpact = async (id: number) => {
    setSelectedSvc(id)
    const data = await api.get<Record<string, unknown>>(`admin/services/${id}/visibility-impact`)
    setImpact(data)
  }

  const setServiceStatus = async (id: number, public_status: string, direct_url_behavior?: string) => {
    await api.patch(`admin/services/${id}/visibility`, {
      public_status,
      ...(direct_url_behavior ? { direct_url_behavior } : {}),
    })
    await load()
    await loadImpact(id)
    setMessage('Service visibility updated — navigation and forms sync automatically.')
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
        <button
          type="button"
          onClick={save}
          disabled={saving}
          className="s2-btn s2-btn--primary"
          style={{ background: colors.primary || '#4A6FA5', border: 'none', color: '#fff', padding: '12px 24px', borderRadius: 999, fontWeight: 700, cursor: 'pointer' }}
        >
          {saving ? 'Saving…' : 'Publish design'}
        </button>
      </div>
      {message && (
        <div className="s2-alert s2-alert--info" style={{ marginTop: 16, padding: 12, background: '#EFF6FF', borderLeft: '4px solid #0284C7' }}>
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
              border: tab === t ? '2px solid #4A6FA5' : '1px solid #E2E8F0',
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
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 16 }}>
          {Object.entries(spacing).map(([key, val]) => (
            <label key={key} style={{ fontSize: 13 }}>
              <span style={{ fontWeight: 600 }}>{key.replace(/_/g, ' ')}</span>
              <input
                type="text"
                value={val}
                onChange={(e) => patch(['spacing', key], e.target.value)}
                style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
              />
            </label>
          ))}
        </div>
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

      {(tab === 'Global' || tab === 'Typography') && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 16 }}>
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

      {tab === 'Presets' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 16 }}>
          {Object.entries(presets).map(([id, p]) => (
            <div key={id} className="s2-card" style={{ border: '1px solid #E2E8F0', borderRadius: 16, padding: 20 }}>
              <h3 style={{ margin: '0 0 8px' }}>{p.label}</h3>
              <p style={{ fontSize: 13, color: '#64748B', minHeight: 40 }}>{p.description}</p>
              <button type="button" onClick={() => applyPreset(id)} style={{ marginTop: 12, padding: '8px 16px', borderRadius: 999, border: 'none', background: '#1E2D40', color: '#fff', cursor: 'pointer' }}>
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
          <div className="s2-card s2-card--service" style={{ maxWidth: 360, marginBottom: 16 }}>
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

      {tab === 'Service Registry' && (
        <div>
          <p style={{ color: '#64748B', maxWidth: 720 }}>
            Hide or publish a service once — navigation, homepage, search, forms, footer, and related sections update automatically.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginTop: 16 }}>
            <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, maxHeight: 400, overflow: 'auto' }}>
              {registry.map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => loadImpact(s.id)}
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
                  <select
                    defaultValue={(registry.find((r) => r.id === selectedSvc)?.public_status) || 'published'}
                    onChange={(e) => setServiceStatus(selectedSvc, e.target.value)}
                    style={{ width: '100%', padding: 10, borderRadius: 8, marginBottom: 12 }}
                  >
                    {['published', 'hidden', 'draft', 'disabled', 'coming_soon'].map((st) => (
                      <option key={st} value={st}>{st}</option>
                    ))}
                  </select>
                  <label style={{ fontSize: 13, fontWeight: 600 }}>Direct URL behavior (when hidden/disabled)</label>
                  <select
                    defaultValue="not_found"
                    onChange={(e) => setServiceStatus(selectedSvc, (registry.find((r) => r.id === selectedSvc)?.public_status) || 'hidden', e.target.value)}
                    style={{ width: '100%', padding: 10, borderRadius: 8, marginBottom: 16 }}
                  >
                    {['not_found', 'redirect_directory', 'redirect_home', 'unavailable_page', 'active'].map((b) => (
                      <option key={b} value={b}>{b.replace(/_/g, ' ')}</option>
                    ))}
                  </select>
                  {impact && (
                    <div style={{ fontSize: 13, color: '#334155' }}>
                      <p><strong>Currently visible on:</strong></p>
                      <ul>
                        {((impact.currently_visible as string[]) || []).map((x) => (
                          <li key={x}>{x}</li>
                        ))}
                      </ul>
                      <p style={{ fontSize: 12, color: '#64748B' }}>{String(impact.historical_note || '')}</p>
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

      {(tab === 'Buttons' || tab === 'Cards' || tab === 'Forms') && (
        <p style={{ color: '#64748B' }}>
          Component styles inherit global tokens. Use the <strong>Preview</strong> tab and publish to apply across all public pages.
        </p>
      )}
    </div>
  )
}
