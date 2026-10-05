/**
 * Visual site-builder primitives (band panels, grouped design controls).
 */
import React from 'react'
import type { SectionCatalogDef } from '@/lib/design-system-catalog'
import { hasOverrideAtPath, readPathLeaf } from './design-inherit-ui'
import { SECTION_DESIGN_COLOR_KEYS } from '@/lib/design-system-catalog'

export type DeviceId = 'desktop' | 'tablet' | 'mobile'

const DEVICE_LABELS: Record<DeviceId, string> = {
  desktop: 'Desktop',
  tablet: 'Tablet',
  mobile: 'Mobile',
}

export function BandDeviceTabs({
  device,
  onDevice,
}: {
  device: DeviceId
  onDevice: (d: DeviceId) => void
}) {
  return (
    <div className="s2-band-device-tabs" role="tablist" aria-label="Screen size">
      {(['desktop', 'tablet', 'mobile'] as const).map((d) => (
        <button
          key={d}
          type="button"
          role="tab"
          aria-selected={device === d}
          className={`s2-band-device-tabs__btn${device === d ? ' is-active' : ''}`}
          onClick={() => onDevice(d)}
        >
          {DEVICE_LABELS[d]}
        </button>
      ))}
    </div>
  )
}

export function BandSegment({
  label,
  options,
  value,
  onChange,
}: {
  label: string
  options: { id: string; label: string }[]
  value: string
  onChange: (id: string) => void
}) {
  return (
    <div className="s2-band-field">
      <span className="s2-band-field__label">{label}</span>
      <div className="s2-band-segment">
        {options.map((opt) => (
          <button
            key={opt.id}
            type="button"
            className={`s2-band-segment__btn${value === opt.id ? ' is-active' : ''}`}
            onClick={() => onChange(opt.id)}
          >
            {opt.label}
          </button>
        ))}
      </div>
    </div>
  )
}

export function BandDesignGroup({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) {
  return (
    <section className="s2-band-design-group">
      <h4 className="s2-band-design-group__title">{title}</h4>
      <div className="s2-band-design-group__body">{children}</div>
    </section>
  )
}

export function BandFieldGrid({
  children,
  columns,
}: {
  children: React.ReactNode
  columns?: 2 | 3
}) {
  return (
    <div className={`s2-band-field-grid${columns ? ` s2-band-field-grid--cols-${columns}` : ''}`}>{children}</div>
  )
}

export function BandTextField({
  label,
  hint,
  value,
  placeholder,
  onChange,
  inherited,
  onClear,
}: {
  label: string
  hint?: string
  value: string
  placeholder?: string
  onChange: (v: string) => void
  inherited?: boolean
  onClear?: () => void
}) {
  return (
    <label className={`s2-band-field s2-band-field--input${inherited ? ' is-inherited' : ''}`}>
      <span className="s2-band-field__label">
        {label}
        {inherited && <span className="s2-band-field__inherit">Built-in</span>}
      </span>
      {hint && <span className="s2-band-field__hint">{hint}</span>}
      <input
        type="text"
        className="s2-band-input"
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
      />
      {onClear && !inherited && (
        <button type="button" className="s2-band-link-btn" onClick={onClear}>
          Reset to built-in
        </button>
      )}
    </label>
  )
}

export function sectionHasCustomDesign(
  section: SectionCatalogDef,
  settings: Record<string, string>,
  config: Record<string, unknown>,
): boolean {
  if (section.hideSettingKey && settings[section.hideSettingKey] === '1') return true
  if (section.designSettingFields?.some((f) => (settings[f.key] ?? '').trim() !== '')) return true
  if (!section.isPageScope && section.sectionKey) {
    for (const colorKey of SECTION_DESIGN_COLOR_KEYS) {
      const path = ['overrides', 'sections', section.sectionKey, 'colors', colorKey]
      if (hasOverrideAtPath(config, path)) return true
    }
    const typo = readPathLeaf(config, ['overrides', 'sections', section.sectionKey, 'typography'])
    if (typo && typeof typo === 'object') return true
  }
  return false
}

export const SECTION_BAND_COPY: Record<string, { subtitle: string }> = {
  hero: { subtitle: 'Search, headline, trust stats, and primary calls to action.' },
  notice: { subtitle: 'Public notice bar above the fold.' },
  search: { subtitle: 'Site search band for services.' },
  features: { subtitle: 'Why Choose Us cards and proof points.' },
  services: { subtitle: 'Featured services grid and categories.' },
  home_services: { subtitle: 'Featured services grid and categories.' },
  cities: { subtitle: 'Popular destinations or city tiles.' },
  stats: { subtitle: 'Trust metrics and social proof numbers.' },
  testimonials: { subtitle: 'Customer quotes and ratings.' },
  how: { subtitle: 'Step-by-step how it works.' },
  faq: { subtitle: 'Questions and answers accordion.' },
  newsletter: { subtitle: 'Email capture and lead form.' },
}
