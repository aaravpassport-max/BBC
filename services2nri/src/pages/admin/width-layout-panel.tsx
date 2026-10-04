/**
 * Admin — Width & Layout Management (global, page type, page, section).
 */
import React, { useMemo, useState } from 'react'
import { WIDTH_PAGE_TYPES, WIDTH_SECTIONS } from '@/lib/width-layout'
import { PxTokenField, PercentField, parsePx, stripPxForInput, WIDTH_DESKTOP_PRESETS } from './design-admin-fields'

type PatchFn = (path: string[], value: unknown) => void

const GLOBAL_KEYS = [
  'page_max', 'content_max', 'inner_max', 'full_bleed',
  'section_standard', 'section_wide', 'section_narrow', 'section_compact',
  'padding_x', 'min_width', 'max_width_cap',
] as const

const BP = ['desktop', 'laptop', 'tablet', 'mobile'] as const

type WidthConfig = Record<string, unknown>

function ResponsiveField({
  label,
  fieldKey,
  value,
  onChange,
  onReset,
  inherited,
}: {
  label: string
  fieldKey: string
  value: unknown
  onChange: (v: Record<string, string> | string) => void
  onReset?: () => void
  inherited?: boolean
}) {
  const isPercent = fieldKey === 'full_bleed'
  const isResponsive = typeof value === 'object' && value !== null && !Array.isArray(value)
  const formatOut = (raw: string) => (isPercent ? (raw.includes('%') ? raw : `${raw}%`) : parsePx(raw))
  return (
    <fieldset style={{ border: '1px solid #E2E8F0', borderRadius: 10, padding: 12, margin: 0 }}>
      <legend style={{ fontWeight: 700, fontSize: 13, padding: '0 6px' }}>
        {label}{' '}
        <span style={{ fontWeight: 500, color: inherited ? '#64748B' : '#059669', fontSize: 11 }}>
          {inherited ? 'Inherited' : 'Overridden'}
        </span>
      </legend>
      {isResponsive ? (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 8 }}>
          {BP.map((bp) => (
            <div key={bp}>
              {isPercent ? (
                <PercentField
                  label={bp}
                  value={String((value as Record<string, string>)[bp] ?? '')}
                  onChange={(v) => onChange({ ...(value as Record<string, string>), [bp]: v })}
                />
              ) : (
                <PxTokenField
                  label={bp}
                  value={String((value as Record<string, string>)[bp] ?? '')}
                  onChange={(v) => onChange({ ...(value as Record<string, string>), [bp]: formatOut(v) })}
                />
              )}
            </div>
          ))}
        </div>
      ) : isPercent ? (
        <PercentField label="Value" value={String(value ?? '')} onChange={(v) => onChange(v)} />
      ) : (
        <PxTokenField
          label="All breakpoints"
          value={String(value ?? '')}
          onChange={(v) => onChange(formatOut(stripPxForInput(v) ? `${stripPxForInput(v)}px` : v))}
        />
      )}
      {onReset && (
        <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" style={{ marginTop: 8 }} onClick={onReset}>
          Reset to inherited
        </button>
      )}
    </fieldset>
  )
}

export type WidthLayoutFocus = {
  scope: 'global' | 'page_type' | 'page' | 'service_section' | 'section'
  selectedType?: string
  selectedPage?: string
  selectedSection?: string
}

export function WidthLayoutPanel({
  config,
  patch,
  focus,
}: {
  config: WidthConfig
  patch: PatchFn
  focus?: WidthLayoutFocus | null
}) {
  const widths = (config.widths || {}) as WidthConfig
  const global = (widths.global || {}) as Record<string, unknown>
  const pageTypes = (widths.page_types || {}) as Record<string, Record<string, unknown>>
  const pages = (widths.pages || {}) as Record<string, Record<string, unknown>>
  const sections = (widths.sections || {}) as Record<string, Record<string, unknown>>
  const servicePage = (widths.service_page || {}) as Record<string, unknown>
  const serviceSections = (servicePage.sections || {}) as Record<string, Record<string, unknown>>

  const [scope, setScope] = useState<'global' | 'page_type' | 'page' | 'service_section' | 'section'>('global')
  const [selectedType, setSelectedType] = useState<string>('home')
  const [selectedPage, setSelectedPage] = useState<string>('about')
  const [selectedSection, setSelectedSection] = useState<string>('hero')

  React.useEffect(() => {
    if (!focus) return
    setScope(focus.scope)
    if (focus.selectedType) setSelectedType(focus.selectedType)
    if (focus.selectedPage) setSelectedPage(focus.selectedPage)
    if (focus.selectedSection) setSelectedSection(focus.selectedSection)
  }, [focus])

  const activeLayer = useMemo(() => {
    if (scope === 'global') return global
    if (scope === 'page_type') return pageTypes[selectedType] || {}
    if (scope === 'page') return pages[selectedPage] || {}
    if (scope === 'service_section') return serviceSections[selectedSection] || {}
    return sections[selectedSection] || {}
  }, [scope, global, pageTypes, pages, sections, serviceSections, selectedType, selectedPage, selectedSection])

  const basePath = useMemo(() => {
    if (scope === 'global') return ['widths', 'global']
    if (scope === 'page_type') return ['widths', 'page_types', selectedType]
    if (scope === 'page') return ['widths', 'pages', selectedPage]
    if (scope === 'service_section') return ['widths', 'service_page', 'sections', selectedSection]
    return ['widths', 'sections', selectedSection]
  }, [scope, selectedType, selectedPage, selectedSection])

  function applyDesktopPreset(preset: (typeof WIDTH_DESKTOP_PRESETS)[0]) {
    for (const [key, num] of Object.entries(preset.values)) {
      const cur = activeLayer[key]
      const responsive =
        typeof cur === 'object' && cur !== null && !Array.isArray(cur)
          ? { ...(cur as Record<string, string>) }
          : { desktop: '', laptop: '', tablet: '', mobile: '' }
      responsive.desktop = `${num}px`
      responsive.laptop = `${num}px`
      patch([...basePath, key], responsive)
    }
  }

  return (
    <div style={{ display: 'grid', gap: 20 }}>
      <p className="s2-t-body" style={{ maxWidth: 720, margin: 0 }}>
        All widths use <strong>px</strong> (except full bleed = %). Hierarchy:{' '}
        <strong>Global → Page type → Page → Section</strong>. Hero/service section max maps to{' '}
        <code>--s2-width-sec-hero-max</code> on the live site.
      </p>

      {scope === 'global' && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
          <span style={{ fontSize: 13, fontWeight: 600 }}>Desktop presets:</span>
          {WIDTH_DESKTOP_PRESETS.map((p) => (
            <button key={p.label} type="button" className="s2-btn s2-btn--sm s2-btn--outline" onClick={() => applyDesktopPreset(p)}>
              {p.label}
            </button>
          ))}
        </div>
      )}

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        {(['global', 'page_type', 'page', 'service_section', 'section'] as const).map((s) => (
          <button
            key={s}
            type="button"
            className={`s2-btn s2-btn--sm ${scope === s ? 's2-btn--primary' : 's2-btn--outline'}`}
            onClick={() => setScope(s)}
          >
            {s.replace(/_/g, ' ')}
          </button>
        ))}
      </div>

      {scope === 'page_type' && (
        <label style={{ fontSize: 13 }}>
          Page type
          <select value={selectedType} onChange={(e) => setSelectedType(e.target.value)} style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, minWidth: 220 }}>
            {WIDTH_PAGE_TYPES.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </select>
        </label>
      )}

      {(scope === 'page') && (
        <label style={{ fontSize: 13 }}>
          Page slug
          <input
            type="text"
            value={selectedPage}
            onChange={(e) => setSelectedPage(e.target.value.replace(/\s+/g, '-').toLowerCase())}
            placeholder="about, contact, home…"
            style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, minWidth: 280 }}
          />
        </label>
      )}

      {(scope === 'section' || scope === 'service_section') && (
        <label style={{ fontSize: 13 }}>
          Section
          <select value={selectedSection} onChange={(e) => setSelectedSection(e.target.value)} style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, minWidth: 280 }}>
            {WIDTH_SECTIONS.map((s) => (
              <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
            ))}
          </select>
        </label>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 }}>
        {GLOBAL_KEYS.map((key) => (
          <ResponsiveField
            key={key}
            fieldKey={key}
            label={key.replace(/_/g, ' ')}
            value={activeLayer[key]}
            inherited={scope !== 'global' && activeLayer[key] == null}
            onChange={(v) => patch([...basePath, key], v)}
            onReset={scope !== 'global' ? () => patch([...basePath, key], undefined) : undefined}
          />
        ))}
      </div>

      {scope === 'global' && (
        <p style={{ fontSize: 12, color: '#64748B' }}>
          Tip: <code>container_max</code> in Spacing tab stays synced with <strong>page_max (desktop)</strong> on publish for legacy components.
        </p>
      )}
    </div>
  )
}
