/**
 * Visual site-builder primitives (band panels, grouped design controls).
 */
import React from 'react'
import type { SectionCatalogDef } from '@/lib/design-system-catalog'
import { hasOverrideAtPath, readPathLeaf } from './design-inherit-ui'
import { SECTION_DESIGN_COLOR_KEYS } from '@/lib/design-system-catalog'
import { paddingKeys } from '@/lib/responsive-band-padding'
import { readAdminSetting } from '@/lib/settings-admin'

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

export function BandPaddingTriple({
  baseKey,
  label,
  settings,
  onChange,
}: {
  baseKey: string
  label?: string
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const k = paddingKeys(baseKey)
  const legacy = readAdminSetting(settings, k.legacy)
  const cols: { id: DeviceId; key: string; title: string }[] = [
    { id: 'desktop', key: k.desktop, title: 'Desktop' },
    { id: 'tablet', key: k.tablet, title: 'Tablet' },
    { id: 'mobile', key: k.mobile, title: 'Mobile' },
  ]
  return (
    <div className="s2-band-padding-triple">
      <span className="s2-band-field__label">{label || 'Top / bottom padding (px or CSS)'}</span>
      <div className="s2-band-responsive-row">
        {cols.map((col) => {
          const val = readAdminSetting(settings, col.key)
          const inherited = val === '' && legacy === ''
          return (
            <BandTextField
              key={col.id}
              label={col.title}
              placeholder={legacy || 'Built-in'}
              value={val}
              inherited={inherited}
              onChange={(v) => onChange(col.key, v)}
              onClear={val !== '' ? () => onChange(col.key, '') : undefined}
            />
          )
        })}
      </div>
    </div>
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
  if (
    section.designSettingFields?.some((f) => {
      if ((settings[f.key] ?? '').trim() !== '') return true
      if (f.key.includes('_padding')) {
        const k = paddingKeys(f.key)
        return [k.desktop, k.tablet, k.mobile].some((pk) => (settings[pk] ?? '').trim() !== '')
      }
      return false
    })
  ) {
    return true
  }
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
  _page: { subtitle: 'Page-wide defaults — typography, width, and shared chrome.' },
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
  process: { subtitle: 'Numbered steps and process explanation.' },
  faq: { subtitle: 'Questions and answers accordion.' },
  faq_cta: { subtitle: 'Support call-to-action below the FAQ list.' },
  newsletter: { subtitle: 'Email capture and lead form.' },
  intro: { subtitle: 'Page hero — title, subtitle, and trust meta.' },
  directory: { subtitle: 'Category filters, search, and service cards.' },
  contact: { subtitle: 'Contact channels, hours, and inquiry form.' },
  pricing: { subtitle: 'Plan cards loaded from Pricing admin.' },
  compare: { subtitle: 'Feature matrix across plans.' },
  about: { subtitle: 'Story, imagery, and highlight stats.' },
  values: { subtitle: 'Core value cards with icons.' },
  team: { subtitle: 'Leadership and specialist profiles.' },
  cta: { subtitle: 'Dark-band conversion block at page foot.' },
  hiw_cta: { subtitle: 'Consultation callout after the steps.' },
  feed: { subtitle: 'Published articles from Blog admin.' },
  content: { subtitle: 'Primary page body or hub content.' },
  marquee: { subtitle: 'Scrolling announcement above service hero.' },
  wizard: { subtitle: 'Multi-step booking inquiry form.' },
  tagline: { subtitle: 'Short quote strip between bands.' },
  partners: { subtitle: 'Partner logos or names.' },
  press: { subtitle: 'Press and media mentions.' },
  awards: { subtitle: 'Awards and certification badges.' },
  locations: { subtitle: 'Office and city presence row.' },
  footer: { subtitle: 'Copyright, brand, and footer links.' },
  app: { subtitle: 'Mobile app download band.' },
}
