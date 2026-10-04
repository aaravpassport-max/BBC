/**
 * Admin pages — complete working source (all 22 pages)
 * Exact match of compiled admin-DC3AMdvm.js (182,322 chars)
 */
import React, { useState, useEffect, useCallback, useRef } from 'react'
import { resolvePrimary } from '@/lib/design-tokens'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useStore } from '@/lib/store'
import { api } from '@/lib/api'
import { STAFF_ROLES } from '@/lib/constants'
import { SidebarLayout } from '@/components/layout/Layout'
import { ServiceRegistryVisibilityBlock, CategoryRegistryVisibilityBlock } from '@/components/admin/RegistryVisibilityBlock'

// ── Shared primitives ─────────────────────────────────────────────────────────
function Card({ children, style = {} }: { children: React.ReactNode; style?: React.CSSProperties }) {
  return <div style={{ background: '#fff', borderRadius: 12, border: '1px solid #EBF0F8', padding: 24, boxShadow: '0 2px 8px rgba(0,0,0,.04)', ...style }}>{children}</div>
}
function PageCard({ children, style = {}, onClick }: { children: React.ReactNode; style?: React.CSSProperties; onClick?: () => void }) {
  return <div style={{ background: '#fff', borderRadius: 12, border: '1px solid #EBF0F8', padding: 24, boxShadow: '0 2px 8px rgba(0,0,0,.04)', ...style }} onClick={onClick}>{children}</div>
}
function PageWrap({ title, subtitle, action, children }: { title?: string; subtitle?: string; action?: React.ReactNode; children?: React.ReactNode }) {
  return (
    <div>
      {(title || action) && (
        <div style={{ marginBottom: 28, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12 }}>
          <div>{title && <h1 style={{ fontSize: 22, fontWeight: 800, color: '#1E2D40', margin: '0 0 4px' }}>{title}</h1>}{subtitle && <p style={{ color: '#666', fontSize: 14, margin: 0 }}>{subtitle}</p>}</div>
          {action}
        </div>
      )}
      {children}
    </div>
  )
}
function Spinner() { return <div style={{ textAlign: 'center', padding: '48px 20px', color: '#9ca3af', fontSize: 14 }}>Loading…</div> }
function Empty({ icon = '📭', title, description, action }: { icon?: string; title: string; description?: string; action?: React.ReactNode }) {
  return (
    <div style={{ textAlign: 'center', padding: '56px 24px', color: '#6b7280' }}>
      <div style={{ fontSize: 48, marginBottom: 16 }}>{icon}</div>
      <h3 style={{ margin: '0 0 8px', color: '#374151', fontSize: 18 }}>{title}</h3>
      {description && <p style={{ margin: '0 0 24px', fontSize: 14 }}>{description}</p>}
      {action}
    </div>
  )
}
function PageHeader({ title, subtitle, action }: { title: string; subtitle?: string; action?: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
      <div><h1 style={{ fontSize: 22, fontWeight: 800, color: '#1E2D40', margin: '0 0 4px' }}>{title}</h1>{subtitle && <p style={{ color: '#666', fontSize: 14, margin: 0 }}>{subtitle}</p>}</div>
      {action && <div>{action}</div>}
    </div>
  )
}
type BtnVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
function Btn({ children, onClick, variant = 'primary', loading, disabled, style, ...rest }: React.ButtonHTMLAttributes<HTMLButtonElement> & { children: React.ReactNode; variant?: BtnVariant; loading?: boolean }) {
  const primary = resolvePrimary(useStore(s => s.settings))
  const bg: Record<BtnVariant, string> = { primary, secondary: 'transparent', ghost: '#f3f4f6', danger: '#dc2626' }
  const color: Record<BtnVariant, string> = { primary: '#fff', secondary: primary, ghost: '#374151', danger: '#fff' }
  const border: Record<BtnVariant, string> = { primary: 'none', secondary: `2px solid ${primary}`, ghost: 'none', danger: 'none' }
  return (
    <button onClick={onClick} disabled={disabled || loading}
      style={{ background: bg[variant], color: color[variant], border: border[variant], padding: '9px 18px', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: disabled || loading ? 'not-allowed' : 'pointer', opacity: disabled || loading ? 0.7 : 1, display: 'inline-flex', alignItems: 'center', gap: 6, ...style }}
      {...rest}>{loading ? '…' : children}</button>
  )
}
function Alert({ type = 'error', message, onClose }: { type?: 'error' | 'success'; message?: string | null; onClose?: () => void }) {
  if (!message) return null
  const s = type === 'success' ? { bg: '#f0fdf4', border: '#86efac', color: '#15803d' } : { bg: '#fef2f2', border: '#fca5a5', color: '#b91c1c' }
  return (
    <div style={{ background: s.bg, border: `1px solid ${s.border}`, color: s.color, borderRadius: 8, padding: '12px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, fontSize: 14 }}>
      <span>{message}</span>
      {onClose && <button onClick={onClose} aria-label="Dismiss" style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 18, color: s.color, padding: '0 0 0 12px' }}>×</button>}
    </div>
  )
}
const STATUS_COLORS: Record<string, [string, string]> = {
  submitted: ['#EBF0F8', '#1E2D40'], under_review: ['#fffbeb', '#b45309'], quote_sent: ['#fdf4ff', '#7c3aed'],
  quote_approved: ['#ecfdf5', '#059669'], in_progress: ['#EBF0F8', '#4A6FA5'], completed: ['#f0fdf4', '#15803d'],
  cancelled: ['#fef2f2', '#b91c1c'], on_hold: ['#f9fafb', '#6b7280'], pending: ['#fffbeb', '#b45309'],
  paid: ['#ecfdf5', '#15803d'], verified: ['#ecfdf5', '#15803d'], failed: ['#fef2f2', '#b91c1c'],
  open: ['#EBF0F8', '#1E2D40'], resolved: ['#ecfdf5', '#059669'], closed: ['#f9fafb', '#6b7280'],
  pending_review: ['#fffbeb', '#b45309'], published: ['#ecfdf5', '#15803d'], rejected: ['#fef2f2', '#b91c1c'],
}
function StatusBadge({ status, label }: { status: string; label?: string }) {
  const [bg, color] = STATUS_COLORS[status] || ['#f3f4f6', '#374151']
  return <span style={{ background: bg, color, padding: '3px 10px', borderRadius: 99, fontSize: 12, fontWeight: 600, whiteSpace: 'nowrap' }}>{label || status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</span>
}
function FormInput({ label, value, onChange, type = 'text', placeholder, hint, required, disabled }: { label?: string; value: string; onChange: (e: React.ChangeEvent<HTMLInputElement>) => void; type?: string; placeholder?: string; hint?: string; required?: boolean; disabled?: boolean }) {
  // FIXED: this local FormInput (separate from the properly-built shared
  // one in src/components/ui/index.tsx) had a <label> with no htmlFor and
  // an <input> with no id — they were sibling elements with no
  // programmatic association at all. Used 22 times throughout the entire
  // admin panel, so a screen reader user focusing any of these fields
  // would never hear its label announced. Generates a stable id from the
  // label text, matching the same approach already used correctly in the
  // shared component.
  const inputId = label ? 'fi_' + label.toLowerCase().replace(/[^a-z0-9]+/g, '_') : undefined
  return (
    <div style={{ marginBottom: 14 }}>
      {label && <label htmlFor={inputId} style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 5 }}>{label}{required && <span aria-hidden="true" style={{ color: '#dc2626' }}> *</span>}</label>}
      <input id={inputId} type={type} value={value} onChange={onChange} placeholder={placeholder} required={required} disabled={disabled}
        style={{ width: '100%', padding: '10px 14px', border: '1px solid #e0e0e0', borderRadius: 8, fontSize: 14, fontFamily: 'inherit', outline: 'none', boxSizing: 'border-box', background: disabled ? '#f9f9f9' : '#fff' }} />
      {hint && <p style={{ fontSize: 12, color: '#888', margin: '3px 0 0' }}>{hint}</p>}
    </div>
  )
}
function Textarea({ label, value, onChange, placeholder, rows = 4 }: { label?: string; value: string; onChange: (e: React.ChangeEvent<HTMLTextAreaElement>) => void; placeholder?: string; rows?: number }) {
  // FIXED: same missing label/input association gap as the local
  // FormInput above, in the same file - used 5 times across the admin
  // panel.
  const taId = label ? 'ta_' + label.toLowerCase().replace(/[^a-z0-9]+/g, '_') : undefined
  return (
    <div style={{ marginBottom: 14 }}>
      {label && <label htmlFor={taId} style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 5 }}>{label}</label>}
      <textarea id={taId} rows={rows} value={value} onChange={onChange} placeholder={placeholder}
        style={{ width: '100%', padding: '10px 14px', border: '1px solid #e0e0e0', borderRadius: 8, fontSize: 14, fontFamily: 'inherit', outline: 'none', resize: 'vertical', boxSizing: 'border-box' }} />
    </div>
  )
}
async function exportCSV(path: string) {
  const cfg = window.S2NRI_CONFIG || {}
  const base = (cfg.apiBase || '').replace(/\/$/, ''), nonce = cfg.nonce || ''
  let token = ''; try { token = localStorage.getItem('s2nri_portal_token') || cfg.portalToken || '' } catch {}
  const hdrs: Record<string, string> = { 'X-WP-Nonce': nonce }
  if (token && token.length === 64) hdrs['X-S2NRI-Token'] = token
  try {
    const res = await fetch(`${base}/${path}`, { headers: hdrs, credentials: 'include' })
    if (!res.ok) { alert(`Export failed: HTTP ${res.status}`); return }
    const blob = await res.blob(), a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = path.split('/').pop() + '-' + new Date().toISOString().slice(0, 10) + '.csv'; a.click(); URL.revokeObjectURL(a.href)
  } catch (e: unknown) { alert('Export failed: ' + (e as Error).message) }
}
async function uploadImageToEndpoint(endpoint: string): Promise<string | null> {
  return new Promise(resolve => {
    const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*'
    input.onchange = async () => {
      const file = input.files?.[0]; if (!file) { resolve(null); return }
      const cfg = window.S2NRI_CONFIG || {}, base = (cfg.apiBase || '').replace(/\/$/, '')
      let token = ''; try { token = localStorage.getItem('s2nri_portal_token') || cfg.portalToken || '' } catch {}
      const hdrs: Record<string, string> = { 'X-WP-Nonce': cfg.nonce || '' }
      if (token && token.length === 64) hdrs['X-S2NRI-Token'] = token
      const form = new FormData(); form.append('file', file)
      try { const r = await (await fetch(`${base}/${endpoint}`, { method: 'POST', credentials: 'include', body: form, headers: hdrs })).json(); resolve(r.url || null) }
      catch { resolve(null) }
    }; input.click()
  })
}
// ── Admin Portal Guard ────────────────────────────────────────────────────────
export function AdminPortal({ children }: { children: React.ReactNode }) {
  const user = useStore(s => s.user), nav = useNavigate()
  useEffect(() => {
    if (!user) { nav('/login?redirect=/admin', { replace: true }); return }
    if (!STAFF_ROLES.includes(user.s2nri_role)) { nav('/dashboard', { replace: true }); return }
  }, [user])
  if (!user || !STAFF_ROLES.includes(user.s2nri_role)) return null
  return <SidebarLayout>{children}</SidebarLayout>
}
// ── Sparkline & BarChart ──────────────────────────────────────────────────────
function Sparkline({ data = [], color = '#4A6FA5', height = 60, label = '' }: { data?: number[]; color?: string; height?: number; label?: string }) {
  if (!data || data.length < 2) return null
  const max = Math.max(...data, 1), min = Math.min(...data), range = max - min || 1, W = 260, H = height
  const pts = data.map((v, i) => `${i / (data.length - 1) * W},${H - (v - min) / range * (H - 8) - 4}`).join(' ')
  const fill = `0,${H} ` + pts + ` ${W},${H}`
  const last = data[data.length - 1], cx = W, cy = H - (last - min) / range * (H - 8) - 4
  return <div><svg width={W} height={H} style={{ display: 'block', overflow: 'visible' }}>
    <defs><linearGradient id={`grad-${label}`} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={color} stopOpacity="0.2" /><stop offset="100%" stopColor={color} stopOpacity="0.02" /></linearGradient></defs>
    <polygon points={fill} fill={`url(#grad-${label})`} />
    <polyline points={pts} fill="none" stroke={color} strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
    <circle cx={cx} cy={cy} r={4} fill={color} stroke="#fff" strokeWidth={2} />
  </svg></div>
}
function BarChart({ data = [], color = '#4A6FA5' }: { data?: Array<{ label: string; value: number }>; color?: string }) {
  const max = Math.max(...data.map(d => d.value), 1)
  return <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', height: 80 }}>
    {data.map(({ label, value }) => <div key={label} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
      <div style={{ fontSize: 11, fontWeight: 700, color: '#374151' }}>{value}</div>
      <div style={{ width: '100%', background: `${color}20`, borderRadius: '4px 4px 0 0', height: 64, display: 'flex', alignItems: 'flex-end', overflow: 'hidden' }}>
        <div style={{ width: '100%', background: color, borderRadius: '4px 4px 0 0', height: `${value / max * 100}%`, transition: 'height .5s ease' }} />
      </div>
      <div style={{ fontSize: 10, color: '#9ca3af', textAlign: 'center', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: 44 }}>{label}</div>
    </div>)}
  </div>
}
// ── AdminDashboard (We) ───────────────────────────────────────────────────────
export function AdminDashboard() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const nav = useNavigate()
  const [data, setData] = useState<Record<string, unknown> | null>(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { api.get<Record<string, unknown>>('admin/analytics/dashboard').then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false)) }, [])
  if (loading) return <Spinner />
  const r = (data?.stats || {}) as Record<string, unknown>
  const stats = [
    { label: 'Total Bookings',   value: r.total_bookings   || 0, icon: '📋', color: primary,   link: '/admin/requests' },
    { label: 'Bookings Today',   value: r.bookings_today   || 0, icon: '📅', color: '#7c3aed', link: '/admin/requests' },
    { label: 'Open Bookings',    value: r.open_bookings    || 0, icon: '⏳', color: '#b45309', link: '/admin/requests?status=submitted' },
    { label: 'Pending Payments', value: r.pending_payments || 0, icon: '💳', color: '#dc2626', link: '/admin/payments' },
    { label: 'Revenue (Month)',  value: `₹${Number(r.revenue_this_month || 0).toLocaleString('en-IN')}`, icon: '💰', color: '#15803d', link: '/admin/payments' },
    { label: 'Total Customers',  value: r.total_customers  || 0, icon: '👥', color: '#1E2D40', link: '/admin/customers' },
    { label: 'Open Tickets',     value: r.open_tickets     || 0, icon: '🎫', color: '#9333ea', link: '/admin/tickets' },
    // Issue 13 fix: "Pending Quotes" = bookings with status under_review → clicking shows them
    { label: 'Pending Quotes',   value: r.pending_quotes   || 0, icon: '📝', color: '#c026d3', link: '/admin/requests?status=under_review' },
  ]
  const trends = data?.trends as Record<string, unknown> | undefined
  const recent = (data?.recent || []) as Array<Record<string, unknown>>
  return (
    <div>
      <PageHeader title="Admin Dashboard" subtitle="Services2NRI Overview" />
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 16, marginBottom: 32 }}>
        {stats.map(({ label, value, icon, color, link }) => (
          <div key={label} onClick={() => nav(link)} onMouseEnter={e => { (e.currentTarget as HTMLDivElement).style.boxShadow = `0 4px 16px ${color}30` }} onMouseLeave={e => { (e.currentTarget as HTMLDivElement).style.boxShadow = '' }} style={{ background: '#fff', borderRadius: 12, border: '1px solid #EBF0F8', padding: 16, boxShadow: '0 2px 8px rgba(0,0,0,.04)', borderLeft: `4px solid ${color}`, cursor: 'pointer', transition: 'box-shadow .15s' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div><div style={{ fontSize: 24, fontWeight: 800, color }}>{String(value)}</div><div style={{ fontSize: 13, color: '#6b7280', marginTop: 4 }}>{label}</div></div>
              <span style={{ fontSize: 24, opacity: 0.6 }}>{icon}</span>
            </div>
          </div>
        ))}
      </div>
      {trends && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16, marginBottom: 24 }}>
          <Card style={{ padding: '16px 20px' }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: '#374151', marginBottom: 4 }}>Bookings (30 days)</div>
            <div style={{ fontSize: 22, fontWeight: 800, color: primary, marginBottom: 8 }}>{Number((data?.stats as Record<string, unknown>)?.total_bookings_month || 0)}</div>
            <Sparkline data={(trends.bookings_daily || []) as number[]} color={primary} label="bookings" />
          </Card>
          <Card style={{ padding: '16px 20px' }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: '#374151', marginBottom: 4 }}>Revenue (30 days)</div>
            <div style={{ fontSize: 22, fontWeight: 800, color: '#15803d', marginBottom: 8 }}>₹{Number((data?.stats as Record<string, unknown>)?.revenue_this_month || 0).toLocaleString('en-IN')}</div>
            <Sparkline data={(trends.revenue_daily || []) as number[]} color="#15803d" label="revenue" />
          </Card>
          <Card style={{ padding: '16px 20px' }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: '#374151', marginBottom: 4 }}>Bookings by Service</div>
            <BarChart data={((trends.by_category || []) as Array<{ label: string; value: number }>).slice(0, 6)} color={primary} />
          </Card>
        </div>
      )}
      <Card>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>Recent Bookings</h2>
          <Link to="/admin/requests" style={{ color: primary, textDecoration: 'none', fontSize: 13, fontWeight: 600 }}>View all →</Link>
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
            <thead><tr style={{ background: '#f9fafb', textAlign: 'left' }}>{['Ref', 'Customer', 'Service', 'Status', 'Date'].map(h => <th key={h} style={{ padding: '10px 12px', fontWeight: 600, color: '#374151', borderBottom: '1px solid #e5e7eb' }}>{h}</th>)}</tr></thead>
            <tbody>{recent.length === 0 ? <tr><td colSpan={5} style={{ padding: '32px', textAlign: 'center', color: '#9ca3af' }}>No bookings yet</td></tr> : recent.map(b => (
              <tr key={String(b.id)} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '10px 12px' }}><Link to={`/admin/bookings/${b.id}`} style={{ color: primary, fontWeight: 600 }}>{String(b.booking_ref)}</Link></td>
                <td style={{ padding: '10px 12px', color: '#374151' }}>{String(b.customer_name)}</td>
                <td style={{ padding: '10px 12px', color: '#374151' }}>{String(b.service_name)}</td>
                <td style={{ padding: '10px 12px' }}><StatusBadge status={String(b.status)} /></td>
                <td style={{ padding: '10px 12px', color: '#9ca3af' }}>{new Date(String(b.created_at)).toLocaleDateString('en-IN')}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      </Card>
    </div>
  )
}
// ── AdminBookingList (Be) ─────────────────────────────────────────────────────
export function AdminBookingList() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const nav = useNavigate()
  const [data, setData] = useState<{ rows: Record<string, unknown>[]; total: number }>({ rows: [], total: 0 })
  const [loading, setLoading] = useState(true)
  const [filters, setFilters] = useState({ status: '', search: '', page: 1, qual_status: '' })
  const [selected, setSelected] = useState(new Set<number>())
  const [bulkBusy, setBulkBusy] = useState(false)
  const STATUSES = ['', 'submitted', 'under_review', 'quote_sent', 'quote_approved', 'in_progress', 'docs_requested', 'docs_received', 'processing', 'completed', 'cancelled', 'on_hold']
  const QUAL_STATS = ['', 'highly_qualified', 'qualified', 'requires_review', 'missing_documents', 'not_yet_eligible', 'incomplete']
  const QUAL_LABEL: Record<string, string> = { '': 'All Leads', highly_qualified: '🏆 Highly Qualified', qualified: '✅ Qualified', requires_review: '👀 Requires Review', missing_documents: '📎 Missing Docs', not_yet_eligible: '⛔ Not Eligible', incomplete: '⏳ Incomplete' }
  const QUAL_COLOR: Record<string, [string, string]> = { highly_qualified: ['#166534', '#dcfce7'], qualified: ['#1E2D40', '#d0effa'], requires_review: ['#92400e', '#fef3c7'], missing_documents: ['#7c2d12', '#fee2e2'], not_yet_eligible: ['#dc2626', '#fee2e2'], incomplete: ['#6b7280', '#f3f4f6'] }
  const load = useCallback(() => {
    setLoading(true)
    const qs = new URLSearchParams({ page: String(filters.page), per_page: '20', ...(filters.status && { status: filters.status }), ...(filters.search && { search: filters.search }), ...(filters.qual_status && { qual_status: filters.qual_status }) })
    api.get<{ rows: Record<string, unknown>[]; total: number }>(`admin/bookings?${qs}`).then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false))
  }, [filters])
  useEffect(() => { load() }, [load])
  const setF = (key: string) => (val: string) => setFilters(f => ({ ...f, [key]: val, page: 1 }))
  const [err, setErr] = useState('')
  async function bulkUpdate(status: string) {
    if (!selected.size) return; setBulkBusy(true); setErr('')
    // FIXED: previously `catch {}` silently swallowed any per-item
    // failure — an admin selecting 10 bookings where 3 failed to update
    // (e.g. a transient DB lock) saw no error at all and would wrongly
    // believe all 10 succeeded, leaving 3 bookings silently unchanged.
    let failed = 0
    for (const id of selected) { try { await api.put(`admin/bookings/${id}/status`, { status }) } catch { failed++ } }
    setSelected(new Set()); setBulkBusy(false)
    if (failed > 0) setErr(`${failed} of ${selected.size} bookings failed to update. Please check and retry those individually.`)
    load()
  }
  const allIds = data.rows.map(r => Number(r.id))
  const allSelected = allIds.length > 0 && allIds.every(id => selected.has(id))
  return (
    <div>
      <Alert type="error" message={err} onClose={() => setErr('')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div><h1 style={{ fontSize: 22, fontWeight: 800, color: '#1E2D40', margin: '0 0 4px' }}>All Bookings</h1><p style={{ color: '#666', fontSize: 14, margin: 0 }}>{data.total} total bookings</p></div>
        <div style={{ display: 'flex', gap: 10 }}>
          {selected.size > 0 && <div style={{ display: 'flex', alignItems: 'center', gap: 8, background: '#fff9e6', border: '1px solid #fde68a', borderRadius: 8, padding: '6px 12px' }}>
            <span style={{ fontSize: 13, fontWeight: 600, color: '#92400e' }}>{selected.size} selected</span>
            <button disabled={bulkBusy} onClick={() => bulkUpdate('in_progress')} style={{ fontSize: 12, fontWeight: 600, color: '#4A6FA5', background: '#d0effa', border: 'none', padding: '4px 10px', borderRadius: 6, cursor: 'pointer' }}>→ In Progress</button>
            <button disabled={bulkBusy} onClick={() => bulkUpdate('completed')} style={{ fontSize: 12, fontWeight: 600, color: '#15803d', background: '#d1fae5', border: 'none', padding: '4px 10px', borderRadius: 6, cursor: 'pointer' }}>✓ Complete</button>
            <button onClick={() => setSelected(new Set())} style={{ fontSize: 12, color: '#9ca3af', background: 'none', border: 'none', cursor: 'pointer' }}>✕</button>
          </div>}
          <button onClick={() => exportCSV('admin/export/bookings')} style={{ background: '#f0fdf4', color: '#15803d', border: '1px solid #86efac', padding: '8px 16px', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>⬇ Export CSV</button>
        </div>
      </div>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16, flexWrap: 'wrap' }}>
        <input type="search" placeholder="Search by ref, email, name…" value={filters.search} onChange={e => setF('search')(e.target.value)} style={{ flex: 1, minWidth: 200, padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14 }} />
        <select value={filters.status} onChange={e => setF('status')(e.target.value)} style={{ padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, background: '#fff' }}>{STATUSES.map(s => <option key={s} value={s}>{s || 'All Statuses'}</option>)}</select>
        <select value={filters.qual_status} onChange={e => setF('qual_status')(e.target.value)} style={{ padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, background: '#fff' }}>{QUAL_STATS.map(s => <option key={s} value={s}>{QUAL_LABEL[s] || s}</option>)}</select>
      </div>
      {loading ? <Spinner /> : <Card style={{ padding: 0, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
            <thead><tr style={{ background: '#f9fafb' }}>
              <th style={{ padding: '12px 14px', width: 36 }}><input type="checkbox" checked={allSelected} onChange={e => e.target.checked ? setSelected(new Set(allIds)) : setSelected(new Set())} /></th>
              {['Ref', 'Customer', 'Service', 'Status', 'Lead', 'Date', ''].map(h => <th key={h} style={{ padding: '12px 14px', fontWeight: 600, color: '#374151', borderBottom: '1px solid #e5e7eb', textAlign: 'left', whiteSpace: 'nowrap' }}>{h}</th>)}
            </tr></thead>
            <tbody>{data.rows.length === 0 ? <tr><td colSpan={8} style={{ padding: '32px', textAlign: 'center', color: '#9ca3af' }}>No bookings found</td></tr> : data.rows.map(b => {
              const qs = String(b.qual_status || ''), [qc, qbg] = QUAL_COLOR[qs] || ['#6b7280', '#f3f4f6']
              return <tr key={String(b.id)} style={{ borderBottom: '1px solid #f3f4f6', cursor: 'pointer' }} onClick={() => nav(`/admin/bookings/${b.id}`)}>
                <td style={{ padding: '12px 14px' }} onClick={e => e.stopPropagation()}><input type="checkbox" checked={selected.has(Number(b.id))} onChange={e => { const s = new Set(selected); e.target.checked ? s.add(Number(b.id)) : s.delete(Number(b.id)); setSelected(s) }} /></td>
                <td style={{ padding: '12px 14px' }}><Link to={`/admin/bookings/${b.id}`} style={{ color: primary, fontWeight: 600 }} onClick={e => e.stopPropagation()}>{String(b.booking_ref)}</Link></td>
                <td style={{ padding: '12px 14px', color: '#374151' }}>{String(b.customer_name)}</td>
                <td style={{ padding: '12px 14px', color: '#374151', maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{String(b.service_name)}</td>
                <td style={{ padding: '12px 14px' }}><StatusBadge status={String(b.status)} /></td>
                <td style={{ padding: '12px 14px' }}>{qs && <span style={{ color: qc, background: qbg, padding: '3px 8px', borderRadius: 99, fontSize: 11, fontWeight: 600 }}>{qs.replace(/_/g, ' ')}</span>}</td>
                <td style={{ padding: '12px 14px', color: '#9ca3af' }}>{new Date(String(b.created_at)).toLocaleDateString('en-IN')}</td>
                <td style={{ padding: '12px 14px' }}><Link to={`/admin/bookings/${b.id}`} style={{ color: primary, fontWeight: 600, textDecoration: 'none', fontSize: 13 }} onClick={e => e.stopPropagation()}>View →</Link></td>
              </tr>
            })}</tbody>
          </table>
        </div>
        {data.total > 20 && <div style={{ display: 'flex', justifyContent: 'center', gap: 8, padding: 12 }}>
          <Btn variant="ghost" onClick={() => setFilters(f => ({ ...f, page: Math.max(1, f.page - 1) }))} disabled={filters.page === 1}>← Prev</Btn>
          <span style={{ padding: '10px 16px', fontSize: 13, color: '#6b7280' }}>Page {filters.page} of {Math.ceil(data.total / 20)}</span>
          <Btn variant="ghost" onClick={() => setFilters(f => ({ ...f, page: f.page + 1 }))} disabled={filters.page >= Math.ceil(data.total / 20)}>Next →</Btn>
        </div>}
      </Card>}
    </div>
  )
}
// ── AdminBookingDetail ($e) ───────────────────────────────────────────────────
export function AdminBookingDetail() {
  const { id } = useParams<{ id: string }>(), primary = resolvePrimary(useStore(s => s.settings))
  const [booking, setBooking] = useState<Record<string, unknown> | null>(null)
  const [loading, setLoading] = useState(true), [status, setStatus] = useState('')
  const [msg, setMsg] = useState(''), [msgBusy, setMsgBusy] = useState(false)
  const [showQuote, setShowQuote] = useState(false)
  const [qForm, setQForm] = useState({ amount: '', notes: '', line_items: [{ description: '', amount: '' }] })
  const [qBusy, setQBusy] = useState(false), [err, setErr] = useState(''), [ok, setOk] = useState('')
  const [loadFailed, setLoadFailed] = useState(false)
  const reload = useCallback(() => {
    setLoading(true); setLoadFailed(false)
    api.get<{ booking: Record<string, unknown> }>(`admin/bookings/${id}`)
      .then(d => { setBooking(d.booking); setStatus(String(d.booking?.status || '')); setLoading(false) })
      // FIXED: previously .catch(() => setLoading(false)) left `booking`
      // null on ANY failure, and the render below said "Booking not
      // found" regardless of cause — a transient server error would
      // falsely tell staff a booking doesn't exist, when it might just be
      // a momentary hiccup. Distinguishing lets staff retry instead of
      // wrongly concluding the booking was deleted/corrupted.
      .catch(() => { setLoading(false); setLoadFailed(true) })
  }, [id])
  useEffect(() => { reload() }, [reload])
  async function updateStatus() { try { await api.put(`admin/bookings/${id}/status`, { status }); setOk('Status updated.'); reload() } catch (e: unknown) { setErr((e as { message: string }).message) } }
  async function sendMsg() { if (!msg.trim()) return; setMsgBusy(true); try { await api.post(`admin/bookings/${id}/messages`, { message: msg }); setMsg(''); reload() } catch (e: unknown) { setErr((e as { message: string }).message) }; setMsgBusy(false) }
  async function sendQuote() { if (!qForm.amount) return; setQBusy(true); try { await api.post(`admin/bookings/${id}/quote`, qForm); setShowQuote(false); setOk('Quote sent.'); reload() } catch (e: unknown) { setErr((e as { message: string }).message) }; setQBusy(false) }
  if (loading) return <Spinner />
  if (loadFailed) return <Alert type="error" message="Couldn't load this booking. Please refresh and try again." />
  if (!booking) return <Alert type="error" message="Booking not found." />
  const docs = (booking.documents || []) as Array<Record<string, unknown>>
  const messages = (booking.messages || []) as Array<Record<string, unknown>>
  const fields = (booking.field_data || {}) as Record<string, unknown>
  const STATUSES = ['submitted','under_review','quote_sent','quote_approved','in_progress','docs_requested','docs_received','processing','completed','cancelled','on_hold']
  return (
    <div>
      <div style={{ marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
        <Link to="/admin/requests" style={{ color: primary, textDecoration: 'none', fontSize: 14 }}>← Requests</Link>
        <span style={{ color: '#d1d5db' }}>/</span><span style={{ fontSize: 14, color: '#374151' }}>{String(booking.booking_ref)}</span>
      </div>
      <Alert type="error" message={err} onClose={() => setErr('')} /><Alert type="success" message={ok} onClose={() => setOk('')} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: 24 }}>
        <div>
          <Card style={{ marginBottom: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
              <div><h1 style={{ margin: '0 0 4px', fontSize: 20, fontWeight: 700 }}>{String(booking.service_name)}</h1><div style={{ fontSize: 13, color: '#9ca3af' }}>{String(booking.booking_ref)} · {String(booking.customer_name)} · {new Date(String(booking.created_at)).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}</div></div>
              <StatusBadge status={String(booking.status)} />
            </div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 16 }}>
              <select value={status} onChange={e => setStatus(e.target.value)} style={{ padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, background: '#fff', flex: 1 }}>{STATUSES.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}</select>
              <Btn onClick={updateStatus}>Update Status</Btn>
              <Btn onClick={() => setShowQuote(true)} style={{ background: '#7c3aed' }}>💬 Send Quote</Btn>
            </div>
            {booking.quoted_amount ? <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginBottom: 12 }}>
              <div style={{ background: '#EBF0F8', padding: '12px 20px', borderRadius: 8 }}><div style={{ fontSize: 12, color: '#6b7280', marginBottom: 2 }}>Quoted Amount</div><div style={{ fontSize: 22, fontWeight: 800, color: primary }}>₹{Number(booking.quoted_amount as number).toLocaleString('en-IN')}</div></div>
              <div style={{ background: '#f9fafb', padding: '12px 20px', borderRadius: 8 }}><div style={{ fontSize: 12, color: '#6b7280', marginBottom: 2 }}>Payment</div><StatusBadge status={String(booking.payment_status || 'pending')} /></div>
            </div> : null}
            {Object.keys(fields).length > 0 && <details style={{ marginTop: 8 }}>
              <summary style={{ cursor: 'pointer', fontSize: 13, fontWeight: 600, color: primary, marginBottom: 8 }}>📋 Submission Data ({Object.keys(fields).length} fields)</summary>
              <div style={{ background: '#F5F7FA', borderRadius: 8, padding: 14, marginTop: 6 }}>
                {Object.entries(fields).slice(0, 20).map(([k, v]) => <div key={k} style={{ display: 'flex', gap: 12, padding: '5px 0', borderBottom: '1px solid #f0f0f0', fontSize: 13 }}>
                  <span style={{ color: '#9ca3af', minWidth: 160, flexShrink: 0, textTransform: 'capitalize' }}>{k.replace(/_/g, ' ')}</span>
                  <span style={{ color: '#374151', fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{String(v)}</span>
                </div>)}
              </div>
            </details>}
          </Card>
          <Card>
            <h2 style={{ margin: '0 0 16px', fontSize: 17, fontWeight: 700 }}>Messages</h2>
            <div style={{ maxHeight: 380, overflowY: 'auto', marginBottom: 16, display: 'flex', flexDirection: 'column', gap: 10 }}>
              {messages.length === 0 ? <p style={{ color: '#9ca3af', fontSize: 14, textAlign: 'center', padding: 20 }}>No messages yet.</p> : messages.map(m => <div key={String(m.id)} style={{ display: 'flex', flexDirection: m.sender_type === 'staff' ? 'row-reverse' : 'row', gap: 8, alignItems: 'flex-end' }}>
                <div style={{ background: m.sender_type === 'staff' ? primary : '#f3f4f6', color: m.sender_type === 'staff' ? '#fff' : '#374151', padding: '10px 14px', borderRadius: m.sender_type === 'staff' ? '16px 16px 4px 16px' : '16px 16px 16px 4px', maxWidth: '75%', fontSize: 14 }}>
                  <div style={{ fontWeight: 700, marginBottom: 4, fontSize: 12 }}>{String(m.sender_name)}{m.sender_type === 'staff' ? ' (Staff)' : ''}</div>
                  {String(m.message)}
                  <div style={{ fontSize: 11, opacity: 0.7, marginTop: 4, textAlign: 'right' }}>{new Date(String(m.created_at)).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}</div>
                </div>
              </div>)}
            </div>
            <div style={{ display: 'flex', gap: 10 }}>
              <textarea value={msg} onChange={e => setMsg(e.target.value)} placeholder="Type reply… (Ctrl+Enter to send)" rows={2}
                style={{ flex: 1, padding: '10px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, resize: 'none', fontFamily: 'inherit' }}
                onKeyDown={e => { if (e.key === 'Enter' && e.ctrlKey) sendMsg() }} />
              <Btn onClick={sendMsg} loading={msgBusy} disabled={!msg.trim()} style={{ alignSelf: 'flex-end' }}>Send</Btn>
            </div>
          </Card>
        </div>
        <div>
          <Card style={{ marginBottom: 16 }}>
            <h3 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 700 }}>Customer</h3>
            {booking.customer_id ? <Link to={`/admin/customers/${String(booking.customer_id)}`} style={{ color: primary, fontWeight: 700, textDecoration: 'none', fontSize: 14, display: 'block', marginBottom: 4 }}>{String(booking.customer_name)} →</Link> : null}
            {booking.customer_email ? <p style={{ margin: '4px 0', fontSize: 13, color: '#6b7280' }}>{String(booking.customer_email as string)}</p> : null}
            {booking.customer_phone ? <p style={{ margin: '4px 0', fontSize: 13, color: '#6b7280' }}>{String(booking.customer_phone as string)}</p> : null}
          </Card>
          <Card>
            <h3 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 700 }}>Documents ({docs.length})</h3>
            {docs.length === 0 ? <p style={{ color: '#9ca3af', fontSize: 13 }}>No documents yet.</p> : <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {docs.map(doc => <a key={String(doc.id)} href={String(doc.file_url)} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', background: '#f9fafb', borderRadius: 6, textDecoration: 'none', color: '#374151' }}>
                <span style={{ fontSize: 18 }}>{String(doc.mime_type).includes('pdf') ? '📄' : '🖼️'}</span>
                <div style={{ flex: 1, minWidth: 0 }}><div style={{ fontSize: 13, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{String(doc.doc_type)}</div><div style={{ fontSize: 11, color: '#9ca3af' }}>{String(doc.file_name || '').substring(0, 30)}</div></div>
                <span style={{ fontSize: 16, color: primary }}>↓</span>
              </a>)}
            </div>}
          </Card>
        </div>
      </div>
      {showQuote && <div style={{ position: 'fixed', inset: 0, zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(0,0,0,.5)', padding: 16 }} onClick={e => e.target === e.currentTarget && setShowQuote(false)}>
        <div style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 560, overflow: 'auto', maxHeight: '90vh' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', padding: '20px 24px', borderBottom: '1px solid #e5e7eb' }}><h2 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Send Quote</h2><button onClick={() => setShowQuote(false)} style={{ background: 'none', border: 'none', fontSize: 24, cursor: 'pointer', color: '#6b7280' }}>×</button></div>
          <div style={{ padding: 24 }}>
            <FormInput label="Total Amount (₹) *" value={qForm.amount} onChange={e => setQForm(f => ({ ...f, amount: e.target.value }))} type="number" placeholder="5000" required />
            <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 8 }}>Line Items (optional)</label>
            {qForm.line_items.map((item, i) => <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
              <input value={item.description} onChange={e => setQForm(f => ({ ...f, line_items: f.line_items.map((x, j) => j === i ? { ...x, description: e.target.value } : x) }))} placeholder="Description" style={{ flex: 1, padding: '7px 10px', border: '1px solid #d1d5db', borderRadius: 7, fontSize: 13 }} />
              <input value={item.amount} onChange={e => setQForm(f => ({ ...f, line_items: f.line_items.map((x, j) => j === i ? { ...x, amount: e.target.value } : x) }))} placeholder="Amount" type="number" style={{ width: 100, padding: '7px 10px', border: '1px solid #d1d5db', borderRadius: 7, fontSize: 13 }} />
              <button onClick={() => setQForm(f => ({ ...f, line_items: f.line_items.filter((_, j) => j !== i) }))} style={{ background: 'none', border: 'none', color: '#ef4444', fontSize: 18, cursor: 'pointer', flexShrink: 0 }}>×</button>
            </div>)}
            <button onClick={() => setQForm(f => ({ ...f, line_items: [...f.line_items, { description: '', amount: '' }] }))} style={{ fontSize: 13, color: '#4A6FA5', background: 'none', border: '1px dashed #4A6FA5', padding: '6px 14px', borderRadius: 7, cursor: 'pointer', marginBottom: 14 }}>+ Add Line Item</button>
            <Textarea label="Quote Notes" value={qForm.notes} onChange={e => setQForm(f => ({ ...f, notes: e.target.value }))} rows={3} placeholder="Breakdown, inclusions, terms…" />
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}><Btn variant="ghost" onClick={() => setShowQuote(false)}>Cancel</Btn><Btn onClick={sendQuote} loading={qBusy} disabled={!qForm.amount}>Send Quote</Btn></div>
          </div>
        </div>
      </div>}
    </div>
  )
}
// ── AdminPayments (Ne) ────────────────────────────────────────────────────────
export function AdminPayments() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const [data, setData] = useState<{ rows: Record<string, unknown>[] }>({ rows: [] })
  const [loading, setLoading] = useState(true), [search, setSearch] = useState(''), [status, setStatus] = useState('pending')
  const [verifying, setVerifying] = useState<number | null>(null), [err, setErr] = useState(''), [ok, setOk] = useState('')
  const load = useCallback(() => {
    const qs = new URLSearchParams({ per_page: '50', status, ...(search && { search }) })
    api.get<{ rows: Record<string, unknown>[] }>(`admin/payments?${qs}`).then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false))
  }, [search, status])
  useEffect(() => { const t = setTimeout(load, 300); return () => clearTimeout(t) }, [load])
  async function verify(id: number) {
    setVerifying(id); try { await api.post(`admin/payments/${id}/verify`, {}); setOk('Payment verified.'); load() } catch (e: unknown) { setErr((e as { message: string }).message) }; setVerifying(null)
  }
  // ADDED: admin/payments/{id}/reject genuinely exists on the backend
  // (already fixed earlier this session — checks its own write result,
  // sets status='failed' with the given reason) but this screen provided
  // NO way to call it at all. A customer submitting a fake or incorrect
  // bank transfer reference had no resolution path — the payment would
  // sit in "pending" forever, since staff had only a Verify button, never
  // a Reject one.
  async function reject(id: number) {
    const reason = window.prompt('Reason for rejecting this payment (shown internally, optional):')
    if (reason === null) return // user cancelled the prompt
    setVerifying(id); try { await api.post(`admin/payments/${id}/reject`, { reason }); setOk('Payment rejected.'); load() } catch (e: unknown) { setErr((e as { message: string }).message) }; setVerifying(null)
  }
  // ADDED: admin/payments/{id}/refund — this is what actually activates
  // the 'refunded' status. Confirmed earlier this session that no code
  // path anywhere ever set a payment/booking to 'refunded', despite it
  // being a real value in both ENUMs and a real tab in the status filter
  // above — selecting "Refunded" always silently showed nothing. Follows
  // the same staff-attestation pattern as Verify/Reject (records that a
  // refund was processed externally, e.g. bank transfer reversal or
  // Razorpay dashboard action — this system doesn't call any live
  // payment-gateway refund API, consistent with how Verify doesn't call
  // any bank API either).
  async function refund(id: number) {
    const reason = window.prompt('Reason for this refund (shown to the customer, optional):')
    if (reason === null) return
    if (!confirm('Confirm this payment has actually been refunded externally (bank transfer reversal, Razorpay dashboard, etc.)? This only records that fact here — it does not itself move any money.')) return
    setVerifying(id); try { await api.post(`admin/payments/${id}/refund`, { reason }); setOk('Payment marked as refunded.'); load() } catch (e: unknown) { setErr((e as { message: string }).message) }; setVerifying(null)
  }
  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div><h1 style={{ fontSize: 22, fontWeight: 800, color: '#1E2D40', margin: '0 0 4px' }}>Payment Verification</h1><p style={{ color: '#666', fontSize: 14, margin: 0 }}>Verify bank transfers submitted by customers</p></div>
        <button onClick={() => exportCSV('admin/export/payments')} style={{ background: '#f0fdf4', color: '#15803d', border: '1px solid #86efac', padding: '8px 16px', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>⬇ Export CSV</button>
      </div>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16, flexWrap: 'wrap' }}>
        <input type="search" placeholder="Search by UTR / booking ref / email…" value={search} onChange={e => setSearch(e.target.value)} style={{ flex: 1, minWidth: 220, padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14 }} />
        <select value={status} onChange={e => setStatus(e.target.value)} style={{ padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, background: '#fff' }}>
          {['', 'pending', 'verified', 'failed', 'refunded'].map(s => <option key={s} value={s}>{s || 'All Statuses'}</option>)}
        </select>
      </div>
      <Alert type="error" message={err} onClose={() => setErr('')} /><Alert type="success" message={ok} onClose={() => setOk('')} />
      {loading ? <Spinner /> : <Card style={{ padding: 0, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
            <thead><tr style={{ background: '#f9fafb' }}>{['Booking', 'Customer', 'Amount', 'Method', 'UTR/Ref', 'Date', 'Action'].map(h => <th key={h} style={{ padding: '12px 14px', fontWeight: 600, color: '#374151', borderBottom: '1px solid #e5e7eb', textAlign: 'left', whiteSpace: 'nowrap' }}>{h}</th>)}</tr></thead>
            <tbody>{data.rows.length === 0 ? <tr><td colSpan={7} style={{ padding: 32, textAlign: 'center', color: '#9ca3af' }}>No pending payments</td></tr> : data.rows.map(r => (
              <tr key={String(r.id)} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '12px 14px' }}><Link to={`/admin/bookings/${r.booking_id}`} style={{ color: primary, fontWeight: 600 }}>{String(r.booking_ref)}</Link></td>
                <td style={{ padding: '12px 14px', color: '#374151' }}>{String(r.customer_name)}</td>
                <td style={{ padding: '12px 14px', fontWeight: 700 }}>₹{Number(r.amount).toLocaleString('en-IN')}</td>
                <td style={{ padding: '12px 14px', color: '#6b7280' }}>{String(r.method || '').replace(/_/g, ' ')}</td>
                <td style={{ padding: '12px 14px', color: '#374151', fontFamily: 'monospace' }}>{String(r.payment_ref || '—')}</td>
                <td style={{ padding: '12px 14px', color: '#9ca3af' }}>{new Date(String(r.created_at)).toLocaleDateString('en-IN')}</td>
                <td style={{ padding: '12px 14px' }}><div style={{ display: 'flex', gap: 6 }}>
                  {r.status === 'pending' && <>
                    <Btn onClick={() => verify(Number(r.id))} loading={verifying === Number(r.id)} style={{ padding: '6px 14px', fontSize: 13 }}>✓ Verify</Btn>
                    <button onClick={() => reject(Number(r.id))} disabled={verifying === Number(r.id)} style={{ padding: '6px 14px', fontSize: 13, border: '1px solid #ef4444', borderRadius: 8, color: '#ef4444', background: 'transparent', cursor: 'pointer', fontWeight: 600 }}>✕ Reject</button>
                  </>}
                  {r.status === 'verified' && (
                    <button onClick={() => refund(Number(r.id))} disabled={verifying === Number(r.id)} style={{ padding: '6px 14px', fontSize: 13, border: '1px solid #f59e0b', borderRadius: 8, color: '#b45309', background: 'transparent', cursor: 'pointer', fontWeight: 600 }}>↩ Refund</button>
                  )}
                  {r.status !== 'pending' && r.status !== 'verified' && <span style={{ color: '#9ca3af', fontSize: 12 }}>—</span>}
                </div></td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      </Card>}
    </div>
  )
}
// ── AdminSettings (Te) ────────────────────────────────────────────────────────
export function AdminSettings() {
  const [settings, setSettings] = useState<Record<string, string>>({}), [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false), [err, setErr] = useState(''), [ok, setOk] = useState('')
  useEffect(() => { api.get<{ settings: Record<string, unknown> }>('admin/settings').then(d => { const flat: Record<string, string> = {}; for (const [k, v] of Object.entries(d.settings || {})) flat[k] = String((v as Record<string, unknown>)?.value ?? v); setSettings(flat); setLoading(false) }).catch(() => setLoading(false)) }, [])
  async function save() { setSaving(true); setErr(''); setOk(''); try { await api.put('admin/settings', settings); setOk('Settings saved.') } catch (e: unknown) { setErr((e as { message: string }).message) }; setSaving(false) }
  const sections = [
    { title: '🏷️ Brand', fields: [['platform_name','Platform Name'],['platform_tagline','Tagline'],['platform_email','Email'],['platform_phone','Phone'],['platform_whatsapp','WhatsApp Number'],['platform_logo_url','Logo URL'],['platform_address','Address']] },
    { title: '🎨 Appearance', fields: [['primary_color','Primary Color (hex)'],['accent_color','Accent Color (hex)']] },
    { title: '🏦 Bank Transfer', fields: [['bank_name','Bank Name'],['bank_account_name','Account Name'],['bank_account_number','Account Number'],['bank_ifsc','IFSC Code'],['bank_upi','UPI ID']] },
    { title: '🔌 Razorpay (Optional)', fields: [['razorpay_enabled','Enabled (0/1)'],['razorpay_key_id','Key ID'],['razorpay_key_secret','Key Secret (keep private)'],['razorpay_webhook_secret','Webhook Secret']] },
    // FIXED (was previously missing entirely): this codebase has 4 real,
    // wired-up backend trigger points (Bootstrap.php:127-149 — booking
    // submitted, status changed, payment verified, quote approved) that
    // send WhatsApp notifications via CallMeBot — but the two settings
    // keys they depend on (whatsapp_callmebot_apikey,
    // whatsapp_notify_numbers — traced in WhatsAppService.php:32 and :93)
    // did not appear ANYWHERE in the admin UI. Every one of those 4
    // triggers has been silently no-op-ing since there was no way for an
    // admin to ever configure the required API key. This section is the
    // fix — same FormInput pattern as every other section above.
    // NOTE: whatsapp_notify_numbers is read server-side via explode("\n",
    // ...) to support multiple recipient numbers, but this is a plain
    // single-line input (matching every other field in this settings
    // page — none of them support multi-line), so only one number can be
    // entered through this field. That's still a complete fix for the
    // "notifications never fire at all" bug — multi-recipient support
    // would need a textarea variant added to this settings page's field
    // renderer, a separate, non-blocking enhancement.
    { title: '📱 WhatsApp Notifications (Optional)', fields: [
      ['whatsapp_callmebot_apikey','CallMeBot API Key'],
      ['whatsapp_notify_numbers','Notify Number (E.164, e.g. 919876543210)'],
    ] },
    { title: '📧 Email', fields: [['email_from','From Email'],['email_from_name','From Name']] },
    { title: '📋 Quotes', fields: [['quote_validity_days','Default validity (days)'],['quote_reminder_days','Reminder before expiry (days)']] },
    { title: '🔍 SEO & Verification', fields: [['seo_title','Default Page Title'],['seo_description','Default Meta Description'],['google_site_verification','Google Search Console Verification Code'],['facebook_pixel_id','Facebook Pixel ID'],['google_analytics_id','Google Analytics ID (G-XXXXXXX)']] },
  ]
  if (loading) return <Spinner />
  return (
    <div>
      <PageHeader title="Platform Settings" action={<Btn onClick={save} loading={saving}>Save All Changes</Btn>} />
      <Alert type="error" message={err} onClose={() => setErr('')} /><Alert type="success" message={ok} onClose={() => setOk('')} />
      <div style={{ background: '#e0f2fe', border: '1px solid #7dd3fc', borderRadius: 10, padding: '12px 16px', marginBottom: 16, fontSize: 14, color: '#1E2D40', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
        <span><strong>Visual settings</strong> (hero text, banners, stats, colors) are managed in the Homepage Builder.</span>
        <Link to="/admin/homepage" style={{ background: '#1E2D40', color: '#fff', padding: '6px 14px', borderRadius: 7, textDecoration: 'none', fontWeight: 700, fontSize: 13 }}>→ Homepage Builder</Link>
      </div>
      <div style={{ maxWidth: 700, display: 'flex', flexDirection: 'column', gap: 20 }}>
        {sections.map(({ title, fields }) => <Card key={title}>
          <h3 style={{ margin: '0 0 16px', fontSize: 16, fontWeight: 700 }}>{title}</h3>
          {fields.map(([key, label]) => {
            // FIXED: these three fields are explicitly labeled "keep
            // private" / are secret API credentials, but FormInput
            // defaults to type='text' and none of these calls overrode
            // it — they were rendered as plain, visible text in the
            // browser. Masked the specific known-sensitive keys.
            const isSecret = ['razorpay_key_secret','razorpay_webhook_secret','whatsapp_callmebot_apikey'].includes(key)
            // FIXED: razorpay_enabled was a free-text field labeled
            // "Enabled (0/1)" with zero validation. The frontend's
            // actual gate (src/pages/customer/index.tsx) checks
            // settings.razorpay_enabled === '1' with a STRICT string
            // comparison — an admin typing "yes", "true", "on", or even
            // "1 " (trailing space) would be silently treated as
            // disabled, with no error and no indication why the
            // "Pay Online via Razorpay" button never appears on the
            // customer side. A checkbox removes this entire class of
            // silent misconfiguration — it can only ever write the
            // exact '1' or '0' strings both sides already expect.
            if (key === 'razorpay_enabled') {
              const checked = settings[key] === '1'
              return (
                <label key={key} style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, cursor: 'pointer', fontSize: 14, fontWeight: 600, color: '#374151' }}>
                  <input type="checkbox" checked={checked} onChange={e => setSettings(s => ({ ...s, [key]: e.target.checked ? '1' : '0' }))} style={{ width: 18, height: 18 }} />
                  Enable Razorpay online payments
                </label>
              )
            }
            return <FormInput key={key} type={isSecret?'password':'text'} label={label} value={settings[key] || ''} onChange={e => setSettings(s => ({ ...s, [key]: e.target.value }))} />
          })}
        </Card>)}
      </div>
    </div>
  )
}
// ── AdminCustomers (Ae) ───────────────────────────────────────────────────────
export function AdminCustomers() {
  const primary = resolvePrimary(useStore(s => s.settings)), nav = useNavigate()
  const [data, setData] = useState<{ rows: Record<string, unknown>[]; total: number }>({ rows: [], total: 0 })
  const [loading, setLoading] = useState(true), [search, setSearch] = useState('')
  const load = useCallback(() => { const qs = new URLSearchParams({ per_page: '50', ...(search && { search }) }); api.get<{ rows: Record<string, unknown>[]; total: number }>(`admin/customers?${qs}`).then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false)) }, [search])
  useEffect(() => { const t = setTimeout(load, 300); return () => clearTimeout(t) }, [load])
  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div><h1 style={{ fontSize: 22, fontWeight: 800, color: '#1E2D40', margin: '0 0 4px' }}>Customers</h1><p style={{ color: '#666', fontSize: 14, margin: 0 }}>{data.total} total customers</p></div>
        <button onClick={() => exportCSV('admin/export/customers')} style={{ background: '#f0fdf4', color: '#15803d', border: '1px solid #86efac', padding: '8px 16px', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>⬇ Export CSV</button>
      </div>
      <div style={{ marginBottom: 16 }}><input type="search" placeholder="Search by name, email, phone…" value={search} onChange={e => setSearch(e.target.value)} style={{ width: '100%', maxWidth: 400, padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14 }} /></div>
      {loading ? <Spinner /> : <Card style={{ padding: 0, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
            <thead><tr style={{ background: '#f9fafb' }}>{['Name','Email','Country','Phone','Bookings','Total Paid',''].map(h => <th key={h} style={{ padding: '12px 14px', fontWeight: 600, color: '#374151', borderBottom: '1px solid #e5e7eb', textAlign: 'left' }}>{h}</th>)}</tr></thead>
            <tbody>{data.rows.map(c => <tr key={String(c.id)} style={{ borderBottom: '1px solid #f3f4f6', cursor: 'pointer' }} onClick={() => nav(`/admin/customers/${c.id}`)}>
              <td style={{ padding: '12px 14px', fontWeight: 600, color: '#111827' }}>{String(c.name)}</td>
              <td style={{ padding: '12px 14px', color: '#374151' }}>{String(c.email)}</td>
              <td style={{ padding: '12px 14px', color: '#6b7280' }}>{String(c.country || '—')}</td>
              <td style={{ padding: '12px 14px', color: '#6b7280' }}>{String(c.phone || '—')}</td>
              <td style={{ padding: '12px 14px', textAlign: 'center' }}>{String(c.booking_count || 0)}</td>
              <td style={{ padding: '12px 14px', fontWeight: 600, color: '#15803d' }}>{c.total_paid ? `₹${Number(c.total_paid).toLocaleString('en-IN')}` : '—'}</td>
              <td style={{ padding: '12px 14px' }} onClick={e => e.stopPropagation()}><div style={{ display: 'flex', gap: 6 }}>
                <span style={{ color: primary, fontSize: 16, cursor: 'pointer' }} onClick={() => nav(`/admin/customers/${c.id}`)}>→</span>
                <button onClick={async e => { e.stopPropagation(); if (!confirm(`${c.is_disabled ? 'Enable' : 'Disable'} ${c.name}?`)) return; try { await api.post(`admin/customers/${c.id}/${c.is_disabled ? 'enable' : 'disable'}`, {}); load() } catch (err: unknown) { alert((err as { message: string }).message) } }} style={{ fontSize: 11, padding: '3px 8px', border: `1px solid ${c.is_disabled ? '#16a34a' : '#ef4444'}`, borderRadius: 5, color: c.is_disabled ? '#16a34a' : '#ef4444', background: 'transparent', cursor: 'pointer', fontWeight: 600 }}>{c.is_disabled ? 'Enable' : 'Disable'}</button>
              </div></td>
            </tr>)}</tbody>
          </table>
        </div>
      </Card>}
    </div>
  )
}
// ── AdminCustomerDetail (Oe) ──────────────────────────────────────────────────
export function AdminCustomerDetail() {
  const { id } = useParams<{ id: string }>(), primary = resolvePrimary(useStore(s => s.settings))
  const [data, setData] = useState<Record<string, unknown> | null>(null), [loading, setLoading] = useState(true)
  const [err, setErr] = useState(''), [ok, setOk] = useState('')
  const [loadFailed, setLoadFailed] = useState(false)
  const reload = useCallback(() => {
    setLoading(true); setLoadFailed(false)
    api.get<Record<string, unknown>>(`admin/customers/${id}`)
      .then(d => { setData(d); setLoading(false) })
      // FIXED: same "not found" vs "failed to load" conflation fixed for
      // AdminBookingDetail this session — a transient server error
      // previously told staff "Customer not found" (an affirmative false
      // claim), rather than distinguishing a genuine 404 from a load
      // failure that just needs a retry.
      .catch((e: unknown) => {
        setLoading(false)
        const status = (e as { status?: number })?.status
        if (status === 404) { setErr('Customer not found.') } else { setLoadFailed(true) }
      })
  }, [id])
  useEffect(() => { reload() }, [reload])
  if (loading) return <Spinner />
  if (loadFailed) return <Alert type="error" message="Couldn't load this customer. Please refresh and try again." />
  if (!data) return <Alert type="error" message={err} />
  const c = (data.customer || {}) as Record<string, unknown>
  const bookings = (data.bookings || []) as Array<Record<string, unknown>>
  const stats = (data.stats || {}) as Record<string, unknown>
  return (
    <div>
      <div style={{ marginBottom: 16, display: 'flex', gap: 8, alignItems: 'center' }}><Link to="/admin/customers" style={{ color: primary, textDecoration: 'none', fontSize: 14 }}>← Customers</Link><span>/</span><span style={{ fontSize: 14, color: '#374151' }}>{String(c.name)}</span></div>
      <Alert type="error" message={err} onClose={() => setErr('')} /><Alert type="success" message={ok} onClose={() => setOk('')} />
      <div style={{ display: 'grid', gridTemplateColumns: '320px 1fr', gap: 24 }}>
        <div>
          <Card style={{ marginBottom: 16 }}>
            <div style={{ textAlign: 'center', marginBottom: 16 }}>
              <div style={{ width: 64, height: 64, background: `${primary}20`, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 28, margin: '0 auto 10px' }}>👤</div>
              <h2 style={{ margin: '0 0 4px', fontSize: 18, fontWeight: 700 }}>{String(c.name)}</h2>
              <div style={{ fontSize: 14, color: '#9ca3af' }}>{String(c.email)}</div>
              {Boolean(c.is_disabled) && <div style={{ marginTop: 8 }}><StatusBadge status="cancelled" label="Account Disabled" /></div>}
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 14 }}>
              {[['Phone',c.phone],['WhatsApp',c.whatsapp],['Country',c.country],['City Abroad',c.city_abroad],['City India',c.city_india]].filter(([,v])=>v).map(([k,v])=><div key={String(k)} style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: '#9ca3af' }}>{String(k)}</span><span style={{ fontWeight: 500, color: '#374151' }}>{String(v)}</span></div>)}
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span style={{ color: '#9ca3af' }}>Joined</span><span style={{ fontWeight: 500 }}>{new Date(String(c.created_at || '')).toLocaleDateString('en-IN')}</span></div>
            </div>
          </Card>
          <Card style={{ marginBottom: 16 }}>
            <h3 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700 }}>Activity Summary</h3>
            {[['Total Bookings',String(stats.total_bookings||0)],['Completed',String(stats.completed||0)],['Total Paid',`₹${Number(stats.total_paid||0).toLocaleString('en-IN')}`]].map(([k,v])=><div key={k} style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 14 }}><span style={{ color: '#6b7280' }}>{k}</span><span style={{ fontWeight: 700 }}>{v}</span></div>)}
          </Card>
          <Card>{c.is_disabled
            ? <Btn onClick={async()=>{try{await api.post(`admin/customers/${id}/enable`,{});setOk('Account enabled.');reload()}catch(e:unknown){setErr((e as{message:string}).message)}}} style={{width:'100%',justifyContent:'center'}}>Enable Account</Btn>
            : <Btn variant="danger" onClick={async()=>{if(!confirm(`Disable ${c.name}?`))return;try{await api.post(`admin/customers/${id}/disable`,{});setOk('Account disabled.');reload()}catch(e:unknown){setErr((e as{message:string}).message)}}} style={{width:'100%',justifyContent:'center'}}>Disable Account</Btn>}
          </Card>
        </div>
        <Card>
          <h3 style={{ margin: '0 0 16px', fontSize: 16, fontWeight: 700 }}>Booking History ({bookings.length})</h3>
          {bookings.length === 0 ? <Empty icon="📋" title="No bookings" description="This customer has no bookings yet." /> : <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
              <thead><tr style={{ background: '#f9fafb' }}>{['Ref','Service','Status','Amount','Date'].map(h=><th key={h} style={{ padding: '10px 12px', fontWeight: 600, color: '#374151', borderBottom: '1px solid #e5e7eb', textAlign: 'left' }}>{h}</th>)}</tr></thead>
              <tbody>{bookings.map(b=><tr key={String(b.id)} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '10px 12px' }}><Link to={`/admin/bookings/${b.id}`} style={{ color: primary, fontWeight: 600 }}>{String(b.booking_ref)}</Link></td>
                <td style={{ padding: '10px 12px', color: '#374151' }}>{String(b.service_name)}</td>
                <td style={{ padding: '10px 12px' }}><StatusBadge status={String(b.status)} /></td>
                <td style={{ padding: '10px 12px', fontWeight: 600 }}>{b.quoted_amount?`₹${Number(b.quoted_amount).toLocaleString('en-IN')}`:'—'}</td>
                <td style={{ padding: '10px 12px', color: '#9ca3af' }}>{new Date(String(b.created_at)).toLocaleDateString('en-IN')}</td>
              </tr>)}</tbody>
            </table>
          </div>}
        </Card>
      </div>
    </div>
  )
}
// ── AdminStaff (De) ───────────────────────────────────────────────────────────
export function AdminStaff() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const [staff, setStaff] = useState<Record<string, unknown>[]>([]), [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false), [form, setForm] = useState({ email: '', name: '', s2nri_role: 'agent', phone: '' })
  const [saving, setSaving] = useState(false), [err, setErr] = useState(''), [ok, setOk] = useState('')
  const load = () => { api.get<{ staff: Record<string, unknown>[] }>('admin/staff').then(d => { setStaff(d.staff || []); setLoading(false) }).catch(() => setLoading(false)) }
  useEffect(() => { load() }, [])
  async function addStaff() {
    if (!form.email || !form.name) { setErr('Name and email are required.'); return }
    setSaving(true); setErr(''); try { await api.post('admin/staff', form); setShowModal(false); setForm({ email: '', name: '', s2nri_role: 'agent', phone: '' }); setOk('Staff member added successfully.'); load() } catch (e: unknown) { setErr((e as { message: string }).message) }; setSaving(false)
  }
  async function toggle(id: number, isActive: boolean) { try { await api.put(`admin/staff/${id}`, { is_active: isActive ? 0 : 1 }); setOk(`Staff member ${isActive ? 'deactivated' : 'activated'}.`); load() } catch (e: unknown) { setErr((e as { message: string }).message) } }
  const ROLES = [{ value: 'agent', label: 'Agent — handles bookings, messages, docs' }, { value: 'manager', label: 'Manager — full access except super-admin' }, { value: 'finance', label: 'Finance — payment verification only' }]
  return (
    <div>
      <PageHeader title="Staff Management" subtitle={`${staff.length} team members`} action={<Btn onClick={() => setShowModal(true)}>+ Add Staff</Btn>} />
      <Alert type="error" message={err} onClose={() => setErr('')} /><Alert type="success" message={ok} onClose={() => setOk('')} />
      {loading ? <Spinner /> : staff.length === 0 ? <Empty icon="👔" title="No staff members" description="Add team members to handle bookings and customer requests." action={<Btn onClick={() => setShowModal(true)}>Add First Staff Member</Btn>} /> : (
        <Card style={{ padding: 0, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
            <thead><tr style={{ background: '#f9fafb' }}>{['Name','Email','Role','Phone','Status','Joined','Action'].map(h => <th key={h} style={{ padding: '12px 16px', fontWeight: 600, color: '#374151', borderBottom: '1px solid #e5e7eb', textAlign: 'left' }}>{h}</th>)}</tr></thead>
            <tbody>{staff.map(s => <tr key={String(s.id)} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <td style={{ padding: '12px 16px', fontWeight: 600 }}>{String(s.name || s.display_name)}</td>
              <td style={{ padding: '12px 16px', color: '#6b7280' }}>{String(s.email)}</td>
              <td style={{ padding: '12px 16px' }}><span style={{ background: `${primary}15`, color: primary, padding: '3px 10px', borderRadius: 99, fontSize: 12, fontWeight: 600 }}>{String(s.s2nri_role)}</span></td>
              <td style={{ padding: '12px 16px', color: '#6b7280' }}>{String(s.phone || '—')}</td>
              <td style={{ padding: '12px 16px' }}><StatusBadge status={s.is_active ? 'verified' : 'cancelled'} label={s.is_active ? 'Active' : 'Inactive'} /></td>
              <td style={{ padding: '12px 16px', color: '#9ca3af' }}>{new Date(String(s.created_at)).toLocaleDateString('en-IN')}</td>
              <td style={{ padding: '12px 16px' }}><Btn variant={s.is_active ? 'ghost' : 'secondary'} onClick={() => toggle(Number(s.id), Boolean(s.is_active))} style={{ padding: '5px 12px', fontSize: 12 }}>{s.is_active ? 'Deactivate' : 'Activate'}</Btn></td>
            </tr>)}</tbody>
          </table>
        </Card>
      )}
      {showModal && <div style={{ position: 'fixed', inset: 0, zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(0,0,0,.5)', padding: 16 }} onClick={e => e.target === e.currentTarget && setShowModal(false)}>
        <div style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 480 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', padding: '20px 24px', borderBottom: '1px solid #e5e7eb' }}><h2 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Add Staff Member</h2><button onClick={() => setShowModal(false)} style={{ background: 'none', border: 'none', fontSize: 24, cursor: 'pointer', color: '#6b7280' }}>×</button></div>
          <div style={{ padding: 24 }}>
            <p style={{ margin: '0 0 16px', color: '#6b7280', fontSize: 14 }}>A WordPress account will be created. They can log in via OTP or set a password.</p>
            <Alert type="error" message={err} onClose={() => setErr('')} />
            <FormInput label="Full Name" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} required placeholder="Priya Sharma" />
            <FormInput label="Email Address" type="email" value={form.email} onChange={e => setForm(f => ({ ...f, email: e.target.value }))} required placeholder="priya@example.com" />
            <FormInput label="Phone (optional)" type="tel" value={form.phone} onChange={e => setForm(f => ({ ...f, phone: e.target.value }))} placeholder="+91 98765 43210" />
            <div style={{ marginBottom: 16 }}>
              <label style={{ display: 'block', fontWeight: 600, marginBottom: 6, fontSize: 14, color: '#374151' }}>Role <span style={{ color: '#dc2626' }}>*</span></label>
              <select value={form.s2nri_role} onChange={e => setForm(f => ({ ...f, s2nri_role: e.target.value }))} style={{ width: '100%', padding: '10px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, background: '#fff' }}>{ROLES.map(r => <option key={r.value} value={r.value}>{r.label}</option>)}</select>
            </div>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}><Btn variant="ghost" onClick={() => { setShowModal(false); setErr('') }}>Cancel</Btn><Btn onClick={addStaff} loading={saving}>Add Staff Member</Btn></div>
          </div>
        </div>
      </div>}
    </div>
  )
}
// ── AdminReviews (Fe) ─────────────────────────────────────────────────────────
export function AdminReviews() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const [data, setData] = useState<{ rows: Record<string, unknown>[]; total: number }>({ rows: [], total: 0 })
  const [loading, setLoading] = useState(true), [status, setStatus] = useState('pending'), [err, setErr] = useState(''), [ok, setOk] = useState('')
  const load = useCallback(() => { api.get<{ rows: Record<string, unknown>[]; total: number }>(`admin/reviews?status=${status}&per_page=50`).then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false)) }, [status])
  useEffect(() => { load() }, [load])
  async function publish(id: number) { try { await api.patch(`admin/reviews/${id}/publish`, {}); setOk('Review published.'); load() } catch (e: unknown) { setErr((e as { message: string }).message) } }
  async function reject(id: number) { if (!confirm('Reject and hide this review?')) return; try { await api.patch(`admin/reviews/${id}/reject`, {}); setOk('Review rejected.'); load() } catch (e: unknown) { setErr((e as { message: string }).message) } }
  return (
    <div>
      <PageHeader title="Reviews" subtitle={`${data.total} total`} />
      <Alert type="error" message={err} onClose={() => setErr('')} /><Alert type="success" message={ok} onClose={() => setOk('')} />
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>{['pending','published','rejected'].map(s => <button key={s} onClick={() => setStatus(s)} style={{ padding: '7px 16px', border: `1px solid ${status===s?primary:'#e5e7eb'}`, borderRadius: 99, background: status===s?`${primary}15`:'#fff', color: status===s?primary:'#374151', fontWeight: status===s?700:500, cursor: 'pointer', fontSize: 13, textTransform: 'capitalize' }}>{s}</button>)}</div>
      {loading ? <Spinner /> : data.rows.length === 0 ? <Empty icon="⭐" title={`No ${status} reviews`} description="Reviews appear here after customers complete a booking." /> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {data.rows.map(r => <Card key={String(r.id)}>
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
                  <span style={{ fontSize: 18, color: '#f59e0b', letterSpacing: 2 }}>{'★'.repeat(Number(r.rating))}{'☆'.repeat(5-Number(r.rating))}</span>
                  <span style={{ fontWeight: 700, color: '#111827' }}>{String(r.customer_name)}</span>
                  <span style={{ color: '#9ca3af', fontSize: 13 }}>for {String(r.service_name)}</span>
                  <StatusBadge status={String(r.status)} />
                </div>
                {r.review_text ? <p style={{ margin: '0 0 6px', color: '#374151', fontSize: 14, lineHeight: 1.6 }}>{String(r.review_text)}</p> : null}
                <div style={{ fontSize: 12, color: '#9ca3af' }}>{new Date(String(r.created_at)).toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })}</div>
              </div>
              {status === 'pending' && <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}><Btn onClick={() => publish(Number(r.id))} style={{ padding: '6px 14px', fontSize: 13 }}>✓ Publish</Btn><Btn variant="ghost" onClick={() => reject(Number(r.id))} style={{ padding: '6px 14px', fontSize: 13 }}>✕ Reject</Btn></div>}
              {status === 'published' && <Btn variant="ghost" onClick={() => reject(Number(r.id))} style={{ padding: '6px 14px', fontSize: 13 }}>Unpublish</Btn>}
            </div>
          </Card>)}
        </div>
      )}
    </div>
  )
}
// ── AdminTickets (Ee) ─────────────────────────────────────────────────────────
export function AdminTickets() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const [data, setData] = useState<{ rows: Record<string, unknown>[] }>({ rows: [] }), [loading, setLoading] = useState(true)
  const [openId, setOpenId] = useState<number | null>(null), [detail, setDetail] = useState<Record<string, unknown> | null>(null)
  const [reply, setReply] = useState(''), [replying, setReplying] = useState(false)
  const [sFilter, setSFilter] = useState('open'), [err, setErr] = useState('')
  const load = useCallback(() => { api.get<{ rows: Record<string, unknown>[] }>(`admin/tickets?status=${sFilter}&per_page=50`).then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false)) }, [sFilter])
  useEffect(() => { load() }, [load])
  function openTicket(id: number) { if (openId === id) { setOpenId(null); setDetail(null); return }; setOpenId(id); api.get<{ ticket: Record<string, unknown> }>(`admin/tickets/${id}`).then(d => setDetail(d.ticket)).catch(() => {}) }
  async function sendReply() { if (!reply.trim()) return; setReplying(true); try { await api.post(`admin/tickets/${openId}/messages`, { message: reply }); setReply(''); api.get<{ ticket: Record<string, unknown> }>(`admin/tickets/${openId}`).then(d => setDetail(d.ticket)).catch(() => {}) } catch (e: unknown) { setErr((e as { message: string }).message) }; setReplying(false) }
  async function changeStatus(id: number, s: string) { try { await api.patch(`admin/tickets/${id}/status`, { status: s }); load(); if (openId === id) setOpenId(null) } catch (e: unknown) { setErr((e as { message: string }).message) } }
  return (
    <div>
      <PageHeader title="Support Tickets" subtitle={`${data.rows.length} ${sFilter}`} />
      <Alert type="error" message={err} onClose={() => setErr('')} />
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>{['open','in_progress','resolved','closed'].map(s => <button key={s} onClick={() => { setSFilter(s); setOpenId(null) }} style={{ padding: '7px 16px', border: `1px solid ${sFilter===s?primary:'#e5e7eb'}`, borderRadius: 99, background: sFilter===s?`${primary}15`:'#fff', color: sFilter===s?primary:'#374151', fontWeight: sFilter===s?700:500, cursor: 'pointer', fontSize: 13 }}>{s.replace('_',' ')}</button>)}</div>
      {loading ? <Spinner /> : data.rows.length === 0 ? <Empty icon="🎫" title={`No ${sFilter} tickets`} description="Support tickets from customers appear here." /> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {data.rows.map(t => <Card key={String(t.id)} style={{ padding: 0, overflow: 'hidden' }}>
            <div onClick={() => openTicket(Number(t.id))} style={{ padding: '14px 16px', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', background: openId===Number(t.id)?`${primary}05`:'#fff' }}>
              <div style={{ flex: 1, minWidth: 0 }}><div style={{ fontWeight: 700, color: '#111827', marginBottom: 3 }}>{String(t.subject)}</div><div style={{ fontSize: 12, color: '#9ca3af' }}>#{String(t.id)} · {String(t.customer_name)} · {new Date(String(t.created_at)).toLocaleDateString('en-IN')}</div></div>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexShrink: 0 }}><StatusBadge status={String(t.status)} /><span style={{ color: '#9ca3af', fontSize: 16 }}>{openId===Number(t.id)?'▲':'▼'}</span></div>
            </div>
            {openId===Number(t.id) && detail && detail.id===t.id && <div style={{ padding: '0 16px 16px', borderTop: '1px solid #f3f4f6' }}>
              <div style={{ maxHeight: 300, overflowY: 'auto', margin: '12px 0', display: 'flex', flexDirection: 'column', gap: 8 }}>
                {((detail.messages||[]) as Array<Record<string, unknown>>).map(m => <div key={String(m.id)} style={{ padding: '10px 14px', background: m.sender_type==='customer'?'#EBF0F8':'#f0fdf4', borderRadius: 8, fontSize: 14 }}>
                  <div style={{ fontWeight: 700, fontSize: 12, color: m.sender_type==='customer'?primary:'#15803d', marginBottom: 4 }}>{String(m.sender_name)}{m.sender_type==='staff'?' (Staff)':''}</div>
                  {String(m.message)}<div style={{ fontSize: 11, color: '#9ca3af', marginTop: 4 }}>{new Date(String(m.created_at)).toLocaleString('en-IN')}</div>
                </div>)}
              </div>
              <div style={{ display: 'flex', gap: 8, marginBottom: 10 }}>
                <textarea value={reply} onChange={e => setReply(e.target.value)} placeholder="Type reply…" rows={2} style={{ flex: 1, padding: '10px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, resize: 'none', fontFamily: 'inherit' }} />
                <Btn onClick={sendReply} loading={replying} disabled={!reply.trim()} style={{ alignSelf: 'flex-end' }}>Reply</Btn>
              </div>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {String(t.status)!=='in_progress' && <Btn variant="secondary" onClick={() => changeStatus(Number(t.id),'in_progress')} style={{ padding: '6px 12px', fontSize: 12 }}>Mark In Progress</Btn>}
                {String(t.status)!=='resolved'    && <Btn variant="secondary" onClick={() => changeStatus(Number(t.id),'resolved')}    style={{ padding: '6px 12px', fontSize: 12 }}>Mark Resolved</Btn>}
                {String(t.status)!=='closed'      && <Btn variant="ghost"     onClick={() => changeStatus(Number(t.id),'closed')}      style={{ padding: '6px 12px', fontSize: 12 }}>Close Ticket</Btn>}
              </div>
            </div>}
          </Card>)}
        </div>
      )}
    </div>
  )
}
// ── AdminAuditLog (qe) ────────────────────────────────────────────────────────
export function AdminAuditLog() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const [data, setData] = useState<{ rows: Record<string, unknown>[]; total: number }>({ rows: [], total: 0 }), [loading, setLoading] = useState(true)
  const [filters, setFilters] = useState({ page: 1, search: '', action: '' })
  // FIXED: 6 of the previous 13 values never matched any real logAudit()
  // call anywhere in the codebase — confirmed via a full-codebase search
  // of every actual action string passed to logAudit(). 'status_updated'
  // was a genuine mismatch (the real value is 'status_changed'); the
  // other 5 ('payment_submitted', 'document_uploaded', 'otp_sent',
  // 'login', 'register') are never logged at all anywhere. Selecting any
  // of those always silently showed "No activity found" — misleading
  // staff into thinking the event never happened, when really the
  // filter itself was wrong or nothing tracks that event. Rebuilt to
  // match the real, complete set of action strings actually in use.
  const ACTION_COLORS: Record<string, string> = { booking_created: '#d0effa', booking_cancelled: '#fee2e2', booking_deleted_soft: '#fee2e2', status_changed: '#fef9c3', secondary_status_set: '#fef3c7', quote_sent: '#ede9fe', quote_approved: '#dcfce7', quote_rejected: '#fee2e2', payment_verified: '#dcfce7', payment_refunded: '#fef3c7', ticket_created: '#f3e8ff', message_sent: '#e0f2fe', media_upload: '#f0fdf4', media_delete: '#fef2f2', customer_disabled: '#fee2e2', customer_enabled: '#dcfce7', gdpr_erasure: '#fef2f2', review_published: '#dcfce7', review_rejected: '#fee2e2' }
  const ACTIONS = ['booking_created','booking_cancelled','booking_deleted_soft','status_changed','secondary_status_set','quote_sent','quote_approved','quote_rejected','payment_verified','payment_refunded','ticket_created','message_sent','media_upload','media_delete','customer_disabled','customer_enabled','gdpr_erasure','review_published','review_rejected','contact_form','faq_create','blog_create','city_created','city_updated','city_deleted','vendor_assigned','delivery_date_set']
  const load = useCallback(() => {
    setLoading(true)
    const qs = new URLSearchParams({ page: String(filters.page), per_page: '50', ...(filters.search && { search: filters.search }), ...(filters.action && { action: filters.action }) })
    api.get<{ rows: Record<string, unknown>[]; total: number }>(`admin/audit-log?${qs}`).then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false))
  }, [filters])
  useEffect(() => { load() }, [load])
  return (
    <div>
      <PageHeader title="Audit Log" subtitle="All system activity" />
      <div style={{ display: 'flex', gap: 10, marginBottom: 16, flexWrap: 'wrap' }}>
        <input type="search" placeholder="Search by user, booking ref…" value={filters.search} onChange={e => setFilters(f => ({ ...f, search: e.target.value, page: 1 }))} style={{ flex: 1, minWidth: 200, padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14 }} />
        <select value={filters.action} onChange={e => setFilters(f => ({ ...f, action: e.target.value, page: 1 }))} style={{ padding: '8px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, background: '#fff' }}>
          <option value="">All actions</option>{ACTIONS.map(a => <option key={a} value={a}>{a.replace(/_/g,' ')}</option>)}
        </select>
      </div>
      {loading ? <Spinner /> : <Card style={{ padding: 0, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead><tr style={{ background: '#f9fafb' }}>{['Time','User','Action','Booking','Detail'].map(h => <th key={h} style={{ padding: '10px 14px', fontWeight: 600, color: '#374151', borderBottom: '1px solid #e5e7eb', textAlign: 'left' }}>{h}</th>)}</tr></thead>
          <tbody>{data.rows.length === 0 ? <tr><td colSpan={5} style={{ padding: 32, textAlign: 'center', color: '#9ca3af' }}>No activity found</td></tr> : data.rows.map(r => <tr key={String(r.id)} style={{ borderBottom: '1px solid #f9fafb' }}>
            <td style={{ padding: '10px 14px', color: '#9ca3af', whiteSpace: 'nowrap' }}>{new Date(String(r.created_at)).toLocaleString('en-IN',{ day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit' })}</td>
            <td style={{ padding: '10px 14px', color: '#374151' }}>{String(r.user_name||'—')}</td>
            <td style={{ padding: '10px 14px' }}><span style={{ background: ACTION_COLORS[String(r.action)]||'#f3f4f6', padding: '3px 8px', borderRadius: 4, fontSize: 12, fontWeight: 500 }}>{String(r.action||'').replace(/_/g,' ')}</span></td>
            <td style={{ padding: '10px 14px' }}>{r.booking_id?<Link to={`/admin/bookings/${r.booking_id}`} style={{ color: primary, fontWeight: 600 }}>{String(r.booking_ref||`#${r.booking_id}`)}</Link>:'—'}</td>
            <td style={{ padding: '10px 14px', color: '#6b7280', maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.details?typeof r.details==='string'?r.details:JSON.stringify(r.details):'—'}</td>
          </tr>)}</tbody>
        </table>
        {data.total > 50 && <div style={{ display: 'flex', justifyContent: 'center', gap: 8, padding: 12 }}>
          <Btn variant="ghost" onClick={() => setFilters(f => ({ ...f, page: Math.max(1,f.page-1) }))} disabled={filters.page===1}>← Prev</Btn>
          <span style={{ padding: '10px', fontSize: 13, color: '#6b7280' }}>Page {filters.page} of {Math.ceil(data.total/50)}</span>
          <Btn variant="ghost" onClick={() => setFilters(f => ({ ...f, page: f.page+1 }))} disabled={filters.page>=Math.ceil(data.total/50)}>Next →</Btn>
        </div>}
      </Card>}
    </div>
  )
}
// ── AdminServices (Pe) — CRUD + schema editor + reseed ────────────────────────
export function AdminServices() {
  const primary = resolvePrimary(useStore(s => s.settings)), nav = useNavigate()
  const [services, setServices] = useState<Record<string, unknown>[]>([]), [categories, setCategories] = useState<Record<string, unknown>[]>([])
  const [loading, setLoading] = useState(true), [showForm, setShowForm] = useState(false), [editId, setEditId] = useState<number|null>(null)
  const [form, setForm] = useState({ name:'',name_hi:'',category_id:'',short_desc:'',description:'',pricing_model:'quote',base_price:'',price_min:'',price_max:'',turnaround_days:'7',icon:'📋',required_docs:'',is_active:1,image_url:'',form_schema:'',sort_order:0,seo_desc:'',using_form_builder:false })
  const [saving, setSaving] = useState(false), [fErr, setFErr] = useState(''), [ok, setOk] = useState(''), [pErr, setPErr] = useState('')
  const load = useCallback(() => { setLoading(true); Promise.all([api.get<{ services: Record<string, unknown>[] }>('admin/services?per_page=200'), api.get<{ categories: Record<string, unknown>[] }>('admin/categories')]).then(([s,c]) => { setServices((s.services||[]).map(x=>({...x,is_active:+String(x.is_active)})));setCategories((c.categories||[]).map(x=>({...x,is_active:+String(x.is_active)})));setLoading(false) }).catch(()=>setLoading(false)) }, [])
  useEffect(() => { load() }, [load])
  function openNew() { setEditId(null); setForm({ name:'',name_hi:'',category_id:String(categories[0]?.id||''),short_desc:'',description:'',pricing_model:'quote',base_price:'',price_min:'',price_max:'',turnaround_days:'7',icon:'📋',required_docs:'',is_active:1,image_url:'',form_schema:'',sort_order:0,seo_desc:'',using_form_builder:false }); setShowForm(true); setFErr('') }
  // Issue 11 fix: always fetch full service row (includes description, form_schema, required_docs)
  // The list endpoint returns short_desc aliased as description - full data requires GET /admin/services/{id}
  async function openEdit(svc: Record<string, unknown>) {
    setEditId(Number(svc.id))
    setShowForm(true); setFErr('')
    setForm(f => ({ ...f, name: String(svc.name||''), icon: String(svc.icon||'📋') })) // show name immediately
    try {
      const full = await api.get<{ service: Record<string, unknown> }>(`admin/services/${svc.id}`)
      const s = full.service || svc
      const docs = s.required_docs ? (Array.isArray(s.required_docs) ? (s.required_docs as string[]).join('\n') : String(s.required_docs)) : ''
      const schema = s.form_schema ? (typeof s.form_schema==='string' ? s.form_schema : JSON.stringify(s.form_schema,null,2)) : ''
      setForm({ name:String(s.name||''),name_hi:String(s.name_hi||''),category_id:String(s.category_id||''),short_desc:String(s.short_desc||''),description:String(s.description||s.short_desc||''),pricing_model:String(s.pricing_model||'quote'),base_price:String(s.base_price||''),price_min:String(s.price_min||''),price_max:String(s.price_max||''),turnaround_days:String(s.turnaround_days||7),icon:String(s.icon||'📋'),required_docs:docs,is_active:Number(s.is_active??1),image_url:String(s.image_url||''),form_schema:schema,sort_order:Number(s.sort_order||0),seo_desc:String(s.seo_desc||s.seo_title||''),using_form_builder:Boolean((full.service||s).using_form_builder) })
    } catch(e: unknown) { setFErr('Failed to load service details: ' + (e as {message:string}).message) }
  }
  const [pendingBuilderUrl, setPendingBuilderUrl] = useState<string|null>(null)

  function formBuilderUrl(serviceId: number | string): string {
    const base = (window.S2NRI_CONFIG?.builderUrl || '/s2nri-builder').replace(/\/$/, '')
    return `${base}?page=form-builder&service=${serviceId}`
  }

  async function save() {
    if (!form.name.trim()) { setFErr('Service name is required.'); return }
    if (!form.category_id) { setFErr('Category is required.'); return }
    setSaving(true); setFErr('')
    const payload = { ...form, required_docs:form.required_docs.split('\n').map(s=>s.trim()).filter(Boolean), category_id:parseInt(form.category_id), base_price:form.base_price?parseFloat(form.base_price):null, price_min:form.price_min?parseFloat(form.price_min):null, price_max:form.price_max?parseFloat(form.price_max):null, turnaround_days:parseInt(form.turnaround_days)||7, sort_order:parseInt(String(form.sort_order))||0 }
    try {
      if (editId) {
        await api.put(`admin/services/${editId}`, payload)
        setOk('Service updated — changes are live.')
        setShowForm(false); load()
      } else {
        const res = await api.post<{ id: number; form_builder_url?: string }>('admin/services', payload)
        setShowForm(false); load()
        const bUrl = res.form_builder_url || formBuilderUrl(res.id)
        setPendingBuilderUrl(bUrl)
        setOk('Service created. 5 default contact fields added automatically. Click "Manage Form" to add service-specific fields.')
      }
    } catch(e: unknown) { setFErr((e as {message:string}).message) }
    setSaving(false)
  }
  async function toggle(svc: Record<string, unknown>) { try { await api.patch(`admin/services/${svc.id}/toggle`,{}); setOk(`"${svc.name}" ${svc.is_active?'deactivated':'activated'}.`); load() } catch(e: unknown){setPErr((e as{message:string}).message)} }
  async function reseed() { if(!confirm('Add any missing default services without touching existing ones?')) return; try { const r = await api.post<{total_services:number;active_services:number;orphans_fixed:number}>('admin/services/reseed',{}); setOk(`Done. ${r.total_services} total (${r.active_services} active).${r.orphans_fixed>0?` Fixed ${r.orphans_fixed} category assignment(s).`:''}`); load() } catch(e: unknown){setPErr((e as{message:string}).message)} }
  return (
    <div>
      <PageHeader title="Services" subtitle={`${services.length} services`} action={<div style={{display:'flex',gap:8}}><Btn variant="ghost" onClick={reseed}>Reseed Defaults</Btn><Btn onClick={openNew}>+ Add Service</Btn></div>} />
      <Alert type="error" message={pErr} onClose={()=>setPErr('')} /><Alert type="success" message={ok} onClose={()=>setOk('')} />
      {pendingBuilderUrl && (
        <div style={{ background:'#EBF0F8',border:'1px solid #7dd3fc',borderRadius:10,padding:'14px 18px',marginBottom:16,display:'flex',justifyContent:'space-between',alignItems:'center',flexWrap:'wrap',gap:10 }}>
          <div>
            <strong>✅ Service created with 5 default contact fields.</strong>
            <p style={{ margin:'4px 0 0',fontSize:13,color:'#374151' }}>Add service-specific inquiry fields (e.g. "Passport Number", "Date of Birth") using the Form Builder.</p>
          </div>
          <div style={{ display:'flex',gap:8 }}>
            <a href={pendingBuilderUrl} target="_blank" rel="noopener noreferrer" style={{ background:'#1E2D40',color:'#fff',padding:'9px 18px',borderRadius:8,fontWeight:700,fontSize:13,textDecoration:'none' }}>📝 Open Form Builder →</a>
            <button onClick={()=>setPendingBuilderUrl(null)} style={{ background:'none',border:'none',cursor:'pointer',color:'#6b7280',fontSize:18 }}>×</button>
          </div>
        </div>
      )}
      {showForm && <Card style={{ marginBottom: 20, border: `2px solid ${primary}` }}>
        <h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 16px' }}>{editId?'Edit Service':'New Service'}</h3>
        {fErr && <Alert type="error" message={fErr} onClose={()=>setFErr('')} />}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <FormInput label="Service Name *" value={form.name} onChange={e=>setForm(f=>({...f,name:e.target.value}))} required placeholder="e.g. OCI Card Renewal" />
          <FormInput label="Hindi Name" value={form.name_hi} onChange={e=>setForm(f=>({...f,name_hi:e.target.value}))} placeholder="हिंदी नाम" />
          <div style={{ marginBottom: 14 }}>
            <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 5 }}>Category *</label>
            <select value={form.category_id} onChange={e=>setForm(f=>({...f,category_id:e.target.value}))} style={{ width:'100%', padding:'10px 14px', border:'1px solid #e0e0e0', borderRadius:8, fontSize:14, background:'#fff' }}><option value="">Select category</option>{categories.map(c=><option key={String(c.id)} value={String(c.id)}>{String(c.name)}</option>)}</select>
          </div>
          <FormInput label="Icon (emoji)" value={form.icon} onChange={e=>setForm(f=>({...f,icon:e.target.value}))} placeholder="📋" />
          <FormInput label="Turnaround (days)" type="number" value={form.turnaround_days} onChange={e=>setForm(f=>({...f,turnaround_days:e.target.value}))} placeholder="7" />
          <FormInput label="Image URL" value={form.image_url} onChange={e=>setForm(f=>({...f,image_url:e.target.value}))} placeholder="https://…" />
        </div>
        <Textarea label="Short Description" value={form.short_desc} onChange={e=>setForm(f=>({...f,short_desc:e.target.value}))} placeholder="1-2 sentence summary" rows={2} />
        <Textarea label="Full Description" value={form.description} onChange={e=>setForm(f=>({...f,description:e.target.value}))} placeholder="Detailed description" rows={4} />
        <Textarea label="Required Documents (one per line)" value={form.required_docs} onChange={e=>setForm(f=>({...f,required_docs:e.target.value}))} placeholder={`Valid Passport\nOld OCI Card\n2 Passport Photos`} rows={4} />
        <div style={{ marginBottom: 14 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 5 }}>
            <label style={{ fontSize: 13, fontWeight: 600, color: '#374151' }}>Form Schema (JSON array)</label>
            <div style={{ display: 'flex', gap: 6 }}>
              <button type="button" onClick={()=>{try{const j=JSON.parse(form.form_schema);setForm(f=>({...f,form_schema:JSON.stringify(j,null,2)}))}catch{alert('Invalid JSON')}}} style={{ fontSize:11,color:primary,background:`${primary}15`,border:'none',padding:'4px 10px',borderRadius:5,cursor:'pointer',fontWeight:600 }}>Format JSON</button>
              <button type="button" onClick={()=>setForm(f=>({...f,form_schema:'[{"key":"remarks","label":"Additional Remarks","type":"textarea","required":false,"step":3}]'}))} style={{ fontSize:11,color:'#6b7280',background:'#f3f4f6',border:'none',padding:'4px 10px',borderRadius:5,cursor:'pointer' }}>Insert Example</button>
            </div>
          </div>
          {form.using_form_builder && (
            <div style={{ background:'#EBF0F8',border:'1px solid #7dd3fc',borderRadius:8,padding:'10px 14px',marginBottom:8,fontSize:13,color:'#0369a1',display:'flex',alignItems:'center',gap:8 }}>
              <span>ℹ️</span>
              <span>This service uses the <strong>Form Builder</strong>. The JSON shown below reflects the current live form fields. Saving here will update those fields directly. To use the visual editor, click <strong>🔧 Page Builder</strong> → Form Builder tab.</span>
            </div>
          )}
          <textarea rows={6} value={form.form_schema} onChange={e=>setForm(f=>({...f,form_schema:e.target.value}))} placeholder='[{"key":"applicant_name","label":"Full Name","type":"text","required":true,"step":1}]' style={{ width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:13,fontFamily:'monospace',outline:'none',resize:'vertical',boxSizing:'border-box',lineHeight:1.6 }} />
          <p style={{ fontSize:11,color:'#888',margin:'3px 0 0' }}>
            {form.using_form_builder
              ? 'Live fields shown. Saving will sync each field (by key) into the form builder. New keys are added; existing keys updated; removed keys are NOT deleted.'
              : 'Each field: key, label, type, required, step (1-4 for content, 98=contact, 99=files). Saving will also create Form Builder records so changes reflect on the service page.'}
          </p>
        </div>
        {editId ? <ServiceRegistryVisibilityBlock serviceId={editId} compact /> : null}
        <div style={{ display: 'flex', gap: 10, justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 14 }}><input type="checkbox" checked={!!form.is_active} onChange={e=>setForm(f=>({...f,is_active:e.target.checked?1:0}))} /> Active (legacy flag — prefer registry status above)</label>
          <div style={{ display: 'flex', gap: 10 }}>
            {editId && <Btn variant="ghost" onClick={()=>nav(`/admin/services/${editId}/builder`)}>🔧 Page Builder</Btn>}
            <Btn variant="ghost" onClick={()=>{setShowForm(false);setFErr('')}}>Cancel</Btn>
            <Btn onClick={save} loading={saving}>{editId?'Save Changes':'Create Service'}</Btn>
          </div>
        </div>
      </Card>}
      {loading ? <Spinner /> : <Card style={{ padding: 0, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
          <thead><tr style={{ background: '#f9fafb' }}>{['Icon','Name','Category','Form','Status','Sort','Actions'].map(h=><th key={h} style={{ padding:'12px 14px',fontWeight:600,color:'#374151',borderBottom:'1px solid #e5e7eb',textAlign:'left' }}>{h}</th>)}</tr></thead>
          <tbody>{services.length===0?<tr><td colSpan={7} style={{ padding:32,textAlign:'center',color:'#9ca3af' }}>No services found. Click "Reseed Defaults" to add all seeded services.</td></tr>:services.map(svc=><tr key={String(svc.id)} style={{ borderBottom:'1px solid #f3f4f6' }}>
            <td style={{ padding:'12px 14px',fontSize:20 }}>{String(svc.icon||'📋')}</td>
            <td style={{ padding:'12px 14px' }}><div style={{ fontWeight:600,color:'#111827' }}>{String(svc.name)}</div><div style={{ fontSize:12,color:'#9ca3af' }}>{String(svc.slug)}</div></td>
            <td style={{ padding:'12px 14px',color:'#374151' }}>{String(svc.category_name||'—')}</td>
            <td style={{ padding:'12px 14px' }}>{(() => { const fc=Number(svc.form_field_count||0); const url=formBuilderUrl(Number(svc.id)); return fc>0 ? <a href={url} target="_blank" rel="noopener noreferrer" style={{ display:'inline-flex',alignItems:'center',gap:5,color:'#15803d',background:'#dcfce7',padding:'3px 10px',borderRadius:99,fontSize:12,fontWeight:700,textDecoration:'none' }}>✓ {fc} field{fc!==1?'s':''}</a> : <a href={url} target="_blank" rel="noopener noreferrer" style={{ display:'inline-flex',alignItems:'center',gap:5,color:'#dc2626',background:'#fee2e2',padding:'3px 10px',borderRadius:99,fontSize:12,fontWeight:700,textDecoration:'none' }}>⚠ No form</a> })()}</td>
            <td style={{ padding:'12px 14px' }}><StatusBadge status={svc.is_active?'verified':'cancelled'} label={svc.is_active?'Active':'Inactive'} /></td>
            <td style={{ padding:'12px 14px',color:'#6b7280' }}>{String(svc.sort_order||0)}</td>
            <td style={{ padding:'12px 14px' }}><div style={{ display:'flex',gap:6,flexWrap:'wrap' }}>
              <Btn variant="ghost" onClick={()=>openEdit(svc)} style={{ padding:'5px 10px',fontSize:12 }}>Edit</Btn>
              <a href={formBuilderUrl(Number(svc.id))} target="_blank" rel="noopener noreferrer" style={{ display:'inline-flex',alignItems:'center',fontSize:12,padding:'5px 10px',border:'1px solid #e5e7eb',borderRadius:6,textDecoration:'none',color:'#374151',background:'#fff',fontWeight:500 }}>📝 Form</a>
              <Btn variant="ghost" onClick={()=>nav(`/admin/services/${svc.id}/builder`)} style={{ padding:'5px 10px',fontSize:12 }}>🔧</Btn>
              <Btn variant={svc.is_active?'ghost':'primary'} onClick={()=>toggle(svc)} style={{ padding:'5px 10px',fontSize:12 }}>{svc.is_active?'Hide':'Show'}</Btn>
            </div></td>
          </tr>)}</tbody>
        </table>
      </Card>}
    </div>
  )
}
// ── AdminCategories (Le) — with image upload ──────────────────────────────────
export function AdminCategories() {
  const primary = resolvePrimary(useStore(s => s.settings))
  const [cats, setCats] = useState<Record<string, unknown>[]>([]), [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false), [editId, setEditId] = useState<number|null>(null)
  const [form, setForm] = useState({ name:'',name_hi:'',icon:'📋',color:primary,seo_desc:'',sort_order:0,image_url:'' })
  const [saving, setSaving] = useState(false), [uploading, setUploading] = useState(false), [err, setErr] = useState(''), [ok, setOk] = useState('')
  const load = useCallback(() => { api.get<{ categories: Record<string, unknown>[] }>('admin/categories').then(d=>{setCats((d.categories||[]).map(x=>({...x,is_active:+String(x.is_active)})));setLoading(false)}).catch(()=>setLoading(false)) }, [])
  useEffect(() => { load() }, [load])
  function openNew() { setEditId(null); setForm({name:'',name_hi:'',icon:'📋',color:primary,seo_desc:'',sort_order:cats.length+1,image_url:''}); setShowForm(true); setErr('') }
  function openEdit(cat: Record<string, unknown>) { setEditId(Number(cat.id)); setForm({name:String(cat.name||''),name_hi:String(cat.name_hi||''),icon:String(cat.icon||'📋'),color:String(cat.color||primary),seo_desc:String(cat.seo_desc||''),sort_order:Number(cat.sort_order||0),image_url:String(cat.image_url||'')}); setShowForm(true); setErr('') }
  async function save() {
    if (!form.name.trim()) { setErr('Category name is required.'); return }
    setSaving(true); setErr('')
    try {
      if(editId){await api.put(`admin/categories/${editId}`,{...form,sort_order:parseInt(String(form.sort_order))||0});setOk(`"${form.name}" updated — changes are live on the homepage tabs.`)}
      else{await api.post('admin/categories',{...form,sort_order:parseInt(String(form.sort_order))||0});setOk(`"${form.name}" created — it now appears as a new tab on the homepage.`)}
      setShowForm(false); load()
    } catch(e: unknown){setErr((e as{message:string}).message)}; setSaving(false)
  }
  async function toggle(cat: Record<string, unknown>) { try{await api.patch(`admin/categories/${cat.id}/toggle`,{});setOk(`"${cat.name}" ${cat.is_active?'hidden':'shown'} on the homepage.`);load()}catch(e: unknown){setErr((e as{message:string}).message)} }
  async function del(cat: Record<string, unknown>) { if(!confirm(`Delete category "${cat.name}"?\n\nServices in this category won't appear under any tab until reassigned.`))return;try{await api.delete(`admin/categories/${cat.id}`);setOk(`"${cat.name}" deleted.`);load()}catch(e: unknown){setErr((e as{message:string}).message)} }
  async function uploadImg(catId: number) {
    setUploading(true)
    const url = await uploadImageToEndpoint(`admin/categories/${catId}/image`)
    if(url){setOk(`Image updated.`);load()}else setErr('Upload failed.'); setUploading(false)
  }
  async function uploadNewImg() {
    if(!editId) return; setUploading(true)
    const url = await uploadImageToEndpoint(`admin/categories/${editId}/image`)
    if(url){setForm(f=>({...f,image_url:url}));setOk('Image uploaded.')}else setErr('Upload failed.'); setUploading(false)
  }
  return (
    <div>
      <PageHeader title="Service Categories" subtitle={`${cats.length} categories · each becomes a tab on the homepage`} action={<Btn onClick={openNew}>+ Add Category</Btn>} />
      <Alert type="error" message={err} onClose={()=>setErr('')} /><Alert type="success" message={ok} onClose={()=>setOk('')} />
      <div style={{ background:`${primary}08`,border:`1px solid ${primary}20`,borderRadius:10,padding:'12px 16px',marginBottom:20,fontSize:13,color:'#374151' }}>
        <strong>Homepage tabs</strong> — each active category appears as a tab on the homepage services section. Reorder using Sort Order.
      </div>
      {showForm && <Card style={{ marginBottom: 20, border: `2px solid ${primary}` }}>
        <h3 style={{ fontSize:16,fontWeight:700,margin:'0 0 16px' }}>{editId?'Edit Category':'New Category'}</h3>
        {err && <Alert type="error" message={err} onClose={()=>setErr('')} />}
        <div style={{ display:'grid',gridTemplateColumns:'1fr 1fr',gap:12 }}>
          <FormInput label="Category Name *" value={form.name} onChange={e=>setForm(f=>({...f,name:e.target.value}))} required placeholder="e.g. Property" />
          <FormInput label="Hindi Name" value={form.name_hi} onChange={e=>setForm(f=>({...f,name_hi:e.target.value}))} placeholder="संपत्ति" />
          <FormInput label="Icon (emoji)" value={form.icon} onChange={e=>setForm(f=>({...f,icon:e.target.value}))} placeholder="🏠" />
          <div style={{ marginBottom:14 }}>
            <label style={{ display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5 }}>Card Colour</label>
            <div style={{ display:'flex',gap:8,alignItems:'center' }}><input type="color" value={form.color} onChange={e=>setForm(f=>({...f,color:e.target.value}))} style={{ width:44,height:36,borderRadius:6,border:'1px solid #e0e0e0',cursor:'pointer',padding:2 }} /><span style={{ fontSize:13,color:'#666' }}>{form.color}</span></div>
          </div>
          <FormInput label="Display Order" type="number" value={String(form.sort_order)} onChange={e=>setForm(f=>({...f,sort_order:parseInt(e.target.value)||0}))} hint="0 = first" />
          <div style={{ marginBottom:14 }}>
            <label style={{ display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5 }}>Image</label>
            <div style={{ display:'flex',gap:8 }}>
              <input value={form.image_url} onChange={e=>setForm(f=>({...f,image_url:e.target.value}))} placeholder="https://… or upload →" style={{ flex:1,padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none' }} />
              {editId && <button type="button" onClick={uploadNewImg} disabled={uploading} style={{ padding:'0 12px',border:`1px solid ${primary}`,borderRadius:8,background:'transparent',color:primary,cursor:'pointer',fontWeight:600,fontSize:13 }}>{uploading?'…':'📷'}</button>}
            </div>
            {!editId && <p style={{ fontSize:11,color:'#888',margin:'3px 0 0' }}>Save first, then click 📷 to upload image</p>}
          </div>
        </div>
        <Textarea label="SEO Description" value={form.seo_desc} onChange={e=>setForm(f=>({...f,seo_desc:e.target.value}))} rows={2} placeholder="Shown in search results for this category page" />
        {editId ? <CategoryRegistryVisibilityBlock categoryId={editId} compact /> : null}
        <div style={{ display:'flex',gap:10,justifyContent:'flex-end',marginTop:8 }}><Btn variant="ghost" onClick={()=>{setShowForm(false);setErr('')}}>Cancel</Btn><Btn onClick={save} loading={saving}>{editId?'Save Changes':'Create Category'}</Btn></div>
      </Card>}
      {loading ? <Spinner /> : cats.length===0 ? <Empty icon="📁" title="No categories" description="Add service categories to organise your services on the homepage." action={<Btn onClick={openNew}>Add First Category</Btn>} /> : (
        <div style={{ display:'grid',gridTemplateColumns:'repeat(auto-fill, minmax(280px, 1fr))',gap:16 }}>
          {cats.map(cat=><Card key={String(cat.id)}>
            <div style={{ display:'flex',alignItems:'center',gap:12,marginBottom:14 }}>
              {cat.image_url?<img src={String(cat.image_url)} alt={String(cat.name)} style={{ width:48,height:48,borderRadius:10,objectFit:'cover',flexShrink:0 }} />:<div style={{ width:48,height:48,background:String(cat.color||primary)+'20',borderRadius:10,display:'flex',alignItems:'center',justifyContent:'center',fontSize:24,flexShrink:0 }}>{String(cat.icon||'📋')}</div>}
              <div style={{ flex:1,minWidth:0 }}>
                <div style={{ fontWeight:700,fontSize:15,color:'#1E2D40' }}>{String(cat.name)}</div>
                <div style={{ fontSize:12,color:'#9ca3af' }}>{String(cat.service_count||0)} services · Sort: {String(cat.sort_order)}</div>
                <StatusBadge status={cat.is_active?'verified':'cancelled'} label={cat.is_active?'Active':'Hidden'} />
              </div>
            </div>
            <div style={{ display:'flex',gap:8,flexWrap:'wrap' }}>
              <Btn variant="ghost" onClick={()=>openEdit(cat)} style={{ flex:1,justifyContent:'center',fontSize:13 }}>Edit</Btn>
              <Btn variant={cat.is_active?'ghost':'primary'} onClick={()=>toggle(cat)} style={{ flex:1,justifyContent:'center',fontSize:13 }}>{cat.is_active?'Hide':'Show'}</Btn>
              <Btn variant="ghost" onClick={()=>uploadImg(Number(cat.id))} disabled={uploading} style={{ fontSize:13 }}>📷</Btn>
              <Btn variant="danger" onClick={()=>del(cat)} style={{ fontSize:13 }}>Delete</Btn>
            </div>
          </Card>)}
        </div>
      )}
    </div>
  )
}
// ── Diagnostics components (Me, He, Ge, Xe) ───────────────────────────────────
const SEV_BG: Record<string, string>    = { critical:'#fef2f2',high:'#fff7ed',medium:'#fffbeb',low:'#EBF0F8',info:'#f9fafb' }
const SEV_COLOR: Record<string, string> = { critical:'#dc2626',high:'#ea580c',medium:'#d97706',low:'#4A6FA5',info:'#6b7280' }
const CAT_ICON: Record<string, string>  = { php_environment:'🐘',database:'🗄️',wordpress:'🔷',api_rest:'🔌',plugin_conflict:'⚡',performance:'⚡',security:'🔒',network:'🌐',integration:'🔗',frontend:'🖥️',infrastructure:'🏗️',unknown_anomaly:'🔮' }
const CAT_TABS = [
  {key:'php_environment',label:'PHP',icon:'🐘'},{key:'database',label:'Database',icon:'🗄️'},{key:'wordpress',label:'WordPress',icon:'🔷'},
  {key:'api_rest',label:'API',icon:'🔌'},{key:'security',label:'Security',icon:'🔒'},{key:'performance',label:'Performance',icon:'⚡'},
  {key:'plugin_conflict',label:'Plugins',icon:'⚡'},{key:'infrastructure',label:'Infra',icon:'🏗️'},{key:'integration',label:'Integrations',icon:'🔗'},{key:'frontend',label:'Frontend',icon:'🖥️'},
]
function HealthGauge({ score, label, size=80 }: { score:number; label:string; size?:number }) {
  const color = score>=90?'#16a34a':score>=70?'#d97706':'#dc2626', r=size/2-6, circ=2*Math.PI*r, dash=score/100*circ
  return <div style={{ textAlign:'center',width:size }}>
    <svg width={size} height={size}>
      <circle cx={size/2} cy={size/2} r={r} fill="none" stroke="#e5e7eb" strokeWidth={6} />
      <circle cx={size/2} cy={size/2} r={r} fill="none" stroke={color} strokeWidth={6} strokeDasharray={`${dash} ${circ-dash}`} strokeLinecap="round" transform={`rotate(-90 ${size/2} ${size/2})`} />
      <text x="50%" y="50%" dominantBaseline="middle" textAnchor="middle" fontSize={size>70?18:13} fontWeight="800" fill={color}>{score}</text>
    </svg>
    <div style={{ fontSize:11,fontWeight:700,color:'#6b7280',marginTop:2 }}>{label}</div>
  </div>
}
function TrendLine({ data=[], color='#1E2D40' }: { data?: Array<{avg_score:string}>; color?:string }) {
  if (!data.length) return null
  const vals = data.map(d=>parseFloat(d.avg_score)||0), max=Math.max(...vals,1), W=120, H=36
  const pts = vals.map((v,i)=>`${i/Math.max(vals.length-1,1)*W},${H-v/max*H}`).join(' ')
  return <svg width={W} height={H} style={{ overflow:'visible' }}><polyline points={pts} fill="none" stroke={color} strokeWidth={2} strokeLinejoin="round" /></svg>
}
function FindingCard({ f }: { f: Record<string, unknown> }) {
  const [open, setOpen] = useState(false)
  const sev=String(f.severity||''), bg=SEV_BG[sev]||'#f9fafb', color=SEV_COLOR[sev]||'#6b7280', icon=CAT_ICON[String(f.category)]||'🔍'
  return <div style={{ border:'1px solid #e5e7eb',borderRadius:10,marginBottom:10,overflow:'hidden' }}>
    <div onClick={()=>setOpen(o=>!o)} style={{ display:'flex',alignItems:'center',gap:10,padding:'12px 16px',background:open?bg:'#fff',cursor:'pointer',userSelect:'none' as const }}>
      <span style={{ background:color,color:'#fff',padding:'2px 9px',borderRadius:99,fontSize:11,fontWeight:700,flexShrink:0 }}>{sev.toUpperCase()}</span>
      <span style={{ fontSize:13,color:'#6b7280',flexShrink:0 }}>{icon} {String(f.category)}</span>
      <span style={{ fontSize:14,fontWeight:600,color:'#111827',flex:1,minWidth:0,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap' }}>{String(f.title)}</span>
      <span style={{ color:'#9ca3af',fontSize:18,flexShrink:0 }}>{open?'▲':'▼'}</span>
    </div>
    {open && <div style={{ padding:'14px 16px',borderTop:'1px solid #e5e7eb',background:bg,fontSize:13,lineHeight:1.7,color:'#374151' }}>
      <p style={{ marginBottom:8 }}><strong>🔍 Issue:</strong> {String(f.description)}</p>
      <p style={{ marginBottom:8,background:'#ecfdf5',padding:'8px 12px',borderRadius:8,borderLeft:'3px solid #16a34a' }}><strong>✅ Fix:</strong> {String(f.fix)}</p>
      {(f.prevention||f.impact) ? <p style={{ marginBottom:8,background:'#EBF0F8',padding:'8px 12px',borderRadius:8,borderLeft:'3px solid #4A6FA5' }}>
        {f.prevention ? <><strong>🛡️ Prevention:</strong> {String(f.prevention)}</> : null}
        {f.impact ? <><br /><strong>📊 Impact:</strong> {String(f.impact)}</> : null}
      </p> : null}
      {f.evidence && Object.keys(f.evidence as object).length>0 ? <details style={{ marginTop:8 }}>
        <summary style={{ cursor:'pointer',fontWeight:600,fontSize:12,color:'#6b7280' }}>🔬 Evidence</summary>
        <pre style={{ background:'#f3f4f6',padding:10,borderRadius:6,fontSize:11,overflow:'auto',marginTop:6,whiteSpace:'pre-wrap' }}>{JSON.stringify(f.evidence,null,2)}</pre>
      </details> : null}
    </div>}
  </div>
}
export function DiagnosticsPage() {
  const primary = useStore(s=>s.settings).primary_color||'#1E2D40'
  const [runs,setRuns]=useState<Record<string,unknown>[]>([]),[trends,setTrends]=useState<Array<{avg_score:string}>>([])
  const [activeRun,setActiveRun]=useState<string|null>(null),[runDetail,setRunDetail]=useState<Record<string,unknown>|null>(null)
  const [loading,setLoading]=useState(true),[scanning,setScanning]=useState(false)
  const [sevFilter,setSevFilter]=useState('all'),[catFilter,setCatFilter]=useState('all')
  const [errMsg,setErrMsg]=useState(''),[autoRefresh,setAutoRefresh]=useState(false),[dlId,setDlId]=useState<string|null>(null)
  const loadRuns=useCallback(()=>{api.get<{runs:Record<string,unknown>[];trends:Array<{avg_score:string}>}>('admin/diagnostics').then(d=>{setRuns(d.runs||[]);setTrends(d.trends||[]);setLoading(false)}).catch(()=>setLoading(false))},[])
  useEffect(()=>{loadRuns()},[loadRuns])
  useEffect(()=>{if(!autoRefresh)return;const t=setInterval(loadRuns,30000);return()=>clearInterval(t)},[autoRefresh,loadRuns])
  const selectRun=useCallback((runId:string)=>{setActiveRun(runId);setRunDetail(null);setSevFilter('all');setCatFilter('all');api.get<Record<string,unknown>>(`admin/diagnostics/${runId}`).then(d=>setRunDetail(d)).catch(()=>setErrMsg('Failed to load.'))},[])
  async function runScan(){setScanning(true);setErrMsg('');try{const r=await api.post<{run_id?:string}>('admin/diagnostics/run',{});loadRuns();if(r.run_id)selectRun(r.run_id)}catch(e:unknown){setErrMsg((e as{message:string}).message||'Scan failed.')};setScanning(false)}
  async function downloadReport(runId:string,format:string){
    setDlId(`${runId}-${format}`)
    const cfg=window.S2NRI_CONFIG||{},base=(cfg.apiBase||'').replace(/\/$/,''),nonce=cfg.nonce||''
    let token='';try{token=localStorage.getItem('s2nri_portal_token')||cfg.portalToken||''}catch{}
    const hdrs:Record<string,string>={'X-WP-Nonce':nonce};if(token&&token.length===64)hdrs['X-S2NRI-Token']=token
    try{const res=await fetch(`${base}/admin/diagnostics/${runId}/download?format=${format}`,{headers:hdrs,credentials:'include'});if(!res.ok)throw new Error(`HTTP ${res.status}`);const blob=await res.blob(),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=`s2nri-diagnostic-${runId}.${format}`;a.click();URL.revokeObjectURL(a.href)}catch(e:unknown){setErrMsg(`Download failed: ${(e as Error).message}`)}
    setDlId(null)
  }
  async function deleteRun(runId:string){if(!confirm('Delete this run?'))return;try{await api.delete(`admin/diagnostics/${runId}`);if(activeRun===runId){setActiveRun(null);setRunDetail(null)};loadRuns()}catch(e:unknown){setErrMsg((e as{message:string}).message)}}
  const findings=((runDetail?.findings||[]) as Array<Record<string,unknown>>)
  const filtered=findings.filter(f=>(sevFilter==='all'||f.severity===sevFilter)&&(catFilter==='all'||f.category===catFilter))
  const cats=[...new Set(findings.map(f=>String(f.category)))]
  const latestRun=runs[0]
  const catScores=CAT_TABS.map(c=>{const inCat=findings.filter(f=>f.category===c.key);const score=inCat.some(f=>f.severity==='critical')?20:inCat.some(f=>f.severity==='high')?60:inCat.some(f=>f.severity==='medium')?80:inCat.length>0?90:100;return{...c,score,count:inCat.length,color:score>=90?'#16a34a':score>=70?'#d97706':'#dc2626'}})
  if(loading)return<Spinner/>
  return (
    <div>
      <PageHeader title="🔍 Diagnostic & Error Intelligence" subtitle="Full-stack: PHP · Database · WordPress · API · Performance · Security · Frontend · Browser" action={<div style={{display:'flex',gap:8,alignItems:'center'}}>
        <label style={{display:'flex',alignItems:'center',gap:6,fontSize:13,color:'#6b7280',cursor:'pointer'}}><input type="checkbox" checked={autoRefresh} onChange={e=>setAutoRefresh(e.target.checked)} /> Auto-refresh (30s)</label>
        <Btn onClick={runScan} loading={scanning} style={{background:primary,color:'#fff'}}>{scanning?'Scanning…':'▶ Run Full Scan'}</Btn>
      </div>} />
      <Alert type="error" message={errMsg} onClose={()=>setErrMsg('')} />
      {latestRun && <Card style={{ marginBottom:20 }}>
        <div style={{ display:'grid',gridTemplateColumns:'auto 1fr auto',gap:24,alignItems:'center' }}>
          <HealthGauge score={parseInt(String(latestRun.health_score||0))} label={String(latestRun.health_label||'Health Score')} size={90} />
          <div>
            <div style={{ fontWeight:800,fontSize:18,color:'#111827',marginBottom:4 }}>{String(latestRun.health_label||'')}</div>
            <div style={{ fontSize:13,color:'#9ca3af' }}>Last scan: {new Date(String(latestRun.created_at)).toLocaleString('en-IN')}</div>
            <div style={{ marginTop:8 }}><TrendLine data={trends} color={primary} /></div>
          </div>
          <Btn variant="ghost" onClick={()=>selectRun(String(latestRun.id))}>View Details →</Btn>
        </div>
      </Card>}
      {/* Category score grid (shown when a run is selected) */}
      {runDetail && <div style={{ display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(100px,1fr))',gap:8,marginBottom:20 }}>
        {catScores.map(c=><div key={c.key} onClick={()=>setCatFilter(catFilter===c.key?'all':c.key)} style={{ background:catFilter===c.key?`${c.color}15`:'#f9fafb',border:`1.5px solid ${catFilter===c.key?c.color:'#e5e7eb'}`,borderRadius:8,padding:'10px 8px',textAlign:'center',cursor:'pointer' }}>
          <div style={{ fontSize:18,marginBottom:4 }}>{c.icon}</div>
          <div style={{ fontSize:10,fontWeight:700,color:'#374151',marginBottom:4 }}>{c.label}</div>
          <div style={{ fontSize:16,fontWeight:800,color:c.color }}>{c.score}</div>
          {c.count>0&&<div style={{ fontSize:10,color:'#9ca3af' }}>{c.count} issue{c.count!==1?'s':''}</div>}
        </div>)}
      </div>}
      <div style={{ display:'grid',gridTemplateColumns:'260px 1fr',gap:20 }}>
        {/* Run list */}
        <div>
          <h3 style={{ fontSize:13,fontWeight:700,color:'#374151',margin:'0 0 12px' }}>📋 Scan History</h3>
          {runs.length===0?<p style={{ color:'#9ca3af',fontSize:13 }}>No scans yet. Run your first scan.</p>:runs.slice(0,10).map(run=><div key={String(run.id)} style={{ marginBottom:8 }}>
            <div onClick={()=>selectRun(String(run.id))} style={{ padding:'10px 14px',borderRadius:8,cursor:'pointer',background:activeRun===String(run.id)?`${primary}15`:'#f9fafb',border:`1px solid ${activeRun===String(run.id)?primary:'#e5e7eb'}` }}>
              <div style={{ display:'flex',justifyContent:'space-between',alignItems:'center' }}>
                <div style={{ fontWeight:700,fontSize:14,color:'#111827' }}>Score: {String(run.health_score)}</div>
                <div style={{ display:'flex',gap:4 }}>
                  {(['csv','html','json'] as const).map(fmt=><button key={fmt} onClick={e=>{e.stopPropagation();downloadReport(String(run.id),fmt)}} disabled={dlId===`${run.id}-${fmt}`} style={{ fontSize:9,padding:'2px 5px',border:'1px solid #d1d5db',borderRadius:4,cursor:'pointer',background:'#fff',color:'#374151' }}>{dlId===`${run.id}-${fmt}`?'…':fmt.toUpperCase()}</button>)}
                  <button onClick={e=>{e.stopPropagation();deleteRun(String(run.id))}} style={{ fontSize:9,padding:'2px 5px',border:'1px solid #fca5a5',borderRadius:4,cursor:'pointer',background:'#fff',color:'#dc2626' }}>DEL</button>
                </div>
              </div>
              <div style={{ fontSize:12,color:'#9ca3af' }}>{new Date(String(run.created_at)).toLocaleString('en-IN')}</div>
              <div style={{ fontSize:11,color:'#9ca3af' }}>{String(run.finding_count||0)} findings</div>
            </div>
          </div>)}
        </div>
        {/* Finding detail */}
        <div>
          {!runDetail?<Empty icon="🔍" title="Select a scan" description="Choose a scan from the list to view findings." />:<>
            <div style={{ display:'flex',gap:8,marginBottom:16,flexWrap:'wrap' }}>
              <select value={sevFilter} onChange={e=>setSevFilter(e.target.value)} style={{ padding:'7px 12px',border:'1px solid #d1d5db',borderRadius:8,fontSize:13,background:'#fff' }}>{['all','critical','high','medium','low','info'].map(s=><option key={s} value={s}>{s==='all'?'All Severities':s}</option>)}</select>
              <select value={catFilter} onChange={e=>setCatFilter(e.target.value)} style={{ padding:'7px 12px',border:'1px solid #d1d5db',borderRadius:8,fontSize:13,background:'#fff' }}><option value="all">All Categories</option>{cats.map(c=><option key={c} value={c}>{c}</option>)}</select>
              <span style={{ padding:'7px 12px',fontSize:13,color:'#6b7280' }}>{filtered.length} findings</span>
            </div>
            {filtered.length===0?<Empty icon="✅" title="No findings match your filters" />:filtered.map((f,i)=><FindingCard key={i} f={f} />)}
          </>}
        </div>
      </div>
    </div>
  )
}
// ── AdminHomepage (Ve) — section-based settings editor ────────────────────────
export function AdminHomepage() {
  const t=useStore(s=>s.settings), s=t.primary_color||'#4A6FA5'
  const [settings,setSettings]=useState<Record<string,string>>({}),[saving,setSaving]=useState(false),[feedback,setFeedback]=useState('')
  useEffect(()=>{setSettings(t as Record<string,string>)},[])
  async function saveSection(keys:string[]){setSaving(true);try{const p:Record<string,string>={};keys.forEach(k=>{p[k]=settings[k]||''});await api.put('admin/settings',p);setFeedback('Saved!');setTimeout(()=>setFeedback(''),2000)}catch{setFeedback('Error saving')};setSaving(false)}
  function F({label,k,type='text',placeholder='',hint=''}:{label:string;k:string;type?:string;placeholder?:string;hint?:string}){
    return <div style={{marginBottom:14}}>
      <label style={{display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5}}>{label}</label>
      {type==='textarea'?<textarea rows={3} value={settings[k]||''} onChange={e=>setSettings(p=>({...p,[k]:e.target.value}))} placeholder={placeholder} style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none',resize:'vertical',boxSizing:'border-box' as const}}/>:<input type={type} value={settings[k]||''} onChange={e=>setSettings(p=>({...p,[k]:e.target.value}))} placeholder={placeholder} style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none',boxSizing:'border-box' as const}}/>}
      {hint&&<p style={{fontSize:12,color:'#888',margin:'3px 0 0'}}>{hint}</p>}
    </div>
  }
  function Section({title,icon,keys,children}:{title:string;icon:string;keys:string[];children:React.ReactNode}){
    return <PageCard style={{marginBottom:20}}>
      <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:18,paddingBottom:14,borderBottom:'1px solid #f0f0f0'}}>
        <h3 style={{fontSize:16,fontWeight:700,color:'#1E2D40',margin:0,display:'flex',alignItems:'center',gap:8}}><span>{icon}</span>{title}</h3>
        <button onClick={()=>saveSection(keys)} disabled={saving} style={{background:s,color:'#fff',border:'none',padding:'7px 18px',borderRadius:7,fontWeight:600,fontSize:13,cursor:'pointer',opacity:saving?.7:1}}>{saving?'Saving…':feedback?'✓ Saved':'Save Section'}</button>
      </div>
      {children}
    </PageCard>
  }
  return (
    <PageWrap title="Homepage Builder" subtitle="Customise every section of your homepage without touching code.">
      {feedback&&<div style={{background:'#d1fae5',border:'1px solid #6ee7b7',borderRadius:8,padding:'10px 16px',marginBottom:16,fontSize:14,color:'#065f46'}}>✓ {feedback}</div>}
      <Section title="Hero Section" icon="🎯" keys={['hero_heading_1','hero_heading_2','hero_subheading','hero_description','hero_banners']}>
        <F label="Heading Line 1" k="hero_heading_1" placeholder="Stay Connected to" />
        <F label="Heading Line 2 (Bold, colored)" k="hero_heading_2" placeholder="INDIA" />
        <F label="Subheading" k="hero_subheading" placeholder="Without the Paperwork Stress" />
        <F label="Description" k="hero_description" type="textarea" placeholder="OCI · Passport · Visa · Documentation…" />
        <F label="Banner Images (comma-separated URLs)" k="hero_banners" placeholder="https://…/banner1.jpg, https://…/banner2.jpg" hint="Enter up to 5 image URLs separated by commas. Leave blank to use built-in gradient banners." />
      </Section>
      <Section title="Stats Counter" icon="📊" keys={['stat_1_number','stat_1_label','stat_2_number','stat_2_label','stat_3_number','stat_3_label','stat_4_number','stat_4_label']}>
        <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:12}}>
          {[1,2,3,4].map(n=><div key={n} style={{background:'#F5F7FA',borderRadius:8,padding:14}}>
            <div style={{fontSize:12,fontWeight:700,color:'#666',marginBottom:8}}>Stat {n}</div>
            <F label="Number" k={`stat_${n}_number`} placeholder="10,000+" />
            <F label="Label" k={`stat_${n}_label`} placeholder="Happy Clients" />
          </div>)}
        </div>
      </Section>
      <Section title="About Section" icon="📖" keys={['about_heading','about_text','about_video_url','about_image_url','home_tagline']}>
        <F label="Heading" k="about_heading" placeholder="Your Trusted Partner for All NRI Services" />
        <F label="Text" k="about_text" type="textarea" placeholder="We are a team of dedicated experts…" />
        <F label="Video URL" k="about_video_url" placeholder="https://youtube.com/…" />
        <F label="About Image URL" k="about_image_url" placeholder="https://…/about.jpg" />
        <F label="Tagline (italic banner below stats)" k="home_tagline" placeholder="Forming strong and trusted connections with our clients" />
      </Section>
      <Section title="Brand Details" icon="🏷️" keys={['platform_name','platform_tagline','platform_whatsapp','platform_phone','platform_us_phone','platform_logo_url']}>
        <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:12}}>
          <F label="Platform Name" k="platform_name" placeholder="Services2NRI" />
          <F label="Tagline" k="platform_tagline" placeholder="Your Trusted NRI Service Partner" />
          <F label="WhatsApp" k="platform_whatsapp" placeholder="919876543210" />
          <F label="Phone" k="platform_phone" placeholder="+91 98765 43210" />
          <F label="US Phone" k="platform_us_phone" placeholder="+1 555 0123" />
          <F label="Logo URL" k="platform_logo_url" placeholder="https://…/logo.png" />
        </div>
      </Section>
      <Section title="Mobile App Links" icon="📱" keys={['app_playstore_url','app_appstore_url']}>
        <F label="Play Store URL" k="app_playstore_url" placeholder="https://play.google.com/store/apps/…" />
        <F label="Apple App Store URL" k="app_appstore_url" placeholder="https://apps.apple.com/app/…" />
      </Section>
      <Section title="Google Reviews & SEO" icon="⭐" keys={['google_rating','google_review_count','seo_title','seo_description']}>
        <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:12}}>
          <F label="Google Rating" k="google_rating" placeholder="4.9" />
          <F label="Review Count" k="google_review_count" placeholder="500+" />
          <div style={{gridColumn:'1/-1'}}><F label="SEO Title" k="seo_title" placeholder="Services2NRI — Complete NRI Service Platform" /></div>
          <div style={{gridColumn:'1/-1'}}><F label="SEO Description" k="seo_description" type="textarea" placeholder="Expert NRI services…" /></div>
        </div>
      </Section>
    </PageWrap>
  )
}
// ── AdminMedia (Ye) ───────────────────────────────────────────────────────────
export function AdminMedia() {
  const s=useStore(x=>x.settings).primary_color||'#4A6FA5'
  const [items,setItems]=useState<Record<string,unknown>[]>([]),[loading,setLoading]=useState(true),[uploading,setUploading]=useState(false)
  const [page,setPage]=useState(1),[total,setTotal]=useState(0),[copied,setCopied]=useState<string|null>(null)
  const fileRef=useRef<HTMLInputElement>(null)
  async function load(p=1){setLoading(true);try{const d=await api.get<{items:Record<string,unknown>[];total:number}>(`admin/media?page=${p}`);setItems(d.items||[]);setTotal(d.total||0);setPage(p)}catch{}setLoading(false)}
  useEffect(()=>{load()},[])
  async function upload(e:React.ChangeEvent<HTMLInputElement>){const files=e.target.files;if(!files?.length)return;setUploading(true);try{for(const f of Array.from(files)){const form=new FormData;form.append('file',f);await api.upload('admin/media/upload',form)};await load(1)}catch{alert('Upload failed. Max 8MB per file. Allowed: JPG, PNG, WebP, GIF')};setUploading(false);e.target.value=''}
  async function del(id:number){if(!confirm('Delete this image permanently?'))return;try{await api.delete(`admin/media/${id}`);setItems(prev=>prev.filter(i=>i.id!==id))}catch{alert('Delete failed')}}
  function copy(url:string){navigator.clipboard.writeText(url).catch(()=>{});setCopied(url);setTimeout(()=>setCopied(null),2000)}
  return (
    <PageWrap title="Media Library" subtitle={`${total} images uploaded. Hover any image to copy URL or delete.`} action={<div style={{display:'flex',gap:10}}>
      <input ref={fileRef} type="file" multiple accept="image/*" onChange={upload} style={{display:'none'}} />
      <button onClick={()=>fileRef.current?.click()} disabled={uploading} style={{background:s,color:'#fff',border:'none',padding:'10px 20px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer',display:'flex',alignItems:'center',gap:8}}>{uploading?'⏳ Uploading…':'+ Upload Images'}</button>
    </div>}>
      <PageCard style={{marginBottom:20,border:`2px dashed ${s}40`,background:`${s}04`,textAlign:'center',padding:28,cursor:'pointer'}} onClick={()=>fileRef.current?.click()}>
        <div style={{fontSize:36,marginBottom:8}}>🖼️</div>
        <div style={{fontWeight:700,color:'#374151',marginBottom:4}}>Click to upload or drag images here</div>
        <div style={{fontSize:13,color:'#888'}}>JPG, PNG, WebP, GIF · Max 8MB each · Multiple files supported</div>
      </PageCard>
      {loading?<div style={{textAlign:'center',padding:40,color:'#888'}}>Loading media…</div>:items.length===0?<Empty icon="🖼️" title="No images yet" description="Upload your first image to get started." />:<>
        <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(180px,1fr))',gap:14}}>
          {items.map(item=><div key={String(item.id)} style={{background:'#fff',borderRadius:10,overflow:'hidden',border:'1px solid #EBF0F8',position:'relative'}} onMouseEnter={e=>{const el=e.currentTarget.querySelector('.overlay') as HTMLElement;if(el)el.style.opacity='1'}} onMouseLeave={e=>{const el=e.currentTarget.querySelector('.overlay') as HTMLElement;if(el)el.style.opacity='0'}}>
            <img src={String(item.url)} alt={String(item.file_name)} style={{width:'100%',height:140,objectFit:'cover',display:'block'}} />
            <div className="overlay" style={{position:'absolute',inset:0,background:'rgba(0,0,0,.55)',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',gap:8,opacity:0,transition:'opacity .2s'}}>
              <button onClick={()=>copy(String(item.url))} style={{background:copied===String(item.url)?'#25d366':'rgba(255,255,255,.9)',color:'#111',border:'none',padding:'7px 14px',borderRadius:6,fontWeight:600,fontSize:12,cursor:'pointer'}}>{copied===String(item.url)?'✓ Copied!':'📋 Copy URL'}</button>
              <button onClick={()=>del(Number(item.id))} style={{background:'rgba(239,68,68,.9)',color:'#fff',border:'none',padding:'7px 14px',borderRadius:6,fontWeight:600,fontSize:12,cursor:'pointer'}}>🗑️ Delete</button>
            </div>
            <div style={{padding:'8px 10px'}}><div style={{fontSize:11,color:'#555',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{String(item.file_name)}</div></div>
          </div>)}
        </div>
        {total>20&&<div style={{display:'flex',justifyContent:'center',gap:8,marginTop:20}}>
          <button onClick={()=>load(page-1)} disabled={page===1} style={{padding:'8px 16px',border:'1px solid #e0e0e0',borderRadius:7,cursor:'pointer',background:'#fff',fontSize:14}}>← Prev</button>
          <span style={{padding:'8px 16px',fontSize:14,color:'#666'}}>Page {page} of {Math.ceil(total/20)}</span>
          <button onClick={()=>load(page+1)} disabled={page>=Math.ceil(total/20)} style={{padding:'8px 16px',border:'1px solid #e0e0e0',borderRadius:7,cursor:'pointer',background:'#fff',fontSize:14}}>Next →</button>
        </div>}
      </>}
    </PageWrap>
  )
}
// ── AdminFAQs (Je) ────────────────────────────────────────────────────────────
export function AdminFAQs() {
  const s=useStore(x=>x.settings).primary_color||'#4A6FA5'
  const [faqs,setFaqs]=useState<Record<string,unknown>[]>([]),[loading,setLoading]=useState(true)
  const [editId,setEditId]=useState<string|number|null>(null),[form,setForm]=useState({question:'',answer:'',category:'General',sort_order:0}),[saving,setSaving]=useState(false)
  async function load(){setLoading(true);try{const d=await api.get<{faqs:Record<string,unknown>[]}>('admin/faqs');setFaqs(d.faqs||[])}catch{}setLoading(false)}
  useEffect(()=>{load()},[])
  function openEdit(faq:Record<string,unknown>|null=null){setEditId(faq?.id as number||'new');setForm(faq?{question:String(faq.question||faq.q||''),answer:String(faq.answer||faq.a||''),category:String(faq.category||'General'),sort_order:Number(faq.sort_order||0)}:{question:'',answer:'',category:'General',sort_order:faqs.length})}
  async function save(){if(!form.question||!form.answer)return;setSaving(true);try{editId==='new'?await api.post('admin/faqs',form):await api.put(`admin/faqs/${editId}`,form);await load();setEditId(null)}catch{alert('Save failed')};setSaving(false)}
  async function del(id:unknown,question?:unknown){if(!confirm(`Delete FAQ "${String(question||'this question').slice(0,60)}"? This cannot be undone.`))return;try{await api.delete(`admin/faqs/${id}`);await load()}catch{alert('Delete failed')}}
  return (
    <PageWrap title="FAQ Manager" subtitle="Add, edit, and reorder frequently asked questions shown on the FAQ page and homepage." action={<button onClick={()=>openEdit()} style={{background:s,color:'#fff',border:'none',padding:'10px 20px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>+ Add FAQ</button>}>
      {editId!==null&&<PageCard style={{marginBottom:20,border:`2px solid ${s}`}}>
        <h3 style={{fontSize:16,fontWeight:700,margin:'0 0 16px'}}>{editId==='new'?'New FAQ':'Edit FAQ'}</h3>
        <div style={{display:'grid',gridTemplateColumns:'1fr auto',gap:12,marginBottom:12}}>
          <input value={form.question} onChange={e=>setForm(f=>({...f,question:e.target.value}))} placeholder="Question" style={{padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}} />
          <input value={form.category} onChange={e=>setForm(f=>({...f,category:e.target.value}))} placeholder="Category" style={{width:140,padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}} />
        </div>
        <textarea rows={4} value={form.answer} onChange={e=>setForm(f=>({...f,answer:e.target.value}))} placeholder="Answer" style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none',resize:'vertical',boxSizing:'border-box' as const,marginBottom:12}} />
        <div style={{display:'flex',gap:10}}>
          <button onClick={save} disabled={saving} style={{background:s,color:'#fff',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>{saving?'Saving…':'Save FAQ'}</button>
          <button onClick={()=>setEditId(null)} style={{background:'#f3f4f6',color:'#374151',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:600,fontSize:14,cursor:'pointer'}}>Cancel</button>
        </div>
      </PageCard>}
      {loading?<div style={{padding:40,textAlign:'center',color:'#888'}}>Loading…</div>:<PageCard>
        <table style={{width:'100%',borderCollapse:'collapse'}}>
          <thead><tr style={{background:'#F5F7FA'}}>{['#','Question','Category','Actions'].map(h=><th key={h} style={{padding:'12px 14px',textAlign:'left',fontSize:12,fontWeight:700,color:'#666',textTransform:'uppercase',letterSpacing:1,borderBottom:'1px solid #EBF0F8'}}>{h}</th>)}</tr></thead>
          <tbody>{faqs.length===0?<tr><td colSpan={4} style={{textAlign:'center',padding:40,color:'#888'}}>No FAQs yet. Click "Add FAQ" to create your first one.</td></tr>:faqs.map((f,i)=><tr key={String(f.id)} style={{borderBottom:'1px solid #f5f5f5'}}>
            <td style={{padding:'12px 14px',color:'#9ca3af',fontSize:13}}>{i+1}</td>
            <td style={{padding:'12px 14px',color:'#374151',fontSize:14}}>{String(f.question||f.q||'').slice(0,80)}…</td>
            <td style={{padding:'12px 14px'}}><span style={{fontSize:12,background:`${s}15`,color:s,padding:'3px 10px',borderRadius:99,fontWeight:600}}>{String(f.category||'General')}</span></td>
            <td style={{padding:'12px 14px'}}><div style={{display:'flex',gap:8}}>
              <button onClick={()=>openEdit(f)} style={{fontSize:12,padding:'5px 12px',border:`1px solid ${s}`,borderRadius:6,color:s,background:'transparent',cursor:'pointer',fontWeight:600}}>Edit</button>
              <button onClick={()=>del(f.id,f.question||f.q)} style={{fontSize:12,padding:'5px 12px',border:'1px solid #ef4444',borderRadius:6,color:'#ef4444',background:'transparent',cursor:'pointer',fontWeight:600}}>Delete</button>
            </div></td>
          </tr>)}</tbody>
        </table>
      </PageCard>}
    </PageWrap>
  )
}
// ── AdminTestimonials (Ke) ────────────────────────────────────────────────────
export function AdminTestimonials() {
  const s=useStore(x=>x.settings).primary_color||'#4A6FA5'
  const [items,setItems]=useState<Record<string,unknown>[]>([]),[loading,setLoading]=useState(true)
  const [editId,setEditId]=useState<string|number|null>(null),[form,setForm]=useState({name:'',location:'',rating:5,text:'',image_url:'',is_active:true}),[saving,setSaving]=useState(false)
  async function load(){setLoading(true);try{const d=await api.get<{testimonials:Record<string,unknown>[]}>('admin/testimonials');setItems(d.testimonials||[])}catch{}setLoading(false)}
  useEffect(()=>{load()},[])
  function openEdit(t:Record<string,unknown>|null=null){setEditId(t?.id as number||'new');setForm(t?{name:String(t.name||''),location:String(t.location||''),rating:Number(t.rating||5),text:String(t.text||t.review||''),image_url:String(t.image_url||''),is_active:t.is_active!==false as boolean}:{name:'',location:'',rating:5,text:'',image_url:'',is_active:true})}
  async function save(){if(!form.name||!form.text)return;setSaving(true);try{editId==='new'?await api.post('admin/testimonials',form):await api.put(`admin/testimonials/${editId}`,form);await load();setEditId(null)}catch{alert('Save failed')};setSaving(false)}
  async function del(id:unknown){if(!confirm('Delete this testimonial?'))return;try{await api.delete(`admin/testimonials/${id}`);await load()}catch{alert('Delete failed')}}
  return (
    <PageWrap title="Testimonials Manager" subtitle="Manage customer testimonials displayed on the homepage and service pages." action={<button onClick={()=>openEdit()} style={{background:s,color:'#fff',border:'none',padding:'10px 20px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>+ Add Testimonial</button>}>
      {editId!==null&&<PageCard style={{marginBottom:20,border:`2px solid ${s}`}}>
        <h3 style={{fontSize:16,fontWeight:700,margin:'0 0 16px'}}>{editId==='new'?'New Testimonial':'Edit Testimonial'}</h3>
        <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:12,marginBottom:12}}>
          <input value={form.name} onChange={e=>setForm(f=>({...f,name:e.target.value}))} placeholder="Customer Name" style={{padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}} />
          <input value={form.location} onChange={e=>setForm(f=>({...f,location:e.target.value}))} placeholder="Location (e.g. USA, Singapore)" style={{padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}} />
          <input value={form.image_url} onChange={e=>setForm(f=>({...f,image_url:e.target.value}))} placeholder="Photo URL (optional)" style={{padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}} />
          <select value={form.rating} onChange={e=>setForm(f=>({...f,rating:+e.target.value}))} style={{padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}}>{[5,4,3,2,1].map(n=><option key={n} value={n}>{n} Stars</option>)}</select>
        </div>
        <textarea rows={4} value={form.text} onChange={e=>setForm(f=>({...f,text:e.target.value}))} placeholder="Customer review text" style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none',resize:'vertical',boxSizing:'border-box' as const,marginBottom:12}} />
        <label style={{display:'flex',alignItems:'center',gap:8,marginBottom:14,cursor:'pointer',fontSize:14,color:'#374151'}}><input type="checkbox" checked={form.is_active} onChange={e=>setForm(f=>({...f,is_active:e.target.checked}))} /> Show on website</label>
        <div style={{display:'flex',gap:10}}>
          <button onClick={save} disabled={saving} style={{background:s,color:'#fff',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>{saving?'Saving…':'Save'}</button>
          <button onClick={()=>setEditId(null)} style={{background:'#f3f4f6',color:'#374151',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:600,fontSize:14,cursor:'pointer'}}>Cancel</button>
        </div>
      </PageCard>}
      {loading?<Spinner/>:items.length===0?<Empty icon="💬" title="No testimonials" description="Add customer testimonials to build trust." action={<Btn onClick={()=>openEdit()}>Add First Testimonial</Btn>}/>:(
        <div style={{display:'flex',flexDirection:'column',gap:12}}>
          {items.map(t=><PageCard key={String(t.id)} style={{display:'flex',alignItems:'flex-start',gap:16,flexWrap:'wrap'}}>
            <div style={{flex:1,minWidth:0}}>
              <div style={{display:'flex',gap:10,alignItems:'center',marginBottom:6}}>
                {t.image_url ? <img src={String(t.image_url)} alt={String(t.name)} style={{width:36,height:36,borderRadius:'50%',objectFit:'cover'}} /> : null}
                <span style={{fontWeight:700,color:'#111827'}}>{String(t.name)}</span>
                <span style={{fontSize:13,color:'#9ca3af'}}>{String(t.location||'')}</span>
                <span style={{color:'#f59e0b'}}>{'★'.repeat(Number(t.rating||5))}</span>
                <StatusBadge status={Boolean(t.is_active)?'verified':'cancelled'} label={Boolean(t.is_active)?'Active':'Hidden'} />
              </div>
              <p style={{margin:0,color:'#374151',fontSize:13,lineHeight:1.6}}>{String(t.text||t.review||'').slice(0,120)}…</p>
            </div>
            <div style={{display:'flex',gap:8}}>
              <button onClick={()=>openEdit(t)} style={{fontSize:12,padding:'5px 12px',border:`1px solid ${s}`,borderRadius:6,color:s,background:'transparent',cursor:'pointer',fontWeight:600}}>Edit</button>
              <button onClick={()=>del(t.id)} style={{fontSize:12,padding:'5px 12px',border:'1px solid #ef4444',borderRadius:6,color:'#ef4444',background:'transparent',cursor:'pointer',fontWeight:600}}>Delete</button>
            </div>
          </PageCard>)}
        </div>
      )}
    </PageWrap>
  )
}
// ── AdminBlog (Ze) ────────────────────────────────────────────────────────────
export function AdminBlog() {
  const s=useStore(x=>x.settings).primary_color||'#4A6FA5'
  const [posts,setPosts]=useState<Record<string,unknown>[]>([]),[loading,setLoading]=useState(true)
  const [editId,setEditId]=useState<string|number|null>(null),[form,setForm]=useState({title:'',slug:'',excerpt:'',content:'',category:'',image_url:'',is_published:false}),[saving,setSaving]=useState(false)
  const slugify=(t:string)=>t.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')
  async function load(){setLoading(true);try{const d=await api.get<{posts:Record<string,unknown>[]}>('admin/blog');setPosts(d.posts||[])}catch{}setLoading(false)}
  useEffect(()=>{load()},[])
  function openEdit(p:Record<string,unknown>|null=null){setEditId(p?.id as number||'new');setForm(p?{title:String(p.title||''),slug:String(p.slug||''),excerpt:String(p.excerpt||''),content:String(p.content||p.body||''),category:String(p.category||''),image_url:String(p.image_url||''),is_published:Boolean(p.is_published)}:{title:'',slug:'',excerpt:'',content:'',category:'',image_url:'',is_published:false})}
  async function save(){if(!form.title||!form.content)return;setSaving(true);const p={...form,slug:form.slug||slugify(form.title)};try{editId==='new'?await api.post('admin/blog',p):await api.put(`admin/blog/${editId}`,p);await load();setEditId(null)}catch{alert('Save failed')};setSaving(false)}
  async function del(id:unknown){if(!confirm('Delete this post?'))return;try{await api.delete(`admin/blog/${id}`);await load()}catch{alert('Delete failed')}}
  if(editId!==null)return(
    <PageWrap title={editId==='new'?'New Blog Post':'Edit Blog Post'} action={<button onClick={()=>setEditId(null)} style={{background:'#f3f4f6',color:'#374151',border:'none',padding:'10px 20px',borderRadius:8,fontWeight:600,fontSize:14,cursor:'pointer'}}>← Back to Posts</button>}>
      <PageCard>
        <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:14,marginBottom:14}}>
          <div style={{gridColumn:'1/-1'}}>
            <label style={{display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5}}>Post Title *</label>
            <input value={form.title} onChange={e=>{const newTitle=e.target.value;setForm(f=>{const wasAutoSlug=!f.slug||f.slug===slugify(f.title);return{...f,title:newTitle,slug:wasAutoSlug?slugify(newTitle):f.slug}})}} placeholder="Your blog post title" style={{width:'100%',padding:'11px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:15,fontFamily:'inherit',outline:'none',boxSizing:'border-box' as const,fontWeight:600}} />
          </div>
          {[['slug','URL Slug (auto-generated)'],['category','Category'],['image_url','Featured Image URL']].map(([key,label])=><div key={key}>
            <label style={{display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5}}>{label}</label>
            <input value={form[key as keyof typeof form] as string} onChange={e=>setForm(f=>({...f,[key]:e.target.value}))} style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none',boxSizing:'border-box' as const}} />
          </div>)}
        </div>
        <div style={{marginBottom:14}}>
          <label style={{display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5}}>Excerpt (short summary)</label>
          <textarea rows={2} value={form.excerpt} onChange={e=>setForm(f=>({...f,excerpt:e.target.value}))} style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none',resize:'vertical',boxSizing:'border-box' as const}} />
        </div>
        <div style={{marginBottom:14}}>
          <label style={{display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5}}>Content (HTML supported) *</label>
          <textarea rows={16} value={form.content} onChange={e=>setForm(f=>({...f,content:e.target.value}))} placeholder="Write your blog post content here. You can use HTML for formatting." style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'monospace',outline:'none',resize:'vertical',boxSizing:'border-box' as const,lineHeight:1.7}} />
        </div>
        <div style={{display:'flex',gap:16,alignItems:'center'}}>
          <label style={{display:'flex',alignItems:'center',gap:8,cursor:'pointer',fontSize:14}}><input type="checkbox" checked={form.is_published} onChange={e=>setForm(f=>({...f,is_published:e.target.checked}))} /> Published (visible on website)</label>
          <div style={{display:'flex',gap:10,marginLeft:'auto'}}>
            <button onClick={()=>setEditId(null)} style={{background:'#f3f4f6',color:'#374151',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:600,fontSize:14,cursor:'pointer'}}>Cancel</button>
            <button onClick={save} disabled={saving} style={{background:s,color:'#fff',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>{saving?'Saving…':'Save Post'}</button>
          </div>
        </div>
      </PageCard>
    </PageWrap>
  )
  return (
    <PageWrap title="Blog Posts" subtitle={`${posts.length} posts`} action={<button onClick={()=>openEdit()} style={{background:s,color:'#fff',border:'none',padding:'10px 20px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>+ New Post</button>}>
      {loading?<Spinner/>:posts.length===0?<Empty icon="✍️" title="No blog posts" description="Create your first NRI knowledge article." action={<Btn onClick={()=>openEdit()}>Write First Post</Btn>}/>:(
        <PageCard style={{padding:0,overflow:'hidden'}}>
          <table style={{width:'100%',borderCollapse:'collapse',fontSize:14}}>
            <thead><tr style={{background:'#f9fafb'}}>{['Title','Category','Status','Date','Actions'].map(h=><th key={h} style={{padding:'12px 14px',fontWeight:600,color:'#374151',borderBottom:'1px solid #e5e7eb',textAlign:'left'}}>{h}</th>)}</tr></thead>
            <tbody>{posts.map(p=><tr key={String(p.id)} style={{borderBottom:'1px solid #f3f4f6'}}>
              <td style={{padding:'12px 14px',fontWeight:600,color:'#111827',maxWidth:300,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{String(p.title)}</td>
              <td style={{padding:'12px 14px',color:'#6b7280'}}>{String(p.category||'—')}</td>
              <td style={{padding:'12px 14px'}}><StatusBadge status={p.is_published?'verified':'cancelled'} label={p.is_published?'Published':'Draft'} /></td>
              <td style={{padding:'12px 14px',fontSize:13,color:'#888'}}>{String(p.created_at||'').split(' ')[0]||'—'}</td>
              <td style={{padding:'12px 14px'}}><div style={{display:'flex',gap:8}}>
                <button onClick={()=>openEdit(p)} style={{fontSize:12,padding:'5px 12px',border:`1px solid ${s}`,borderRadius:6,color:s,background:'transparent',cursor:'pointer',fontWeight:600}}>Edit</button>
                <button onClick={()=>del(p.id)} style={{fontSize:12,padding:'5px 12px',border:'1px solid #ef4444',borderRadius:6,color:'#ef4444',background:'transparent',cursor:'pointer',fontWeight:600}}>Delete</button>
              </div></td>
            </tr>)}</tbody>
          </table>
        </PageCard>
      )}
    </PageWrap>
  )
}
// ── AdminPricing (et) ─────────────────────────────────────────────────────────
export function AdminPricing() {
  const s=useStore(x=>x.settings).primary_color||'#4A6FA5'
  const [plans,setPlans]=useState<Record<string,unknown>[]>([]),[loading,setLoading]=useState(true)
  const [editId,setEditId]=useState<string|number|null>(null),[form,setForm]=useState({name:'',subtitle:'',price:'',price_note:'',color:s,popular:false,features:''}),[saving,setSaving]=useState(false)
  async function load(){setLoading(true);try{const d=await api.get<{plans:Record<string,unknown>[]}>('admin/pricing-plans');setPlans(d.plans||[])}catch{}setLoading(false)}
  useEffect(()=>{load()},[])
  function openEdit(p:Record<string,unknown>|null=null){setEditId(p?.id as number||'new');setForm(p?{name:String(p.name||''),subtitle:String(p.subtitle||''),price:String(p.price||''),price_note:String(p.price_note||''),color:String(p.color||s),popular:Boolean(p.popular),features:((p.features as string[])||[]).join('\n')}:{name:'',subtitle:'',price:'',price_note:'',color:s,popular:false,features:''})}
  async function save(){if(!form.name||!form.price)return;setSaving(true);const p={...form,features:form.features.split('\n').map(x=>x.trim()).filter(Boolean)};try{editId==='new'?await api.post('admin/pricing-plans',p):await api.put(`admin/pricing-plans/${editId}`,p);await load();setEditId(null)}catch{alert('Save failed')};setSaving(false)}
  async function del(id:unknown){if(!confirm('Delete this plan?'))return;try{await api.delete(`admin/pricing-plans/${id}`);await load()}catch{alert('Delete failed')}}
  return (
    <PageWrap title="Pricing Plans Manager" subtitle="Manage the pricing plans shown on the Pricing page." action={<button onClick={()=>openEdit()} style={{background:s,color:'#fff',border:'none',padding:'10px 20px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>+ Add Plan</button>}>
      {editId!==null&&<PageCard style={{marginBottom:20,border:`2px solid ${s}`}}>
        <h3 style={{fontSize:16,fontWeight:700,margin:'0 0 16px'}}>{editId==='new'?'New Plan':'Edit Plan'}</h3>
        <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:12,marginBottom:12}}>
          {([['name','Plan Name','Starter / Basic / Pro'],['subtitle','Subtitle','Property Valuation'],['price','Price','Free / ₹5,000 / 1 Month Rent'],['price_note','Price Note (optional)','onwards / per year']] as [string,string,string][]).map(([key,label,ph])=><input key={key} value={form[key as keyof typeof form] as string} onChange={e=>setForm(f=>({...f,[key]:e.target.value}))} placeholder={`${label}: ${ph}`} style={{padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}} />)}
        </div>
        <div style={{display:'flex',gap:12,marginBottom:12,alignItems:'center',flexWrap:'wrap'}}>
          <div style={{display:'flex',gap:8,alignItems:'center'}}><label style={{fontSize:13,fontWeight:600,color:'#374151'}}>Card Color:</label><input type="color" value={form.color} onChange={e=>setForm(f=>({...f,color:e.target.value}))} style={{width:44,height:36,borderRadius:6,border:'1px solid #e0e0e0',cursor:'pointer',padding:2}} /></div>
          <label style={{display:'flex',alignItems:'center',gap:8,cursor:'pointer',fontSize:14,color:'#374151'}}><input type="checkbox" checked={form.popular} onChange={e=>setForm(f=>({...f,popular:e.target.checked}))} /> Mark as Most Popular</label>
        </div>
        <div style={{marginBottom:12}}>
          <label style={{display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5}}>Features (one per line)</label>
          <textarea rows={8} value={form.features} onChange={e=>setForm(f=>({...f,features:e.target.value}))} placeholder={`Property Listing\nProperty Inspection\nCustomer Support`} style={{width:'100%',padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none',resize:'vertical',boxSizing:'border-box' as const}} />
        </div>
        <div style={{display:'flex',gap:10}}>
          <button onClick={save} disabled={saving} style={{background:s,color:'#fff',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>{saving?'Saving…':editId==='new'?'Create Plan':'Save Changes'}</button>
          <button onClick={()=>setEditId(null)} style={{background:'#f3f4f6',color:'#374151',border:'none',padding:'10px 22px',borderRadius:8,fontWeight:600,fontSize:14,cursor:'pointer'}}>Cancel</button>
        </div>
      </PageCard>}
      {loading?<Spinner/>:plans.length===0?<Empty icon="💰" title="No pricing plans" description="Add pricing plans to display on the Pricing page." action={<Btn onClick={()=>openEdit()}>Add First Plan</Btn>}/>:(
        <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(260px,1fr))',gap:20}}>
          {plans.map(p=><PageCard key={String(p.id)} style={{border:p.popular?`2px solid ${s}`:'1px solid #EBF0F8'}}>
            {p.popular ? <div style={{fontSize:11,fontWeight:700,background:s,color:'#fff',padding:'3px 10px',borderRadius:99,display:'inline-block',marginBottom:8}}>⭐ MOST POPULAR</div> : null}
            <div style={{fontWeight:800,fontSize:18,color:String(p.color||'#374151')}}>{String(p.name)}</div>
            <div style={{fontSize:13,color:'#6b7280',marginBottom:8}}>{String(p.subtitle||'')}</div>
            <div style={{fontSize:22,fontWeight:900,color:String(p.color||'#374151'),marginBottom:4}}>{String(p.price)}{p.price_note ? <span style={{fontSize:13,fontWeight:500,color:'#6b7280'}}> {String(p.price_note)}</span> : null}</div>
            <div style={{fontSize:12,color:'#555',margin:'12px 0',lineHeight:1.7}}>{((p.features as string[])||[]).slice(0,4).map((f,i)=><div key={i}>✓ {f}</div>)}{((p.features as string[])||[]).length>4&&<div>+{((p.features as string[])||[]).length-4} more…</div>}</div>
            <div style={{display:'flex',gap:8}}>
              <button onClick={()=>openEdit(p)} style={{flex:1,fontSize:12,padding:'6px 14px',border:`1px solid ${s}`,borderRadius:6,color:s,background:'transparent',cursor:'pointer',fontWeight:600}}>Edit</button>
              <button onClick={()=>del(p.id)} style={{fontSize:12,padding:'6px 14px',border:'1px solid #ef4444',borderRadius:6,color:'#ef4444',background:'transparent',cursor:'pointer',fontWeight:600}}>Delete</button>
            </div>
          </PageCard>)}
        </div>
      )}
    </PageWrap>
  )
}
// ── AdminCities (tt) — with image upload + reseed patterns ────────────────────
export function AdminCities() {
  const s=useStore(x=>x.settings).primary_color||'#4A6FA5'
  const [cities,setCities]=useState<Record<string,unknown>[]>([]),[loading,setLoading]=useState(true)
  const [editId,setEditId]=useState<string|number|null>(null),[form,setForm]=useState({name:'',slug:'',state:'',tagline:'',image_url:'',sort_order:0,is_active:1})
  const [saving,setSaving]=useState(false),[uploading,setUploading]=useState(false),[err,setErr]=useState(''),[ok,setOk]=useState('')
  const slugify=(t:string)=>t.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')
  function load(){api.get<{cities:Record<string,unknown>[]}>('admin/cities').then(d=>{setCities(d.cities||[]);setLoading(false)}).catch(()=>setLoading(false))}
  useEffect(()=>{load()},[])
  function openNew(){setEditId('new');setForm({name:'',slug:'',state:'',tagline:'',image_url:'',sort_order:cities.length+1,is_active:1});setErr('')}
  function openEdit(city:Record<string,unknown>){setEditId(city.id as number);setForm({name:String(city.name||''),slug:String(city.slug||''),state:String(city.state||''),tagline:String(city.tagline||''),image_url:String(city.image_url||''),sort_order:Number(city.sort_order||0),is_active:Number(city.is_active??1)});setErr('')}
  async function save(){
    if(!form.name.trim()){setErr('City name is required.');return}
    const slug=form.slug||slugify(form.name);setSaving(true);setErr('')
    try{if(editId==='new'){await api.post('admin/cities',{...form,slug});setOk('City added successfully.')}else{await api.put(`admin/cities/${editId}`,{...form,slug});setOk('City updated.')};setEditId(null);load()}catch(e:unknown){setErr((e as{message:string}).message)};setSaving(false)
  }
  async function toggle(city:Record<string,unknown>){try{await api.patch(`admin/cities/${city.id}/toggle`,{});setOk(`City ${Number(city.is_active)?'hidden':'shown'} on website.`);load()}catch(e:unknown){setErr((e as{message:string}).message)}}
  async function del(city:Record<string,unknown>){if(!confirm(`Delete city "${city.name}"? This cannot be undone.`))return;try{await api.delete(`admin/cities/${city.id}`);setOk('City deleted.');load()}catch(e:unknown){setErr((e as{message:string}).message)}}
  async function uploadImg(catId:number|string){
    setUploading(true)
    const url=await uploadImageToEndpoint(`admin/cities/${catId}/image`)
    if(url){setOk('Image uploaded.');if(editId===catId)setForm(f=>({...f,image_url:url}));load()}else setErr('Upload failed.');setUploading(false)
  }
  return (
    <div>
      <PageHeader title="City Manager" subtitle="Manage cities shown on homepage and city SEO pages" action={<button onClick={openNew} style={{background:s,color:'#fff',border:'none',padding:'10px 20px',borderRadius:8,fontWeight:700,fontSize:14,cursor:'pointer'}}>+ Add City</button>} />
      <Alert type="error" message={err} onClose={()=>setErr('')} /><Alert type="success" message={ok} onClose={()=>setOk('')} />
      {editId!==null&&<Card style={{marginBottom:20,border:`2px solid ${s}`}}>
        <h3 style={{fontSize:16,fontWeight:700,margin:'0 0 16px'}}>{editId==='new'?'Add New City':`Edit: ${form.name}`}</h3>
        {err&&<Alert type="error" message={err} onClose={()=>setErr('')} />}
        <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:12}}>
          <FormInput label="City Name *" value={form.name} onChange={e=>{const newName=e.target.value;setForm(f=>{const wasAutoSlug=!f.slug||f.slug===slugify(f.name);return{...f,name:newName,slug:wasAutoSlug?slugify(newName):f.slug}})}} required placeholder="e.g. Pune" />
          <FormInput label="State" value={form.state} onChange={e=>setForm(f=>({...f,state:e.target.value}))} placeholder="e.g. Maharashtra" />
          <FormInput label="URL Slug" value={form.slug} onChange={e=>setForm(f=>({...f,slug:e.target.value}))} placeholder="pune" hint="Auto-generated from city name" />
          <FormInput label="Sort Order" type="number" value={String(form.sort_order)} onChange={e=>setForm(f=>({...f,sort_order:parseInt(e.target.value)||0}))} />
          <FormInput label="Tagline" value={form.tagline} onChange={e=>setForm(f=>({...f,tagline:e.target.value}))} placeholder="IT capital of India — trusted property management" />
          <div style={{marginBottom:14}}>
            <label style={{display:'block',fontSize:13,fontWeight:600,color:'#374151',marginBottom:5}}>Image</label>
            <div style={{display:'flex',gap:8}}>
              <input value={form.image_url} onChange={e=>setForm(f=>({...f,image_url:e.target.value}))} placeholder="https://… or upload →" style={{flex:1,padding:'10px 14px',border:'1px solid #e0e0e0',borderRadius:8,fontSize:14,fontFamily:'inherit',outline:'none'}} />
              {editId!=='new'&&<button type="button" onClick={()=>uploadImg(editId as number)} disabled={uploading} style={{padding:'0 12px',border:`1px solid ${s}`,borderRadius:8,background:'transparent',color:s,cursor:'pointer',fontWeight:600,fontSize:13}}>{uploading?'…':'📷'}</button>}
            </div>
          </div>
        </div>
        <label style={{display:'flex',alignItems:'center',gap:8,cursor:'pointer',fontSize:14,color:'#374151',marginTop:8,marginBottom:16}}><input type="checkbox" checked={!!form.is_active} onChange={e=>setForm(f=>({...f,is_active:e.target.checked?1:0}))} /> Active (visible on website)</label>
        <div style={{display:'flex',gap:10}}><Btn onClick={save} loading={saving}>{editId==='new'?'Add City':'Save Changes'}</Btn><Btn variant="ghost" onClick={()=>{setEditId(null);setErr('')}}>Cancel</Btn></div>
      </Card>}
      {loading?<Spinner/>:cities.length===0?<Empty icon="🏙️" title="No cities" description="Add cities to create SEO landing pages and show on the homepage." action={<Btn onClick={openNew}>Add First City</Btn>}/>:(
        <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(240px,1fr))',gap:16}}>
          {cities.map(city=><Card key={String(city.id)}>
            <div style={{display:'flex',gap:12,alignItems:'flex-start',marginBottom:10}}>
              {city.image_url?<img src={String(city.image_url)} alt={String(city.name)} style={{width:56,height:56,borderRadius:8,objectFit:'cover',flexShrink:0}} />:<div style={{width:56,height:56,background:`${s}15`,borderRadius:8,display:'flex',alignItems:'center',justifyContent:'center',fontSize:26,flexShrink:0}}>🏙️</div>}
              <div style={{flex:1,minWidth:0}}>
                <div style={{fontWeight:700,fontSize:15,color:'#1E2D40'}}>{String(city.name)}</div>
                <div style={{fontSize:13,color:'#9ca3af'}}>{String(city.state||'')} · Sort: {String(city.sort_order)}</div>
                <StatusBadge status={city.is_active?'verified':'cancelled'} label={city.is_active?'Active':'Hidden'} />
              </div>
            </div>
            {city.tagline ? <p style={{margin:'0 0 12px',fontSize:12,color:'#6b7280',lineHeight:1.5}}>{String(city.tagline).slice(0,80)}…</p> : null}
            <div style={{display:'flex',gap:8}}>
              <Btn variant="ghost" onClick={()=>openEdit(city)} style={{flex:1,justifyContent:'center',fontSize:13}}>Edit</Btn>
              <Btn variant={city.is_active?'ghost':'primary'} onClick={()=>toggle(city)} style={{fontSize:13}}>{city.is_active?'Hide':'Show'}</Btn>
              <Btn variant="ghost" onClick={()=>uploadImg(Number(city.id))} disabled={uploading} style={{fontSize:13}}>📷</Btn>
              <Btn variant="danger" onClick={()=>del(city)} style={{fontSize:13}}>Delete</Btn>
            </div>
          </Card>)}
        </div>
      )}
    </div>
  )
}
// ── Service Page Builder sub-components (je, re, it, me, nt, ot, st, at) ──────
// Section type metadata (ye[] + we{}) — exact match from compiled bundle
const SECTION_TYPES = [
  {type:'trust_badges',label:'Trust Badges (Stats Row)',icon:'🏅',color:'#4A6FA5'},
  {type:'description',label:'Service Description',icon:'📄',color:'#1E2D40'},
  {type:'documents',label:'Required Documents',icon:'📎',color:'#4527a0'},
  {type:'process',label:'How the Process Works',icon:'⚙️',color:'#7b1fa2'},
  {type:'security',label:'🔐 Is My Data Secure?',icon:'🔐',color:'#1b5e20'},
  {type:'charges',label:'💰 Charges & Payment',icon:'💰',color:'#e65100'},
  {type:'faq',label:'FAQs',icon:'❓',color:'#1E2D40'},
  {type:'benefits',label:'Benefits',icon:'✅',color:'#2e7d32'},
  {type:'features',label:'Features',icon:'⭐',color:'#f57f17'},
  {type:'eligibility',label:'Eligibility Criteria',icon:'🎯',color:'#ad1457'},
  {type:'highlights',label:'Service Highlights',icon:'🏆',color:'#00695c'},
  {type:'testimonials',label:'Testimonials',icon:'💬',color:'#1E2D40'},
  {type:'notes',label:'Important Notes',icon:'📌',color:'#bf360c'},
  {type:'why_choose',label:'Why Choose Us',icon:'🌟',color:'#37474f'},
  {type:'related',label:'Related Services',icon:'🔗',color:'#546e7a'},
  {type:'cta',label:'CTA / Call to Action',icon:'🚀',color:'#880e4f'},
  {type:'text',label:'Custom Text Block',icon:'✏️',color:'#455a64'},
]
const SECTION_META = Object.fromEntries(SECTION_TYPES.map(t=>[t.type,t]))
// Default content for each section type (Ce{} exact match)
const DEFAULT_CONTENT: Record<string,unknown> = {
  trust_badges:{badges:[{icon:'⭐',value:'4.9',label:'Google Rating'},{icon:'👥',value:'10,000+',label:'Happy Customers'},{icon:'🌏',value:'750+',label:'Pan India Coverage'}]},
  description:{heading:'Complete Guide for NRIs',html:'<p>Add your service description here…</p>'},
  documents:{items:[]},
  process:{steps:[{title:'Submit Request',desc:'Fill the form on this page. No login needed.'},{title:'Document Review',desc:'Our expert team reviews your submission within 24 hours.'},{title:'Get Quote',desc:'Receive a detailed, itemised quote. No payment until you approve.'},{title:'Processing',desc:'We handle everything on the ground in India with regular updates.'},{title:'Delivery',desc:'Documents delivered to your overseas address by tracked courier.'}]},
  security:{html:'<p>Your data is protected using AES-256 encryption. Documents are never shared via email or WhatsApp, and all copies are deleted after processing.</p>'},
  charges:{html:'<p>Get a personalised quote after submitting your request. Payment in two parts — initial deposit then balance on completion.</p>',note:''},
  faq:{items:[{q:'How long does it take?',a:'Typically 7–15 business days depending on document completeness.'}]},
  benefits:{items:[{icon:'✅',text:'Professional expert handling'}]},
  features:{items:[{icon:'⭐',title:'Feature Title',desc:'Feature description.'}]},
  eligibility:{items:['Valid passport holder','Non-Resident Indian (NRI)']},
  highlights:{items:[{icon:'🏆',title:'Highlight',desc:'Why this matters.'}]},
  testimonials:{items:[{name:'Priya S.',location:'London, UK',rating:5,text:'Excellent service, handled everything smoothly!'}]},
  notes:{items:['Please ensure all documents are self-attested.']},
  why_choose:{cards:[{icon:'🔐',title:'Secure Platform',desc:'AES-256 encrypted.'},{icon:'📊',title:'Real-Time Tracking',desc:'Track every step.'},{icon:'💰',title:'Money-Back Guarantee',desc:'Full refund if we fail.'}]},
  related:{slugs:[]},
  cta:{headline:'Ready to get started?',sub:'Expert assistance. Transparent pricing. Tracked at every step.',btn:'Get Free Quote',url:'#booking-form'},
  text:{html:'<p>Custom content goes here…</p>'},
}
// CodeEditor (je) — contentEditable rich text with toolbar
function CodeEditor({value,onChange}:{value:string;onChange:(v:string)=>void}){
  const ref=useRef<HTMLDivElement>(null)
  useEffect(()=>{if(ref.current)ref.current.innerHTML=value||''},[])
  const cmd=(c:string,v?:string)=>{document.execCommand(c,false,v||undefined);ref.current?.focus()}
  const ToolBtn=({label,c,val}:{label:string;c:string;val?:string})=><button type="button" onMouseDown={e=>{e.preventDefault();cmd(c,val)}} style={{padding:'3px 8px',fontSize:12,border:'1px solid #d1d5db',borderRadius:4,cursor:'pointer',background:'#fff',fontWeight:c==='bold'?700:400,fontStyle:c==='italic'?'italic':'normal'}}>{label}</button>
  return <div style={{border:'1.5px solid #d1d5db',borderRadius:8,overflow:'hidden'}}>
    <div style={{background:'#F5F7FA',borderBottom:'1px solid #e5e7eb',padding:'5px 8px',display:'flex',flexWrap:'wrap',gap:4}}>
      <ToolBtn label="B" c="bold"/><ToolBtn label="I" c="italic"/><ToolBtn label="U" c="underline"/>
      <ToolBtn label="H2" c="formatBlock" val="h2"/><ToolBtn label="H3" c="formatBlock" val="h3"/><ToolBtn label="P" c="formatBlock" val="p"/>
      <ToolBtn label="• List" c="insertUnorderedList"/><ToolBtn label="1. List" c="insertOrderedList"/>
      <button type="button" onMouseDown={e=>{e.preventDefault();const url=prompt('URL:');if(url)cmd('createLink',url)}} style={{padding:'3px 8px',fontSize:12,border:'1px solid #d1d5db',borderRadius:4,cursor:'pointer',background:'#fff'}}>🔗</button>
      <button type="button" onMouseDown={e=>{e.preventDefault();cmd('removeFormat')}} style={{padding:'3px 8px',fontSize:12,border:'1px solid #d1d5db',borderRadius:4,cursor:'pointer',background:'#fff'}}>✕</button>
    </div>
    <div ref={ref} contentEditable suppressContentEditableWarning onInput={e=>onChange(e.currentTarget.innerHTML)} style={{minHeight:120,padding:'10px 12px',fontSize:14,lineHeight:1.75,outline:'none',fontFamily:'inherit'}} />
  </div>
}
// SchemaFieldList (re) — sortable list of structured items
function SchemaFieldList({items,onChange,schema,addLabel}:{items:Record<string,unknown>[];onChange:(v:Record<string,unknown>[])=>void;schema:Array<{key:string;label:string;type:string}>;addLabel?:string}){
  const newItem=()=>{const a:Record<string,unknown>={};schema.forEach(d=>{a[d.key]=d.type==='number'?5:''});return a}
  const remove=(i:number)=>onChange(items.filter((_,j)=>j!==i))
  const update=(i:number,k:string,v:unknown)=>onChange(items.map((x,j)=>j===i?{...x,[k]:v}:x))
  const move=(i:number,dir:number)=>{const l=[...items],j=i+dir;if(j<0||j>=l.length)return;[l[i],l[j]]=[l[j],l[i]];onChange(l)}
  return <div>
    {items.map((item,i)=><div key={i} style={{border:'1px solid #e5e7eb',borderRadius:8,padding:10,marginBottom:8,background:'#fafafa'}}>
      <div style={{display:'flex',justifyContent:'space-between',marginBottom:8}}>
        <span style={{fontSize:12,fontWeight:700,color:'#6b7280'}}>Item {i+1}</span>
        <div style={{display:'flex',gap:4}}>
          {([['\u2191',-1],['\u2193',1]] as [string,number][]).map(([lbl,dir])=><button key={lbl} type="button" onClick={()=>move(i,dir)} disabled={dir===-1?i===0:i===items.length-1} style={{padding:'2px 6px',fontSize:11,border:'1px solid #d1d5db',borderRadius:4,cursor:'pointer',background:'#fff',opacity:(dir===-1?i===0:i===items.length-1)?.4:1}}>{lbl}</button>)}
          <button type="button" onClick={()=>remove(i)} style={{padding:'2px 6px',fontSize:11,border:'1px solid #fca5a5',borderRadius:4,cursor:'pointer',background:'#fff',color:'#dc2626'}}>✕</button>
        </div>
      </div>
      {schema.map(field=><div key={field.key} style={{marginBottom:5}}>
        <label style={{fontSize:11,fontWeight:600,color:'#374151',display:'block',marginBottom:2}}>{field.label}</label>
        {field.type==='textarea'?<textarea value={String(item[field.key]||'')} onChange={e=>update(i,field.key,e.target.value)} rows={3} style={{width:'100%',padding:'5px 8px',fontSize:13,border:'1px solid #d1d5db',borderRadius:6,fontFamily:'inherit',boxSizing:'border-box' as const,resize:'vertical'}}/>:field.type==='number'?<input type="number" min={1} max={5} value={Number(item[field.key])||5} onChange={e=>update(i,field.key,+e.target.value)} style={{width:60,padding:'5px 8px',fontSize:13,border:'1px solid #d1d5db',borderRadius:6}}/>:<input value={String(item[field.key]||'')} onChange={e=>update(i,field.key,e.target.value)} style={{width:'100%',padding:'5px 8px',fontSize:13,border:'1px solid #d1d5db',borderRadius:6}}/>}
      </div>)}
    </div>)}
    <button type="button" onClick={()=>onChange([...items,newItem()])} style={{width:'100%',padding:'7px',border:'2px dashed #d1d5db',borderRadius:7,cursor:'pointer',fontSize:13,color:'#6b7280',background:'transparent'}}>+ {addLabel||'Add Item'}</button>
  </div>
}
// TagList (it) — simple string list with reorder
function TagList({items,onChange,placeholder}:{items:string[];onChange:(v:string[])=>void;placeholder?:string}){
  const remove=(i:number)=>onChange(items.filter((_,j)=>j!==i))
  const update=(i:number,v:string)=>onChange(items.map((x,j)=>j===i?v:x))
  const move=(i:number,dir:number)=>{const l=[...items],j=i+dir;if(j<0||j>=l.length)return;[l[i],l[j]]=[l[j],l[i]];onChange(l)}
  return <div>
    {items.map((item,i)=><div key={i} style={{display:'flex',gap:5,marginBottom:6}}>
      <input value={item} onChange={e=>update(i,e.target.value)} placeholder={placeholder||'Item'} style={{flex:1,padding:'6px 10px',fontSize:13,border:'1px solid #d1d5db',borderRadius:6}}/>
      {([['\u2191',-1],['\u2193',1]] as [string,number][]).map(([lbl,dir])=><button key={lbl} type="button" onClick={()=>move(i,dir)} disabled={dir===-1?i===0:i===items.length-1} style={{padding:'4px 7px',fontSize:11,border:'1px solid #d1d5db',borderRadius:5,cursor:'pointer',background:'#fff',opacity:(dir===-1?i===0:i===items.length-1)?.4:1}}>{lbl}</button>)}
      <button type="button" onClick={()=>remove(i)} style={{padding:'4px 7px',fontSize:11,border:'1px solid #fca5a5',borderRadius:5,cursor:'pointer',background:'#fff',color:'#dc2626'}}>✕</button>
    </div>)}
    <button type="button" onClick={()=>onChange([...items,''])} style={{width:'100%',padding:'6px',border:'2px dashed #d1d5db',borderRadius:7,cursor:'pointer',fontSize:13,color:'#6b7280',background:'transparent'}}>+ Add Item</button>
  </div>
}
// FormRow (me) — simple labeled input/textarea
function FormRow({label,value,onChange,type='text',rows,placeholder}:{label:string;value:string;onChange:(v:string)=>void;type?:string;rows?:number;placeholder?:string}){
  const st={width:'100%',padding:'7px 10px',fontSize:13,border:'1px solid #d1d5db',borderRadius:6,boxSizing:'border-box' as const,fontFamily:'inherit'}
  return <div style={{marginBottom:10}}>
    <label style={{fontSize:12,fontWeight:700,color:'#374151',display:'block',marginBottom:3}}>{label}</label>
    {type==='textarea'?<textarea value={value} onChange={e=>onChange(e.target.value)} rows={rows||3} placeholder={placeholder} style={st}/>:<input type={type} value={value} onChange={e=>onChange(e.target.value)} placeholder={placeholder} style={st}/>}
  </div>
}
// SectionEditor (nt) — per-type content editor
function SectionEditor({section,onUpdate}:{section:Record<string,unknown>;onUpdate:(patch:Record<string,unknown>)=>void}){
  const y=(section.content||DEFAULT_CONTENT[String(section.type)]||{}) as Record<string,unknown>
  const u=(k:string,v:unknown)=>onUpdate({content:{...y,[k]:v}})
  const type=String(section.type)
  if(type==='description')return<div><FormRow label="Heading (shown above description)" value={String(y.heading||'')} onChange={v=>u('heading',v)} placeholder="Complete Guide for NRIs"/><label style={{fontSize:12,fontWeight:700,color:'#374151',display:'block',marginBottom:4}}>Content (Rich Text)</label><CodeEditor value={String(y.html||'')} onChange={v=>u('html',v)}/></div>
  if(type==='security'||type==='text')return<div><label style={{fontSize:12,fontWeight:700,color:'#374151',display:'block',marginBottom:4}}>Content (Rich Text)</label><CodeEditor value={String(y.html||'')} onChange={v=>u('html',v)}/></div>
  if(type==='charges')return<div><label style={{fontSize:12,fontWeight:700,color:'#374151',display:'block',marginBottom:4}}>Pricing Details (Rich Text)</label><CodeEditor value={String(y.html||'')} onChange={v=>u('html',v)}/><FormRow label="Price Badge (e.g. Starting from ₹5,000)" value={String(y.note||'')} onChange={v=>u('note',v)} placeholder="Starting from ₹5,000"/></div>
  if(type==='trust_badges')return<SchemaFieldList items={(y.badges||[]) as Record<string,unknown>[]} onChange={v=>u('badges',v)} addLabel="Add Badge" schema={[{key:'icon',label:'Icon (emoji)',type:'text'},{key:'value',label:'Value (e.g. 4.9)',type:'text'},{key:'label',label:'Label',type:'text'}]}/>
  if(type==='process')return<SchemaFieldList items={(y.steps||[]) as Record<string,unknown>[]} onChange={v=>u('steps',v)} addLabel="Add Step" schema={[{key:'title',label:'Step Title',type:'text'},{key:'desc',label:'Description',type:'textarea'}]}/>
  if(type==='faq')return<SchemaFieldList items={(y.items||[]) as Record<string,unknown>[]} onChange={v=>u('items',v)} addLabel="Add FAQ" schema={[{key:'q',label:'Question',type:'text'},{key:'a',label:'Answer',type:'textarea'}]}/>
  if(type==='benefits')return<SchemaFieldList items={(y.items||[]) as Record<string,unknown>[]} onChange={v=>u('items',v)} addLabel="Add Benefit" schema={[{key:'icon',label:'Icon (emoji)',type:'text'},{key:'text',label:'Benefit text',type:'text'}]}/>
  if(type==='features'||type==='highlights')return<SchemaFieldList items={(y.items||[]) as Record<string,unknown>[]} onChange={v=>u('items',v)} addLabel="Add Item" schema={[{key:'icon',label:'Icon',type:'text'},{key:'title',label:'Title',type:'text'},{key:'desc',label:'Description',type:'textarea'}]}/>
  if(type==='why_choose')return<SchemaFieldList items={(y.cards||[]) as Record<string,unknown>[]} onChange={v=>u('cards',v)} addLabel="Add Card" schema={[{key:'icon',label:'Icon',type:'text'},{key:'title',label:'Title',type:'text'},{key:'desc',label:'Description',type:'textarea'}]}/>
  if(type==='documents'||type==='eligibility'||type==='notes')return<TagList items={(y.items||[]) as string[]} onChange={v=>u('items',v)} placeholder="Enter item…"/>
  if(type==='testimonials')return<SchemaFieldList items={(y.items||[]) as Record<string,unknown>[]} onChange={v=>u('items',v)} addLabel="Add Testimonial" schema={[{key:'name',label:'Name',type:'text'},{key:'location',label:'Location',type:'text'},{key:'rating',label:'Rating 1-5',type:'number'},{key:'text',label:'Review',type:'textarea'}]}/>
  if(type==='related')return<div><label style={{fontSize:12,fontWeight:700,color:'#374151',display:'block',marginBottom:4}}>Service slugs (one per line)</label><textarea value={((y.slugs||[]) as string[]).join('\n')} rows={4} onChange={e=>u('slugs',e.target.value.split('\n').map(r=>r.trim()).filter(Boolean))} placeholder={'birth-certificate\napostille'} style={{width:'100%',padding:'8px 10px',fontSize:13,border:'1px solid #d1d5db',borderRadius:7,fontFamily:'inherit',boxSizing:'border-box' as const}}/></div>
  if(type==='cta')return<div>{[['headline','Headline'],['sub','Subheading'],['btn','Button Label'],['url','Button URL']].map(([k,l])=><FormRow key={k} label={l} value={String(y[k]||'')} onChange={v=>u(k,v)}/>)}</div>
  return<p style={{color:'#9ca3af',fontSize:13}}>No editor for type "{type}"</p>
}
// SectionCard (ot) — expandable card with drag/drop
function SectionCard({section,index,total,onUpdate,onDelete,onDuplicate,onToggle,onMove,primary}:{section:Record<string,unknown>;index:number;total:number;onUpdate:(patch:Record<string,unknown>)=>Promise<void>;onDelete:(s:Record<string,unknown>)=>void;onDuplicate:(s:Record<string,unknown>)=>void;onToggle:(s:Record<string,unknown>)=>void;onMove:(from:number,to:number)=>void;primary:string}){
  const [open,setOpen]=useState(false),[saving,setSaving]=useState(false),[draft,setDraft]=useState(section)
  const meta=SECTION_META[String(section.type)]||{label:String(section.type),icon:'📄',color:'#6b7280'}
  useEffect(()=>{setDraft(section)},[section])
  async function doSave(){setSaving(true);await onUpdate(draft);setSaving(false);setOpen(false)}
  const onDragStart=(e:React.DragEvent)=>e.dataTransfer.setData('sectionIndex',String(index))
  const onDrop=(e:React.DragEvent)=>{const from=parseInt(e.dataTransfer.getData('sectionIndex'));if(!isNaN(from)&&from!==index)onMove(from,index)}
  return <div draggable onDragStart={onDragStart} onDragOver={e=>e.preventDefault()} onDrop={onDrop} style={{border:`1.5px solid ${open?primary:'#e5e7eb'}`,borderRadius:10,marginBottom:10,background:'#fff',transition:'border-color .2s',opacity:section.is_visible?1:.5}}>
    <div style={{display:'flex',alignItems:'center',gap:10,padding:'12px 14px',cursor:'pointer'}} onClick={()=>setOpen(o=>!o)}>
      <span style={{fontSize:8,color:'#bbb',cursor:'grab'}}>⋮⋮</span>
      <div style={{width:32,height:32,borderRadius:8,background:`${meta.color}18`,display:'flex',alignItems:'center',justifyContent:'center',fontSize:16,flexShrink:0}}>{meta.icon}</div>
      <div style={{flex:1,minWidth:0}}>
        <div style={{fontWeight:700,fontSize:14,color:'#111827',display:'flex',alignItems:'center',gap:6}}>{String(section.title||meta.label)}{!section.is_visible&&<span style={{fontSize:10,background:'#fef3c7',color:'#92400e',padding:'1px 6px',borderRadius:99,fontWeight:600}}>Hidden</span>}</div>
        <div style={{fontSize:11,color:'#9ca3af'}}>{meta.label} · pos {String(section.sort_order)}</div>
      </div>
      <div style={{display:'flex',gap:4}} onClick={e=>e.stopPropagation()}>
        {([['\u2191',-1],['\u2193',1]] as [string,number][]).map(([lbl,dir])=><button key={lbl} type="button" onClick={()=>onMove(index,index+dir)} disabled={dir===-1?index===0:index===total-1} style={{padding:'3px 7px',fontSize:11,border:'1px solid #d1d5db',borderRadius:5,cursor:'pointer',background:'#fff',opacity:(dir===-1?index===0:index===total-1)?.4:1}}>{lbl}</button>)}
        <button type="button" title={section.is_visible?'Hide':'Show'} onClick={()=>onToggle(section)} style={{padding:'3px 7px',fontSize:11,border:'1px solid #d1d5db',borderRadius:5,cursor:'pointer',background:'#fff'}}>{section.is_visible?'🙈':'👁'}</button>
        <button type="button" title="Duplicate" onClick={()=>onDuplicate(section)} style={{padding:'3px 7px',fontSize:11,border:'1px solid #d1d5db',borderRadius:5,cursor:'pointer',background:'#fff'}}>⧉</button>
        <button type="button" title="Delete" onClick={()=>{if(window.confirm(`Delete "${String(section.title||meta.label)}"?`))onDelete(section)}} style={{padding:'3px 7px',fontSize:11,border:'1px solid #fca5a5',borderRadius:5,cursor:'pointer',background:'#fff',color:'#dc2626'}}>🗑</button>
        <button type="button" onClick={()=>setOpen(o=>!o)} style={{padding:'3px 9px',fontSize:11,border:`1px solid ${primary}`,borderRadius:5,cursor:'pointer',background:open?primary:'#fff',color:open?'#fff':primary,fontWeight:700}}>{open?'Close':'Edit'}</button>
      </div>
    </div>
    {open&&<div style={{padding:'0 14px 16px',borderTop:'1px solid #f0f0f0'}}>
      <FormRow label="Section Title (shown as heading on page)" value={String(draft.title||'')} onChange={v=>setDraft(d=>({...d,title:v}))} placeholder={meta.label}/>
      <div style={{marginBottom:12}}><label style={{fontSize:12,fontWeight:700,color:'#374151',display:'block',marginBottom:4}}>Content</label><SectionEditor section={draft} onUpdate={p=>setDraft(d=>({...d,...p}))}/></div>
      <button type="button" onClick={doSave} disabled={saving} style={{background:primary,color:'#fff',border:'none',padding:'9px 22px',borderRadius:7,fontWeight:700,fontSize:13,cursor:'pointer',opacity:saving?.7:1}}>{saving?'Saving…':'💾 Save Section'}</button>
    </div>}
  </div>
}
// AddSectionPanel (st)
function AddSectionPanel({onAdd,primary}:{onAdd:(type:string)=>void;primary:string}){
  const [open,setOpen]=useState(false)
  return open?<div style={{border:`2px solid ${primary}`,borderRadius:12,padding:16,marginTop:8,background:`${primary}04`}}>
    <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:12}}>
      <span style={{fontWeight:800,fontSize:15,color:'#111'}}>Choose Section Type</span>
      <button type="button" onClick={()=>setOpen(false)} style={{background:'none',border:'none',fontSize:18,cursor:'pointer',color:'#9ca3af'}}>✕</button>
    </div>
    <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(175px,1fr))',gap:8}}>
      {SECTION_TYPES.map(u=><button key={u.type} type="button" onClick={()=>{onAdd(u.type);setOpen(false)}} style={{display:'flex',alignItems:'center',gap:8,padding:'10px 12px',border:'1.5px solid #e5e7eb',borderRadius:9,cursor:'pointer',background:'#fff',fontWeight:600,fontSize:13,color:'#374151',textAlign:'left'}} onMouseEnter={e=>{e.currentTarget.style.borderColor=u.color;e.currentTarget.style.color=u.color}} onMouseLeave={e=>{e.currentTarget.style.borderColor='#e5e7eb';e.currentTarget.style.color='#374151'}}>
        <span style={{fontSize:18}}>{u.icon}</span><span style={{lineHeight:1.3}}>{u.label}</span>
      </button>)}
    </div>
  </div>:<button type="button" onClick={()=>setOpen(true)} style={{width:'100%',padding:'11px',border:`2px dashed ${primary}`,borderRadius:10,cursor:'pointer',fontSize:14,fontWeight:700,color:primary,background:`${primary}06`,marginTop:8}}>+ Add Section</button>
}
// ── AdminServicePageBuilder (at) ──────────────────────────────────────────────
export function AdminServicePageBuilder() {
  const {id}=useParams<{id:string}>(), nav=useNavigate()
  const settings=useStore(w=>w.settings), primary=settings.primary_color||'#4A6FA5'
  const [svc,setSvc]=useState<Record<string,unknown>|null>(null),[sections,setSections]=useState<Record<string,unknown>[]>([]),[loading,setLoading]=useState(true)
  const [feedback,setFeedback]=useState({type:'',text:''}), setMsg=(type:string,text:string)=>{setFeedback({type,text});setTimeout(()=>setFeedback({type:'',text:''}),4000)}
  const load=useCallback(async()=>{
    setLoading(true)
    try{
      const [s,sec]=await Promise.all([api.get<{services:Record<string,unknown>[]}>('admin/services?per_page=200'),api.get<{sections:Record<string,unknown>[]}>(`admin/services/${id}/sections`)])
      setSvc((s.services||[]).find(x=>String(x.id)===String(id))||null)
      const raw=sec.sections||[]
      const types=raw.map(x=>x.type);if(types.length!==new Set(types).size&&raw.length>0){
        try{const r=await api.post<{sections:Record<string,unknown>[];removed:number}>(`admin/services/${id}/sections/dedup`,{});setSections(r.sections||raw);if(r.removed>0)setMsg('success',`Removed ${r.removed} duplicate section(s) automatically.`)}catch{setSections(raw)}
      }else setSections(raw)
    }catch{setMsg('error','Failed to load.')}
    setLoading(false)
  },[id])
  useEffect(()=>{load()},[load])
  async function dedup(){try{const r=await api.post<{sections:Record<string,unknown>[];removed:number}>(`admin/services/${id}/sections/dedup`,{});setSections(r.sections||[]);setMsg('success',r.removed>0?`Removed ${r.removed} duplicate(s). Each section type now appears once.`:'No duplicates found — sections are already clean.')}catch{setMsg('error','Dedup failed.')}}
  async function seedDefaults(nuke=false){
    if(!svc)return
    const existing=Object.fromEntries(sections.map(x=>[x.type,x]))
    if(nuke){if(!confirm(`Delete all ${sections.length} existing sections and recreate everything?`))return;for(const sec of sections)try{await api.delete(`admin/services/${id}/sections/${sec.id}`)}catch{};setSections([])}
    else if(sections.length>0&&!confirm(`Update ${sections.length} existing sections and add missing ones? Existing edits on same-type sections will be overwritten.`))return
    let docs=svc.required_docs;if(typeof docs==='string')try{docs=JSON.parse(docs)}catch{docs=[]};if(!Array.isArray(docs))docs=[]
    const basePrice=svc.base_price?`Starting from ₹${parseFloat(String(svc.base_price)).toLocaleString('en-IN')}`:svc.price_min?`₹${parseFloat(String(svc.price_min)).toLocaleString('en-IN')} – ₹${parseFloat(String(svc.price_max||svc.price_min)).toLocaleString('en-IN')}`:''
    const seeds=[
      {type:'trust_badges',title:'Trust Badges',content:{badges:[{icon:'⭐',value:settings.google_rating||'4.9',label:'Google Rating'},{icon:'👥',value:settings.google_review_count||'10,000+',label:'Happy Customers'},{icon:'🌏',value:'750+',label:'Pan India Coverage'}]}},
      {type:'description',title:`${svc.name} — Complete Guide for NRIs`,content:{heading:`${svc.name} — Complete Guide for NRIs`,html:`<p>${String(svc.description||svc.short_desc||'')}</p>`}},
      ...((docs as string[]).length>0?[{type:'documents',title:'📎 Documents Required',content:{items:docs}}]:[]),
      {type:'process',title:'How the Process Works',content:DEFAULT_CONTENT.process},
      {type:'security',title:'Is My Data Secure?',content:DEFAULT_CONTENT.security},
      {type:'charges',title:'Charges & Payment',content:{html:(DEFAULT_CONTENT.charges as Record<string,unknown>).html,note:basePrice}},
      {type:'faq',title:'Frequently Asked Questions',content:DEFAULT_CONTENT.faq},
      {type:'why_choose',title:`Why Choose ${settings.platform_name||'Us'}?`,content:DEFAULT_CONTENT.why_choose},
      {type:'cta',title:'Get Started',content:DEFAULT_CONTENT.cta},
    ]
    let sortOrder=(nuke?0:sections.length)*10
    for(const seed of seeds){
      if(!nuke&&existing[seed.type]){try{await api.put(`admin/services/${id}/sections/${existing[seed.type].id}`,{...seed,sort_order:Number(existing[seed.type].sort_order)})}catch{}}
      else{try{await api.post(`admin/services/${id}/sections`,{...seed,sort_order:sortOrder,is_visible:1});sortOrder+=10}catch{}}
    }
    await load();setMsg('success',`${nuke?'Rebuilt':'Seeded'} ${seeds.length} sections.`)
  }
  async function updateSection(sec:Record<string,unknown>,patch:Record<string,unknown>){await api.put(`admin/services/${id}/sections/${sec.id}`,{...sec,...patch});setMsg('success','Section saved.');setSections(prev=>prev.map(x=>x.id===sec.id?{...x,...patch}:x))}
  async function deleteSection(sec:Record<string,unknown>){try{await api.delete(`admin/services/${id}/sections/${sec.id}`);setSections(prev=>prev.filter(x=>x.id!==sec.id));setMsg('success','Section deleted.')}catch(e:unknown){setMsg('error',(e as{message:string}).message)}}
  async function toggleSection(sec:Record<string,unknown>){await api.patch(`admin/services/${id}/sections/${sec.id}/toggle`,{});setSections(prev=>prev.map(x=>x.id===sec.id?{...x,is_visible:x.is_visible?0:1}:x))}
  async function duplicateSection(sec:Record<string,unknown>){try{const r=await api.post<{section:Record<string,unknown>}>(`admin/services/${id}/sections`,{...sec,id:undefined,sort_order:Number(sec.sort_order)+1,title:`${sec.title} (copy)`});setSections(prev=>[...prev,r.section]);setMsg('success','Section duplicated.')}catch(e:unknown){setMsg('error',(e as{message:string}).message)}}
  async function addSection(type:string){try{const r=await api.post<{section:Record<string,unknown>}>(`admin/services/${id}/sections`,{type,title:SECTION_META[type]?.label||type,content:DEFAULT_CONTENT[type]||{},sort_order:(sections.length+1)*10,is_visible:1});setSections(prev=>[...prev,r.section]);setMsg('success','Section added.')}catch(e:unknown){setMsg('error',(e as{message:string}).message)}}
  function moveSection(from:number,to:number){const l=[...sections];[l[from],l[to]]=[l[to],l[from]];setSections(l)}
  if(loading)return<Spinner/>
  return (
    <div>
      <div style={{marginBottom:16,display:'flex',gap:8,alignItems:'center'}}><Link to="/admin/services" style={{color:primary,textDecoration:'none',fontSize:14}}>← Services</Link><span>/</span><span style={{fontSize:14,color:'#374151'}}>{String(svc?.name||id)} — Page Builder</span></div>
      {feedback.text&&<Alert type={feedback.type==='success'?'success':'error'} message={feedback.text} onClose={()=>setFeedback({type:'',text:''})} />}
      <div style={{display:'grid',gridTemplateColumns:'1fr 280px',gap:20}}>
        <div>
          {sections.length===0?<Empty icon="🔧" title="No sections yet" description="This service page uses the default layout. Add sections to customise it." />:sections.map((sec,i)=><SectionCard key={String(sec.id)} section={sec} index={i} total={sections.length} primary={primary} onUpdate={patch=>updateSection(sec,patch)} onDelete={deleteSection} onDuplicate={duplicateSection} onToggle={toggleSection} onMove={moveSection}/>)}
          <AddSectionPanel onAdd={addSection} primary={primary}/>
        </div>
        <div>
          <Card style={{marginBottom:16}}>
            <h3 style={{fontSize:14,fontWeight:700,color:'#374151',margin:'0 0 12px'}}>🔧 Actions</h3>
            <div style={{display:'flex',flexDirection:'column',gap:8}}>
              <Btn onClick={()=>seedDefaults(false)} style={{justifyContent:'center'}}>✨ Seed Defaults</Btn>
              <Btn variant="ghost" onClick={dedup} style={{justifyContent:'center'}}>🔁 Remove Duplicates</Btn>
              <Btn variant="danger" onClick={()=>seedDefaults(true)} style={{justifyContent:'center'}}>💥 Nuke & Reseed</Btn>
            </div>
          </Card>
          <Card>
            <h3 style={{fontSize:12,fontWeight:800,color:'#374151',margin:'0 0 10px'}}>💡 Tips</h3>
            <ul style={{margin:0,padding:'0 0 0 16px',fontSize:12,color:'#6b7280',lineHeight:2}}>
              <li>Drag cards to reorder</li><li>🙈 hides from live page</li>
              <li>⧉ duplicates a section</li><li>Each section saves independently</li>
              <li>Changes are live immediately after save</li>
            </ul>
          </Card>
        </div>
      </div>
    </div>
  )
}
