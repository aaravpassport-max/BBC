/**
 * Shared inherit / custom override chrome for Design System admin (mirrors Layout Studio width UX).
 */
import React from 'react'

export function readPathLeaf(root: Record<string, unknown>, path: string[]): unknown {
  let cur: unknown = root
  for (const p of path) {
    if (!cur || typeof cur !== 'object') return undefined
    cur = (cur as Record<string, unknown>)[p]
  }
  return cur
}

/** True when a stored override exists at this path (empty string is still an override). */
export function hasOverrideAtPath(root: Record<string, unknown>, path: string[]): boolean {
  const v = readPathLeaf(root, path)
  return v !== undefined && v !== null
}

const badgeBase: React.CSSProperties = {
  fontSize: 11,
  fontWeight: 700,
  letterSpacing: 0.04,
  textTransform: 'uppercase',
  padding: '2px 8px',
  borderRadius: 999,
  flexShrink: 0,
}

export function OverrideStatusBadge({ inherited }: { inherited: boolean }) {
  return (
    <span
      style={{
        ...badgeBase,
        color: inherited ? '#64748B' : '#1D4ED8',
        background: inherited ? '#F1F5F9' : '#DBEAFE',
      }}
    >
      {inherited ? 'Inherited' : 'Custom'}
    </span>
  )
}

export function OverrideFieldShell({
  label,
  hint,
  inherited,
  onClear,
  children,
}: {
  label: string
  hint?: string
  inherited: boolean
  onClear?: () => void
  children: React.ReactNode
}) {
  return (
    <div
      style={{
        border: `1px solid ${inherited ? '#E2E8F0' : '#BFDBFE'}`,
        borderRadius: 12,
        padding: 12,
        background: inherited ? '#fff' : '#F8FAFC',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 8, marginBottom: 8 }}>
        <div>
          <div style={{ fontSize: 13, fontWeight: 700, color: '#1E293B' }}>{label}</div>
          {hint && <div style={{ fontSize: 11, color: '#64748B', marginTop: 2 }}>{hint}</div>}
        </div>
        <OverrideStatusBadge inherited={inherited} />
      </div>
      {children}
      {onClear && !inherited && (
        <div style={{ marginTop: 10 }}>
          <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={onClear}>
            Clear override
          </button>
        </div>
      )}
    </div>
  )
}
