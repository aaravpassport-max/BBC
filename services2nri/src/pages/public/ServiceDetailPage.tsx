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
import { resolvePrimary } from '@/lib/design-tokens'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { Layout } from '@/components/layout/Layout'
import { useStore } from '@/lib/store'
import { api } from '@/lib/api'
import { getServiceImage } from '@/lib/images'
import type { Service, ServiceSection, SectionType } from '@/types'

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

// ── Input base style ──────────────────────────────────────────────────────────
const baseInput = (error?: string): React.CSSProperties => ({
  width: '100%', padding: '11px 14px',
  border: `1.5px solid ${error ? '#f87171' : '#d1d5db'}`,
  borderRadius: 9, fontSize: 15, fontFamily: 'inherit',
  outline: 'none', boxSizing: 'border-box',
  background: error ? '#fef2f2' : '#fff', transition: 'border-color .15s',
})

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
    <div ref={ref} style={{ position: 'relative' }}>
      <input value={query} onChange={e => { setQuery(e.target.value); onChange(e.target.value); setOpen(true) }}
        onFocus={() => setOpen(true)} placeholder={field.placeholder || 'Type to search…'} style={baseInput(error)} />
      {open && filtered.length > 0 && (
        <div style={{ position: 'absolute', top: 'calc(100% + 4px)', left: 0, right: 0, background: '#fff', border: '1px solid #e0e0e0', borderRadius: 9, boxShadow: '0 8px 24px rgba(0,0,0,.12)', zIndex: 200, maxHeight: 220, overflowY: 'auto' }}>
          {filtered.map(opt => (
            <div key={opt} onMouseDown={() => { setQuery(opt); onChange(opt); setOpen(false) }}
              style={{ padding: '10px 14px', cursor: 'pointer', fontSize: 14, color: '#374151' }}
              onMouseEnter={e => (e.currentTarget.style.background = '#EBF0F8')}
              onMouseLeave={e => (e.currentTarget.style.background = '')}>{opt}</div>
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
      <div onClick={() => inputRef.current?.click()}
        onDragOver={e => { e.preventDefault(); e.currentTarget.style.borderColor = '#4A6FA5' }}
        onDragLeave={e => { e.currentTarget.style.borderColor = '#d1d5db' }}
        onDrop={e => { e.preventDefault(); e.currentTarget.style.borderColor = '#d1d5db'; add(e.dataTransfer.files) }}
        style={{ border: `2px dashed ${error ? '#f87171' : '#d1d5db'}`, borderRadius: 10, padding: '28px 20px', textAlign: 'center', cursor: 'pointer', background: '#fafafa', transition: 'border-color .2s' }}>
        <div style={{ fontSize: 36, marginBottom: 10 }}>📎</div>
        <div style={{ fontWeight: 600, fontSize: 14, color: '#374151', marginBottom: 4 }}>
          Drop files here or <span style={{ color: '#4A6FA5', textDecoration: 'underline' }}>browse</span>
        </div>
        <div style={{ fontSize: 12, color: '#9ca3af' }}>PDF, JPG, PNG · Max 10 MB each</div>
        <input ref={inputRef} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style={{ display: 'none' }} onChange={e => add(e.target.files)} />
      </div>
      {value.length > 0 && (
        <div style={{ marginTop: 10, display: 'flex', flexDirection: 'column', gap: 7 }}>
          {value.map((f, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, background: '#EBF0F8', borderRadius: 8, padding: '9px 14px' }}>
              <span style={{ fontSize: 18, flexShrink: 0 }}>{f.type.includes('pdf') ? '📄' : '🖼️'}</span>
              <div style={{ flex: 1, fontSize: 13, color: '#374151', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{f.name}</div>
              <span style={{ fontSize: 11, color: '#9ca3af', flexShrink: 0 }}>{(f.size / 1024).toFixed(0)} KB</span>
              <button type="button" onClick={() => remove(i)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444', fontSize: 18, lineHeight: 1, padding: 0 }}>×</button>
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
    <div style={{ display: 'flex', gap: 8 }}>
      <select value={code} onChange={e => { setCode(e.target.value); update(e.target.value, local) }}
        style={{ width: 130, padding: '11px 10px', border: `1.5px solid ${error ? '#f87171' : '#d1d5db'}`, borderRadius: 9, fontSize: 13, fontFamily: 'inherit', outline: 'none', background: '#fff', flexShrink: 0 }}>
        {DIAL_CODES.map(d => <option key={`${d.flag}${d.code}`} value={d.code}>{d.flag} {d.name} {d.code}</option>)}
      </select>
      <input type="tel" value={local} onChange={e => update(code, e.target.value)}
        placeholder={field.placeholder || 'Mobile number'} style={{ flex: 1, ...baseInput(error) }} />
    </div>
  )
}

// ── FieldRenderer — placeholder-first compact design ──────────────────────────
function FieldRenderer({ field, value, onChange, error, primary }: {
  field: FormField; value: unknown; onChange: (v: unknown) => void; error?: string; primary: string
}) {
  const lang = (() => { try { return localStorage.getItem('s2nri_lang') || 'en' } catch { return 'en' } })()
  const label = lang === 'hi' && field.label_hi ? field.label_hi : field.label
  const id   = `bk_${field.key}`
  const type = (field.type || 'text').toLowerCase()
  const isFile = type === 'file' || field.key.includes('document') || field.key.includes('upload')

  const wrap = (input: React.ReactNode) => (
    <div style={{ marginBottom: 18 }}>
      <label htmlFor={id} style={{ display: 'block', fontSize: 11, fontWeight: 700, color: '#6b7280', marginBottom: 5, textTransform: 'uppercase', letterSpacing: 0.8 }}>
        {label}{field.required && <span style={{ color: '#dc2626', marginLeft: 3 }}>*</span>}
      </label>
      {input}
      {field.description && !error && <p style={{ margin: '5px 0 0', fontSize: 12, color: '#6b7280', lineHeight: 1.5 }}>{field.description}</p>}
      {field.hint && !error && <p style={{ margin: '5px 0 0', fontSize: 12, color: '#6b7280' }}>{field.hint}</p>}
      {error && <p role="alert" style={{ margin: '5px 0 0', fontSize: 12, color: '#b91c1c', fontWeight: 600 }}>⚠ {error}</p>}
    </div>
  )

  if (isFile) return wrap(<FileUpload field={field} value={Array.isArray(value) ? value as File[] : []} onChange={v => onChange(v)} error={error} />)
  if (type === 'searchable') return wrap(<SearchableSelect field={field} value={String(value || '')} onChange={onChange} error={error} />)
  if (type === 'phone') return wrap(<PhoneField field={field} value={String(value || '')} onChange={onChange} error={error} />)
  if (type === 'textarea') return wrap(
    <textarea id={id} rows={3} value={String(value || '')} onChange={e => onChange(e.target.value)}
      placeholder={field.placeholder || label} style={{ ...baseInput(error), resize: 'vertical', lineHeight: 1.6 }} />
  )
  if (type === 'select' || type === 'dropdown') return wrap(
    <select id={id} value={String(value || '')} onChange={e => onChange(e.target.value)} style={baseInput(error)}>
      <option value="">— {field.placeholder || `Select ${label}`} —</option>
      {(field.options || []).map(opt => <option key={opt} value={opt}>{opt}</option>)}
    </select>
  )
  if (type === 'radio') return wrap(
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginTop: 4 }}>
      {(field.options || []).map(opt => (
        <label key={opt} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', padding: '9px 16px', border: `1.5px solid ${value === opt ? primary : '#e0e0e0'}`, borderRadius: 8, background: value === opt ? `${primary}10` : '#fff', fontSize: 14, fontWeight: value === opt ? 700 : 400, color: value === opt ? primary : '#374151', transition: 'all .15s', userSelect: 'none' }}>
          <input type="radio" name={id} value={opt} checked={value === opt} onChange={() => onChange(opt)} style={{ accentColor: primary, width: 16, height: 16 }} />{opt}
        </label>
      ))}
    </div>
  )
  if (type === 'checkbox') return wrap(
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 4 }}>
      {(field.options || []).map(opt => {
        const checked = Array.isArray(value) ? (value as string[]).includes(opt) : false
        const toggle  = () => {
          const arr = Array.isArray(value) ? [...value as string[]] : []
          onChange(checked ? arr.filter(v => v !== opt) : [...arr, opt])
        }
        return (
          <label key={opt} style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', padding: '9px 14px', border: `1.5px solid ${checked ? primary : '#e0e0e0'}`, borderRadius: 8, background: checked ? `${primary}08` : '#fff', fontSize: 14, transition: 'all .15s' }}>
            <input type="checkbox" checked={checked} onChange={toggle} style={{ accentColor: primary, width: 16, height: 16, flexShrink: 0 }} />{opt}
          </label>
        )
      })}
    </div>
  )
  if (type === 'date') return wrap(<input id={id} type="date" value={String(value || '')} onChange={e => onChange(e.target.value)} style={baseInput(error)} />)
  if (type === 'number') return wrap(<input id={id} type="number" value={String(value || '')} onChange={e => onChange(e.target.value)} placeholder={field.placeholder || label} style={baseInput(error)} />)
  if (type === 'email') return wrap(<input id={id} type="email" value={String(value || '')} onChange={e => onChange(e.target.value)} placeholder={field.placeholder || label} style={baseInput(error)} />)
  return wrap(<input id={id} type="text" value={String(value || '')} onChange={e => onChange(e.target.value)} placeholder={field.placeholder || label} style={baseInput(error)} />)
}

// ── Section renderer (Wn — service content sections) ─────────────────────────
function FaqItem({ q, a, primary }: { q: string; a: string; primary: string }) {
  const [open, setOpen] = useState(false)
  return (
    <div style={{ borderBottom: '1px solid #f0f0f0', paddingBottom: 10, marginBottom: 10 }}>
      <button onClick={() => setOpen(o => !o)} style={{ width: '100%', textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 0', gap: 10 }}>
        <span style={{ fontWeight: 700, fontSize: 14, color: '#1E2D40', lineHeight: 1.5 }}>{q}</span>
        <span style={{ color: primary, fontWeight: 700, fontSize: 16, flexShrink: 0 }}>{open ? '−' : '+'}</span>
      </button>
      {open && <p style={{ fontSize: 13, color: '#555', lineHeight: 1.7, margin: '8px 0 0' }}>{a}</p>}
    </div>
  )
}

function SectionRenderer({ sec, primary }: { sec: ServiceSection; primary: string }) {
  const r = (sec.content || {}) as Record<string, unknown>
  const wrap = (children: React.ReactNode, style: React.CSSProperties = {}) => (
    <div style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: '24px 28px', marginBottom: 22, ...style }}>
      {sec.title && <h3 style={{ fontSize: 16, fontWeight: 800, color: '#1E2D40', margin: '0 0 14px' }}>{sec.title}</h3>}
      {children}
    </div>
  )
  switch (sec.type as SectionType) {
    case 'trust_badges': {
      const badges = (r.badges as Array<{ icon: string; value: string; label: string }>) || []
      return <div className="s2-mobile-stack" style={{ display: 'grid', gridTemplateColumns: `repeat(${badges.length || 3}, 1fr)`, gap: 12, marginBottom: 22 }}>
        {badges.map((b, i) => <div key={i} style={{ background: primary, color: '#fff', borderRadius: 10, padding: '18px 16px', textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6 }}>
          <div style={{ fontSize: 28 }}>{b.icon}</div>
          <div style={{ fontWeight: 900, fontSize: 22, lineHeight: 1 }}>{b.value}</div>
          <div style={{ fontSize: 12, opacity: 0.85, fontWeight: 500 }}>{b.label}</div>
        </div>)}
      </div>
    }
    case 'why_choose': {
      const cards = (r.cards as Array<{ icon: string; title: string; desc: string }>) || []
      return <section style={{ background: '#F5F7FA', borderRadius: 14, padding: '32px 28px', marginBottom: 22, border: '1px solid #EBF0F8' }}>
        {sec.title && <h3 style={{ fontSize: 18, fontWeight: 800, color: '#1E2D40', margin: '0 0 20px', textAlign: 'center' }}>{sec.title}</h3>}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 14 }}>
          {cards.map((c, i) => <div key={i} style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: 18, display: 'flex', gap: 12 }}>
            <div style={{ width: 42, height: 42, background: `${primary}15`, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20, flexShrink: 0 }}>{c.icon}</div>
            <div><h4 style={{ fontSize: 14, fontWeight: 700, color: '#1E2D40', margin: '0 0 4px' }}>{c.title}</h4><p style={{ fontSize: 12, color: '#666', lineHeight: 1.5, margin: 0 }}>{c.desc}</p></div>
          </div>)}
        </div>
      </section>
    }
    case 'description': case 'security': case 'charges': case 'text':
      return <div style={{ background: sec.type === 'security' ? `${primary}08` : '#fff', border: `1px solid ${sec.type === 'security' ? primary + '20' : '#EBF0F8'}`, borderRadius: 12, padding: '24px 28px', marginBottom: 22 }}>
        {!!(r.heading as string || sec.title) && <h2 style={{ fontSize: sec.type === 'description' ? 20 : 16, fontWeight: 800, color: '#1E2D40', margin: '0 0 14px' }}>{r.heading as string || sec.title}</h2>}
        {!!r.html && <div style={{ fontSize: 14, color: '#374151', lineHeight: 1.8 }} dangerouslySetInnerHTML={{ __html: String(r.html) }} />}
        {sec.type === 'charges' && !!r.note && <div style={{ marginTop: 12, display: 'inline-flex', alignItems: 'center', gap: 6, background: `${primary}15`, color: primary, padding: '6px 14px', borderRadius: 99, fontSize: 14, fontWeight: 700 }}>{'📦 ' + String(r.note)}</div>}
      </div>
    case 'process':
      return wrap(<div>{((r.steps as Array<{ title: string; desc: string }>) || []).map((s, i, arr) => (
        <div key={i} style={{ display: 'flex', gap: 14, marginBottom: i < arr.length - 1 ? 14 : 0, alignItems: 'flex-start' }}>
          <div style={{ width: 28, height: 28, background: primary, color: '#fff', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 13, flexShrink: 0, marginTop: 1 }}>{i + 1}</div>
          <div><div style={{ fontWeight: 700, fontSize: 14, color: '#1E2D40', marginBottom: 2 }}>{s.title}</div><div style={{ fontSize: 13, color: '#666', lineHeight: 1.6 }}>{s.desc}</div></div>
        </div>
      ))}</div>)
    case 'faq':
      return wrap(<div>{((r.items as Array<{ q: string; a: string }>) || []).map((item, i) => <FaqItem key={i} q={item.q} a={item.a} primary={primary} />)}</div>)
    case 'benefits':
      return wrap(<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 10 }}>
        {((r.items as Array<{ icon?: string; text: string }>) || []).map((item, i) => (
          <div key={i} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '10px 12px', background: `${primary}06`, borderRadius: 9 }}>
            <span style={{ fontSize: 20 }}>{item.icon || '✅'}</span><span style={{ fontSize: 13, color: '#374151', lineHeight: 1.5 }}>{item.text}</span>
          </div>
        ))}
      </div>)
    case 'documents': case 'eligibility':
      return wrap(<div className="s2-mobile-stack" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
        {((r.items as string[]) || []).map((item, i) => (
          <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 14, color: '#374151', padding: '6px 0', borderBottom: '1px solid #f5f5f5' }}>
            <span style={{ color: '#2e7d32', fontWeight: 700, flexShrink: 0 }}>✓</span>{item}
          </div>
        ))}
      </div>)
    case 'notes':
      return wrap(<ul style={{ margin: 0, paddingLeft: 20, color: '#374151', fontSize: 14, lineHeight: 2 }}>{((r.items as string[]) || []).map((item, i) => <li key={i}>{item}</li>)}</ul>)
    case 'cta':
      return <div style={{ background: `linear-gradient(135deg, #1E2D40, ${primary})`, borderRadius: 14, padding: '32px 28px', marginBottom: 22, textAlign: 'center', color: '#fff' }}>
        {!!(sec.title || r.headline) && <h3 style={{ fontSize: 20, fontWeight: 900, margin: '0 0 8px' }}>{sec.title || String(r.headline)}</h3>}
        {!!r.sub && <p style={{ opacity: 0.85, fontSize: 15, margin: '0 0 20px' }}>{String(r.sub)}</p>}
        {!!r.btn && <a href={String(r.url || '#booking-form')} style={{ display: 'inline-block', background: '#fff', color: primary, padding: '12px 28px', borderRadius: 9, fontWeight: 800, fontSize: 15, textDecoration: 'none' }}>{String(r.btn)}</a>}
      </div>
    case 'related':
      return wrap(((r.slugs as string[]) || []).length === 0
        ? <p style={{ color: '#9ca3af', fontSize: 13, margin: 0 }}>No related services configured.</p>
        : <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
            {((r.slugs as string[]) || []).map((slug, i) => (
              <Link key={i} to={`/service/${slug}`} style={{ padding: '7px 14px', background: `${primary}10`, color: primary, borderRadius: 99, fontSize: 13, fontWeight: 600, textDecoration: 'none', border: `1px solid ${primary}25` }}>
                {slug.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
              </Link>
            ))}
          </div>
      )
    case 'testimonials': {
      const items = (r.items as Array<{ name: string; quote: string; rating?: number }>) || []
      return wrap(<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 14 }}>
        {items.map((t, i) => <div key={i} style={{ background: '#F5F7FA', border: '1px solid #EBF0F8', borderRadius: 12, padding: 18 }}>
          {typeof t.rating === 'number' && <div style={{ color: '#f59e0b', fontSize: 14, marginBottom: 8 }}>{'★'.repeat(Math.round(t.rating))}{'☆'.repeat(5 - Math.round(t.rating))}</div>}
          <p style={{ fontSize: 13, color: '#374151', lineHeight: 1.6, margin: '0 0 10px', fontStyle: 'italic' }}>&ldquo;{t.quote}&rdquo;</p>
          <div style={{ fontWeight: 700, fontSize: 13, color: '#1E2D40' }}>{t.name}</div>
        </div>)}
      </div>)
    }
    case 'features': case 'highlights':
      return wrap(<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 12 }}>
        {((r.items as Array<{ icon?: string; title: string; desc: string }>) || []).map((item, i) => (
          <div key={i} style={{ padding: '14px 16px', border: '1px solid #EBF0F8', borderRadius: 10, background: '#fff' }}>
            <div style={{ fontSize: 24, marginBottom: 6 }}>{item.icon || '⭐'}</div>
            <div style={{ fontWeight: 700, fontSize: 14, color: '#1E2D40', marginBottom: 4 }}>{item.title}</div>
            <div style={{ fontSize: 12, color: '#666', lineHeight: 1.5 }}>{item.desc}</div>
          </div>
        ))}
      </div>)
    default: return null
  }
}

// ── Fallback content ──────────────────────────────────────────────────────────
function FallbackContent({ svc, primary, siteName }: { svc: Service; primary: string; siteName: string }) {
  return <>
    <div style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: '24px 28px', marginBottom: 22 }}>
      <h3 style={{ fontSize: 16, fontWeight: 800, color: '#1E2D40', margin: '0 0 14px' }}>How the Process Works</h3>
      {[['Submit Request','Fill the form in steps. No login needed to start.'],['Document Review','Our expert team reviews your submission within 24 hours.'],['Get Quote','Receive a detailed, itemised quote. Pay only after approval.'],['Processing','We handle everything in India with real-time updates.'],['Delivery','Documents delivered to your overseas address by courier.']].map(([t, d], i, arr) => (
        <div key={i} style={{ display: 'flex', gap: 14, marginBottom: i < arr.length - 1 ? 14 : 0, alignItems: 'flex-start' }}>
          <div style={{ width: 28, height: 28, background: primary, color: '#fff', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 13, flexShrink: 0, marginTop: 1 }}>{i + 1}</div>
          <div><div style={{ fontWeight: 700, fontSize: 14, color: '#1E2D40', marginBottom: 2 }}>{t}</div><div style={{ fontSize: 13, color: '#666', lineHeight: 1.6 }}>{d}</div></div>
        </div>
      ))}
    </div>
    <div style={{ background: `${primary}08`, border: `1px solid ${primary}20`, borderRadius: 12, padding: '20px 24px', marginBottom: 22 }}>
      <h3 style={{ fontSize: 15, fontWeight: 700, color: '#1E2D40', margin: '0 0 10px' }}>🔐 Is My Data Secure?</h3>
      <p style={{ fontSize: 14, color: '#555', lineHeight: 1.7, margin: 0 }}>{siteName} uses AES-256 encryption for all document uploads. Documents are never shared via email or WhatsApp and are permanently deleted after service completion.</p>
    </div>
    <div style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: '20px 24px' }}>
      <h3 style={{ fontSize: 15, fontWeight: 700, color: '#1E2D40', margin: '0 0 10px' }}>💰 Charges & Payment</h3>
      <p style={{ fontSize: 14, color: '#555', lineHeight: 1.7, margin: '0 0 8px' }}>Get a personalised quote by submitting the form. No payment required until you approve the quote.</p>
      {svc.price_range && <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: `${primary}15`, color: primary, padding: '6px 14px', borderRadius: 99, fontSize: 14, fontWeight: 700 }}>📦 Starting from {svc.price_range}</div>}
    </div>
  </>
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

  const [svc,       setSvc]       = useState<Service | null>(null)
  const [sections,  setSections]  = useState<ServiceSection[]>([])
  const [loading,   setLoading]   = useState(true)

  // Wizard state
  const [stepIdx,   setStepIdx]   = useState(0)
  const [values,    setValues]    = useState<Record<string, unknown>>({})
  const [errors,    setErrors]    = useState<Record<string, string>>({})
  const [submitting,setSubmitting]= useState(false)
  const [submitted, setSubmitted] = useState<{ booking_ref: string; id: number; __uploadWarning?: string } | null>(null)
  const [apiError,  setApiError]  = useState('')
  const [loadFailed, setLoadFailed] = useState(false)

  const loadService = useCallback(() => {
    if (!slug) return
    setLoading(true)
    setLoadFailed(false)
    api.get<{ service: Service; redirect?: string; unavailable?: boolean; message?: string }>(`services/${slug}`)
      .then(data => {
        if (data.redirect) {
          window.location.href = data.redirect
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
        return api.get<{ sections: ServiceSection[] }>(`services/${slug}/sections`)
          .then(r => {
            // Issue 6: filter out is_visible=0 sections so toggle actually works
            const visible = (r.sections || []).filter(sec =>
              sec.is_visible !== 0 && String(sec.is_visible) !== '0' && String(sec.is_visible) !== 'false'
            )
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

  // ── Loading ──────────────────────────────────────────────────────────────────
  if (loading) return (
    <Layout>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '60vh' }}>
        <div style={{ width: 52, height: 52, border: `4px solid ${primary}30`, borderTop: `4px solid ${primary}`, borderRadius: '50%', animation: 'spin .7s linear infinite' }} />
      </div>
    </Layout>
  )

  if (loadFailed) return (
    <Layout>
      <div style={{ textAlign: 'center', padding: '80px 20px' }}>
        <div style={{ fontSize: 56, marginBottom: 16 }}>⚠️</div>
        <h2 style={{ color: '#374151', marginBottom: 12 }}>Couldn't load this page</h2>
        <p style={{ color: '#6b7280', marginBottom: 20 }}>Something went wrong on our end. Please try again.</p>
        <button
          onClick={loadService}
          style={{ background: primary, color: '#fff', border: 'none', padding: '10px 24px', borderRadius: 8, fontWeight: 700, cursor: 'pointer', fontSize: 14 }}
        >
          Retry
        </button>
      </div>
    </Layout>
  )

  if (!svc) return (
    <Layout>
      <div style={{ textAlign: 'center', padding: '80px 20px' }}>
        <div style={{ fontSize: 56, marginBottom: 16 }}>🔍</div>
        <h2 style={{ color: '#374151', marginBottom: 12 }}>Service not found</h2>
        <Link to="/services" style={{ color: primary, fontWeight: 700, fontSize: 15 }}>← Browse All Services</Link>
      </div>
    </Layout>
  )

  // ── Success screen ────────────────────────────────────────────────────────────
  if (submitted) return (
    <Layout>
      <div style={{ maxWidth: 600, margin: '0 auto', padding: '72px 20px', textAlign: 'center' }}>
        <div style={{ width: 88, height: 88, background: '#d1fae5', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 44, margin: '0 auto 24px', boxShadow: '0 0 0 8px #d1fae550' }}>✅</div>
        <h1 style={{ fontSize: 30, fontWeight: 900, color: '#1E2D40', margin: '0 0 12px' }}>Request Submitted!</h1>
        <p style={{ fontSize: 16, color: '#555', lineHeight: 1.75, margin: '0 0 10px' }}>Your <strong>{svc.name}</strong> request has been received.</p>
        {submitted.__uploadWarning && (
          <div style={{ background: '#fffbeb', border: '1px solid #fbbf24', borderRadius: 8, padding: '10px 16px', marginBottom: 12, color: '#92400e', fontSize: 14 }}>⚠️ {submitted.__uploadWarning}</div>
        )}
        <p style={{ fontSize: 15, color: '#666', lineHeight: 1.75, margin: '0 0 28px' }}>
          Booking reference: <strong style={{ color: primary, fontSize: 17 }}>{submitted.booking_ref}</strong><br />
          Our team will review and send you an itemised quote within <strong>24 hours</strong>. A confirmation email has been sent.
        </p>
        <div style={{ background: '#fff', border: `1px solid ${primary}20`, borderRadius: 14, padding: '20px 24px', marginBottom: 28, textAlign: 'left', boxShadow: `0 4px 16px ${primary}15` }}>
          <div className="s2-mobile-stack" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px', fontSize: 14 }}>
            {[['Service', svc.name], ['Booking Ref', submitted.booking_ref], ['Status', 'Under Review'], ['Quote in', '≤ 24 hours']].map(([label, val]) => (
              <div key={label}>
                <div style={{ fontSize: 11, color: '#9ca3af', fontWeight: 700, textTransform: 'uppercase', letterSpacing: 1, marginBottom: 2 }}>{label}</div>
                <div style={{ fontWeight: 700, color: label === 'Booking Ref' ? primary : label === 'Status' ? '#15803d' : '#1E2D40' }}>{val}</div>
              </div>
            ))}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap' }}>
          {user
            ? <Link to={`/dashboard/bookings/${submitted.id}`} style={{ background: primary, color: '#fff', padding: '14px 32px', borderRadius: 10, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}>Track My Booking →</Link>
            : <Link to="/login" style={{ background: primary, color: '#fff', padding: '14px 32px', borderRadius: 10, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}>Sign In to Track →</Link>
          }
          <Link to="/services" style={{ padding: '14px 28px', borderRadius: 10, fontWeight: 600, fontSize: 15, textDecoration: 'none', border: `1.5px solid ${primary}`, color: primary }}>Browse More Services</Link>
        </div>
      </div>
    </Layout>
  )

  const img = svc.image_url || getServiceImage(slug || '')
  const hero = (svc as unknown as Record<string, unknown>).hero_settings as Record<string, unknown> | null | undefined
  const marquee = (svc as unknown as Record<string, unknown>).marquee_settings as Record<string, unknown> | null | undefined

  // Issue 5: hero image/title/overlay from builder settings or fallback to defaults
  const heroImg     = hero && hero.enabled !== false && hero.image_url ? String(hero.image_url) : img
  const heroTitle   = hero && hero.enabled !== false && hero.title     ? String(hero.title)     : svc.name
  const heroHeight  = hero && hero.enabled !== false && hero.height    ? Number(hero.height)    : 280
  const heroOverlay = hero && hero.enabled !== false
    ? `rgba(0,0,0,${(Number(hero.overlay_opacity ?? 40)) / 100})`
    : 'rgba(0,0,0,.6)'
  const heroAlign   = hero && hero.enabled !== false && hero.text_align ? String(hero.text_align) : 'left'

  // Issue 10: section nav items (non-hero, non-marquee, active only)
  

  return (
    <Layout>
      {/* Breadcrumb */}
      <div style={{ background: '#F5F7FA', borderBottom: '1px solid #EBF0F8', padding: '10px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', fontSize: 13, color: '#666', display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
          <Link to="/" style={{ color: primary, textDecoration: 'none' }}>Home</Link>
          <span>›</span>
          <Link to="/services" style={{ color: primary, textDecoration: 'none' }}>Services</Link>
          {svc.category_name && <><span>›</span><Link to={`/services/${svc.category_slug}`} style={{ color: primary, textDecoration: 'none' }}>{svc.category_name}</Link></>}
          <span>›</span>
          <span style={{ color: '#444', fontWeight: 600 }}>{svc.name}</span>
        </div>
      </div>

      {/* Issue 8: Marquee — positioned immediately after hero/breadcrumb, before sections */}
      {marquee && marquee.enabled && marquee.text ? (
        <div style={{
          overflow: 'hidden', background: String(marquee.bg_color || primary),
          color: String(marquee.text_color || '#fff'), padding: '10px 0',
          fontSize: 14, fontWeight: 600,
        }}>
          <style>{`@keyframes s2-marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}`}</style>
          <div style={{
            display: 'flex', whiteSpace: 'nowrap',
            animation: `s2-marquee ${Number(marquee.speed) || 30}s linear infinite`,
          }}>
            <span style={{ paddingRight: 80 }}>{String(marquee.text)}</span>
            <span style={{ paddingRight: 80 }}>{String(marquee.text)}</span>
          </div>
        </div>
      ) : null}

      {/* 2-col layout: [section nav + content] + [booking wizard] */}
      <div className="svc-grid" style={{ maxWidth: 1200, margin: '0 auto', padding: '36px 20px 60px', display: 'grid', gridTemplateColumns: '1fr 420px', gap: 40, alignItems: 'flex-start' }}>

        {/* Left: service info */}
        <div>
          {/* Hero */}
          <div style={{ borderRadius: 14, overflow: 'hidden', marginBottom: 28, position: 'relative', height: heroHeight }}>
            <img src={heroImg} alt={heroTitle} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
            <div style={{ position: 'absolute', inset: 0, background: `linear-gradient(to top, ${heroOverlay} 0%, transparent 50%)` }} />
            <div style={{ position: 'absolute', bottom: 0, left: 0, right: 0, padding: '20px 24px', textAlign: heroAlign as React.CSSProperties['textAlign'] }}>
              {svc.category_name && <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: primary, color: '#fff', padding: '4px 12px', borderRadius: 99, fontSize: 12, fontWeight: 700, marginBottom: 8 }}>{svc.category_name}</div>}
              <h1 style={{ color: '#fff', fontSize: 'clamp(20px, 3vw, 28px)', fontWeight: 900, margin: 0, lineHeight: 1.2, textShadow: '0 2px 8px rgba(0,0,0,.4)' }}>{heroTitle}</h1>
              {hero && hero.subtitle ? <p style={{ color: 'rgba(255,255,255,.85)', margin: '6px 0 0', fontSize: 14 }}>{String(hero.subtitle)}</p> : null}
              {/* Issue 5: CTA buttons from hero_settings */}
              {hero && (hero.cta_text || hero.cta2_text) ? (
                <div style={{ display: 'flex', gap: 10, marginTop: 12, justifyContent: heroAlign === 'center' ? 'center' : 'flex-start', flexWrap: 'wrap' }}>
                  {hero.cta_text ? <a href={String(hero.cta_url || '#booking-form')} style={{ background: '#fff', color: primary, padding: '9px 20px', borderRadius: 7, fontWeight: 700, fontSize: 13, textDecoration: 'none' }}>{String(hero.cta_text)}</a> : null}
                  {hero.cta2_text ? <a href={String(hero.cta2_url || '#booking-form')} style={{ background: 'transparent', color: '#fff', padding: '9px 20px', borderRadius: 7, fontWeight: 600, fontSize: 13, textDecoration: 'none', border: '2px solid rgba(255,255,255,.6)' }}>{String(hero.cta2_text)}</a> : null}
                </div>
              ) : null}
            </div>
          </div>

          {/* Issue 6/9: sections filtered by is_visible, with device-visibility data attrs */}
          {sections.length > 0
            ? sections.map(sec => {
                const dv = (sec.content as Record<string, unknown>)?.device_visibility as Record<string, boolean> | undefined
                const dvClass = dv ? [
                  dv.desktop === false ? 's2-hide-desktop' : '',
                  dv.tablet  === false ? 's2-hide-tablet'  : '',
                  dv.mobile  === false ? 's2-hide-mobile'  : '',
                ].filter(Boolean).join(' ') : ''
                return (
                  <div key={sec.id} className={dvClass || undefined}>
                    <SectionRenderer sec={sec} primary={primary} />
                  </div>
                )
              })
            : <>
                {/* Trust badges */}
                <div className="s2-mobile-stack" style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 28 }}>
                  {[{ icon: '⭐', val: settings.google_rating || '4.9', label: 'Google Rating' }, { icon: '👥', val: settings.google_review_count || '10,000+', label: 'Happy Customers' }, { icon: '🌏', val: '750+', label: 'Pan India Coverage' }].map(({ icon, val, label }) => (
                    <div key={label} style={{ background: primary, color: '#fff', borderRadius: 10, padding: '18px 16px', textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8 }}>
                      <div style={{ fontSize: 28 }}>{icon}</div>
                      <div style={{ fontWeight: 900, fontSize: 22, lineHeight: 1 }}>{val}</div>
                      <div style={{ fontSize: 12, opacity: 0.85, fontWeight: 500 }}>{label}</div>
                    </div>
                  ))}
                </div>
                {/* Description */}
                {(svc.description || svc.short_desc) && (
                  <div style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: '24px 28px', marginBottom: 22 }}>
                    <h2 style={{ fontSize: 20, fontWeight: 800, color: '#1E2D40', margin: '0 0 14px' }}>{svc.name} — Complete Guide for NRIs</h2>
                    <p style={{ fontSize: 15, color: '#374151', margin: 0, lineHeight: 1.8 }}>{svc.description || svc.short_desc}</p>
                  </div>
                )}
                {/* Required docs */}
                {Array.isArray(svc.required_docs) && svc.required_docs.length > 0 && (
                  <div style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: '24px 28px', marginBottom: 22 }}>
                    <h3 style={{ fontSize: 16, fontWeight: 800, color: '#1E2D40', margin: '0 0 14px' }}>📎 Documents Required</h3>
                    <div className="s2-mobile-stack" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                      {svc.required_docs.map((doc, i) => (
                        <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 14, color: '#374151', padding: '6px 0', borderBottom: '1px solid #f5f5f5' }}>
                          <span style={{ color: '#2e7d32', fontWeight: 700, flexShrink: 0 }}>✓</span>{doc}
                        </div>
                      ))}
                    </div>
                  </div>
                )}
                <FallbackContent svc={svc} primary={primary} siteName={siteName} />
              </>
          }
        </div>

        {/* Right: sticky booking wizard */}
        <div id="booking-form" style={{ position: 'sticky', top: 80 }}>

          {/* ── Gradient header with step tracker ── */}
          <div style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, padding: '20px 24px 0', borderRadius: '16px 16px 0 0', userSelect: 'none' }}>
            {/* Service name + meta */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 20 }}>
              <div style={{ width: 48, height: 48, background: 'rgba(255,255,255,.18)', borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 24, flexShrink: 0 }}>
                {svc.icon || '📋'}
              </div>
              <div>
                <div style={{ color: '#fff', fontWeight: 800, fontSize: 15, lineHeight: 1.2 }}>{svc.name}</div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 5 }}>
                  {[svc.turnaround && `⏱ ${svc.turnaround}`, '🔒 SSL', '📧 Quote 24h'].filter(Boolean).map(t => (
                    <span key={t as string} style={{ fontSize: 11, fontWeight: 600, color: 'rgba(255,255,255,.85)', background: 'rgba(255,255,255,.14)', padding: '3px 9px', borderRadius: 99 }}>{t}</span>
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
            <div style={{ display: 'flex', alignItems: 'center', paddingBottom: 1, overflowX: 'auto', WebkitOverflowScrolling: 'touch' }}>
              {orderedStepNums.map((stepNum, idx) => {
                const done = idx < stepIdx
                const curr = idx === stepIdx
                const label = stepNum === CONFIRM_STEP ? 'Confirm' : (STEP_LABEL[stepNum] || `Step ${stepNum}`)
                return (
                  <div key={stepNum} style={{ display: 'flex', alignItems: 'center', flex: idx < totalSteps - 1 ? 1 : 'none' as const }}>
                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }}>
                      <div style={{ width: 32, height: 32, borderRadius: '50%', background: done ? '#22c55e' : curr ? '#fff' : 'rgba(255,255,255,.2)', color: done ? '#fff' : curr ? primary : 'rgba(255,255,255,.6)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 13, boxShadow: curr ? '0 0 0 4px rgba(255,255,255,.25)' : 'none', transition: 'all .3s' }}>
                        {done ? '✓' : idx + 1}
                      </div>
                      <span style={{ fontSize: 9, fontWeight: curr ? 700 : 400, color: curr ? '#fff' : 'rgba(255,255,255,.55)', marginTop: 4, whiteSpace: 'nowrap', maxWidth: 56, textAlign: 'center', lineHeight: 1.2 }}>
                        {label}
                      </span>
                    </div>
                    {idx < totalSteps - 1 && (
                      <div style={{ flex: 1, height: 3, background: done ? '#22c55e' : 'rgba(255,255,255,.2)', margin: '0 4px', marginBottom: 16, borderRadius: 2, transition: 'background .35s' }} />
                    )}
                  </div>
                )
              })}
            </div>
          </div>

          {/* ── Form card ── */}
          <div style={{ background: '#fff', borderRadius: '0 0 16px 16px', boxShadow: '0 6px 36px rgba(0,0,0,.12)' }}>
            {/* Step header */}
            <div style={{ padding: '18px 24px 14px', borderBottom: '1px solid #f0f0f0' }}>
              <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: 2.5, color: primary, marginBottom: 3 }}>
                Step {stepIdx + 1} of {totalSteps}
              </div>
              <h2 style={{ fontSize: 19, fontWeight: 800, color: '#1E2D40', margin: 0 }}>{stepLabel}</h2>
            </div>

            {/* Fields */}
            <div style={{ padding: '20px 24px' }}>
              {apiError && (
                <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 9, padding: '12px 16px', marginBottom: 20, color: '#b91c1c', fontSize: 14, fontWeight: 600 }}>
                  ⚠ {apiError}
                </div>
              )}

              {/* Show service overview on step 1 if no fields */}
              {!isConfirm && currentFields.length === 0 && stepIdx === 0 && (
                <div style={{ color: '#555', fontSize: 15, lineHeight: 1.85 }}>
                  <p style={{ margin: '0 0 12px' }}>You're booking: <strong>{svc.name}</strong></p>
                  {svc.short_desc && <p style={{ margin: '0 0 14px', color: '#666' }}>{svc.short_desc}</p>}
                  {Array.isArray(svc.required_docs) && svc.required_docs.length > 0 && (
                    <div style={{ background: '#F5F7FA', borderRadius: 10, padding: 18, border: '1px solid #EBF0F8' }}>
                      <strong style={{ display: 'block', marginBottom: 10, fontSize: 14, color: '#1E2D40' }}>📎 Documents you'll need:</strong>
                      {svc.required_docs.map((doc, i) => (
                        <div key={i} style={{ fontSize: 14, color: '#555', padding: '4px 0', display: 'flex', gap: 8 }}>
                          <span style={{ color: primary, fontWeight: 700 }}>✓</span>{doc}
                        </div>
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
                  <div style={{ background: '#F5F7FA', borderRadius: 12, padding: '20px 22px', marginBottom: 20, border: '1px solid #EBF0F8' }}>
                    <h3 style={{ fontSize: 15, fontWeight: 800, color: '#1E2D40', margin: '0 0 16px', display: 'flex', alignItems: 'center', gap: 8 }}>📋 Your Request Summary</h3>
                    {/* Service row */}
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #EBF0F8', fontSize: 14 }}>
                      <span style={{ color: '#9ca3af', flex: '0 0 38%' }}>Service</span>
                      <span style={{ fontWeight: 600, color: '#1E2D40', textAlign: 'right', flex: '0 0 60%' }}>{svc.name}</span>
                    </div>
                    {svc.turnaround && (
                      <div style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #EBF0F8', fontSize: 14 }}>
                        <span style={{ color: '#9ca3af', flex: '0 0 38%' }}>Turnaround</span>
                        <span style={{ fontWeight: 600, color: '#1E2D40', textAlign: 'right', flex: '0 0 60%' }}>{svc.turnaround}</span>
                      </div>
                    )}
                    {/* Filled text values */}
                    {Object.entries(values)
                      .filter(([, v]) => v && typeof v === 'string' && (v as string).trim())
                      .slice(0, 12)
                      .map(([key, val]) => {
                        const f = schema.find(f => f.key === key)
                        const label = f?.label || key.replace(/_/g, ' ')
                        return (
                          <div key={key} style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #f0f0f0', fontSize: 14 }}>
                            <span style={{ color: '#9ca3af', flex: '0 0 38%', textTransform: 'capitalize' }}>{label}</span>
                            <span style={{ fontWeight: 500, color: '#374151', textAlign: 'right', flex: '0 0 60%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{String(val).slice(0, 60)}</span>
                          </div>
                        )
                      })}
                    {/* File count */}
                    {Object.values(values).some(v => Array.isArray(v) && (v as unknown[])[0] instanceof File) && (
                      <div style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', fontSize: 14 }}>
                        <span style={{ color: '#9ca3af' }}>Documents</span>
                        <span style={{ fontWeight: 500, color: '#374151' }}>
                          {Object.values(values).filter(v => Array.isArray(v) && (v as unknown[])[0] instanceof File).flatMap(v => v as File[]).length} file(s) attached
                        </span>
                      </div>
                    )}
                  </div>

                  {/* Login gate */}
                  {!user && (
                    <div style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 10, padding: '16px 18px', marginBottom: 16 }}>
                      <p style={{ fontSize: 14, color: '#92400e', margin: '0 0 12px', fontWeight: 700 }}>⚠ You need to sign in to submit this request</p>
                      <div style={{ display: 'flex', gap: 10 }}>
                        <Link to={`/login?redirect=/book/${slug}`} style={{ background: primary, color: '#fff', padding: '9px 20px', borderRadius: 8, fontWeight: 700, fontSize: 13, textDecoration: 'none' }}>Sign In</Link>
                        <Link to={`/register?redirect=/book/${slug}`} style={{ color: primary, padding: '9px 20px', borderRadius: 8, fontWeight: 600, fontSize: 13, textDecoration: 'none', border: `1.5px solid ${primary}` }}>Create Account</Link>
                      </div>
                    </div>
                  )}

                  {/* T&C */}
                  <div style={{ background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 10, padding: '13px 16px', fontSize: 13, color: '#15803d', fontWeight: 500 }}>
                    ✅ By submitting, you confirm the above details are accurate. We will send you an itemised quote within 24 hours. No payment is required until you approve the quote.
                  </div>
                </div>
              )}
            </div>

            {/* Navigation footer */}
            <div style={{ padding: '16px 24px 22px', borderTop: '1px solid #f0f0f0', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
              <button type="button" onClick={prev}
                style={{ background: '#f3f4f6', color: '#374151', border: 'none', padding: '12px 22px', borderRadius: 9, fontWeight: 600, fontSize: 15, cursor: 'pointer', flexShrink: 0 }}>
                ← {stepIdx === 0 ? 'Back' : 'Previous'}
              </button>

              {/* Dot progress */}
              <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                {orderedStepNums.map((_, i) => (
                  <div key={i} style={{ width: stepIdx === i ? 22 : 7, height: 7, borderRadius: 4, background: stepIdx === i ? primary : stepIdx > i ? `${primary}60` : '#e0e0e0', transition: 'all .3s' }} />
                ))}
              </div>

              {!isConfirm ? (
                <button type="button" onClick={next}
                  style={{ background: primary, color: '#fff', border: 'none', padding: '12px 26px', borderRadius: 9, fontWeight: 700, fontSize: 15, cursor: 'pointer', flexShrink: 0 }}>
                  Next Step →
                </button>
              ) : (
                <button type="button" onClick={submit} disabled={submitting || !user}
                  style={{ background: user ? primary : '#9ca3af', color: '#fff', border: 'none', padding: '13px 28px', borderRadius: 9, fontWeight: 700, fontSize: 15, cursor: user ? 'pointer' : 'not-allowed', opacity: submitting ? 0.75 : 1, display: 'flex', alignItems: 'center', gap: 10, flexShrink: 0 }}>
                  {submitting ? (
                    <><div style={{ width: 18, height: 18, border: '3px solid rgba(255,255,255,.4)', borderTop: '3px solid #fff', borderRadius: '50%', animation: 'spin .7s linear infinite' }} />Submitting…</>
                  ) : '⚡ Submit Request'}
                </button>
              )}
            </div>

            {/* Trust signals */}
            <div style={{ display: 'flex', justifyContent: 'center', gap: 16, padding: '0 20px 18px', flexWrap: 'wrap' }}>
              {['🔒 256-bit SSL','📧 Quote in 24h','💰 Pay after approval','✅ 10,000+ NRIs'].map(s => (
                <span key={s} style={{ fontSize: 11, color: '#9ca3af' }}>{s}</span>
              ))}
            </div>
          </div>

          {/* WhatsApp fallback */}
          {waNum && (
            <div style={{ marginTop: 14, textAlign: 'center' }}>
              <a href={`https://wa.me/${String(waNum).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer"
                style={{ display: 'inline-flex', alignItems: 'center', gap: 8, background: '#25d366', color: '#fff', padding: '10px 22px', borderRadius: 10, fontSize: 14, fontWeight: 700, textDecoration: 'none' }}>
                💬 Prefer WhatsApp? Chat with Us
              </a>
            </div>
          )}
        </div>
      </div>

      {/* Why choose us */}
      <section style={{ background: '#F5F7FA', padding: '48px 20px', borderTop: '1px solid #EBF0F8' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(18px, 2.5vw, 26px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 24px' }}>Why Choose {siteName}?</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(250px, 1fr))', gap: 16 }}>
            {[
              { icon: '🔐', t: 'Secure Platform',         d: 'AES-256 encrypted document storage. Never shared or emailed.' },
              { icon: '📊', t: 'Real-Time Tracking',      d: 'Track every step in your dashboard. No black boxes.' },
              { icon: '💰', t: 'Money-Back Guarantee',    d: 'If we cannot deliver, you receive a full refund.' },
              { icon: '🌍', t: 'Global NRI Coverage',     d: 'Serving NRIs in 50+ countries with India-based execution.' },
              { icon: '⚡', t: 'Fast Turnaround',         d: 'Most services delivered in 7–30 days from submission.' },
              { icon: '🏆', t: 'Startup India Recognised',d: 'Government recognised. Professionally managed.' },
            ].map(({ icon, t, d }) => (
              <div key={t} style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: 20, display: 'flex', gap: 12 }}>
                <div style={{ width: 44, height: 44, background: `${primary}15`, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20, flexShrink: 0 }}>{icon}</div>
                <div>
                  <h4 style={{ fontSize: 14, fontWeight: 700, color: '#1E2D40', margin: '0 0 4px' }}>{t}</h4>
                  <p style={{ fontSize: 13, color: '#666', lineHeight: 1.5, margin: 0 }}>{d}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>
    </Layout>
  )
}
