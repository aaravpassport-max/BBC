/**
 * Enterprise admin fields — hex colors and px measurements with validation.
 */
import React from 'react'

const inputStyle: React.CSSProperties = {
  display: 'block',
  width: '100%',
  marginTop: 4,
  padding: '8px 10px',
  borderRadius: 8,
  border: '1px solid #E2E8F0',
  fontSize: 13,
  fontFamily: 'inherit',
}

export function normalizeHex(raw: string): string {
  let v = raw.trim()
  if (!v) return ''
  if (!v.startsWith('#')) v = `#${v.replace(/^#/, '')}`
  if (/^#[0-9A-Fa-f]{3}$/.test(v)) {
    const r = v[1]
    const g = v[2]
    const b = v[3]
    v = `#${r}${r}${g}${g}${b}${b}`
  }
  if (/^#[0-9A-Fa-f]{6}$/.test(v)) return v.toUpperCase()
  return raw.trim()
}

export function parsePx(raw: string): string {
  const v = raw.trim()
  if (!v || v === 'inherit') return v
  if (/^\d+(\.\d+)?$/.test(v)) return `${v}px`
  return v
}

export function stripPxForInput(raw: string): string {
  const v = String(raw ?? '').trim()
  const m = v.match(/^(\d+(?:\.\d+)?)px$/i)
  return m ? m[1] : v.replace(/px$/i, '')
}

/** Solid brand colors — hex only */
export function HexColorField({
  label,
  value,
  onChange,
  hint,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  hint?: string
}) {
  const hex = value.startsWith('#') ? normalizeHex(value) : ''
  const picker = hex || '#4A6FA5'
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 13 }}>
      <span style={{ fontWeight: 600 }}>{label}</span>
      {hint && <span style={{ fontSize: 11, color: '#64748B', fontWeight: 400 }}>{hint}</span>}
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <input
          type="color"
          value={picker}
          onChange={(e) => onChange(normalizeHex(e.target.value))}
          style={{ width: 44, height: 36, padding: 2, borderRadius: 6, border: '1px solid #E2E8F0', cursor: 'pointer' }}
        />
        <input
          type="text"
          value={value.startsWith('#') ? hex : value}
          placeholder="#4A6FA5"
          onChange={(e) => onChange(e.target.value)}
          onBlur={(e) => {
            const n = normalizeHex(e.target.value)
            if (n.startsWith('#')) onChange(n)
          }}
          style={{ ...inputStyle, flex: 1, marginTop: 0, fontFamily: 'ui-monospace, monospace' }}
        />
      </div>
    </label>
  )
}

/** Overlay / shadow tints — hex + opacity % → #RRGGBB + alpha or rgba */
export function HexAlphaColorField({
  label,
  value,
  onChange,
}: {
  label: string
  value: string
  onChange: (v: string) => void
}) {
  const parsed = (() => {
    const v = value.trim()
    const m8 = v.match(/^#([0-9A-Fa-f]{6})([0-9A-Fa-f]{2})$/)
    if (m8) {
      const alpha = Math.round((parseInt(m8[2], 16) / 255) * 100)
      return { hex: `#${m8[1].toUpperCase()}`, alpha }
    }
    const m6 = v.match(/^#([0-9A-Fa-f]{6})$/)
    if (m6) return { hex: v.toUpperCase(), alpha: 100 }
    const rgba = v.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\s*\)/)
    if (rgba) {
      const r = Number(rgba[1])
      const g = Number(rgba[2])
      const b = Number(rgba[3])
      const a = rgba[4] !== undefined ? Number(rgba[4]) : 1
      const hex =
        '#' +
        [r, g, b].map((x) => x.toString(16).padStart(2, '0')).join('').toUpperCase()
      return { hex, alpha: Math.round(a * 100) }
    }
    return { hex: '#1E2D40', alpha: 55 }
  })()

  function emit(hex: string, alphaPct: number) {
    const h = normalizeHex(hex)
    if (!h.startsWith('#')) return
    const a = Math.max(0, Math.min(100, alphaPct))
    if (a >= 100) {
      onChange(h)
      return
    }
    const aa = Math.round((a / 100) * 255)
      .toString(16)
      .padStart(2, '0')
      .toUpperCase()
    onChange(`${h}${aa}`)
  }

  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
      <span style={{ fontWeight: 600 }}>{label}</span>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <input
          type="color"
          value={parsed.hex}
          onChange={(e) => emit(e.target.value, parsed.alpha)}
          style={{ width: 44, height: 36, padding: 2, borderRadius: 6, border: '1px solid #E2E8F0' }}
        />
        <input
          type="text"
          value={parsed.hex}
          onChange={(e) => emit(e.target.value, parsed.alpha)}
          onBlur={(e) => emit(e.target.value, parsed.alpha)}
          style={{ ...inputStyle, flex: 1, marginTop: 0, fontFamily: 'ui-monospace, monospace' }}
        />
      </div>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <span style={{ fontSize: 11, color: '#64748B', minWidth: 52 }}>Opacity</span>
        <input
          type="range"
          min={0}
          max={100}
          value={parsed.alpha}
          onChange={(e) => emit(parsed.hex, Number(e.target.value))}
          style={{ flex: 1 }}
        />
        <span style={{ fontSize: 12, fontWeight: 600, minWidth: 36 }}>{parsed.alpha}%</span>
      </div>
      <code style={{ fontSize: 11, color: '#64748B' }}>{value || '—'}</code>
    </label>
  )
}

export function PxTokenField({
  label,
  value,
  onChange,
  min,
  max,
  step = 1,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  min?: number
  max?: number
  step?: number
}) {
  const num = stripPxForInput(value)
  return (
    <label style={{ fontSize: 13 }}>
      {label}
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4 }}>
        <input
          type="number"
          min={min}
          max={max}
          step={step}
          value={num === '' || Number.isNaN(Number(num)) ? '' : num}
          onChange={(e) => onChange(parsePx(e.target.value))}
          style={{ ...inputStyle, marginTop: 0, flex: 1 }}
        />
        <span style={{ fontSize: 12, fontWeight: 700, color: '#64748B' }}>px</span>
      </div>
    </label>
  )
}

export function PercentField({
  label,
  value,
  onChange,
}: {
  label: string
  value: string
  onChange: (v: string) => void
}) {
  const num = value.replace(/%$/, '')
  return (
    <label style={{ fontSize: 13 }}>
      {label}
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4 }}>
        <input
          type="number"
          min={0}
          max={100}
          value={num}
          onChange={(e) => onChange(`${e.target.value}%`)}
          style={{ ...inputStyle, marginTop: 0, flex: 1 }}
        />
        <span style={{ fontSize: 12, fontWeight: 700, color: '#64748B' }}>%</span>
      </div>
    </label>
  )
}

export const WIDTH_DESKTOP_PRESETS: { label: string; values: Record<string, string> }[] = [
  { label: 'Marketplace (1200 / 1100 / 960 / 860 / 720)', values: { page_max: '1200', content_max: '960', inner_max: '860', section_standard: '1100', section_wide: '1200', section_narrow: '720', section_compact: '640', padding_x: '24' } },
  { label: 'Compact (1080 / 720)', values: { page_max: '1080', content_max: '720', inner_max: '640', section_standard: '960', section_wide: '1080', section_narrow: '640', padding_x: '16' } },
  { label: 'Wide marketing (1320)', values: { page_max: '1320', content_max: '960', inner_max: '880', section_standard: '1200', section_wide: '1320', section_narrow: '760', padding_x: '32' } },
]
