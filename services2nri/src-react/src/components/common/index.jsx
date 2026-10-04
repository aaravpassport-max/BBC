import { useEffect, useRef } from 'react'
import { getStatusColor, getStatusIcon, getStatusLabel } from '../../utils/statuses'

/* ── Button ─────────────────────────────────────────────────────────── */
export function Button({
  children,
  variant = 'secondary',
  size = '',
  loading = false,
  icon = null,
  onClick,
  type = 'button',
  disabled = false,
  className = '',
  ...rest
}) {
  return (
    <button
      type={type}
      className={`btn btn-${variant} ${size ? `btn-${size}` : ''} ${className}`}
      onClick={onClick}
      disabled={disabled || loading}
      {...rest}
    >
      {loading ? <span className="spinner" style={{ width: 14, height: 14, borderWidth: 2 }} /> : icon}
      {children}
    </button>
  )
}

/* ── Modal ──────────────────────────────────────────────────────────── */
export function Modal({ open, onClose, title, children, footer, size = '' }) {
  const dialogRef = useRef(null)
  const previouslyFocused = useRef(null)

  // FIXED: this dialog had NO accessibility attributes at all (no role,
  // no aria-modal, no aria-label anywhere, not even on the icon-only ✕
  // close button) and no focus trap — a keyboard or screen-reader user
  // had no indication this was a dialog at all, and tabbing through it
  // would escape into the page behind it. Also adds Escape-to-close and
  // restores focus to the trigger element on close. This is a shared
  // component used throughout the entire builder app (FormBuilder,
  // ServiceBuilder, HomepageBuilder), so this one fix benefits all of them.
  useEffect(() => {
    if (!open) return
    previouslyFocused.current = document.activeElement
    dialogRef.current?.focus()

    const handleKeyDown = (e) => {
      if (e.key === 'Escape') { onClose(); return }
      if (e.key !== 'Tab' || !dialogRef.current) return
      const focusable = dialogRef.current.querySelectorAll(
        'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
      if (focusable.length === 0) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      previouslyFocused.current?.focus()
    }
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div ref={dialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-label={title} className="modal" style={{ maxWidth: size === 'lg' ? 760 : size === 'xl' ? 960 : 560 }}>
        <div className="modal-header">
          <h2 className="modal-title">{title}</h2>
          <button className="btn btn-ghost btn-icon" onClick={onClose} aria-label="Close dialog">✕</button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-footer">{footer}</div>}
      </div>
    </div>
  )
}

/* ── Toast Container ────────────────────────────────────────────────── */
export function ToastContainer({ toasts }) {
  const icons = { success: '✓', error: '✕', info: 'ℹ' }
  return (
    <div className="toast-container">
      {toasts.map((t) => (
        <div key={t.id} className={`toast toast-${t.type}`}>
          <span>{icons[t.type]}</span>
          {t.message}
        </div>
      ))}
    </div>
  )
}

/* ── Spinner ─────────────────────────────────────────────────────────── */
export function Spinner({ size = 24, label = 'Loading…' }) {
  return (
    <div className="loading-center">
      <div className="spinner" style={{ width: size, height: size }} />
      {label && <span>{label}</span>}
    </div>
  )
}

/* ── Empty State ─────────────────────────────────────────────────────── */
export function EmptyState({ icon = '📭', title, description, action }) {
  return (
    <div className="empty-state">
      <div className="empty-icon">{icon}</div>
      {title && <h3>{title}</h3>}
      {description && <p>{description}</p>}
      {action}
    </div>
  )
}

/* ── Status Badge ─────────────────────────────────────────────────────── */
export function StatusBadge({ value }) {
  const color = getStatusColor(value)
  const icon  = getStatusIcon(value)
  const label = getStatusLabel(value)
  return (
    <span
      className="status-badge"
      style={{
        background: color + '18',
        color: color,
        border: `1px solid ${color}40`,
      }}
    >
      {icon} {label}
    </span>
  )
}

/* ── Alert ───────────────────────────────────────────────────────────── */
export function Alert({ type = 'info', children }) {
  return <div className={`alert alert-${type}`}>{children}</div>
}

/* ── Confirm Dialog ──────────────────────────────────────────────────── */
export function ConfirmDialog({ open, onConfirm, onCancel, title, message, confirmLabel = 'Delete', danger = false }) {
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title || 'Confirm Action'}
      footer={
        <>
          <Button variant="secondary" onClick={onCancel}>Cancel</Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p style={{ margin: 0, fontSize: 14, color: 'var(--text-muted)' }}>{message}</p>
    </Modal>
  )
}

/* ── FormGroup ───────────────────────────────────────────────────────── */
export function FormGroup({ label, hint, error, required, children }) {
  return (
    <div className="form-group">
      {label && (
        <label className="form-label">
          {label}
          {required && <span className="required"> *</span>}
        </label>
      )}
      {children}
      {hint && !error && <div className="form-hint">{hint}</div>}
      {error && <div className="form-error">{error}</div>}
    </div>
  )
}

/* ── Toggle ──────────────────────────────────────────────────────────── */
export function Toggle({ checked, onChange, label }) {
  return (
    <label className="toggle">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span className="toggle-track">
        <span className="toggle-thumb" style={{ left: checked ? 18 : 2 }} />
      </span>
      {label && <span className="toggle-label">{label}</span>}
    </label>
  )
}

/* ── Color Picker ────────────────────────────────────────────────────── */
export function ColorPicker({ value, onChange, label }) {
  return (
    <FormGroup label={label}>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <input
          type="color"
          value={value || '#4A6FA5'}
          onChange={(e) => onChange(e.target.value)}
          style={{ width: 40, height: 36, padding: 2, border: '1px solid var(--border)', borderRadius: 6, cursor: 'pointer' }}
        />
        <input
          type="text"
          value={value || ''}
          onChange={(e) => onChange(e.target.value)}
          placeholder="#4A6FA5"
          className="form-input"
          style={{ flex: 1 }}
        />
      </div>
    </FormGroup>
  )
}

/* ── Image Upload ────────────────────────────────────────────────────── */
export function ImageUpload({ value, onChange, label, hint }) {
  return (
    <FormGroup label={label} hint={hint}>
      {value && (
        <div style={{ marginBottom: 8 }}>
          <img src={value} alt="" style={{ maxHeight: 120, maxWidth: '100%', borderRadius: 8, border: '1px solid var(--border)' }} />
        </div>
      )}
      <div style={{ display: 'flex', gap: 8 }}>
        <input
          type="text"
          value={value || ''}
          onChange={(e) => onChange(e.target.value)}
          placeholder="https://… or upload below"
          className="form-input"
        />
        <Button
          variant="secondary"
          size="sm"
          onClick={() => {
            if (window.wp?.media) {
              const frame = window.wp.media({ title: 'Select Image', button: { text: 'Use Image' }, multiple: false })
              frame.on('select', () => {
                const attachment = frame.state().get('selection').first().toJSON()
                onChange(attachment.url)
              })
              frame.open()
            }
          }}
        >
          📁
        </Button>
      </div>
    </FormGroup>
  )
}

/* ── Drag Handle Icon ─────────────────────────────────────────────────── */
export function DragHandle(props) {
  return (
    <span className="drag-handle" {...props} title="Drag to reorder">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <circle cx="9" cy="5" r="1.5"/>
        <circle cx="15" cy="5" r="1.5"/>
        <circle cx="9" cy="12" r="1.5"/>
        <circle cx="15" cy="12" r="1.5"/>
        <circle cx="9" cy="19" r="1.5"/>
        <circle cx="15" cy="19" r="1.5"/>
      </svg>
    </span>
  )
}
