import React from 'react'

const block: React.CSSProperties = {
  background: 'linear-gradient(90deg, #eef2f7 0%, #f8fafc 45%, #eef2f7 100%)',
  backgroundSize: '200% 100%',
  animation: 's2-skeleton-shimmer 1.15s ease-in-out infinite',
  borderRadius: 8,
}

export function SkeletonBlock({
  height = 16,
  width = '100%',
  style,
}: {
  height?: number | string
  width?: number | string
  style?: React.CSSProperties
}) {
  return <div className="s2-skeleton" style={{ ...block, height, width, ...style }} aria-hidden />
}

export function TableSkeleton({ rows = 8 }: { rows?: number }) {
  return (
    <div className="s2-skeleton-region" style={{ minHeight: 320 }} aria-busy="true" aria-label="Loading table">
      <SkeletonBlock height={44} style={{ marginBottom: 12, borderRadius: 10 }} />
      {Array.from({ length: rows }).map((_, i) => (
        <SkeletonBlock key={i} height={52} style={{ marginBottom: 8 }} />
      ))}
    </div>
  )
}

export function StatCardsSkeleton({ count = 4 }: { count?: number }) {
  return (
    <div
      className="s2-skeleton-region"
      style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 16, minHeight: 120 }}
      aria-busy="true"
    >
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonBlock key={i} height={96} style={{ borderRadius: 12 }} />
      ))}
    </div>
  )
}

export function DashboardPageSkeleton() {
  return (
    <div className="s2-skeleton-region" style={{ display: 'flex', flexDirection: 'column', gap: 20 }} aria-busy="true">
      <SkeletonBlock height={32} width="40%" />
      <StatCardsSkeleton />
      <SkeletonBlock height={220} style={{ borderRadius: 12 }} />
    </div>
  )
}

export function DetailPanelSkeleton() {
  return (
    <div className="s2-skeleton-region" style={{ display: 'flex', flexDirection: 'column', gap: 14, minHeight: 360 }} aria-busy="true">
      <SkeletonBlock height={28} width="55%" />
      <SkeletonBlock height={120} />
      <SkeletonBlock height={80} />
      <SkeletonBlock height={160} />
    </div>
  )
}

export function ServiceDetailShellSkeleton() {
  return (
    <div className="s2-skeleton-region s2-svc-layout svc-grid" style={{ minHeight: 480 }} aria-busy="true">
      <div>
        <SkeletonBlock height={280} style={{ borderRadius: 12, marginBottom: 20 }} />
        <SkeletonBlock height={100} style={{ marginBottom: 12 }} />
        <SkeletonBlock height={140} style={{ marginBottom: 12 }} />
        <SkeletonBlock height={180} />
      </div>
      <SkeletonBlock height={520} style={{ borderRadius: 12 }} />
    </div>
  )
}
