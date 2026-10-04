/**
 * Token-based public layout primitives — prefer over inline styles on marketing pages.
 */
import React from 'react'
import { Link } from 'react-router-dom'
import { cssVars } from '@/lib/design-tokens'

export function PublicSection({
  children,
  alt = false,
  className = '',
  sectionKey,
  width = 'standard',
}: {
  children: React.ReactNode
  alt?: boolean
  className?: string
  /** Registers section with centralized width system (data-s2-section). */
  sectionKey?: string
  width?: 'standard' | 'wide' | 'narrow' | 'compact' | 'full' | 'content' | 'inner'
}) {
  const widthClass = {
    standard: 's2-width-standard',
    wide: 's2-width-wide',
    narrow: 's2-width-narrow',
    compact: 's2-width-compact',
    full: 's2-width-full',
    content: 's2-width-content',
    inner: 's2-width-inner',
  }[width]
  return (
    <section
      className={`s2-section s2-marketing-section${alt ? ' s2-marketing-section--alt' : ''} ${className}`.trim()}
      {...(sectionKey ? { 'data-s2-section': sectionKey } : {})}
    >
      <div className={`s2-container s2-section-inner ${widthClass}`}>{children}</div>
    </section>
  )
}

export function PublicSectionHead({
  eyebrow,
  title,
  subtitle,
}: {
  eyebrow?: string
  title: string
  subtitle?: string
}) {
  return (
    <div className="s2-home-section-head s2-public-section-head">
      {eyebrow && <p className="s2-t-eyebrow">{eyebrow}</p>}
      <h2 className="s2-t-section-heading">{title}</h2>
      {subtitle && <p className="s2-t-body s2-public-section-head__sub">{subtitle}</p>}
    </div>
  )
}

export function PublicGrid({
  children,
  min = 280,
}: {
  children: React.ReactNode
  min?: number
}) {
  return (
    <div className="s2-public-grid" style={cssVars({ 's2-grid-min': `${min}px` })}>
      {children}
    </div>
  )
}

export function PublicCard({
  children,
  className = '',
  style,
}: {
  children: React.ReactNode
  className?: string
  style?: React.CSSProperties
}) {
  return <div className={`s2-card s2-public-card s2-animate-hover ${className}`.trim()} style={style}>{children}</div>
}

export function PublicCtaLink({ to, children }: { to: string; children: React.ReactNode }) {
  return (
    <Link to={to} className="s2-btn s2-btn--primary s2-btn--sm">
      {children}
    </Link>
  )
}
