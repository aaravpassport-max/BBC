/**
 * Premium presentation shell for section-level Design controls.
 */
import React from 'react'

export function DesignPremiumGroup({
  title,
  description,
  children,
  badge,
}: {
  title: string
  description?: string
  badge?: string
  children: React.ReactNode
}) {
  return (
    <section className="s2-ds-premium-card s2-ds-design-group">
      <div className="s2-ds-premium-card__head">
        <div>
          <h4 className="s2-ds-premium-card__title">{title}</h4>
          {description && <p className="s2-ds-premium-card__hint">{description}</p>}
        </div>
        {badge && <span className="s2-ds-status-chip s2-ds-status-chip--neutral">{badge}</span>}
      </div>
      <div className="s2-ds-design-group__body">{children}</div>
    </section>
  )
}

export function DesignVisibilityToggle({
  visible,
  onChange,
  label = 'Visible on public page',
  hint = 'Hidden sections stay in the design system and can be turned back on anytime.',
}: {
  visible: boolean
  onChange: (visible: boolean) => void
  label?: string
  hint?: string
}) {
  return (
    <div className="s2-ds-visibility-toggle">
      <div className="s2-ds-visibility-toggle__copy">
        <strong>{label}</strong>
        <p>{hint}</p>
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={visible}
        className={`s2-ds-switch${visible ? ' is-on' : ''}`}
        onClick={() => onChange(!visible)}
      >
        <span className="s2-ds-switch__thumb" />
        <span className="s2-ds-switch__label">{visible ? 'Visible' : 'Hidden'}</span>
      </button>
    </div>
  )
}

export function DesignColorSwatchGrid({ children }: { children: React.ReactNode }) {
  return <div className="s2-ds-color-grid">{children}</div>
}

export function DesignTypographyGrid({ children }: { children: React.ReactNode }) {
  return <div className="s2-ds-type-grid">{children}</div>
}
