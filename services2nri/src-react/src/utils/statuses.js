/**
 * S2NRI Status Constants
 * ─────────────────────
 * SINGLE SOURCE OF TRUTH for all booking statuses.
 * These values must match what is stored in the s2nri_bookings.status column
 * and what the compiled portal/admin JS expects.
 *
 * The progress strip in the portal SPA uses these keys:
 *   submitted → "Received"
 *   under_review → "Under Review"
 *   quote_sent → "Quote Sent"
 *   quote_approved → "Approved"
 *   in_progress → "In Progress"
 *   processing → "Processing"
 *   completed → "Completed"
 *   service_not_available → "Not Able to Serve" (special case)
 *
 * Required workflow per client requirements:
 *   1. Received          (DB: submitted)
 *   2. Under Review      (DB: under_review)
 *   3. Ready to Process  (DB: quote_sent — repurposed)
 *      OR Not Able to Serve (DB: service_not_available)
 *   4. Quote Sent        (DB: quote_sent)
 *   5. Approved          (DB: quote_approved)
 *   6. In Progress       (DB: in_progress)
 *   7. Processing        (DB: processing)
 *   8. Completed         (DB: completed)
 *   9. Delivered         (DB: completed — same value, display differs)
 */

export const STATUS_VALUES = {
  SUBMITTED:             'submitted',
  UNDER_REVIEW:          'under_review',
  QUOTE_SENT:            'quote_sent',
  QUOTE_APPROVED:        'quote_approved',
  IN_PROGRESS:           'in_progress',
  PROCESSING:            'processing',
  COMPLETED:             'completed',
  CANCELLED:             'cancelled',
  SERVICE_NOT_AVAILABLE: 'service_not_available',
  DOCS_REQUESTED:        'docs_requested',
  DOCS_RECEIVED:         'docs_received',
  ON_HOLD:               'on_hold',
}

/**
 * The progress strip — ordered workflow steps shown to customers and admins.
 * Label: what the USER sees.
 * value: what is stored in the DB.
 */
export const PROGRESS_STEPS = [
  {
    value: 'submitted',
    label: 'Received',
    description: 'Request received and logged',
    color: '#6366f1',
    icon: '📥',
  },
  {
    value: 'under_review',
    label: 'Under Review',
    description: 'Our team is reviewing your request',
    color: '#f59e0b',
    icon: '🔍',
  },
  {
    value: 'quote_sent',
    label: 'Quote Sent',
    description: 'Quote has been sent for your approval',
    color: '#3b82f6',
    icon: '📋',
  },
  {
    value: 'quote_approved',
    label: 'Approved',
    description: 'Quote approved — work begins',
    color: '#10b981',
    icon: '✅',
  },
  {
    value: 'in_progress',
    label: 'In Progress',
    description: 'Service is actively being processed',
    color: '#0d7ab5',
    icon: '⚙️',
  },
  {
    value: 'processing',
    label: 'Processing',
    description: 'Final processing and verification',
    color: '#8b5cf6',
    icon: '🔄',
  },
  {
    value: 'completed',
    label: 'Completed',
    description: 'Service completed and delivered',
    color: '#059669',
    icon: '🎉',
  },
]

/** Special non-linear statuses not in the progress strip */
export const SPECIAL_STATUSES = [
  {
    value: 'service_not_available',
    label: 'Not Able to Serve',
    description: 'We are unable to process this request',
    color: '#ef4444',
    icon: '❌',
  },
  {
    value: 'cancelled',
    label: 'Cancelled',
    description: 'Request was cancelled',
    color: '#6b7280',
    icon: '🚫',
  },
  {
    value: 'docs_requested',
    label: 'Documents Requested',
    description: 'Waiting for additional documents',
    color: '#f97316',
    icon: '📄',
  },
  // FIXED (was previously missing): 'docs_received' exists in the real
  // s2nri_bookings.status ENUM (Installer.php:150) and is explicitly
  // listed as a valid, settable status in the backend's own admin
  // status-update allowlist (AdminControllers.php:302) — genuinely
  // reachable, not dead code. Without this entry, a booking in this
  // status rendered as raw "docs_received" text with a default gray dot
  // instead of a proper label/icon, and wasn't selectable in the admin
  // status dropdown (built from ALL_STATUSES below).
  {
    value: 'docs_received',
    label: 'Documents Received',
    description: 'Documents received — reviewing before we proceed',
    color: '#0891b2',
    icon: '📥',
  },
  {
    value: 'on_hold',
    label: 'On Hold',
    description: 'Processing temporarily paused',
    color: '#64748b',
    icon: '⏸️',
  },
]

/** All statuses combined for dropdowns */
export const ALL_STATUSES = [...PROGRESS_STEPS, ...SPECIAL_STATUSES]

/** Get label for a status value */
export function getStatusLabel(value) {
  const found = ALL_STATUSES.find((s) => s.value === value)
  return found ? found.label : value
}

/** Get color for a status value */
export function getStatusColor(value) {
  const found = ALL_STATUSES.find((s) => s.value === value)
  return found ? found.color : '#6b7280'
}

/** Get icon for a status value */
export function getStatusIcon(value) {
  const found = ALL_STATUSES.find((s) => s.value === value)
  return found ? found.icon : '•'
}

/** Status groups for admin dropdown (all valid transition targets) */
export const ADMIN_STATUS_OPTIONS = ALL_STATUSES.map((s) => ({
  value: s.value,
  label: `${s.icon} ${s.label}`,
}))
