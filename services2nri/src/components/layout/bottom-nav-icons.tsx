import React from 'react'

type IconProps = { className?: string; active?: boolean }

const base = {
  width: 24,
  height: 24,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 2,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
}

export function IconHome({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5Z" />
    </svg>
  )
}

export function IconServices({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z" />
    </svg>
  )
}

export function IconContact({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8Z" />
    </svg>
  )
}

export function IconBookings({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <rect x="3" y="4" width="18" height="18" rx="2" />
      <path d="M16 2v4M8 2v4M3 10h18" />
    </svg>
  )
}

export function IconAccount({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 20c1.5-3.5 5-5 8-5s6.5 1.5 8 5" />
    </svg>
  )
}

export function IconInquire({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" />
    </svg>
  )
}

export function IconDashboard({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <rect x="3" y="3" width="7" height="9" rx="1" />
      <rect x="14" y="3" width="7" height="5" rx="1" />
      <rect x="14" y="12" width="7" height="9" rx="1" />
      <rect x="3" y="16" width="7" height="5" rx="1" />
    </svg>
  )
}

export function IconRequests({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
      <rect x="9" y="3" width="6" height="4" rx="1" />
      <path d="M9 12h6M9 16h6" />
    </svg>
  )
}

export function IconDesign({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <circle cx="13.5" cy="6.5" r="2.5" />
      <circle cx="17.5" cy="10.5" r="2.5" />
      <circle cx="8.5" cy="7.5" r="2.5" />
      <circle cx="6.5" cy="12.5" r="2.5" />
      <path d="M12 22a10 10 0 0 0 10-10" />
    </svg>
  )
}

export function IconMenu({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <path d="M4 6h16M4 12h16M4 18h16" />
    </svg>
  )
}

export function IconSupport({ className }: IconProps) {
  return (
    <svg {...base} className={className} aria-hidden>
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
    </svg>
  )
}
