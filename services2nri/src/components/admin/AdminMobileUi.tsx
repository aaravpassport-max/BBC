import React from 'react'

/** Dashboard screen wrapper (admin + customer portal) — bottom padding when sticky actions are present. */
export function AdminScreen({
  children,
  sticky,
  className = '',
}: {
  children: React.ReactNode
  sticky?: React.ReactNode
  className?: string
}) {
  return (
    <div className={`s2-admin-screen${sticky ? ' s2-admin-screen--sticky' : ''} ${className}`.trim()}>
      {children}
      {sticky ? <AdminStickyActionBar>{sticky}</AdminStickyActionBar> : null}
    </div>
  )
}

/** Fixed action bar above mobile bottom nav (Material-style primary actions). */
export function AdminStickyActionBar({ children }: { children: React.ReactNode }) {
  return (
    <div className="s2-admin-sticky-action-bar" role="region" aria-label="Page actions">
      <div className="s2-admin-sticky-action-bar__inner">{children}</div>
    </div>
  )
}

/** Horizontal scroll-safe toolbar (filters, search). */
export function AdminToolbar({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return <div className={`s2-admin-toolbar ${className}`.trim()}>{children}</div>
}

/** Table wrapper for card-style mobile rows (pairs with `.s2-dash-table` + MobileDashTableEnhancer). */
export function AdminTableWrap({
  children,
  style,
  className = '',
}: {
  children: React.ReactNode
  style?: React.CSSProperties
  className?: string
}) {
  return (
    <div className={`s2-table-wrap ${className}`.trim()} style={style}>
      {children}
    </div>
  )
}

export function adminTableProps(fontSize: 13 | 14 = 14): React.TableHTMLAttributes<HTMLTableElement> {
  return {
    className: 's2-dash-table',
    style: { width: '100%', borderCollapse: 'collapse', fontSize },
  }
}

/** Stack form fields full-width on mobile (class hook in platform-mobile-app.css). */
export function AdminFormStack({
  children,
  className = '',
  style,
}: {
  children: React.ReactNode
  className?: string
  style?: React.CSSProperties
}) {
  return (
    <div className={`s2-admin-form-stack ${className}`.trim()} style={style}>
      {children}
    </div>
  )
}
