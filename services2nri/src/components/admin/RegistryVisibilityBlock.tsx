/**
 * Reusable visibility controls — same rules as Design System → Service Registry.
 */
import React, { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { SurfaceVisibilityMatrix } from '@/pages/admin/design-system-panels'

type Surfaces = Record<string, string>

export function ServiceRegistryVisibilityBlock({
  serviceId,
  compact = false,
}: {
  serviceId: number
  compact?: boolean
}) {
  const [surfaces, setSurfaces] = useState<Surfaces>({})
  const [status, setStatus] = useState('published')
  const [direct, setDirect] = useState('active')
  const [featured, setFeatured] = useState(false)
  const [popular, setPopular] = useState(false)
  const [rules, setRules] = useState<Record<string, boolean | string>>({})
  const [msg, setMsg] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    Promise.all([
      api.get<{ surfaces: Surfaces; services: Array<Record<string, unknown>> }>('admin/service-registry'),
      api.get<{ service: Record<string, unknown> }>(`admin/services/${serviceId}`),
    ])
      .then(([reg, detail]) => {
        if (cancelled) return
        setSurfaces(reg.surfaces || {})
        const row = (reg.services || []).find((s) => Number(s.id) === serviceId)
        const svc = detail.service || row || {}
        setStatus(String(svc.public_status || row?.public_status || 'published'))
        setDirect(String(svc.direct_url_behavior || 'active'))
        setFeatured(Boolean(svc.is_featured || row?.is_featured))
        setPopular(Boolean(svc.is_popular || row?.is_popular))
        const vr = svc.visibility_rules || row?.visibility_rules
        setRules(typeof vr === 'object' && vr ? (vr as Record<string, boolean | string>) : {})
        setLoading(false)
      })
      .catch(() => setLoading(false))
    return () => { cancelled = true }
  }, [serviceId])

  const save = async () => {
    await api.patch(`admin/services/${serviceId}/visibility`, {
      public_status: status,
      direct_url_behavior: direct,
      visibility_rules: rules,
      is_featured: featured,
      is_popular: popular,
    })
    setMsg('Visibility saved — nav, forms, and SEO sync automatically.')
  }

  if (loading) return <p style={{ fontSize: 13, color: '#64748B' }}>Loading visibility…</p>

  return (
    <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: compact ? 12 : 16, marginTop: 16, background: '#F8FAFC' }}>
      <h4 style={{ margin: '0 0 12px', fontSize: 14 }}>Public visibility (registry)</h4>
      {!compact && <p style={{ fontSize: 12, color: '#64748B', marginTop: 0 }}>Single source of truth for nav, homepage, search, forms, footer, and direct URLs.</p>}
      <label style={{ fontSize: 12, fontWeight: 600 }}>Publication status</label>
      <select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '100%', padding: 8, borderRadius: 8, marginBottom: 10 }}>
        {['published', 'hidden', 'draft', 'disabled', 'coming_soon'].map((st) => (
          <option key={st} value={st}>{st}</option>
        ))}
      </select>
      <label style={{ fontSize: 12, fontWeight: 600 }}>Direct URL when hidden</label>
      <select value={direct} onChange={(e) => setDirect(e.target.value)} style={{ width: '100%', padding: 8, borderRadius: 8, marginBottom: 10 }}>
        {['not_found', 'redirect_directory', 'redirect_home', 'unavailable_page', 'active'].map((b) => (
          <option key={b} value={b}>{b.replace(/_/g, ' ')}</option>
        ))}
      </select>
      <div style={{ display: 'flex', gap: 16, marginBottom: 12, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12 }}><input type="checkbox" checked={featured} onChange={(e) => setFeatured(e.target.checked)} /> Featured</label>
        <label style={{ fontSize: 12 }}><input type="checkbox" checked={popular} onChange={(e) => setPopular(e.target.checked)} /> Popular</label>
      </div>
      {Object.keys(surfaces).length > 0 && (
        <>
          <p style={{ fontSize: 12, fontWeight: 600, margin: '8px 0' }}>Per-surface matrix</p>
          <SurfaceVisibilityMatrix surfaces={surfaces} rules={rules} onChange={setRules} />
        </>
      )}
      <button type="button" className="s2-btn s2-btn--primary s2-btn--sm" style={{ marginTop: 12 }} onClick={save}>
        Save visibility
      </button>
      {msg && <p style={{ fontSize: 12, color: '#059669', marginBottom: 0 }}>{msg}</p>}
    </div>
  )
}

export function CategoryRegistryVisibilityBlock({
  categoryId,
  compact = false,
}: {
  categoryId: number
  compact?: boolean
}) {
  const [surfaces, setSurfaces] = useState<Surfaces>({})
  const [status, setStatus] = useState('published')
  const [rules, setRules] = useState<Record<string, boolean | string>>({})
  const [hideEmpty, setHideEmpty] = useState(false)
  const [msg, setMsg] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    api.get<{ surfaces: Surfaces; categories: Array<Record<string, unknown>> }>('admin/service-registry')
      .then((reg) => {
        if (cancelled) return
        setSurfaces(reg.surfaces || {})
        const row = (reg.categories || []).find((c) => Number(c.id) === categoryId)
        if (row) {
          setStatus(String(row.public_status || 'published'))
          setHideEmpty(Boolean(row.hide_when_empty_children))
          const vr = row.visibility_rules
          setRules(typeof vr === 'object' && vr ? (vr as Record<string, boolean | string>) : {})
        }
        setLoading(false)
      })
      .catch(() => setLoading(false))
    return () => { cancelled = true }
  }, [categoryId])

  const save = async () => {
    await api.patch(`admin/categories/${categoryId}/visibility`, {
      public_status: status,
      visibility_rules: rules,
      hide_when_empty_children: hideEmpty,
    })
    setMsg('Category visibility saved — homepage tabs and nav sync automatically.')
  }

  if (loading) return <p style={{ fontSize: 13, color: '#64748B' }}>Loading visibility…</p>

  return (
    <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: compact ? 12 : 16, marginTop: 16, background: '#F8FAFC' }}>
      <h4 style={{ margin: '0 0 12px', fontSize: 14 }}>Public visibility (registry)</h4>
      {!compact && <p style={{ fontSize: 12, color: '#64748B', marginTop: 0 }}>Controls homepage tabs, category pages, nav, search, and sitemap inclusion.</p>}
      <label style={{ fontSize: 12, fontWeight: 600 }}>Publication status</label>
      <select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '100%', padding: 8, borderRadius: 8, marginBottom: 10 }}>
        {['published', 'hidden', 'draft', 'disabled', 'coming_soon'].map((st) => (
          <option key={st} value={st}>{st}</option>
        ))}
      </select>
      <label style={{ fontSize: 12, display: 'block', marginBottom: 12 }}>
        <input type="checkbox" checked={hideEmpty} onChange={(e) => setHideEmpty(e.target.checked)} /> Hide when no published services in category
      </label>
      {Object.keys(surfaces).length > 0 && (
        <>
          <p style={{ fontSize: 12, fontWeight: 600, margin: '8px 0' }}>Per-surface matrix</p>
          <SurfaceVisibilityMatrix surfaces={surfaces} rules={rules} onChange={setRules} />
        </>
      )}
      <button type="button" className="s2-btn s2-btn--primary s2-btn--sm" style={{ marginTop: 12 }} onClick={save}>
        Save visibility
      </button>
      {msg && <p style={{ fontSize: 12, color: '#059669', marginBottom: 0 }}>{msg}</p>}
    </div>
  )
}
