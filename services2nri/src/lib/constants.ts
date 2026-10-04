/**
 * Status helpers — matches ue{} status color map from booking chunk
 * and booking status constants
 */

import type { BookingStatus, PaymentStatus } from '@/types'

/** Status → [bgColor, textColor] — matches ue{} in compiled bundle */
export const STATUS_COLORS: Record<string, [string, string]> = {
  submitted:     ['#EBF0F8', '#1E2D40'],
  under_review:  ['#fffbeb', '#b45309'],
  quote_sent:    ['#fdf4ff', '#7c3aed'],
  quote_approved:['#ecfdf5', '#059669'],
  in_progress:   ['#EBF0F8', '#4A6FA5'],
  docs_requested:['#fff7ed', '#c2410c'],
  docs_received: ['#ecfdf5', '#16a34a'],
  processing:    ['#EBF0F8', '#1E2D40'],
  completed:     ['#f0fdf4', '#15803d'],
  cancelled:     ['#fef2f2', '#b91c1c'],
  on_hold:       ['#f9fafb', '#6b7280'],
  pending:       ['#fffbeb', '#b45309'],
  paid:          ['#ecfdf5', '#15803d'],
  verified:      ['#ecfdf5', '#15803d'],
  failed:        ['#fef2f2', '#b91c1c'],
  quote_rejected:['#fef2f2', '#b91c1c'],
}

/** Booking progress steps — matches J[] in dashboard chunk */
export const BOOKING_STEPS = [
  { key: 'submitted',      label: 'Submitted',   icon: '📥' },
  { key: 'under_review',   label: 'Under Review',icon: '🔍' },
  { key: 'quote_sent',     label: 'Quote Sent',  icon: '💬' },
  { key: 'quote_approved', label: 'Approved',    icon: '✅' },
  { key: 'in_progress',    label: 'In Progress', icon: '⚙️' },
  { key: 'completed',      label: 'Completed',   icon: '🎉' },
] as const

/** Format status label for display */
export function formatStatus(status: BookingStatus | PaymentStatus | string): string {
  return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

/** Staff roles — matches STAFF_ROLES check in compiled bundle */
export const STAFF_ROLES = ['super_admin', 'manager', 'agent', 'finance']

export function isStaff(role?: string): boolean {
  return !!role && STAFF_ROLES.includes(role)
}

/** Currency list — matches oe[] in booking chunk */
export const CURRENCIES = [
  { code: 'USD', flag: '🇺🇸', symbol: '$' },
  { code: 'INR', flag: '🇮🇳', symbol: '₹' },
  { code: 'AUD', flag: '🇦🇺', symbol: 'A$' },
  { code: 'CAD', flag: '🇨🇦', symbol: 'C$' },
  { code: 'EUR', flag: '🇪🇺', symbol: '€' },
  { code: 'GBP', flag: '🇬🇧', symbol: '£' },
  { code: 'SGD', flag: '🇸🇬', symbol: 'S$' },
]

/** Country dial codes — matches ye[] in booking chunk */
export const DIAL_CODES = [
  { code: '+91',  flag: '🇮🇳', name: 'IN' },
  { code: '+1',   flag: '🇺🇸', name: 'US' },
  { code: '+44',  flag: '🇬🇧', name: 'UK' },
  { code: '+61',  flag: '🇦🇺', name: 'AU' },
  { code: '+971', flag: '🇦🇪', name: 'UAE' },
  { code: '+65',  flag: '🇸🇬', name: 'SG' },
  { code: '+1',   flag: '🇨🇦', name: 'CA' },
  { code: '+49',  flag: '🇩🇪', name: 'DE' },
  { code: '+60',  flag: '🇲🇾', name: 'MY' },
  { code: '+974', flag: '🇶🇦', name: 'QA' },
  { code: '+966', flag: '🇸🇦', name: 'SA' },
  { code: '+64',  flag: '🇳🇿', name: 'NZ' },
  { code: '+31',  flag: '🇳🇱', name: 'NL' },
  { code: '+81',  flag: '🇯🇵', name: 'JP' },
]

/** Countries for registration dropdown — matches the array in Xn register page */
export const COUNTRIES = [
  'USA', 'UK', 'Canada', 'Australia', 'UAE', 'Singapore', 'Germany',
  'New Zealand', 'Saudi Arabia', 'Qatar', 'Kuwait', 'Bahrain', 'Oman',
  'Netherlands', 'Malaysia', 'Japan', 'Other',
]
