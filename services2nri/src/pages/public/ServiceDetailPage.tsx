/**
 * ServiceDetailPage — step-driven booking wizard
 *
 * The API returns form_schema where EVERY field has a `step` number:
 *   step 1   → Service classification / type / what do you need
 *   step 2   → Document details / institutional info / identifiers
 *   step 3   → Personal details / applicant / location / purpose
 *   step 4   → Delivery address / timeline / remarks (some schemas)
 *   step 98  → Contact info (phone, email, country, delivery preference)
 *   step 99  → File uploads
 *   [last]   → Confirm & Submit (always added by the widget)
 *
 * Step patterns:
 *   [1,2,3]        → 3 content steps + Confirm = 4 total
 *   [1,2,3,4]      → 4 content steps + Confirm = 5 total
 *   [1,2,3,98,99]  → 5 content steps + Confirm = 6 total
 *
 * For legacy schemas with no step property on fields:
 *   X() fallback assigns 1/2/3 based on key name (matches compiled X() exactly)
 *
 * DISPLAY: placeholder-first compact fields.
 * Small uppercase label above each field for accessibility.
 * No redundant titles. Description/hint below field when present.
 */

import React, { useState, useEffect, useRef, useCallback } from 'react'
import { cssVars, resolvePrimary } from '@/lib/design-tokens'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { Layout } from '@/components/layout/Layout'
import { ServiceMobileStickyCta } from '@/components/public/ServiceMobileStickyCta'
import { PublicGrid, PublicCard } from '@/components/public/PublicLayout'
import { useStore } from '@/lib/store'
import { api } from '@/lib/api'
import { resolveInternalDestination } from '@/lib/spa-navigation'
import { getServiceImage } from '@/lib/images'
import type { Service, ServiceSection, SectionType } from '@/types'
import { installServiceSectionNavStrip } from '@/lib/service-section-nav-strip'
import { hydrateEmptySection } from '@/lib/service-section-normalize'
import { isTemplateSectionHidden } from '@/lib/section-visibility'
import { refreshExperienceReveal } from '@/components/public/ExperienceReveal'
import { ServiceDetailShellSkeleton } from '@/components/ui/LoadingPlaceholders'
import { peekCached } from '@/lib/resource-cache'

// ── Field types ───────────────────────────────────────────────────────────────
interface FormField {
  key: string
  label: string
  label_hi?: string
  type: string
  step?: number
  required?: boolean
  options?: string[]
  placeholder?: string
  hint?: string
  description?: string
  conditions?: Record<string, unknown>
}

// ── Dial codes (ye[] exact match) ─────────────────────────────────────────────
const DIAL_CODES = [
  { code: '+91', flag: '🇮🇳', name: 'IN' },  { code: '+1',   flag: '🇺🇸', name: 'US' },
  { code: '+44', flag: '🇬🇧', name: 'UK' },  { code: '+61',  flag: '🇦🇺', name: 'AU' },
  { code: '+971',flag: '🇦🇪', name: 'UAE' }, { code: '+65',  flag: '🇸🇬', name: 'SG' },
  { code: '+1',  flag: '🇨🇦', name: 'CA' },  { code: '+49',  flag: '🇩🇪', name: 'DE' },
  { code: '+60', flag: '🇲🇾', name: 'MY' },  { code: '+974', flag: '🇶🇦', name: 'QA' },
  { code: '+966',flag: '🇸🇦', name: 'SA' },  { code: '+64',  flag: '🇳🇿', name: 'NZ' },
  { code: '+31', flag: '🇳🇱', name: 'NL' },  { code: '+81',  flag: '🇯🇵', name: 'JP' },
]

const COUNTRIES = [
  'USA','UK','Canada','Australia','UAE','Singapore','Germany',
  'New Zealand','Saudi Arabia','Qatar','Kuwait','Bahrain','Oman',
  'Netherlands','Malaysia','Japan','Other',
]

// ── Step label map ────────────────────────────────────────────────────────────
const STEP_LABEL: Record<number, string> = {
  1:  'Service Details',
  2:  'Document Details',
  3:  'Your Requirements',
  4:  'Delivery & Notes',
  98: 'Contact Details',
  99: 'Upload Documents',
}

// ── X() field priority fallback (exact compiled match) ─────────────────────────
function xPriority(f: FormField): number {
  const o = f.key.toLowerCase()
  if ((f.type || 'text').toLowerCase() === 'file' || o.includes('document') || o.includes('upload')) return 99
  if (['first_name','last_name','full_name','applicant_name','phone','mobile','whatsapp',
       'email','address','city_abroad','country','current_location','delivery','remarks','additional']
    .some(n => o === n || o.startsWith(n))) return 98
  return 1
}

// ── Build step groups from schema ─────────────────────────────────────────────
function buildSteps(schema: FormField[]): Map<number, FormField[]> {
  const map = new Map<number, FormField[]>()
  schema.forEach(f => {
    // Use explicit step if present, else X() fallback
    const step = typeof f.step === 'number' && f.step > 0 ? f.step : xPriority(f)
    if (!map.has(step)) map.set(step, [])
    map.get(step)!.push(f)
  })
  return map
}

// ── Input classes (design-system tokens via public-design-system.css) ─────────
function fieldClass(error?: string, extra = ''): string {
  return ['s2-input', 's2-wizard-field', error ? 's2-input--error' : '', extra].filter(Boolean).join(' ')
}

// ── SearchableSelect ──────────────────────────────────────────────────────────
function SearchableSelect({ field, value, onChange, error }: { field: FormField; value: string; onChange: (v: string) => void; error?: string }) {
  const [query, setQuery] = useState(value || '')
  const [open, setOpen]   = useState(false)
  const ref               = useRef<HTMLDivElement>(null)
  const opts              = field.options || []
  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])
  const filtered = opts.filter(o => o.toLowerCase().includes(query.toLowerCase())).slice(0, 10)
  return (
    <div ref={ref} className="s2-wizard-typeahead">
      <input value={query} onChange={e => { setQuery(e.target.value); onChange(e.target.value); setOpen(true) }}
        onFocus={() => setOpen(true)} placeholder={field.placeholder || 'Type to search…'} className={fieldClass(error)} />
      {open && filtered.length > 0 && (
        <div className="s2-wizard-typeahead-menu">
          {filtered.map(opt => (
            <div key={opt} className="s2-wizard-typeahead-item" onMouseDown={() => { setQuery(opt); onChange(opt); setOpen(false) }}>{opt}</div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── FileUpload ────────────────────────────────────────────────────────────────
function FileUpload({ field, value, onChange, error }: { field: FormField; value: File[]; onChange: (f: File[]) => void; error?: string }) {
  const inputRef = useRef<HTMLInputElement>(null)
  const add = (list: FileList | null) => { if (!list) return; onChange([...value, ...Array.from(list)]) }
  const remove = (i: number) => onChange(value.filter((_, j) => j !== i))
  return (
    <div>
      <div
        role="button"
        tabIndex={0}
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') inputRef.current?.click() }}
        onDragOver={e => { e.preventDefault(); e.currentTarget.classList.add('s2-wizard-upload--drag') }}
        onDragLeave={e => { e.currentTarget.classList.remove('s2-wizard-upload--drag') }}
        onDrop={e => { e.preventDefault(); e.currentTarget.classList.remove('s2-wizard-upload--drag'); add(e.dataTransfer.files) }}
        className={`s2-wizard-upload${error ? ' s2-wizard-upload--error' : ''}`}
      >
        <div className="s2-wizard-upload__icon">📎</div>
        <div className="s2-t-body s2-wizard-upload__title">
          Drop files here or <span className="s2-text-primary s2-wizard-upload__browse">browse</span>
        </div>
        <div className="s2-text-muted s2-wizard-upload__hint">PDF, JPG, PNG · Max 10 MB each</div>
        <input ref={inputRef} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" className="s2-sr-file-input" onChange={e => add(e.target.files)} />
      </div>
      {value.length > 0 && (
        <div className="s2-wizard-upload-list">
          {value.map((f, i) => (
            <div key={i} className="s2-wizard-upload-item">
              <span className="s2-wizard-upload-item__icon">{f.type.includes('pdf') ? '📄' : '🖼️'}</span>
              <div className="s2-wizard-upload-item__name">{f.name}</div>
              <span className="s2-text-muted s2-wizard-upload-item__size">{(f.size / 1024).toFixed(0)} KB</span>
              <button type="button" onClick={() => remove(i)} className="s2-btn s2-btn--ghost s2-btn--sm s2-wizard-upload-item__remove" aria-label="Remove file">×</button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── PhoneField ────────────────────────────────────────────────────────────────
function PhoneField({ field, value, onChange, error }: { field: FormField; value: string; onChange: (v: string) => void; error?: string }) {
  const [code, setCode] = useState('+91')
  const local = (value || '').replace(/^\+\d{1,4}\s?/, '')
  const update = (c: string, n: string) => onChange(`${c} ${n}`)
  return (
    <div className="s2-wizard-phone-row">
      <select value={code} onChange={e => { setCode(e.target.value); update(e.target.value, local) }}
        className={`s2-select s2-wizard-phone-code ${error ? 's2-input--error' : ''}`}>
        {DIAL_CODES.map(d => <option key={`${d.flag}${d.code}`} value={d.code}>{d.flag} {d.name} {d.code}</option>)}
      </select>
      <input type="tel" value={local} onChange={e => update(code, e.target.value)}
        placeholder={field.placeholder || 'Mobile number'} className={fieldClass(error, 's2-wizard-field--flex')} />
    </div>
  )
}

// ── FieldRenderer — placeholder-first compact design ──────────────────────────
function FieldRenderer({ field, value, onChange, error }: {
  field: FormField; value: unknown; onChange: (v: unknown) => void; error?: string; primary?: string
}) {
  const lang = (() => { try { return localStorage.getItem('s2nri_lang') || 'en' } catch { return 'en' } })()
  const label = lang === 'hi' && field.label_hi ? field.label_hi : field.label
  const id   = `bk_${field.key}`
  const type = (field.type || 'text').toLowerCase()
  const isFile = type === 'file' || field.key.includes('document') || field.key.includes('upload')

  const wrap = (input: React.ReactNode) => (
    <div className="s2-wizard-field-wrap">
      <label htmlFor={id} className="s2-wizard-field-label">
        {label}{field.required && <span className="s2-wizard-required">*</span>}
      </label>
      {input}
      {field.description && !error && <p className="s2-wizard-field-hint">{field.description}</p>}
      {field.hint && !error && <p className="s2-wizard-field-hint">{field.hint}</p>}
      {error && <p role="alert" className="s2-wizard-field-error">⚠ {error}</p>}
    </div>
  )

  if (isFile) return wrap(<FileUpload field={field} value={Array.isArray(value) ? value as File[] : []} onChange={v => onChange(v)} error={error} />)
  if (type === 'searchable') return wrap(<SearchableSelect field={field} value={String(value || '')} onChange={onChange} error={error} />)
  if (type === 'phone') return wrap(<PhoneField field={field} value={String(value || '')} onChange={onChange} error={error} />)
  if (type === 'textarea') return wrap(
    <textarea id={id} rows={3} value={String(value || '')} onChange={e => onChange(e.target.value)}
      placeholder={field.placeholder || label} className={fieldClass(error, 's2-textarea s2-wizard-textarea')} />
  )
  if (type === 'select' || type === 'dropdown') return wrap(
    <select id={id} value={String(value || '')} onChange={e => onChange(e.target.value)} className={fieldClass(error, 's2-select')}>
      <option value="">— {field.placeholder || `Select ${label}`} —</option>
      {(field.options || []).map(opt => <option key={opt} value={opt}>{opt}</option>)}
    </select>
  )
  if (type === 'radio') return wrap(
    <div className="s2-wizard-options">
      {(field.options || []).map(opt => (
        <label key={opt} className={`s2-wizard-option${value === opt ? ' s2-wizard-option--checked' : ''}`}>
          <input type="radio" name={id} value={opt} checked={value === opt} onChange={() => onChange(opt)} className="s2-wizard-control" />{opt}
        </label>
      ))}
    </div>
  )
  if (type === 'checkbox') return wrap(
    <div className="s2-wizard-options s2-wizard-options--stack">
      {(field.options || []).map(opt => {
        const checked = Array.isArray(value) ? (value as string[]).includes(opt) : false
        const toggle  = () => {
          const arr = Array.isArray(value) ? [...value as string[]] : []
          onChange(checked ? arr.filter(v => v !== opt) : [...arr, opt])
        }
        return (
          <label key={opt} className={`s2-wizard-option s2-wizard-option--stack${checked ? ' s2-wizard-option--checked' : ''}`}>
            <input type="checkbox" checked={checked} onChange={toggle} className="s2-wizard-control" />{opt}
          </label>
        )
      })}
    </div>
  )
  if (type === 'date') return wrap(<input id={id} type="date" value={String(value || '')} onChange={e => onChange(e.target.value)} className={fieldClass(error)} />)
  if (type === 'number') return wrap(<input id={id} type="number" value={String(value || '')} onChange={e => onChange(e.target.value)} placeholder={field.placeholder || label} className={fieldClass(error)} />)
  if (type === 'email') return wrap(<input id={id} type="email" value={String(value || '')} onChange={e => onChange(e.target.value)} placeholder={field.placeholder || label} className={fieldClass(error)} />)
  return wrap(<input id={id} type="text" value={String(value || '')} onChange={e => onChange(e.target.value)} placeholder={field.placeholder || label} className={fieldClass(error)} />)
}

// ── Section renderer (Wn — service content sections) ─────────────────────────
function FaqItem({ q, a }: { q: string; a: string }) {
  const [open, setOpen] = useState(false)
  return (
    <div className={`s2-svc-faq-inline${open ? ' s2-public-faq-item--open' : ''}`}>
      <button type="button" onClick={() => setOpen(o => !o)} aria-expanded={open}>
        <span>{q}</span>
        <span className="s2-public-faq-toggle" aria-hidden>{open ? '−' : '+'}</span>
      </button>
      {open && <p className="s2-t-body s2-svc-faq-inline__answer">{a}</p>}
    </div>
  )
}

type FeeRow = { label: string; amount: string; total?: boolean }

function FeeBreakdown({ rows }: { rows: FeeRow[] }) {
  return (
    <div className="s2-fee-breakdown" role="table" aria-label="Fee breakdown">
      {rows.map((row, i) => (
        <div
          key={`${row.label}-${i}`}
          className={`s2-fee-breakdown__row${row.total ? ' s2-fee-breakdown__row--total' : ''}`}
          role="row"
        >
          <span role="cell">{row.label}</span>
          <strong role="cell">{row.amount}</strong>
        </div>
      ))}
    </div>
  )
}

const SVC_SECTION_WIDTH_KEY: Partial<Record<string, string>> = {
  trust_badges: 'trust_badges',
  why_choose: 'features',
  description: 'description',
  security: 'description',
  charges: 'pricing',
  text: 'cms',
  process: 'process',
  faq: 'faq',
  benefits: 'benefits',
  documents: 'documents',
  eligibility: 'requirements',
  notes: 'cms',
  cta: 'cta',
  related: 'related_services',
  testimonials: 'testimonials',
  features: 'features',
  highlights: 'features',
}

function SectionRenderer({
  sec,
  settings,
  primary,
}: {
  sec: ServiceSection
  primary: string
  settings: Record<string, string>
}) {
  // Per-service CMS rows honor DB is_visible only — not Design System template hide flags
  // (template hide applies to layout chrome + synthetic fallbacks, see ServiceDetailLeftSections).
  const r = (sec.content || {}) as Record<string, unknown>
  const sectionKey = SVC_SECTION_WIDTH_KEY[sec.type as string]
  const wrap = (children: React.ReactNode, extraClass = '', section = sectionKey) => (
    <div
      className={`s2-svc-block s2-section-inner s2-width-standard ${extraClass}`.trim()}
      data-s2-reveal=""
      {...(section ? { 'data-s2-section': section } : {})}
    >
      {sec.title && <h3 className="s2-svc-block__title">{sec.title}</h3>}
      {children}
    </div>
  )
  switch (sec.type as SectionType) {
    case 'trust_badges': {
      const badgesRaw = (r.badges as Array<{ icon: string; value: string; label: string }>) || []
      const badges =
        badgesRaw.length > 0
          ? badgesRaw
          : [
              { icon: '⭐', value: settings.google_rating || '4.9', label: 'Google Rating' },
              { icon: '👥', value: settings.google_review_count || '10,000+', label: 'Happy Customers' },
              { icon: '🌏', value: '750+', label: 'Pan India Coverage' },
            ]
      return (
        <div
          className="s2-svc-trust-grid s2-svc-trust-grid--auto s2-mobile-stack s2-stagger"
          data-s2-section="trust_badges"
          data-s2-reveal=""
          style={cssVars({ 's2-trust-cols': badges.length || 3 })}
        >
          {badges.map((b, i) => (
            <div key={i} className="s2-svc-trust-tile">
              <div className="s2-svc-trust-tile__icon">{b.icon}</div>
              <div className="s2-svc-trust-tile__value">{b.value}</div>
              <div className="s2-svc-trust-tile__label">{b.label}</div>
            </div>
          ))}
        </div>
      )
    }
    case 'why_choose': {
      const cards = (r.cards as Array<{ icon: string; title: string; desc: string }>) || []
      return (
        <section className="s2-svc-panel s2-section-inner s2-width-standard" data-s2-section="features" data-s2-reveal="">
          {sec.title && <h3 className="s2-svc-panel__title">{sec.title}</h3>}
          <PublicGrid min={220}>
            {cards.map((c, i) => (
              <div key={i} className="s2-svc-mini-card">
                <div className="s2-svc-mini-card__icon">{c.icon}</div>
                <div>
                  <h4 className="s2-public-card__title s2-public-card__title--sm">{c.title}</h4>
                  <p className="s2-public-card__body s2-public-card__body--xs">{c.desc}</p>
                </div>
              </div>
            ))}
          </PublicGrid>
        </section>
      )
    }
    case 'description': case 'security': case 'text':
      return (
        <div
          className={`s2-svc-block s2-section-inner s2-width-standard${sec.type === 'security' ? ' s2-svc-block--security' : ''}`}
          data-s2-section={sectionKey || 'description'}
          data-s2-reveal=""
        >
          {!!(r.heading as string || sec.title) && (
            <h2 className={`s2-svc-block__title${sec.type === 'description' ? ' s2-svc-block__title--lg' : ''}`}>{r.heading as string || sec.title}</h2>
          )}
          {!!r.html && <div className="s2-svc-prose" dangerouslySetInnerHTML={{ __html: String(r.html) }} />}
        </div>
      )
    case 'charges': {
      const feeRows = (r.rows as FeeRow[]) || (r.line_items as FeeRow[]) || []
      const heading = (r.heading as string) || sec.title || 'Charges & Payment'
      const hasHtml = Boolean(String(r.html || '').trim())
      return (
        <div
          className="s2-svc-block s2-svc-block--charges s2-section-inner s2-width-standard"
          data-s2-section="pricing"
          data-s2-reveal=""
        >
          <h2 className="s2-svc-block__title">{heading}</h2>
          {hasHtml ? (
            <div className="s2-svc-prose" dangerouslySetInnerHTML={{ __html: String(r.html) }} />
          ) : (
            <p className="s2-svc-prose">
              Get a personalised quote by submitting the form on the right. No payment is required until you review and
              approve your itemised quote.
            </p>
          )}
          {feeRows.length > 0 && <FeeBreakdown rows={feeRows} />}
          {!!r.note && <div className="s2-svc-pill-note">{'📦 ' + String(r.note)}</div>}
        </div>
      )
    }
    case 'process':
      return wrap(
        <div className="s2-svc-process-journey s2-stagger">
          {((r.steps as Array<{ title: string; desc: string }>) || []).map((s, i) => (
            <div key={i} className="s2-svc-step-row">
              <div className="s2-svc-step-num">{i + 1}</div>
              <div>
                <div className="s2-public-card__title s2-public-card__title--sm">{s.title}</div>
                <div className="s2-public-card__body s2-public-card__body--sm">{s.desc}</div>
              </div>
            </div>
          ))}
        </div>,
        '',
        'process',
      )
    case 'faq':
      return wrap(<div>{((r.items as Array<{ q: string; a: string }>) || []).map((item, i) => <FaqItem key={i} q={item.q} a={item.a} />)}</div>)
    case 'benefits':
      return wrap(<PublicGrid min={220}>
        {((r.items as Array<{ icon?: string; text: string }>) || []).map((item, i) => (
          <div key={i} className="s2-svc-benefit-row">
            <span className="s2-svc-benefit-row__icon">{item.icon || '✅'}</span>
            <span>{item.text || (item as { title?: string }).title || ''}</span>
          </div>
        ))}
      </PublicGrid>)
    case 'documents': case 'eligibility':
      return wrap(<div className="s2-mobile-stack s2-public-form-grid s2-public-form-grid--2col">
        {((r.items as string[]) || []).map((item, i) => (
          <div key={i} className="s2-svc-doc-row"><mark>✓</mark>{item}</div>
        ))}
      </div>)
    case 'notes':
      return wrap(<ul className="s2-svc-prose s2-svc-prose-list">{((r.items as string[]) || []).map((item, i) => <li key={i}>{item}</li>)}</ul>)
    case 'cta':
      return (
        <div className="s2-svc-cta-band s2-surface-dark s2-section-inner s2-width-wide" data-s2-section="cta" data-s2-reveal="">
          {!!(sec.title || r.headline) && <h3 className="s2-t-h3">{sec.title || String(r.headline)}</h3>}
          {!!r.sub && <p className="s2-t-body">{String(r.sub)}</p>}
          {!!r.btn && <a href={String(r.url || '#booking-form')} className="s2-btn s2-btn--secondary s2-svc-cta-band__btn">{String(r.btn)}</a>}
        </div>
      )
    case 'related':
      return wrap(((r.slugs as string[]) || []).length === 0
        ? <p className="s2-text-muted s2-public-card__body--sm">No related services configured.</p>
        : <div className="s2-svc-related-wrap">
            {((r.slugs as string[]) || []).map((slug, i) => (
              <Link key={i} to={`/service/${slug}`} className="s2-svc-related-link">
                {slug.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
              </Link>
            ))}
          </div>
      )
    case 'testimonials': {
      const items = (r.items as Array<{ name: string; quote?: string; text?: string; review?: string; rating?: number }>) || []
      return wrap(<PublicGrid min={240}>
        {items.map((t, i) => {
          const quote = t.quote || t.text || t.review || ''
          return (
          <div key={i} className="s2-svc-testimonial">
            {typeof t.rating === 'number' && <div className="s2-svc-testimonial__stars">{'★'.repeat(Math.round(t.rating))}{'☆'.repeat(5 - Math.round(t.rating))}</div>}
            {quote ? <p className="s2-t-body s2-svc-testimonial__quote">&ldquo;{quote}&rdquo;</p> : null}
            <div className="s2-public-card__title s2-public-card__body--sm">{t.name}</div>
          </div>
          )
        })}
      </PublicGrid>)
    }
    case 'features': case 'highlights':
      return wrap(<PublicGrid min={220}>
        {((r.items as Array<{ icon?: string; title: string; desc: string }>) || []).map((item, i) => (
          <div key={i} className="s2-svc-feature-tile">
            <div className="s2-svc-feature-tile__icon">{item.icon || '⭐'}</div>
            <div className="s2-public-card__title s2-public-card__title--sm">{item.title}</div>
            <div className="s2-public-card__body s2-public-card__body--xs">{item.desc}</div>
          </div>
        ))}
      </PublicGrid>)
    case 'hero':
    case 'marquee':
      return null
    default: {
      if (r.html) {
        return wrap(
          <div className="s2-svc-prose" dangerouslySetInnerHTML={{ __html: String(r.html) }} />,
          '',
          sectionKey || 'cms',
        )
      }
      if (sec.title) {
        return wrap(
          <p className="s2-t-body s2-text-muted">This section is being updated. Contact us for details in the meantime.</p>,
          '',
          sectionKey || 'cms',
        )
      }
      return null
    }
  }
}

const PROCESS_STEPS: [string, string][] = [
  ['Submit Request', 'Fill the form in steps. No login needed to start.'],
  ['Document Review', 'Our expert team reviews your submission within 24 hours.'],
  ['Get Quote', 'Receive a detailed, itemised quote. Pay only after approval.'],
  ['Processing', 'We handle everything in India with real-time updates.'],
  ['Delivery', 'Documents delivered to your overseas address by courier.'],
]

function ServiceProcessBlock() {
  return (
    <div className="s2-svc-block" data-s2-section="process" data-s2-reveal="">
      <h3 className="s2-svc-block__title">How the Process Works</h3>
      <div className="s2-svc-process-journey s2-stagger">
        {PROCESS_STEPS.map(([t, d], i) => (
          <div key={i} className="s2-svc-step-row">
            <div className="s2-svc-step-num">{i + 1}</div>
            <div>
              <div className="s2-svc-step-row__title">{t}</div>
              <div className="s2-svc-step-row__desc">{d}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

function ServiceSecurityBlock({ siteName }: { siteName: string }) {
  return (
    <div className="s2-svc-block s2-svc-block--security" data-s2-reveal="">
      <h3 className="s2-svc-block__title">🔐 Is My Data Secure?</h3>
      <p className="s2-svc-prose">
        {siteName} uses AES-256 encryption for all document uploads. Documents are never shared via email or WhatsApp
        and are permanently deleted after service completion.
      </p>
    </div>
  )
}

function ServiceChargesBlock({ svc }: { svc: Service }) {
  return (
    <div className="s2-svc-block s2-svc-block--charges" data-s2-section="pricing" data-s2-reveal="">
      <h3 className="s2-svc-block__title">💰 Charges & Payment</h3>
      <p className="s2-svc-prose">
        Get a personalised quote by submitting the form. No payment is required until you review and approve your
        itemised quote.
      </p>
      {svc.price_range && <div className="s2-svc-pill-note">📦 Starting from {svc.price_range}</div>}
    </div>
  )
}

function ServiceFaqFillIn() {
  const items = [
    { q: 'How long does this service take?', a: 'Turnaround depends on the service and documents provided. You receive a clear timeline in your quote within 24 hours of submission.' },
    { q: 'Do I need to visit India?', a: 'No. The process is designed for NRIs — documents are submitted digitally and results are delivered to your overseas address when required.' },
    { q: 'When do I pay?', a: 'Only after you approve the itemised quote in your dashboard. There are no hidden charges.' },
  ]
  return (
    <div className="s2-svc-block" data-s2-section="faq" data-s2-reveal="">
      <h3 className="s2-svc-block__title">Frequently Asked Questions</h3>
      {items.map((item) => (
        <FaqItem key={item.q} q={item.q} a={item.a} />
      ))}
    </div>
  )
}

function ServiceTestimonialsFillIn({ settings }: { settings: Record<string, string> }) {
  return (
    <div className="s2-svc-block" data-s2-section="testimonials" data-s2-reveal="">
      <h3 className="s2-svc-block__title">What NRIs Say About Us</h3>
      <div className="s2-svc-testimonial">
        <p className="s2-svc-prose">
          “Professional, transparent, and fast. The dashboard updates kept me informed at every step.”
        </p>
        <p className="s2-svc-testimonial__meta">
          Verified client · Google {settings.google_rating || '4.9'} ({settings.google_review_count || '500+'})
        </p>
      </div>
    </div>
  )
}

function ServiceCtaFillIn({ svc }: { svc: Service }) {
  return (
    <div className="s2-svc-cta-band s2-surface-dark s2-section-inner s2-width-wide" data-s2-section="cta" data-s2-reveal="">
      <h3 className="s2-t-h3">Ready to start {svc.name}?</h3>
      <p className="s2-t-body">Submit the form — our team responds with an itemised quote within 24 hours.</p>
      <a href="#booking-form" className="s2-btn s2-btn--secondary s2-svc-cta-band__btn">
        Start request →
      </a>
    </div>
  )
}

function ServiceDetailFillIns({
  sections,
  settings,
  svc,
  siteName,
}: {
  sections: ServiceSection[]
  settings: Record<string, string>
  svc: Service
  siteName: string
}) {
  const types = new Set(sections.map((s) => String(s.type)))
  const show = (key: string) => !isTemplateSectionHidden(settings, 'service', key)

  return (
    <>
      {show('process') && !types.has('process') && <ServiceProcessBlock />}
      {show('description') && !types.has('security') && !types.has('description') && (
        <ServiceSecurityBlock siteName={siteName} />
      )}
      {show('pricing') && !types.has('charges') && <ServiceChargesBlock svc={svc} />}
      {show('faq') && !types.has('faq') && <ServiceFaqFillIn />}
      {show('testimonials') && !types.has('testimonials') && <ServiceTestimonialsFillIn settings={settings} />}
      {show('cta') && !types.has('cta') && <ServiceCtaFillIn svc={svc} />}
    </>
  )
}

function ServiceTrustBadgesFallback({ settings }: { settings: Record<string, string> }) {
  return (
    <div className="s2-svc-trust-grid s2-mobile-stack">
      {[
        { icon: '⭐', val: settings.google_rating || '4.9', label: 'Google Rating' },
        { icon: '👥', val: settings.google_review_count || '10,000+', label: 'Happy Customers' },
        { icon: '🌏', val: '750+', label: 'Pan India Coverage' },
      ].map(({ icon, val, label }) => (
        <div key={label} className="s2-svc-trust-tile">
          <div className="s2-svc-trust-tile__icon">{icon}</div>
          <div className="s2-svc-trust-tile__value">{val}</div>
          <div className="s2-svc-trust-tile__label">{label}</div>
        </div>
      ))}
    </div>
  )
}

/** CMS sections + entity fallbacks + fill-ins — single path for seeded and default pages. */
function ServiceDetailLeftSections({
  sections,
  svc,
  settings,
  primary,
  siteName,
}: {
  sections: ServiceSection[]
  svc: Service
  settings: Record<string, string>
  primary: string
  siteName: string
}) {
  const cmsTypes = new Set(sections.map((s) => String(s.type)))
  const bandHidden = (key: string) => isTemplateSectionHidden(settings, 'service', key)

  return (
    <>
      {sections.map((sec) => {
        const dv = (sec.content as Record<string, unknown>)?.device_visibility as Record<string, boolean> | undefined
        const dvClass = dv
          ? [
              dv.desktop === false ? 's2-hide-desktop' : '',
              dv.tablet === false ? 's2-hide-tablet' : '',
              dv.mobile === false ? 's2-hide-mobile' : '',
            ]
              .filter(Boolean)
              .join(' ')
          : ''
        return (
          <div key={sec.id} className={`s2-svc-section-wrap${dvClass ? ` ${dvClass}` : ''}`}>
            <SectionRenderer sec={sec} primary={primary} settings={settings} />
          </div>
        )
      })}

      {!cmsTypes.has('trust_badges') && !bandHidden('trust_badges') && (
        <ServiceTrustBadgesFallback settings={settings} />
      )}

      {!cmsTypes.has('description') &&
        !bandHidden('description') &&
        (svc.description || svc.short_desc) && (
          <div className="s2-svc-block">
            <h2 className="s2-svc-block__title s2-svc-block__title--lg">{svc.name} — Complete Guide for NRIs</h2>
            <p className="s2-svc-prose">{svc.description || svc.short_desc}</p>
          </div>
        )}

      {!cmsTypes.has('documents') &&
        !bandHidden('documents') &&
        Array.isArray(svc.required_docs) &&
        svc.required_docs.length > 0 && (
          <div className="s2-svc-block">
            <h3 className="s2-svc-block__title">📎 Documents Required</h3>
            <div className="s2-mobile-stack s2-public-form-grid s2-public-form-grid--2col">
              {svc.required_docs.map((doc, i) => (
                <div key={i} className="s2-svc-doc-row">
                  <mark>✓</mark>
                  {doc}
                </div>
              ))}
            </div>
          </div>
        )}

      <ServiceDetailFillIns sections={sections} settings={settings} svc={svc} siteName={siteName} />
    </>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────
export function ServiceDetailPage() {
  const { slug }   = useParams<{ slug: string }>()
  const navigate   = useNavigate()
  const user       = useStore(s => s.user)
  const settings   = useStore(s => s.settings)
  const primary    = resolvePrimary(settings)
  const siteName   = settings.platform_name || 'Services2NRI'
  const waNum      = settings.platform_whatsapp

  const [svc, setSvc] = useState<Service | null>(() => {
    if (!slug) return null
    const cached = peekCached<{ service: Service }>('GET', `services/${slug}`)
    return cached?.service ?? null
  })
  const [sections, setSections] = useState<ServiceSection[]>(() => {
    if (!slug) return []
    const cached = peekCached<{ sections: ServiceSection[] }>('GET', `services/${slug}/sections`)
    return (cached?.sections || [])
      .filter((sec) => sec.is_visible !== 0 && String(sec.is_visible) !== '0' && String(sec.is_visible) !== 'false')
      .filter((sec) => sec.type !== 'hero' && sec.type !== 'marquee')
      .map((sec) => hydrateEmptySection(sec))
  })
  const [loading, setLoading] = useState(() => !peekCached('GET', `services/${slug || ''}`))

  // Wizard state
  const [stepIdx,   setStepIdx]   = useState(0)
  const [values,    setValues]    = useState<Record<string, unknown>>({})
  const [errors,    setErrors]    = useState<Record<string, string>>({})
  const [submitting,setSubmitting]= useState(false)
  const [submitted, setSubmitted] = useState<{ booking_ref: string; id: number; __uploadWarning?: string } | null>(null)
  const [apiError,  setApiError]  = useState('')
  const [loadFailed, setLoadFailed] = useState(false)

  useEffect(() => installServiceSectionNavStrip(), [slug])

  const loadService = useCallback(() => {
    if (!slug) return
    const hadCache = !!peekCached('GET', `services/${slug}`)
    if (!hadCache) setLoading(true)
    setLoadFailed(false)
    api.getCached<{ service: Service; redirect?: string; unavailable?: boolean; message?: string }>(`services/${slug}`)
      .then(data => {
        if (data.redirect) {
          const dest = resolveInternalDestination(data.redirect)
          if (dest) navigate(dest, { replace: true })
          else window.location.assign(data.redirect)
          return
        }
        if (data.unavailable) {
          setSvc(null)
          setApiError(data.message || 'This service is currently unavailable.')
          return
        }
        const s = data.service
        if (s?.form_schema && typeof s.form_schema === 'string') {
          try { s.form_schema = JSON.parse(s.form_schema) } catch { s.form_schema = [] }
        }
        // Issue 5: parse hero_settings and marquee_settings JSON columns
        if (s?.hero_settings && typeof s.hero_settings === 'string') {
          try { s.hero_settings = JSON.parse(s.hero_settings) } catch { s.hero_settings = null }
        }
        if (s?.marquee_settings && typeof s.marquee_settings === 'string') {
          try { s.marquee_settings = JSON.parse(s.marquee_settings) } catch { s.marquee_settings = null }
        }
        setSvc(s || null)
        return api.getCached<{ sections: ServiceSection[] }>(`services/${slug}/sections`)
          .then(r => {
            // Issue 6: filter out is_visible=0 sections so toggle actually works
            const visible = (r.sections || [])
              .filter((sec) =>
                sec.is_visible !== 0 && String(sec.is_visible) !== '0' && String(sec.is_visible) !== 'false',
              )
              .filter((sec) => sec.type !== 'hero' && sec.type !== 'marquee')
              .map((sec) => hydrateEmptySection(sec))
            setSections(visible)
          })
          .catch(() => {})
      })
      // FIXED (was previously: .catch(() => setSvc(null)), rendering
      // identically to a genuine "Service not found" regardless of WHY
      // the fetch failed. This is the actual booking page — the highest
      // conversion-impact page on the site. A transient server error
      // during a launch-day traffic spike previously told the customer
      // their specific service "doesn't exist" instead of "try again",
      // risking a lost booking for something the business does offer.
      // api.ts's request() throws {message, fields, status} on failure
      // (traced and confirmed earlier this session) — status===404 means
      // the backend genuinely has no such service; anything else
      // (network error status 0, 500, etc.) is a load failure, not a
      // real "not found".
      .catch((e: unknown) => {
        const status = (e as { status?: number })?.status
        if (status === 404) {
          setSvc(null)
        } else {
          setLoadFailed(true)
        }
      })
      .finally(() => setLoading(false))
  }, [slug])

  useEffect(() => { loadService() }, [loadService])

  useEffect(() => {
    if (!loading) refreshExperienceReveal()
  }, [loading, sections, slug])


  // ── Compute step structure ──────────────────────────────────────────────────
  const schema = (svc?.form_schema || []) as FormField[]
  const stepMap = buildSteps(schema)

  // Natural step numbers in sorted order (1 < 2 < 3 < 4 < 98 < 99)
  // Plus a final confirm step (represented as 9999)
  const CONFIRM_STEP = 9999
  const orderedStepNums: number[] = [...Array.from(stepMap.keys()).sort((a, b) => a - b), CONFIRM_STEP]
  const totalSteps = orderedStepNums.length

  const currentStepNum = orderedStepNums[stepIdx]
  const isConfirm      = currentStepNum === CONFIRM_STEP
  const currentFields  = isConfirm ? [] : (stepMap.get(currentStepNum) || [])

  // Step label for display
  const stepLabel = isConfirm ? 'Confirm & Submit' : (STEP_LABEL[currentStepNum] || `Step ${currentStepNum}`)

  // ── Field helpers ───────────────────────────────────────────────────────────
  const setField = useCallback((key: string, val: unknown) => {
    setValues(v => ({ ...v, [key]: val }))
    setErrors(e => { const n = { ...e }; delete n[key]; return n })
  }, [])

  function validate(): boolean {
    const errs: Record<string, string> = {}
    currentFields.forEach(f => {
      if (!f.required) return
      const v = values[f.key]
      if (!v || (typeof v === 'string' && !v.trim()) || (Array.isArray(v) && v.length === 0)) {
        errs[f.key] = `${f.label} is required`
      }
    })
    setErrors(errs)
    return Object.keys(errs).length === 0
  }

  // Mobile: preserve the user's scroll position when advancing/going back
  // a step, instead of always jumping to top. On a small screen, a step
  // change re-renders the SAME form section in place — scrolling to top
  // means losing your place and having to scroll back down to keep
  // filling it out, which is exactly the reported friction. Desktop
  // behaviour (scroll to top) is left unchanged — that wasn't reported as
  // a problem, and a wider viewport already shows more of the form at
  // once, so the same disorientation doesn't apply there.
  // 768px matches this codebase's existing mobile breakpoint convention
  // (see .s2-mobile-stack and other rules in global.css).
  function scrollToTopUnlessMobile() {
    if (window.innerWidth <= 768) return
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function next() {
    if (!isConfirm && !validate()) return
    setStepIdx(i => Math.min(totalSteps - 1, i + 1))
    scrollToTopUnlessMobile()
  }

  function prev() {
    if (stepIdx === 0) navigate(`/service/${svc?.slug}`)
    else { setStepIdx(i => Math.max(0, i - 1)); scrollToTopUnlessMobile() }
  }

  async function submit() {
    if (!user) { navigate(`/login?redirect=/book/${slug}`); return }
    setSubmitting(true); setApiError('')

    const fieldData: Record<string, unknown> = {}
    const fileData:  Record<string, File[]>  = {}

    Object.entries(values).forEach(([k, v]) => {
      if (Array.isArray(v) && v[0] instanceof File) fileData[k] = v as File[]
      else if (v instanceof File) fileData[k] = [v]
      else if (v) fieldData[k] = v
    })

    try {
      const res = await api.post<{ booking: { id: number; booking_ref: string } }>(
        'bookings',
        { service_id: svc!.id, field_data: fieldData, priority: 'normal' }
      )
      const bookingId = res.booking?.id
      let uploadFails = 0
      for (const [docType, files] of Object.entries(fileData)) {
        for (const file of files) {
          const form = new FormData()
          form.append('file', file)
          form.append('doc_type', docType === '__docs' || docType === 'documents' ? 'Customer Document' : docType)
          try { await api.upload(`bookings/${bookingId}/documents`, form) }
          catch { uploadFails++ }
        }
      }
      setSubmitted({ ...res.booking, __uploadWarning: uploadFails > 0 ? `${uploadFails} document(s) could not be uploaded. You can upload them from your booking detail page.` : undefined })
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (e: unknown) {
      setApiError((e as { message: string }).message || 'Submission failed. Please try again.')
      setSubmitting(false)
    }
  }

  if (loadFailed) return (
    <Layout>
      <div className="s2-svc-state">
        <div className="s2-svc-state__emoji">⚠️</div>
        <h2 className="s2-t-h2">Couldn't load this page</h2>
        <p className="s2-t-body s2-t-body--spaced">Something went wrong on our end. Please try again.</p>
        <button type="button" onClick={loadService} className="s2-btn s2-btn--primary">Retry</button>
      </div>
    </Layout>
  )

  if (!svc) {
    if (loading) {
      return (
        <Layout>
          <div className="s2-svc-page-main">
            <ServiceDetailShellSkeleton />
          </div>
        </Layout>
      )
    }
    return (
      <Layout>
        <div className="s2-svc-state">
          <div className="s2-svc-state__emoji">🔍</div>
          <h2 className="s2-t-h2">Service not found</h2>
          <Link to="/services" className="s2-btn s2-btn--ghost">← Browse All Services</Link>
        </div>
      </Layout>
    )
  }

  // ── Success screen ────────────────────────────────────────────────────────────
  if (submitted) return (
    <Layout>
      <div className="s2-svc-success">
        <div className="s2-svc-success__icon">✅</div>
        <h1 className="s2-svc-success__title">Request Submitted!</h1>
        <p className="s2-svc-success__lead">Your <strong>{svc.name}</strong> request has been received.</p>
        {submitted.__uploadWarning && (
          <div className="s2-svc-alert-warn">⚠️ {submitted.__uploadWarning}</div>
        )}
        <p className="s2-svc-success__meta">
          Booking reference: <strong className="s2-svc-success__ref">{submitted.booking_ref}</strong><br />
          Our team will review and send you an itemised quote within <strong>24 hours</strong>. A confirmation email has been sent.
        </p>
        <div className="s2-svc-success__card">
          <div className="s2-svc-success__grid s2-mobile-stack">
            {[['Service', svc.name, ''], ['Booking Ref', submitted.booking_ref, 'ref'], ['Status', 'Under Review', 'status'], ['Quote in', '≤ 24 hours', '']].map(([label, val, mod]) => (
              <div key={label}>
                <div className="s2-svc-success__stat-label">{label}</div>
                <div className={`s2-svc-success__stat-val${mod === 'ref' ? ' s2-svc-success__stat-val--ref' : mod === 'status' ? ' s2-svc-success__stat-val--status' : ''}`}>{val}</div>
              </div>
            ))}
          </div>
        </div>
        <div className="s2-svc-success__actions">
          {user
            ? <Link to={`/dashboard/bookings/${submitted.id}`} className="s2-btn s2-btn--primary s2-btn--lg">Track My Booking →</Link>
            : <Link to="/login" className="s2-btn s2-btn--primary s2-btn--lg">Sign In to Track →</Link>
          }
          <Link to="/services" className="s2-btn s2-btn--outline">Browse More Services</Link>
        </div>
      </div>
    </Layout>
  )

  const img = svc.image_url || getServiceImage(slug || '')
  const svcMeta = svc as unknown as { hero_settings?: Record<string, unknown>; marquee_settings?: Record<string, unknown> }
  const hero = svcMeta.hero_settings
  const marquee = svcMeta.marquee_settings

  // Issue 5: hero image/title/overlay from builder settings or fallback to defaults
  const heroImg     = hero && hero.enabled !== false && hero.image_url ? String(hero.image_url) : img
  const heroTitle   = hero && hero.enabled !== false && hero.title     ? String(hero.title)     : svc.name
  const heroHeight  = hero && hero.enabled !== false && hero.height    ? Number(hero.height)    : 280
  const heroOverlay = hero && hero.enabled !== false
    ? `rgba(0,0,0,${(Number(hero.overlay_opacity ?? 40)) / 100})`
    : 'rgba(0,0,0,.6)'
  const heroAlign   = hero && hero.enabled !== false && hero.text_align ? String(hero.text_align) : 'left'
  const hasWhyChooseSection = sections.some((sec) => sec.type === 'why_choose')

  return (
    <Layout>
      <div className="s2-svc-breadcrumb">
        <div className="s2-svc-breadcrumb__inner">
          <Link to="/">Home</Link>
          <span>›</span>
          <Link to="/services">Services</Link>
          {svc.category_name && <><span>›</span><Link to={`/services/${svc.category_slug}`}>{svc.category_name}</Link></>}
          <span>›</span>
          <span className="s2-svc-breadcrumb__current">{svc.name}</span>
        </div>
      </div>

      {/* Issue 8: Marquee — positioned immediately after hero/breadcrumb, before sections */}
      {!isTemplateSectionHidden(settings, 'service', 'marquee') && marquee && marquee.enabled && marquee.text ? (
        <div
          className="s2-svc-marquee"
          data-s2-section="marquee"
          style={cssVars({
            's2-marquee-bg': String(marquee.bg_color || 'var(--s2-color-primary)'),
            's2-marquee-fg': String(marquee.text_color || '#fff'),
            's2-marquee-duration': `${Number(marquee.speed) || 30}s`,
          })}
        >
          <div className="s2-svc-marquee__track">
            <span>{String(marquee.text)}</span>
            <span>{String(marquee.text)}</span>
          </div>
        </div>
      ) : null}

      <div className="s2-svc-page-main">
      <div className="s2-svc-layout svc-grid s2-svc-layout--with-mobile-cta">

        {/* Left: service info */}
        <div>
          {/* Hero */}
          {!isTemplateSectionHidden(settings, 'service', 'hero') && (
          <div
            data-s2-section="hero"
            className={`s2-svc-hero${heroAlign === 'center' ? ' s2-svc-hero--align-center' : ''}`}
            style={{
              height: heroHeight,
              ...cssVars({
                's2-hero-overlay': heroOverlay,
                ...(hero?.container_max || hero?.content_max
                  ? { 's2-width-sec-hero-max': String(hero.container_max || hero.content_max) }
                  : {}),
              }),
            }}
          >
            <img src={heroImg} alt={heroTitle} />
            <div className="s2-svc-hero__shade" />
            <div className="s2-svc-hero__content">
              {svc.category_name && <div className="s2-svc-hero__badge">{svc.category_name}</div>}
              <h1 className="s2-svc-hero__title">{heroTitle}</h1>
              {hero && hero.subtitle ? <p className="s2-svc-hero__sub">{String(hero.subtitle)}</p> : null}
              {/* Issue 5: CTA buttons from hero_settings */}
              {hero && (hero.cta_text || hero.cta2_text) ? (
                <div className="s2-svc-hero__ctas">
                  {hero.cta_text ? <a href={String(hero.cta_url || '#booking-form')} className="s2-svc-hero__cta">{String(hero.cta_text)}</a> : null}
                  {hero.cta2_text ? <a href={String(hero.cta2_url || '#booking-form')} className="s2-svc-hero__cta s2-svc-hero__cta--ghost">{String(hero.cta2_text)}</a> : null}
                </div>
              ) : null}
              <div className="s2-svc-hero__meta">
                {(svc.turnaround_days || svc.turnaround) && (
                  <span className="s2-svc-hero__meta-chip">⏱ {svc.turnaround_days || svc.turnaround} day turnaround</span>
                )}
                {svc.price_range && <span className="s2-svc-hero__meta-chip">💰 From {svc.price_range}</span>}
                <span className="s2-svc-hero__meta-chip">🔒 Secure & encrypted</span>
              </div>
            </div>
          </div>
          )}

          <ServiceDetailLeftSections
            sections={sections}
            svc={svc}
            settings={settings}
            primary={primary}
            siteName={siteName}
          />
        </div>

        {/* Right: sticky booking wizard */}
        {!isTemplateSectionHidden(settings, 'service', 'wizard') && (
        <div id="booking-form" className="s2-svc-wizard-sticky" data-s2-section="wizard">

          <div className="s2-svc-wizard-head">
            {/* Service name + meta */}
            <div className="s2-svc-wizard-service">
              <div className="s2-svc-wizard-service__icon">
                {svc.icon || '📋'}
              </div>
              <div>
                <div className="s2-svc-wizard-service__name">{svc.name}</div>
                <div className="s2-svc-wizard-service__tags">
                  {[svc.turnaround && `⏱ ${svc.turnaround}`, '🔒 SSL', '📧 Quote 24h'].filter(Boolean).map(t => (
                    <span key={t as string} className="s2-svc-wizard-tag">{t}</span>
                  ))}
                </div>
              </div>
            </div>

            {/* Step tracker circles.
                overflow-x: auto contains this row's own scroll instead of
                letting it push the page wider — the circle+label blocks
                below are flexShrink:0 (deliberately, so a step's label
                never gets squished unreadable), which means a service with
                several steps can have a natural row width wider than a
                mobile viewport. Without this, that width bled out past the
                edge of the screen instead of scrolling within its own box. */}
            <div className="s2-svc-step-track">
              {orderedStepNums.map((stepNum, idx) => {
                const done = idx < stepIdx
                const curr = idx === stepIdx
                const label = stepNum === CONFIRM_STEP ? 'Confirm' : (STEP_LABEL[stepNum] || `Step ${stepNum}`)
                return (
                  <div key={stepNum} className={`s2-svc-step-track__row${idx < totalSteps - 1 ? ' s2-svc-step-track__row--grow' : ''}`}>
                    <div className="s2-svc-step-track__step">
                      <div className={`s2-svc-step-track__circle${done ? ' s2-svc-step-track__circle--done' : curr ? ' s2-svc-step-track__circle--current' : ''}`}>
                        {done ? '✓' : idx + 1}
                      </div>
                      <span className={`s2-svc-step-track__label${curr ? ' s2-svc-step-track__label--current' : ''}`}>
                        {label}
                      </span>
                    </div>
                    {idx < totalSteps - 1 && (
                      <div className={`s2-svc-step-track__connector${done ? ' s2-svc-step-track__connector--done' : ''}`} />
                    )}
                  </div>
                )
              })}
            </div>
          </div>

          <div className="s2-svc-wizard-card">
            <div className="s2-svc-wizard-step-hdr">
              <div className="s2-svc-wizard-step-label">
                Step {stepIdx + 1} of {totalSteps}
              </div>
              <h2 className="s2-svc-wizard-step-title">{stepLabel}</h2>
            </div>

            <div className="s2-svc-wizard-body">
              {apiError && (
                <div className="s2-svc-alert-error">⚠ {apiError}</div>
              )}

              {/* Show service overview on step 1 if no fields */}
              {!isConfirm && currentFields.length === 0 && stepIdx === 0 && (
                <div className="s2-wizard-intro">
                  <p>You're booking: <strong>{svc.name}</strong></p>
                  {svc.short_desc && <p className="s2-wizard-intro__muted">{svc.short_desc}</p>}
                  {Array.isArray(svc.required_docs) && svc.required_docs.length > 0 && (
                    <div className="s2-wizard-docs-box">
                      <strong className="s2-wizard-docs-box__title">📎 Documents you'll need:</strong>
                      {svc.required_docs.map((doc, i) => (
                        <div key={i} className="s2-wizard-doc-line"><mark>✓</mark>{doc}</div>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {/* Render current step fields */}
              {!isConfirm && currentFields.map(field => (
                <FieldRenderer
                  key={field.key}
                  field={field}
                  value={values[field.key] ?? (field.type === 'checkbox' ? [] : '')}
                  onChange={v => setField(field.key, v)}
                  error={errors[field.key]}
                  primary={primary}
                />
              ))}

              {/* Confirm step */}
              {isConfirm && (
                <div>
                  <div className="s2-wizard-confirm-panel">
                    <h3 className="s2-wizard-confirm-panel__title">📋 Your Request Summary</h3>
                    <div className="s2-wizard-summary-row">
                      <span className="s2-wizard-summary-row__label">Service</span>
                      <span className="s2-wizard-summary-row__val">{svc.name}</span>
                    </div>
                    {svc.turnaround && (
                      <div className="s2-wizard-summary-row">
                        <span className="s2-wizard-summary-row__label">Turnaround</span>
                        <span className="s2-wizard-summary-row__val">{svc.turnaround}</span>
                      </div>
                    )}
                    {Object.entries(values)
                      .filter(([, v]) => v && typeof v === 'string' && (v as string).trim())
                      .slice(0, 12)
                      .map(([key, val]) => {
                        const f = schema.find(f => f.key === key)
                        const label = f?.label || key.replace(/_/g, ' ')
                        return (
                          <div key={key} className="s2-wizard-summary-row">
                            <span className="s2-wizard-summary-row__label">{label}</span>
                            <span className="s2-wizard-summary-row__val s2-wizard-summary-row__val--medium">{String(val).slice(0, 60)}</span>
                          </div>
                        )
                      })}
                    {Object.values(values).some(v => Array.isArray(v) && (v as unknown[])[0] instanceof File) && (
                      <div className="s2-wizard-summary-row">
                        <span className="s2-wizard-summary-row__label">Documents</span>
                        <span className="s2-wizard-summary-row__val s2-wizard-summary-row__val--medium">
                          {Object.values(values).filter(v => Array.isArray(v) && (v as unknown[])[0] instanceof File).flatMap(v => v as File[]).length} file(s) attached
                        </span>
                      </div>
                    )}
                  </div>

                  {!user && (
                    <div className="s2-wizard-login-gate">
                      <p>⚠ You need to sign in to submit this request</p>
                      <div className="s2-wizard-login-gate__actions">
                        <Link to={`/login?redirect=/book/${slug}`} className="s2-btn s2-btn--primary s2-btn--sm">Sign In</Link>
                        <Link to={`/register?redirect=/book/${slug}`} className="s2-btn s2-btn--outline s2-btn--sm">Create Account</Link>
                      </div>
                    </div>
                  )}

                  <div className="s2-wizard-tnc">
                    ✅ By submitting, you confirm the above details are accurate. We will send you an itemised quote within 24 hours. No payment is required until you approve the quote.
                  </div>
                </div>
              )}
            </div>

            <div className="s2-svc-wizard-foot">
              <button type="button" onClick={prev} className="s2-btn s2-btn--ghost">
                ← {stepIdx === 0 ? 'Back' : 'Previous'}
              </button>

              {/* Dot progress */}
              <div className="s2-svc-wizard-dots">
                {orderedStepNums.map((_, i) => (
                  <div
                    key={i}
                    className={`s2-svc-wizard-dot${stepIdx === i ? ' s2-svc-wizard-dot--active' : stepIdx > i ? ' s2-svc-wizard-dot--done' : ''}`}
                  />
                ))}
              </div>

              {!isConfirm ? (
                <button type="button" onClick={next} className="s2-btn s2-btn--primary">
                  Next Step →
                </button>
              ) : (
                <button type="button" onClick={submit} disabled={submitting || !user} className="s2-btn s2-btn--primary">
                  {submitting ? 'Submitting…' : '⚡ Submit Request'}
                </button>
              )}
            </div>

            <div className="s2-svc-wizard-trust">
              {['🔒 256-bit SSL', '📧 Quote in 24h', '💰 Pay after approval', '✅ 10,000+ NRIs'].map(s => (
                <span key={s}>{s}</span>
              ))}
            </div>
          </div>

          {waNum && (
            <div className="s2-wizard-wa-wrap">
              <a href={`https://wa.me/${String(waNum).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="s2-btn s2-btn--whatsapp">
                💬 Prefer WhatsApp? Chat with Us
              </a>
            </div>
          )}
        </div>
        )}
      </div>
      </div>

      {!isTemplateSectionHidden(settings, 'service', 'wizard') && (
      <ServiceMobileStickyCta
        label="Start Request"
        secondaryLabel={waNum ? 'WhatsApp' : undefined}
        secondaryHref={waNum ? `https://wa.me/${String(waNum).replace(/\D/g, '')}` : undefined}
      />
      )}

      {!hasWhyChooseSection && !isTemplateSectionHidden(settings, 'service', 'features') && (
      <section className="s2-svc-why s2-experience-section" data-s2-reveal="">
        <div className="s2-svc-why__inner">
          <h2 className="s2-svc-why__title">Why Choose {siteName}?</h2>
          <PublicGrid min={250}>
            {[
              { icon: '🔐', t: 'Secure Platform', d: 'AES-256 encrypted document storage. Never shared or emailed.' },
              { icon: '📊', t: 'Real-Time Tracking', d: 'Track every step in your dashboard. No black boxes.' },
              { icon: '💰', t: 'Money-Back Guarantee', d: 'If we cannot deliver, you receive a full refund.' },
              { icon: '🌍', t: 'Global NRI Coverage', d: 'Serving NRIs in 50+ countries with India-based execution.' },
              { icon: '⚡', t: 'Fast Turnaround', d: 'Most services delivered in 7–30 days from submission.' },
              { icon: '🏆', t: 'Startup India Recognised', d: 'Government recognised. Professionally managed.' },
            ].map(({ icon, t, d }) => (
              <PublicCard key={t}>
                <div className="s2-public-contact-row s2-public-contact-row--flush">
                  <div className="s2-public-contact-icon">{icon}</div>
                  <div>
                    <h4 className="s2-public-card__title s2-public-card__title--sm">{t}</h4>
                    <p className="s2-public-card__body s2-public-card__body--sm">{d}</p>
                  </div>
                </div>
              </PublicCard>
            ))}
          </PublicGrid>
        </div>
      </section>
      )}
    </Layout>
  )
}
