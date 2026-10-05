/**
 * Customer dashboard pages — exact match of compiled components in dashboard-GjnDbqIA.js:
 *   be  → CustomerDashboard  (stats, recent bookings, quick actions)
 *   ve  → BookingListPage    (paginated, status filter)
 *   Se  → BookingDetailPage  (messages, docs, quote approve/reject, razorpay, bank transfer)
 *   ke  → NewBookingPage     (from /dashboard/bookings/new?service=ID)
 *   we  → ProfilePage        (name, phone, whatsapp, country, password change)
 *   _e  → TicketsPage        (list + create modal)
 */

import React, { useState, useEffect, useCallback } from 'react'
import { resolvePrimary } from '@/lib/design-tokens'
import { Link, useParams, useNavigate, useSearchParams } from 'react-router-dom'
import { SidebarLayout } from '@/components/layout/Layout'
import { Card, PageHeader, Button, Alert, Modal, FormInput, Textarea, EmptyState, StatusBadge } from '@/components/ui'
import { DetailPanelSkeleton } from '@/components/ui/LoadingPlaceholders'
import { useStore } from '@/lib/store'
import { useT } from '@/lib/i18n'
import { api } from '@/lib/api'
import { useResource } from '@/lib/useResource'
import { prefetchForRoute } from '@/lib/prefetch'
import { StatCardsSkeleton, TableSkeleton } from '@/components/ui/LoadingPlaceholders'
import { BOOKING_STEPS, STAFF_ROLES } from '@/lib/constants'
import type { Booking, Ticket, UserProfile } from '@/types'
import { AdminScreen, AdminToolbar, AdminFormStack } from '@/components/admin/AdminMobileUi'

// ── Auth guard for customer portal (pe wrapper) ───────────────────────────────
export function CustomerPortal({ children }: { children: React.ReactNode }) {
  const user = useStore((s) => s.user)
  const nav  = useNavigate()

  useEffect(() => {
    if (!user) nav('/login?redirect=' + window.location.pathname, { replace: true })
    else {
      prefetchForRoute('/dashboard')
      prefetchForRoute('/dashboard/bookings')
    }
  }, [user])

  if (!user) return null

  return <SidebarLayout>{children}</SidebarLayout>
}

// ── BookingProgressBar (he component) ─────────────────────────────────────────
function BookingProgressBar({ status, primary }: { status: string; primary: string }) {
  const cancelled = ['cancelled', 'quote_rejected'].includes(status)
  const currentIdx = cancelled ? -1 : BOOKING_STEPS.findIndex((s) => s.key === status)

  if (cancelled) {
    return (
      <div style={{ padding: '16px 0 8px' }}>
        <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8, padding: '12px 16px', color: '#b91c1c', fontWeight: 600, fontSize: 14, textAlign: 'center' }}>
          {status === 'cancelled' ? '❌ Booking Cancelled' : '❌ Quote Rejected — Our team will follow up'}
        </div>
      </div>
    )
  }

  return (
    <div style={{ padding: '16px 0 8px' }}>
      <div style={{ display: 'flex', alignItems: 'center', overflowX: 'auto', paddingBottom: 4 }}>
        {BOOKING_STEPS.map((step, i) => {
          const done = i < currentIdx
          const curr = i === currentIdx
          return (
            <div key={step.key} style={{ display: 'flex', alignItems: 'center', flex: i < BOOKING_STEPS.length - 1 ? 1 : 'none' as const }}>
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }}>
                <div style={{ width: 36, height: 36, borderRadius: '50%', background: done || curr ? primary : '#e5e7eb', color: done || curr ? '#fff' : '#9ca3af', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 16, boxShadow: curr ? `0 0 0 4px ${primary}30` : 'none', transition: 'all .3s', fontWeight: 700 }}>
                  {done ? '✓' : step.icon}
                </div>
                <div style={{ fontSize: 10, fontWeight: curr ? 700 : 500, color: done || curr ? primary : '#9ca3af', marginTop: 4, whiteSpace: 'nowrap', textAlign: 'center' }}>
                  {step.label}
                </div>
              </div>
              {i < BOOKING_STEPS.length - 1 && (
                <div style={{ flex: 1, height: 3, background: done ? primary : '#e5e7eb', margin: '0 4px 14px', borderRadius: 2, transition: 'background .3s', minWidth: 16 }} />
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ── CustomerDashboard (be component) ──────────────────────────────────────────
export function CustomerDashboard() {
  const { t }      = useT()
  const user       = useStore((s) => s.user)
  const settings   = useStore((s) => s.settings)
  const loadNotifs = useStore((s) => s.loadNotifications)
  const primary    = resolvePrimary(settings)

  const { data, isInitialLoad, error: loadError, isRefreshing } = useResource(
    'bookings?per_page=5',
    () => api.getCached<{ rows: Booking[]; total: number }>('bookings?per_page=5'),
    { persist: true },
  )

  useEffect(() => {
    loadNotifs()
  }, [loadNotifs])

  if (loadError && !data) {
    return (
      <AdminScreen>
        <div style={{ marginBottom: 24 }}>
          <h1 style={{ margin: '0 0 4px', fontSize: 22, fontWeight: 700, color: '#111827' }}>Hello! 👋</h1>
        </div>
        <Alert type="error" message="Couldn't load your bookings right now. Please refresh the page or try again shortly." />
      </AdminScreen>
    )
  }

  const active   = (data?.rows || []).filter((b) => !['completed', 'cancelled'].includes(b.status))
  const recent   = (data?.rows || []).slice(0, 5)
  const completed = (data?.rows || []).filter((b) => b.status === 'completed').length
  const loading = isInitialLoad

  const firstName = user?.first_name || user?.display_name?.split(' ')[0] || 'there'
  const dashSticky = (
    <Link to="/services" style={{ flex: 1, textDecoration: 'none' }}>
      <Button style={{ width: '100%', justifyContent: 'center' }}>+ New Service Request</Button>
    </Link>
  )

  return (
    <AdminScreen sticky={dashSticky}>
      <div style={{ marginBottom: 24 }}>
        <h1 style={{ margin: '0 0 4px', fontSize: 22, fontWeight: 700, color: '#111827' }}>Hello, {firstName}! 👋</h1>
        <p style={{ margin: 0, color: '#6b7280', fontSize: 14 }}>Track your service requests and manage your India affairs.</p>
      </div>

      {loading ? (
        <StatCardsSkeleton count={3} />
      ) : (
      <div className={isRefreshing ? 's2-region-refreshing' : undefined} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 32 }}>
        {[
          { label: t('total_bookings'), value: data?.total || 0, icon: '📋' },
          { label: t('in_progress'),   value: active.length,    icon: '⏳' },
          { label: t('completed'),     value: completed,        icon: '✅' },
        ].map(({ label, value, icon }) => (
          <Card key={label} style={{ textAlign: 'center', padding: 20 }}>
            <div style={{ fontSize: 28, marginBottom: 8 }}>{icon}</div>
            <div style={{ fontSize: 28, fontWeight: 800, color: primary, marginBottom: 4 }}>{value}</div>
            <div style={{ fontSize: 13, color: '#6b7280' }}>{label}</div>
          </Card>
        ))}
      </div>
      )}

      {loading ? (
        <TableSkeleton rows={5} />
      ) : (
      <div className={isRefreshing ? 's2-region-refreshing' : undefined} style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 24, flexWrap: 'wrap' as const }}>
        {/* Recent bookings */}
        <Card>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
            <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#111827' }}>{t('recent_bookings')}</h2>
            <Link to="/dashboard/bookings" style={{ fontSize: 13, color: primary, textDecoration: 'none', fontWeight: 600 }}>View all →</Link>
          </div>
          {recent.length === 0 ? (
            <EmptyState icon="📭" title="No bookings yet" description="Submit your first service request to get started."
              action={<Link to="/services"><Button>Browse Services</Button></Link>} />
          ) : (
            <div>
              {recent.map((b) => (
                <Link key={b.id} to={`/dashboard/bookings/${b.id}`} style={{ textDecoration: 'none' }}>
                  <div style={{ padding: '12px 0', borderBottom: '1px solid #f3f4f6', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
                    <div style={{ minWidth: 0 }}>
                      <div style={{ fontWeight: 600, color: '#111827', fontSize: 14, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{b.service_name}</div>
                      <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 2 }}>{b.booking_ref}</div>
                    </div>
                    <div style={{ flexShrink: 0 }}><StatusBadge status={b.status} /></div>
                  </div>
                </Link>
              ))}
            </div>
          )}
        </Card>

        {/* Quick actions + WhatsApp */}
        <div>
          <Card style={{ marginBottom: 16 }}>
            <h3 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 700, color: '#111827' }}>{t('quick_actions')}</h3>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <Link to="/services"><Button style={{ width: '100%', justifyContent: 'center' }}>+ New Service Request</Button></Link>
              <Link to="/dashboard/tickets"><Button variant="ghost" style={{ width: '100%', justifyContent: 'center' }}>🎫 Get Support</Button></Link>
            </div>
          </Card>
          {settings.platform_whatsapp && (
            <Card>
              <h3 style={{ margin: '0 0 8px', fontSize: 14, fontWeight: 700, color: '#111827' }}>Need help?</h3>
              <p style={{ margin: '0 0 12px', fontSize: 13, color: '#6b7280' }}>Chat with us on WhatsApp for quick assistance.</p>
              <a href={`https://wa.me/${String(settings.platform_whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer"
                style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8, background: '#25d366', color: '#fff', padding: '10px', borderRadius: 8, textDecoration: 'none', fontWeight: 600, fontSize: 14 }}>
                💬 WhatsApp Us
              </a>
            </Card>
          )}
        </div>
      </div>
      )}
    </AdminScreen>
  )
}

// ── BookingListPage (ve component) ────────────────────────────────────────────
export function BookingListPage() {
  const primary  = resolvePrimary(useStore((s) => s.settings))
  const [data,   setData]   = useState<{ rows: Booking[]; total: number }>({ rows: [], total: 0 })
  const [loading,setLoading]= useState(true)
  const [loadError, setLoadError] = useState(false)
  const [status, setStatus] = useState('')
  const [page,   setPage]   = useState(1)

  const load = useCallback(() => {
    setLoading(true)
    setLoadError(false)
    const qs = new URLSearchParams({ page: String(page), per_page: '10', ...(status && { status }) })
    api.get<{ rows: Booking[]; total: number }>(`bookings?${qs}`)
      .then((d) => { setData(d); setLoading(false) })
      // FIXED (was previously silent): same bug as the dashboard home
      // widget above — a failed fetch here rendered identically to
      // EmptyState ("No bookings found... Browse Services"), which is
      // especially misleading when a status filter is active, since it
      // looks like a legitimate "no results for this filter" outcome
      // rather than a failed request.
      .catch(() => { setLoading(false); setLoadError(true) })
  }, [page, status])

  useEffect(() => { load() }, [load])

  const filters = [{ label: 'All', value: '' }, { label: 'Active', value: 'active' }, { label: 'Completed', value: 'completed' }, { label: 'Cancelled', value: 'cancelled' }]
  const totalPages = Math.ceil(data.total / 10)
  const newRequestBtn = (
    <Link to="/services">
      <Button>+ New Request</Button>
    </Link>
  )

  return (
    <AdminScreen sticky={newRequestBtn}>
      <PageHeader title="My Bookings" subtitle={`${data.total} total`} action={newRequestBtn} />

      <AdminToolbar>
        {filters.map((f) => (
          <button key={f.value} onClick={() => { setStatus(f.value); setPage(1) }}
            style={{ padding: '6px 14px', border: `1px solid ${status === f.value ? primary : '#e5e7eb'}`, borderRadius: 99, background: status === f.value ? `${primary}15` : '#fff', color: status === f.value ? primary : '#374151', fontWeight: status === f.value ? 700 : 500, cursor: 'pointer', fontSize: 13 }}>
            {f.label}
          </button>
        ))}
      </AdminToolbar>

      {loading ? <TableSkeleton rows={8} /> : loadError ? (
        <div>
          <Alert type="error" message="Couldn't load your bookings right now." />
          <Button onClick={load} style={{ marginTop: 12 }}>Retry</Button>
        </div>
      ) : data.rows.length === 0 ? (
        <EmptyState icon="📭" title="No bookings found" description="Submit a service request to get started." action={<Link to="/services"><Button>Browse Services</Button></Link>} />
      ) : (
        <div>
          {data.rows.map((b) => (
            <Link key={b.id} to={`/dashboard/bookings/${b.id}`} style={{ textDecoration: 'none', display: 'block', marginBottom: 12 }}>
              <Card style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', padding: '16px 20px', transition: 'box-shadow .15s' }}>
                <div style={{ fontSize: 24 }}>{b.category_icon || '📋'}</div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 700, color: '#111827', fontSize: 15 }}>{b.service_name}</div>
                  <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 2 }}>
                    {b.booking_ref} · {new Date(b.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}
                  </div>
                </div>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexShrink: 0 }}>
                  {(b.unread_count || 0) > 0 && <span style={{ background: '#dc2626', color: '#fff', borderRadius: 99, padding: '2px 8px', fontSize: 11, fontWeight: 700 }}>{b.unread_count} new</span>}
                  <StatusBadge status={b.status} />
                  {b.quoted_amount && <span style={{ fontSize: 14, fontWeight: 700, color: primary }}>₹{Number(b.quoted_amount).toLocaleString('en-IN')}</span>}
                </div>
              </Card>
            </Link>
          ))}

          {data.total > 10 && (
            <div style={{ display: 'flex', justifyContent: 'center', gap: 8, marginTop: 20 }}>
              <Button variant="ghost" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1}>← Prev</Button>
              <span style={{ padding: '10px 16px', color: '#6b7280', fontSize: 14 }}>Page {page} of {totalPages}</span>
              <Button variant="ghost" onClick={() => setPage((p) => p + 1)} disabled={page >= totalPages}>Next →</Button>
            </div>
          )}
        </div>
      )}
    </AdminScreen>
  )
}

// ── BookingDetailPage (Se component) ──────────────────────────────────────────
export function BookingDetailPage() {
  const { t }    = useT()
  const { id }   = useParams<{ id: string }>()
  const nav      = useNavigate()
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)

  const [booking,       setBooking]       = useState<Booking | null>(null)
  const [loading,       setLoading]       = useState(true)
  const [msg,           setMsg]           = useState('')
  const [sending,       setSending]       = useState(false)
  const [msgError,      setMsgError]      = useState('')
  const [quoteLoading,  setQuoteLoading]  = useState(false)
  const [showPayModal,  setShowPayModal]  = useState(false)
  const [payForm,       setPayForm]       = useState({ amount: '', payment_ref: '', notes: '' })
  const [payLoading,    setPayLoading]    = useState(false)
  const [errorMsg,      setErrorMsg]      = useState('')
  const [successMsg,    setSuccessMsg]    = useState('')

  const reload = useCallback(() => {
    api.get<{ booking: Booking }>(`bookings/${id}`)
      .then((d) => { setBooking(d.booking); setLoading(false) })
      .catch(() => { setLoading(false); setErrorMsg('Booking not found.') })
  }, [id])

  useEffect(() => { reload() }, [reload])

  async function approveQuote() {
    if (quoteLoading) return
    setQuoteLoading(true); setErrorMsg(''); setSuccessMsg('')
    try {
      const r = await api.post<{ message: string }>(`bookings/${id}/approve-quote`, {})
      setSuccessMsg(r.message); reload()
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
    setQuoteLoading(false)
  }

  async function rejectQuote() {
    if (quoteLoading || !window.confirm('Reject this quote? Our team will follow up to discuss further.')) return
    setQuoteLoading(true)
    try {
      await api.post(`bookings/${id}/reject-quote`, { reason: 'Customer rejected via portal' })
      setSuccessMsg('Quote rejected. Our team will reach out.'); reload()
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
    setQuoteLoading(false)
  }

  async function cancelBooking() {
    if (!booking || !window.confirm(`Cancel booking ${booking.booking_ref}? This cannot be undone.`)) return
    try {
      await api.post(`bookings/${id}/cancel`, {})
      nav('/dashboard/bookings')
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
  }

  async function sendMessage() {
    if (!msg.trim()) return
    setSending(true); setMsgError('')
    try {
      await api.post(`bookings/${id}/messages`, { message: msg })
      setMsg(''); reload()
    } catch (e: unknown) { setMsgError((e as { message: string }).message) }
    setSending(false)
  }

  async function submitPayment() {
    if (!payForm.amount) { setErrorMsg('Amount required.'); return }
    setPayLoading(true)
    try {
      await api.post(`bookings/${id}/payment`, { method: 'bank_transfer', ...payForm })
      setSuccessMsg('Payment details submitted for verification.')
      setShowPayModal(false); reload()
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
    setPayLoading(false)
  }

  async function payRazorpay() {
    setErrorMsg('')
    try {
      const r = await api.post<{ key_id: string; amount: number; currency: string; order_id: string; booking_ref: string; customer_name: string; customer_email: string }>(`bookings/${id}/razorpay-order`, {})
      if (!window.Razorpay) {
        await new Promise<void>((res, rej) => {
          const s = document.createElement('script'); s.src = 'https://checkout.razorpay.com/v1/checkout.js'; s.onload = () => res(); s.onerror = () => rej(new Error('Failed to load Razorpay')); document.body.appendChild(s)
        })
      }
      new window.Razorpay({
        key: r.key_id, amount: r.amount, currency: r.currency,
        name: settings.platform_name || 'Services2NRI',
        description: `Booking ${r.booking_ref}`,
        order_id: r.order_id,
        prefill: { name: r.customer_name, email: r.customer_email },
        handler: async (resp) => {
          try {
            const v = await api.post<{ message: string }>(`bookings/${id}/razorpay-verify`, resp)
            setSuccessMsg(v.message); reload()
          } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
        },
        modal: { ondismiss: () => {} },
      }).open()
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
  }

  if (loading) {
    return (
      <AdminScreen>
        <PageHeader title="Booking details" />
        <DetailPanelSkeleton />
      </AdminScreen>
    )
  }
  if (!booking) return <Alert type="error" message="Booking not found." />

  const pendingQuote = (booking.quotes || []).find((q) => q.status === 'pending')
  const canCancel    = ['submitted', 'under_review', 'quote_sent'].includes(booking.status)
  const needsPayment = booking.status === 'quote_approved' && booking.payment_status !== 'paid'
  const bankInfo     = { bank_name: settings.bank_name, account_number: settings.bank_account_number, ifsc: settings.bank_ifsc, upi: settings.bank_upi }
  const hasBankInfo  = bankInfo.account_number || bankInfo.upi

  const detailSticky = (
    <>
      {booking.status === 'quote_sent' && pendingQuote && (
        <>
          <Button onClick={approveQuote} loading={quoteLoading}>✓ Approve Quote</Button>
          <Button variant="ghost" onClick={rejectQuote}>✕ Reject</Button>
        </>
      )}
      {needsPayment && (
        <>
          {settings.razorpay_enabled === '1' && (
            <Button onClick={payRazorpay} style={{ background: '#3395ff', color: '#fff', border: 'none' }}>Pay Online</Button>
          )}
          <Button variant={settings.razorpay_enabled === '1' ? 'secondary' : 'primary'} onClick={() => setShowPayModal(true)}>Bank Transfer</Button>
        </>
      )}
      {!['cancelled', 'completed'].includes(booking.status) && (
        <Button onClick={sendMessage} loading={sending} disabled={!msg.trim()}>{t('send')}</Button>
      )}
      {canCancel && (
        <Button variant="danger" onClick={cancelBooking}>Cancel Booking</Button>
      )}
    </>
  )

  return (
    <AdminScreen sticky={detailSticky}>
      <div style={{ marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
        <Link to="/dashboard/bookings" style={{ color: primary, textDecoration: 'none', fontSize: 14 }}>← Bookings</Link>
        <span style={{ color: '#d1d5db' }}>/</span>
        <span style={{ color: '#374151', fontSize: 14 }}>{booking.booking_ref}</span>
      </div>

      <Alert type="error"   message={errorMsg}   onClose={() => setErrorMsg('')} />
      <Alert type="success" message={successMsg} onClose={() => setSuccessMsg('')} />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 24 }}>
        {/* Left column */}
        <div>
          {/* Booking summary */}
          <Card style={{ marginBottom: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12, marginBottom: 8 }}>
              <div>
                <h1 style={{ margin: '0 0 4px', fontSize: 20, fontWeight: 700, color: '#111827' }}>{booking.service_name}</h1>
                <div style={{ fontSize: 13, color: '#9ca3af' }}>{booking.booking_ref} · {booking.category_name} · {new Date(booking.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}</div>
              </div>
              <StatusBadge status={booking.status} />
            </div>
            <BookingProgressBar status={booking.status} primary={primary} />

            {/* Quoted amount */}
            {booking.quoted_amount && (
              <div style={{ display: 'flex', gap: 16, marginBottom: 16, flexWrap: 'wrap' }}>
                <div style={{ background: '#EBF0F8', padding: '12px 20px', borderRadius: 8 }}>
                  <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 2 }}>Quoted Amount</div>
                  <div style={{ fontSize: 22, fontWeight: 800, color: primary }}>₹{Number(booking.quoted_amount).toLocaleString('en-IN')}</div>
                </div>
                <div style={{ background: '#f9fafb', padding: '12px 20px', borderRadius: 8 }}>
                  <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 2 }}>Payment</div>
                  <StatusBadge status={booking.payment_status} />
                </div>
              </div>
            )}

            {/* Quote approval */}
            {booking.status === 'quote_sent' && pendingQuote && (
              <div style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 10, padding: 16, marginBottom: 16 }}>
                <h3 style={{ margin: '0 0 8px', fontSize: 15, fontWeight: 700, color: '#b45309' }}>📋 Quote Received</h3>
                <div style={{ fontSize: 24, fontWeight: 800, color: primary, marginBottom: 8 }}>₹{Number(pendingQuote.amount).toLocaleString('en-IN')}</div>
                {pendingQuote.notes && <p style={{ margin: '0 0 12px', color: '#555', fontSize: 14 }}>{pendingQuote.notes}</p>}
                <p style={{ margin: '0 0 12px', fontSize: 13, color: '#6b7280' }}>Valid until: {new Date(pendingQuote.valid_until).toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })}</p>
                <div style={{ display: 'flex', gap: 10 }}>
                  <Button onClick={approveQuote} loading={quoteLoading} style={{ flex: 1, justifyContent: 'center' }}>✓ Approve Quote</Button>
                  <Button variant="ghost" onClick={rejectQuote} style={{ flexShrink: 0 }}>✕ Reject</Button>
                </div>
              </div>
            )}

            {/* Payment section */}
            {needsPayment && (
              <div style={{ background: '#ecfdf5', border: '1px solid #bbf7d0', borderRadius: 10, padding: 16, marginBottom: 16 }}>
                <h3 style={{ margin: '0 0 8px', fontSize: 15, fontWeight: 700, color: '#15803d' }}>💳 Payment Required</h3>
                <p style={{ margin: '0 0 12px', fontSize: 14, color: '#374151' }}>Quote approved! Please complete payment to start processing.</p>
                {settings.razorpay_enabled === '1' && (
                  <Button onClick={payRazorpay} style={{ width: '100%', justifyContent: 'center', marginBottom: 10, background: '#3395ff', color: '#fff', border: 'none' }}>
                    Pay Online via Razorpay
                  </Button>
                )}
                {hasBankInfo && (
                  <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, padding: 12, marginBottom: 12, fontSize: 13 }}>
                    <strong>Bank Transfer Details:</strong><br />
                    {bankInfo.bank_name && <>{<strong>Bank:</strong>} {bankInfo.bank_name}<br /></>}
                    {bankInfo.account_number && <>{<strong>Account:</strong>} {bankInfo.account_number}<br /></>}
                    {bankInfo.ifsc && <>{<strong>IFSC:</strong>} {bankInfo.ifsc}<br /></>}
                    {bankInfo.upi && <>{<strong>UPI:</strong>} {bankInfo.upi}</>}
                  </div>
                )}
                <Button variant={settings.razorpay_enabled === '1' ? 'secondary' : 'primary'} onClick={() => setShowPayModal(true)} style={{ width: '100%', justifyContent: 'center' }}>
                  Submit Bank Transfer Details
                </Button>
              </div>
            )}
          </Card>

          {/* Messages */}
          <Card>
            <h2 style={{ margin: '0 0 16px', fontSize: 17, fontWeight: 700, color: '#111827' }}>{t('messages')}</h2>
            <div style={{ maxHeight: 400, overflowY: 'auto', marginBottom: 16, display: 'flex', flexDirection: 'column', gap: 12 }}>
              {(booking.messages || []).length === 0 ? (
                <p style={{ color: '#9ca3af', fontSize: 14, textAlign: 'center', padding: 20 }}>No messages yet.</p>
              ) : (
                (booking.messages || []).map((m) => (
                  <div key={m.id} style={{ display: 'flex', flexDirection: m.sender_type === 'customer' ? 'row-reverse' : 'row', gap: 8, alignItems: 'flex-end' }}>
                    <div style={{ background: m.sender_type === 'customer' ? primary : '#f3f4f6', color: m.sender_type === 'customer' ? '#fff' : '#374151', padding: '10px 14px', borderRadius: m.sender_type === 'customer' ? '16px 16px 4px 16px' : '16px 16px 16px 4px', maxWidth: '75%', fontSize: 14, lineHeight: 1.5 }}>
                      {m.sender_type !== 'customer' && <div style={{ fontWeight: 700, marginBottom: 4, fontSize: 12 }}>{m.sender_name || 'Support Team'}</div>}
                      {m.message}
                      <div style={{ fontSize: 11, opacity: 0.7, marginTop: 4, textAlign: 'right' }}>{new Date(m.created_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}</div>
                    </div>
                  </div>
                ))
              )}
            </div>
            {!['cancelled', 'completed'].includes(booking.status) && (
              <div style={{ display: 'flex', gap: 10 }}>
                <textarea
                  value={msg}
                  onChange={(e) => setMsg(e.target.value)}
                  placeholder="Type your message…"
                  rows={2}
                  style={{ flex: 1, padding: '10px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, resize: 'none' }}
                  onKeyDown={(e) => { if (e.key === 'Enter' && e.ctrlKey) sendMessage() }}
                />
                <Button onClick={sendMessage} loading={sending} disabled={!msg.trim()} style={{ alignSelf: 'flex-end' }}>{t('send')}</Button>
              </div>
            )}
            {msgError && <Alert type="error" message={msgError} />}
          </Card>
        </div>

        {/* Right column */}
        <div>
          {/* Documents */}
          <Card style={{ marginBottom: 16 }}>
            <h3 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 700, color: '#111827' }}>Documents ({(booking.documents || []).length})</h3>
            {(booking.documents || []).length === 0 ? (
              <p style={{ color: '#9ca3af', fontSize: 13 }}>No documents yet.</p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {(booking.documents || []).map((doc) => (
                  <a key={doc.id} href={doc.file_url} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', background: '#f9fafb', borderRadius: 6, textDecoration: 'none', color: '#374151' }}>
                    <span style={{ fontSize: 18 }}>{doc.mime_type?.includes('pdf') ? '📄' : '🖼️'}</span>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 13, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{doc.doc_type}</div>
                      <div style={{ fontSize: 11, color: '#9ca3af' }}>{doc.file_name?.substring(0, 30)}</div>
                    </div>
                    <span style={{ fontSize: 16, color: primary }}>↓</span>
                  </a>
                ))}
              </div>
            )}
            {!['cancelled', 'completed'].includes(booking.status) && (
              <div style={{ marginTop: 12 }}>
                <label htmlFor="doc-upload" style={{ display: 'block', background: `${primary}15`, color: primary, border: `1px dashed ${primary}`, padding: 10, borderRadius: 8, textAlign: 'center', cursor: 'pointer', fontWeight: 600, fontSize: 14 }}>
                  + Upload Document
                </label>
                <input id="doc-upload" type="file" accept=".pdf,.jpg,.png,.doc,.docx" style={{ display: 'none' }}
                  onChange={async (e) => {
                    const file = e.target.files?.[0]; if (!file) return
                    const form = new FormData(); form.append('file', file); form.append('doc_type', 'Customer Document')
                    try { await api.upload(`bookings/${id}/documents`, form); reload() }
                    catch (err: unknown) { setErrorMsg((err as { message: string }).message) }
                  }}
                />
              </div>
            )}
          </Card>

          {/* Actions */}
          {(canCancel || booking.status === 'completed') && (
            <Card>
              <h3 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700, color: '#111827' }}>Actions</h3>
              {canCancel && (
                <Button variant="danger" onClick={cancelBooking} style={{ width: '100%', justifyContent: 'center', marginBottom: 8 }} aria-label={`Cancel booking ${booking.booking_ref}`}>
                  Cancel Booking
                </Button>
              )}
              {booking.status === 'completed' && !booking.has_review && (
                <Link to={`/dashboard/bookings/${id}/review`}>
                  <Button variant="secondary" style={{ width: '100%', justifyContent: 'center' }}>⭐ Leave Review</Button>
                </Link>
              )}
            </Card>
          )}
        </div>
      </div>

      {/* Bank transfer modal */}
      <Modal open={showPayModal} onClose={() => setShowPayModal(false)} title="Submit Payment Details">
        <p style={{ color: '#6b7280', fontSize: 14, marginBottom: 16 }}>After transferring payment, enter the transaction reference below so our team can verify it.</p>
        <FormInput label="Amount Paid (₹)" type="number" value={payForm.amount} onChange={(e) => setPayForm((f) => ({ ...f, amount: e.target.value }))} placeholder="e.g. 5000" required />
        <FormInput label="UTR / Transaction Reference" value={payForm.payment_ref} onChange={(e) => setPayForm((f) => ({ ...f, payment_ref: e.target.value }))} placeholder="UTR / bank reference number" hint="Found in your bank statement / payment receipt" />
        <Textarea label="Notes (optional)" value={payForm.notes} onChange={(e) => setPayForm((f) => ({ ...f, notes: e.target.value }))} placeholder="Any additional info…" rows={2} />
        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
          <Button variant="ghost" onClick={() => setShowPayModal(false)}>Cancel</Button>
          <Button onClick={submitPayment} loading={payLoading}>Submit Payment</Button>
        </div>
      </Modal>
    </AdminScreen>
  )
}

// ── NewBookingPage (ke component) ─────────────────────────────────────────────
export function NewBookingPage() {
  useT()
  const [params]  = useSearchParams()
  const serviceId = params.get('service')
  const nav       = useNavigate()
  const primary   = resolvePrimary(useStore((s) => s.settings))

  const [svc,     setSvc]     = useState<import('@/types').Service | null>(null)
  const [loading, setLoading] = useState(true)
  const [notes,   setNotes]   = useState('')
  const [priority,setPriority]= useState('normal')
  const [submitting,setSubmitting] = useState(false)
  const [errorMsg,  setErrorMsg]   = useState('')

  useEffect(() => {
    if (!serviceId) { setLoading(false); return }
    api.get<{ services: import('@/types').Service[] }>('services?surface=forms&per_page=100')
      .then((d) => { setSvc((d.services || []).find((s) => String(s.id) === serviceId) || null); setLoading(false) })
      .catch(() => setLoading(false))
  }, [serviceId])

  async function submit() {
    if (!serviceId) { setErrorMsg('Please select a service first.'); return }
    setSubmitting(true); setErrorMsg('')
    try {
      const res = await api.post<{ booking: { id: number } }>('bookings', { service_id: parseInt(serviceId), field_data: { notes }, priority })
      nav(`/dashboard/bookings/${res.booking.id}`)
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message); setSubmitting(false) }
  }

  if (loading) {
    return (
      <AdminScreen>
        <PageHeader title="New Service Request" />
        <DetailPanelSkeleton />
      </AdminScreen>
    )
  }

  const submitBtn = svc ? (
    <Button onClick={submit} loading={submitting} style={{ width: '100%', justifyContent: 'center' }}>
      Submit Request →
    </Button>
  ) : null

  return (
    <AdminScreen sticky={submitBtn || undefined}>
      <PageHeader title="New Service Request" subtitle="Fill in the details and we'll send you a quote within 24 hours." action={submitBtn || undefined} />
      <Alert type="error" message={errorMsg} onClose={() => setErrorMsg('')} />
      <div style={{ maxWidth: 680, margin: '0 auto' }}>
        {!svc ? (
          <Card style={{ marginBottom: 16, textAlign: 'center', padding: 32 }}>
            <p style={{ marginBottom: 16, color: '#6b7280' }}>Select a service to continue</p>
            <Link to="/services"><Button>Browse Services →</Button></Link>
          </Card>
        ) : (
          <AdminFormStack>
            <Card style={{ marginBottom: 16 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12 }}>
                <div style={{ width: 44, height: 44, background: `${primary}20`, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 22 }}>{svc.icon || '📋'}</div>
                <div>
                  <div style={{ fontSize: 12, color: primary, fontWeight: 600, textTransform: 'uppercase' }}>{svc.category_name}</div>
                  <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#111827' }}>{svc.name}</h2>
                </div>
              </div>
              {svc.short_desc && <p style={{ margin: 0, color: '#6b7280', fontSize: 14 }}>{svc.short_desc}</p>}
            </Card>
            <Card style={{ marginBottom: 16 }}>
              <h3 style={{ margin: '0 0 16px', fontWeight: 700, color: '#111827' }}>Request Details</h3>
              <div style={{ marginBottom: 16 }}>
                <label style={{ display: 'block', fontWeight: 600, marginBottom: 6, fontSize: 14, color: '#374151' }}>Priority</label>
                <div style={{ display: 'flex', gap: 10 }}>
                  {['normal', 'high', 'urgent'].map((p) => (
                    <button key={p} onClick={() => setPriority(p)} style={{ flex: 1, padding: 8, border: `2px solid ${priority === p ? primary : '#e5e7eb'}`, borderRadius: 8, background: priority === p ? `${primary}10` : '#fff', color: priority === p ? primary : '#374151', fontWeight: priority === p ? 700 : 500, cursor: 'pointer', fontSize: 14, textTransform: 'capitalize' }}>
                      {p}
                    </button>
                  ))}
                </div>
              </div>
              <Textarea label="Describe your requirements" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Please provide as much detail as possible." rows={5} hint="The more detail you provide, the more accurate your quote will be." />
            </Card>
            <Button className="s2-admin-page-header__actions--desktop-only" onClick={submit} loading={submitting} style={{ width: '100%', justifyContent: 'center', padding: 14 }}>
              Submit Request →
            </Button>
          </AdminFormStack>
        )}
      </div>
    </AdminScreen>
  )
}

// ── ProfilePage (we component) ────────────────────────────────────────────────
export function ProfilePage() {
  useT()
  const primary   = resolvePrimary(useStore((s) => s.settings))
  const [profile, setProfile]  = useState<UserProfile | null>(null)
  const [loading, setLoading]  = useState(true)
  const [saving,  setSaving]   = useState(false)
  const [successMsg,setSuccessMsg] = useState('')
  const [errorMsg,  setErrorMsg]   = useState('')
  const [pwForm,  setPwForm]   = useState({ current: '', next: '', confirm: '' })
  const [pwSaving,setPwSaving] = useState(false)
  const [pwError, setPwError]  = useState('')

  useEffect(() => {
    api.get<UserProfile>('profile')
      .then((d) => { setProfile(d); setLoading(false) })
      .catch(() => setLoading(false))
  }, [])

  async function saveProfile() {
    if (!profile) return
    setSaving(true); setErrorMsg(''); setSuccessMsg('')
    try {
      await api.put('profile', { name: profile.name, ...profile.profile })
      setSuccessMsg('Profile updated successfully.')
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
    setSaving(false)
  }

  async function changePassword() {
    setPwError('')
    if (!pwForm.current) { setPwError('Current password required.'); return }
    if (pwForm.next.length < 8) { setPwError('New password must be at least 8 characters.'); return }
    if (pwForm.next !== pwForm.confirm) { setPwError('Passwords do not match.'); return }
    setPwSaving(true)
    try {
      await api.put('profile/password', { current_password: pwForm.current, new_password: pwForm.next })
      setSuccessMsg('Password changed successfully.')
      setPwForm({ current: '', next: '', confirm: '' })
    } catch (e: unknown) { setPwError((e as { message: string }).message) }
    setPwSaving(false)
  }

  function setField(section: 'name' | keyof NonNullable<UserProfile['profile']>, val: string) {
    if (section === 'name') { setProfile((p) => p ? { ...p, name: val } : p); return }
    setProfile((p) => p ? { ...p, profile: { ...p.profile, [section]: val } } : p)
  }

  if (loading) {
    return (
      <AdminScreen>
        <PageHeader title="My Profile" />
        <DetailPanelSkeleton />
      </AdminScreen>
    )
  }

  const saveBtn = <Button onClick={saveProfile} loading={saving} style={{ width: '100%', justifyContent: 'center' }}>Save Profile</Button>

  return (
    <AdminScreen sticky={saveBtn}>
      <PageHeader title="My Profile" action={saveBtn} />
      <Alert type="error"   message={errorMsg}   onClose={() => setErrorMsg('')} />
      <Alert type="success" message={successMsg} onClose={() => setSuccessMsg('')} />
      <AdminFormStack style={{ maxWidth: 560 }}>
        <Card>
          <FormInput label="Full Name"    value={profile?.name || ''}                         onChange={(e) => setField('name', e.target.value)} required />
          <FormInput label="Email"        value={profile?.email || ''}                        disabled hint="Email cannot be changed." />
          <FormInput label="Phone"        value={profile?.profile?.phone || ''}               onChange={(e) => setField('phone', e.target.value)} placeholder="+1-555-0123" />
          <FormInput label="WhatsApp"     value={profile?.profile?.whatsapp || ''}            onChange={(e) => setField('whatsapp', e.target.value)} placeholder="+1-555-0123" />
          <FormInput label="Country of Residence" value={profile?.profile?.country || ''}    onChange={(e) => setField('country', e.target.value)} />
          <FormInput label="City Abroad"  value={profile?.profile?.city_abroad || ''}        onChange={(e) => setField('city_abroad', e.target.value)} />
          <FormInput label="City in India" value={profile?.profile?.city_india || ''}        onChange={(e) => setField('city_india', e.target.value)} />
          <Textarea  label="India Address" value={profile?.profile?.address_india || ''}     onChange={(e) => setField('address_india', e.target.value)} rows={3} />
          <Button className="s2-admin-page-header__actions--desktop-only" onClick={saveProfile} loading={saving}>Save Profile</Button>
        </Card>

        <Card style={{ marginTop: 20 }}>
          <h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 16px', color: '#111827' }}>🔐 Change Password</h3>
          <FormInput label="Current Password" type="password" value={pwForm.current} onChange={(e) => setPwForm((f) => ({ ...f, current: e.target.value }))} placeholder="Enter current password" />
          <FormInput label="New Password"     type="password" value={pwForm.next}    onChange={(e) => setPwForm((f) => ({ ...f, next: e.target.value }))}    placeholder="Minimum 8 characters" hint="Leave blank if you prefer OTP login." />
          <FormInput label="Confirm New Password" type="password" value={pwForm.confirm} onChange={(e) => setPwForm((f) => ({ ...f, confirm: e.target.value }))} placeholder="Repeat new password" />
          {pwError && <p style={{ color: '#b91c1c', fontSize: 13, margin: '0 0 10px' }}>{pwError}</p>}
          <Button onClick={changePassword} loading={pwSaving} variant="secondary">Update Password</Button>
        </Card>

        <TwoFactorCard />
      </AdminFormStack>
    </AdminScreen>
  )
}

// ── TwoFactorCard ────────────────────────────────────────────────────────────
// ADDED: lets a staff/admin account enable or disable 2FA for themselves.
// Only rendered for staff/admin accounts (customers don't have this
// option), matching the backend's own scoping in AuthController::login().
function TwoFactorCard() {
  const user = useStore((s) => s.user)
  const [enabled, setEnabled] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving,  setSaving]  = useState(false)
  const [msg,     setMsg]     = useState('')

  useEffect(() => {
    api.get<{ two_factor_enabled?: boolean }>('profile')
      .then((d) => { setEnabled(!!d.two_factor_enabled); setLoading(false) })
      .catch(() => setLoading(false))
  }, [])

  if (!user || !STAFF_ROLES.includes(user.s2nri_role)) return null
  if (loading) return null

  async function toggle() {
    setSaving(true); setMsg('')
    try {
      const next = !enabled
      await api.post('auth/toggle-2fa', { enabled: next })
      setEnabled(next)
      setMsg(next ? 'Two-factor authentication is now ON. You will be asked for a code by email at your next login.' : 'Two-factor authentication is now OFF.')
    } catch (e: unknown) {
      setMsg((e as { message: string }).message)
    }
    setSaving(false)
  }

  return (
    <Card style={{ marginTop: 20 }}>
      <h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 8px', color: '#111827' }}>🔒 Two-Factor Authentication</h3>
      <p style={{ fontSize: 13, color: '#6b7280', margin: '0 0 16px' }}>
        When enabled, signing in with your password also requires a one-time code sent to your email — an extra layer of protection for staff/admin accounts.
      </p>
      {msg && <p style={{ fontSize: 13, color: enabled ? '#15803d' : '#374151', margin: '0 0 12px' }}>{msg}</p>}
      <Button onClick={toggle} loading={saving} variant={enabled ? 'secondary' : 'primary'}>
        {enabled ? 'Disable 2FA' : 'Enable 2FA'}
      </Button>
    </Card>
  )
}

// ── TicketsPage (_e component) ────────────────────────────────────────────────
export function TicketsPage() {
  useT()
  const primary      = resolvePrimary(useStore((s) => s.settings))
  const [tickets,    setTickets]    = useState<Ticket[]>([])
  const [loading,    setLoading]    = useState(true)
  const [showModal,  setShowModal]  = useState(false)
  const [form,       setForm]       = useState({ subject: '', message: '' })
  const [submitting, setSubmitting] = useState(false)
  const [errorMsg,   setErrorMsg]   = useState('')

  function load() {
    api.get<{ tickets: Ticket[] }>('tickets')
      .then((d) => { setTickets(d.tickets || []); setLoading(false) })
      .catch(() => setLoading(false))
  }

  useEffect(() => { load() }, [])

  async function submit() {
    if (!form.subject) { setErrorMsg('Subject required.'); return }
    setSubmitting(true); setErrorMsg('')
    try {
      await api.post('tickets', form)
      setShowModal(false)
      setForm({ subject: '', message: '' })
      load()
    } catch (e: unknown) { setErrorMsg((e as { message: string }).message) }
    setSubmitting(false)
  }

  const newTicketBtn = <Button onClick={() => setShowModal(true)} style={{ width: '100%', justifyContent: 'center' }}>+ New Ticket</Button>

  return (
    <AdminScreen sticky={newTicketBtn}>
      <PageHeader title="Support Tickets" action={newTicketBtn} />

      {loading ? <TableSkeleton rows={6} /> : tickets.length === 0 ? (
        <EmptyState icon="🎫" title="No tickets" description="Open a support ticket if you need help with any booking or issue." action={<Button onClick={() => setShowModal(true)}>Open Ticket</Button>} />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {tickets.map((t) => (
            <Card key={t.id} style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 700, color: '#111827', marginBottom: 4 }}>{t.subject}</div>
                <div style={{ fontSize: 13, color: '#9ca3af' }}>#{t.id} · {new Date(t.created_at).toLocaleDateString('en-IN')}</div>
              </div>
              <StatusBadge status={t.status} />
            </Card>
          ))}
        </div>
      )}

      <Modal open={showModal} onClose={() => setShowModal(false)} title="New Support Ticket">
        <Alert type="error" message={errorMsg} onClose={() => setErrorMsg('')} />
        <FormInput label="Subject" value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} placeholder="Briefly describe your issue" required />
        <Textarea label="Message" value={form.message} onChange={(e) => setForm((f) => ({ ...f, message: e.target.value }))} placeholder="Describe the issue in detail…" rows={4} />
        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
          <Button variant="ghost" onClick={() => setShowModal(false)}>Cancel</Button>
          <Button onClick={submit} loading={submitting}>Submit Ticket</Button>
        </div>
      </Modal>
    </AdminScreen>
  )
}
