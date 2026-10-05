/**
 * Common UI components — exact match of compiled components in booking-ChxRsZYG.js:
 *
 *   Ce  → Button
 *   de  → Spinner
 *   ze  → Alert  (type error/success/info/warning)
 *   Re  → FormInput
 *   Ie  → FormSelect
 *   Ae  → Card
 *   Te  → Modal
 *   Be  → EmptyState
 *   Le  → PageHeader
 *   Pe  → LoadingScreen
 *   Ne  → WhatsAppButton
 *   We  → StatusBadge
 */

import React, { useEffect, useRef } from 'react'
import { useStore } from '@/lib/store'
import { resolvePrimary } from '@/lib/design-tokens'
import { DashboardPageSkeleton } from '@/components/ui/LoadingPlaceholders'

// ── Spinner ───────────────────────────────────────────────────────────────────
interface SpinnerProps {
  size?: number
  color?: string
}
export function Spinner({ size = 24, color = '#1E2D40' }: SpinnerProps) {
  return (
    <span
      role="status"
      aria-label="Loading"
      style={{
        display: 'inline-block',
        width: size,
        height: size,
        border: `2px solid ${color}30`,
        borderTopColor: color,
        borderRadius: '50%',
        animation: 'spin .7s linear infinite',
        flexShrink: 0,
      }}
    />
  )
}

// ── Button ────────────────────────────────────────────────────────────────────
type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'accent'

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  loading?: boolean
  children: React.ReactNode
}

export function Button({
  children,
  onClick,
  type = 'button',
  variant = 'primary',
  loading = false,
  disabled = false,
  className = '',
  style,
  ...rest
}: ButtonProps) {
  const settings = useStore((s) => s.settings)
  const primary = resolvePrimary(settings)
  const accent  = settings.accent_color  || '#f97316'

  const variants: Record<ButtonVariant, React.CSSProperties> = {
    primary:   { background: primary,     color: '#fff', border: 'none' },
    secondary: { background: 'transparent', color: primary, border: `2px solid ${primary}` },
    danger:    { background: '#dc2626',   color: '#fff', border: 'none' },
    ghost:     { background: 'transparent', color: '#555', border: '1px solid #e2e8f0' },
    accent:    { background: accent,      color: '#fff', border: 'none' },
  }

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled || loading}
      className={`s2btn s2btn--${variant} ${className}`}
      style={{
        ...variants[variant],
        padding: '10px 20px',
        borderRadius: '8px',
        fontSize: '15px',
        fontWeight: 600,
        cursor: disabled || loading ? 'not-allowed' : 'pointer',
        opacity: disabled || loading ? 0.7 : 1,
        display: 'inline-flex',
        alignItems: 'center',
        gap: '8px',
        transition: 'opacity .15s, transform .1s',
        whiteSpace: 'nowrap',
        ...style,
      }}
      {...rest}
    >
      {loading && <Spinner size={16} color={variant === 'primary' ? '#fff' : primary} />}
      {children}
    </button>
  )
}

// ── Alert ─────────────────────────────────────────────────────────────────────
type AlertType = 'error' | 'success' | 'info' | 'warning'

interface AlertProps {
  type?: AlertType
  message?: string | null
  onClose?: () => void
}

const ALERT_STYLES: Record<AlertType, { bg: string; border: string; text: string; icon: string }> = {
  error:   { bg: '#fef2f2', border: '#fecaca', text: '#b91c1c', icon: '✕' },
  success: { bg: '#f0fdf4', border: '#bbf7d0', text: '#15803d', icon: '✓' },
  info:    { bg: '#EBF0F8', border: '#b8e4f5', text: '#1E2D40', icon: 'ℹ' },
  warning: { bg: '#fffbeb', border: '#fde68a', text: '#b45309', icon: '⚠' },
}

export function Alert({ type = 'error', message, onClose }: AlertProps) {
  if (!message) return null
  const s = ALERT_STYLES[type] || ALERT_STYLES.error
  return (
    <div
      role="alert"
      style={{
        background: s.bg,
        border: `1px solid ${s.border}`,
        color: s.text,
        borderRadius: '8px',
        padding: '12px 16px',
        display: 'flex',
        alignItems: 'flex-start',
        gap: '10px',
        marginBottom: '16px',
        fontSize: '14px',
      }}
    >
      <span aria-hidden="true">{s.icon}</span>
      <span style={{ flex: 1 }}>{message}</span>
      {onClose && (
        <button
          onClick={onClose}
          aria-label="Dismiss"
          style={{
            background: 'none',
            border: 'none',
            color: s.text,
            cursor: 'pointer',
            padding: '0 0 0 8px',
            fontSize: '16px',
          }}
        >
          ×
        </button>
      )}
    </div>
  )
}

// ── FormInput ─────────────────────────────────────────────────────────────────
interface FormInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string
  error?: string
  hint?: string
  required?: boolean
  id?: string
}

export function FormInput({ label, error, hint, required, id, type = 'text', ...rest }: FormInputProps) {
  const inputId = id || label?.toLowerCase().replace(/\s+/g, '_')
  return (
    <div style={{ marginBottom: '16px' }}>
      {label && (
        <label
          htmlFor={inputId}
          style={{ display: 'block', fontWeight: 600, marginBottom: '6px', fontSize: '14px', color: '#374151' }}
        >
          {label}
          {required && <span aria-hidden="true" style={{ color: '#dc2626', marginLeft: '3px' }}>*</span>}
        </label>
      )}
      <input
        id={inputId}
        type={type}
        required={required}
        aria-invalid={!!error}
        aria-describedby={error ? `${inputId}-err` : hint ? `${inputId}-hint` : undefined}
        style={{
          width: '100%',
          padding: '10px 14px',
          border: `1px solid ${error ? '#fca5a5' : '#d1d5db'}`,
          borderRadius: '8px',
          fontSize: '15px',
          outline: 'none',
          background: error ? '#fef2f2' : '#fff',
          boxSizing: 'border-box',
          transition: 'border-color .15s',
        }}
        {...rest}
      />
      {hint && !error && (
        <p id={`${inputId}-hint`} style={{ margin: '4px 0 0', fontSize: '12px', color: '#6b7280' }}>
          {hint}
        </p>
      )}
      {error && (
        <p id={`${inputId}-err`} role="alert" style={{ margin: '4px 0 0', fontSize: '12px', color: '#b91c1c' }}>
          {error}
        </p>
      )}
    </div>
  )
}

// ── FormSelect ────────────────────────────────────────────────────────────────
interface FormSelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  label?: string
  error?: string
  required?: boolean
  id?: string
  options?: string[]
  placeholder?: string
}

export function FormSelect({ label, error, required, id, options = [], placeholder, ...rest }: FormSelectProps) {
  const selectId = id || label?.toLowerCase().replace(/\s+/g, '_')
  return (
    <div style={{ marginBottom: '16px' }}>
      {label && (
        <label
          htmlFor={selectId}
          style={{ display: 'block', fontWeight: 600, marginBottom: '6px', fontSize: '14px', color: '#374151' }}
        >
          {label}
          {required && <span aria-hidden="true" style={{ color: '#dc2626', marginLeft: '3px' }}>*</span>}
        </label>
      )}
      <select
        id={selectId}
        required={required}
        aria-invalid={!!error}
        style={{
          width: '100%',
          padding: '10px 14px',
          border: `1px solid ${error ? '#fca5a5' : '#d1d5db'}`,
          borderRadius: '8px',
          fontSize: '15px',
          background: '#fff',
          boxSizing: 'border-box',
        }}
        {...rest}
      >
        {placeholder && <option value="">{placeholder}</option>}
        {options.map((o) => <option key={o} value={o}>{o}</option>)}
      </select>
      {error && (
        <p role="alert" style={{ margin: '4px 0 0', fontSize: '12px', color: '#b91c1c' }}>{error}</p>
      )}
    </div>
  )
}

// ── Textarea ──────────────────────────────────────────────────────────────────
interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string
  error?: string
  hint?: string
  required?: boolean
  id?: string
}

export function Textarea({ label, error, hint, required, id, rows = 4, ...rest }: TextareaProps) {
  const inputId = id || label?.toLowerCase().replace(/\s+/g, '_')
  return (
    <div style={{ marginBottom: '16px' }}>
      {label && (
        <label
          htmlFor={inputId}
          style={{ display: 'block', fontWeight: 600, marginBottom: '6px', fontSize: '14px', color: '#374151' }}
        >
          {label}
          {required && <span aria-hidden="true" style={{ color: '#dc2626', marginLeft: '3px' }}>*</span>}
        </label>
      )}
      <textarea
        id={inputId}
        rows={rows}
        required={required}
        aria-invalid={!!error}
        style={{
          width: '100%',
          padding: '10px 14px',
          border: `1px solid ${error ? '#fca5a5' : '#d1d5db'}`,
          borderRadius: '8px',
          fontSize: '15px',
          outline: 'none',
          background: error ? '#fef2f2' : '#fff',
          boxSizing: 'border-box',
          resize: 'vertical',
          fontFamily: 'inherit',
        }}
        {...rest}
      />
      {hint && !error && (
        <p style={{ margin: '4px 0 0', fontSize: '12px', color: '#6b7280' }}>{hint}</p>
      )}
      {error && (
        <p role="alert" style={{ margin: '4px 0 0', fontSize: '12px', color: '#b91c1c' }}>{error}</p>
      )}
    </div>
  )
}

// ── Card ──────────────────────────────────────────────────────────────────────
interface CardProps {
  children: React.ReactNode
  style?: React.CSSProperties
  className?: string
}
export function Card({ children, style = {}, className = '' }: CardProps) {
  return (
    <div
      className={`s2-ui-card ${className}`.trim()}
      style={{
        background: '#fff',
        border: '1px solid #e5e7eb',
        borderRadius: '12px',
        padding: '24px',
        ...style,
      }}
    >
      {children}
    </div>
  )
}

// ── Modal ─────────────────────────────────────────────────────────────────────
interface ModalProps {
  open: boolean
  onClose: () => void
  title: string
  children: React.ReactNode
  maxWidth?: number
}
export function Modal({ open, onClose, title, children, maxWidth = 560 }: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null)
  const previouslyFocused = useRef<HTMLElement | null>(null)

  // FIXED: this dialog had correct static ARIA (role="dialog",
  // aria-modal="true", aria-label on both the container and the
  // icon-only close button) but no actual focus trap — a keyboard user
  // tabbing through it would tab straight out into the page behind it,
  // despite aria-modal="true" implying that shouldn't be possible. Also
  // adds Escape-to-close (standard dialog behavior) and restores focus
  // to whatever triggered the modal once it closes, rather than leaving
  // focus lost on a removed element. This fixes every modal in the app
  // at once, since Modal is a shared component used throughout the
  // admin panel.
  useEffect(() => {
    if (!open) return
    previouslyFocused.current = document.activeElement as HTMLElement
    dialogRef.current?.focus()

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { onClose(); return }
      if (e.key !== 'Tab' || !dialogRef.current) return
      const focusable = dialogRef.current.querySelectorAll<HTMLElement>(
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
    <div
      ref={dialogRef}
      tabIndex={-1}
      role="dialog"
      aria-modal="true"
      aria-label={title}
      className="s2-modal-backdrop"
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 1000,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '16px',
        background: 'rgba(0,0,0,.5)',
      }}
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div
        className="s2-modal-panel"
        style={{
          background: '#fff',
          borderRadius: '16px',
          width: '100%',
          maxWidth,
          maxHeight: '90vh',
          overflow: 'auto',
        }}
      >
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '20px 24px',
            borderBottom: '1px solid #e5e7eb',
          }}
        >
          <h2 style={{ margin: 0, fontSize: '18px', fontWeight: 700, color: '#111827' }}>{title}</h2>
          <button
            onClick={onClose}
            aria-label="Close dialog"
            style={{
              background: 'none',
              border: 'none',
              fontSize: '24px',
              cursor: 'pointer',
              color: '#6b7280',
              padding: '4px 8px',
              borderRadius: '6px',
            }}
          >
            ×
          </button>
        </div>
        <div style={{ padding: '24px' }}>{children}</div>
      </div>
    </div>
  )
}

// ── EmptyState ────────────────────────────────────────────────────────────────
interface EmptyStateProps {
  icon?: string
  title: string
  description?: string
  action?: React.ReactNode
}
export function EmptyState({ icon = '📭', title, description, action }: EmptyStateProps) {
  return (
    <div style={{ textAlign: 'center', padding: '48px 24px', color: '#6b7280' }}>
      <div style={{ fontSize: '48px', marginBottom: '16px' }}>{icon}</div>
      <h3 style={{ margin: '0 0 8px', color: '#374151', fontSize: '18px' }}>{title}</h3>
      {description && <p style={{ margin: '0 0 24px', fontSize: '14px' }}>{description}</p>}
      {action}
    </div>
  )
}

// ── PageHeader ────────────────────────────────────────────────────────────────
interface PageHeaderProps {
  title: string
  subtitle?: string
  action?: React.ReactNode
}
export function PageHeader({ title, subtitle, action }: PageHeaderProps) {
  return (
    <div
      className="s2-dash-page-header s2-admin-page-header"
      style={{
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'space-between',
        marginBottom: '24px',
        flexWrap: 'wrap',
        gap: '12px',
      }}
    >
      <div>
        <h1 style={{ margin: 0, fontSize: '22px', fontWeight: 700, color: '#111827' }}>{title}</h1>
        {subtitle && <p style={{ margin: '4px 0 0', color: '#6b7280', fontSize: '14px' }}>{subtitle}</p>}
      </div>
      {action && <div className="s2-admin-page-header__actions--desktop-only">{action}</div>}
    </div>
  )
}

// ── LoadingScreen ─────────────────────────────────────────────────────────────
interface LoadingScreenProps {
  message?: string
}
export function LoadingScreen({ message }: LoadingScreenProps) {
  return (
    <div style={{ minHeight: 300 }} aria-busy="true" aria-label={message || 'Loading content'}>
      <DashboardPageSkeleton />
    </div>
  )
}

// ── WhatsAppButton ────────────────────────────────────────────────────────────
interface WhatsAppButtonProps {
  number: string
  message?: string
  label?: string
  style?: React.CSSProperties
}
export function WhatsAppButton({
  number,
  message = 'Hello, I need help with my NRI service request.',
  label = 'Chat on WhatsApp',
  style = {},
}: WhatsAppButtonProps) {
  if (!number) return null
  const href = `https://wa.me/${number.replace(/\D/g, '')}?text=${encodeURIComponent(message)}`
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '8px',
        background: '#25d366',
        color: '#fff',
        padding: '10px 20px',
        borderRadius: '8px',
        fontWeight: 600,
        textDecoration: 'none',
        fontSize: '14px',
        ...style,
      }}
    >
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005" />
      </svg>
      {label}
    </a>
  )
}

// ── StatusBadge ───────────────────────────────────────────────────────────────
import { STATUS_COLORS, formatStatus } from '@/lib/constants'

interface StatusBadgeProps {
  status: string
  label?: string
}
export function StatusBadge({ status, label }: StatusBadgeProps) {
  const [bg, color] = STATUS_COLORS[status] || ['#f3f4f6', '#374151']
  const text = label || formatStatus(status)
  return (
    <span
      style={{
        background: bg,
        color,
        padding: '3px 10px',
        borderRadius: '99px',
        fontSize: '12px',
        fontWeight: 600,
        whiteSpace: 'nowrap',
      }}
    >
      {text}
    </span>
  )
}

// ── DashboardLayout wrapper ───────────────────────────────────────────────────
interface DashboardWrapperProps {
  children: React.ReactNode
}
export function DashboardLayout({ children }: DashboardWrapperProps) {
  return (
    <div style={{ maxWidth: 1200, margin: '0 auto', padding: '32px 20px' }}>
      {children}
    </div>
  )
}
