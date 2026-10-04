import React from 'react'

interface ServiceMobileStickyCtaProps {
  label: string
  secondaryLabel?: string
  secondaryHref?: string
}

/** Mobile-only sticky bar above the global bottom nav; links to booking wizard. */
export function ServiceMobileStickyCta({
  label,
  secondaryLabel,
  secondaryHref,
}: ServiceMobileStickyCtaProps) {
  return (
    <div className="s2-svc-mobile-cta" aria-label="Quick actions">
      {secondaryLabel && secondaryHref ? (
        <a href={secondaryHref} className="s2-btn s2-btn--outline s2-btn--sm">
          {secondaryLabel}
        </a>
      ) : null}
      <a href="#booking-form" className="s2-btn s2-btn--primary">
        {label}
      </a>
    </div>
  )
}
