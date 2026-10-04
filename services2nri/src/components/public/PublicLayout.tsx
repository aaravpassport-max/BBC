/**
 * Token-based public layout primitives — prefer over inline styles on marketing pages.
 */
import React from 'react'
import { Link } from 'react-router-dom'

export function PublicSection({
  children,
  alt = false,
  className = '',
}: {
  children: React.ReactNode
  alt?: boolean
  className?: string
}) {
  return (
    <section className={`s2-section s2-marketing-section${alt ? ' s2-marketing-section--alt' : ''} ${className}`.trim()}>
      <div className="s2-container">{children}</div>
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
    <div className="s2-public-grid" style={{ ['--s2-grid-min' as string]: `${min}px` }}>
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
