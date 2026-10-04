/**
 * Admin — Width & Layout (task-first UX: pick what to change, then edit grouped controls).
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

type GlobalKey = (typeof GLOBAL_KEYS)[number]

const BP = ['desktop', 'laptop', 'tablet', 'mobile'] as const

type WidthConfig = Record<string, unknown>

const FIELD_META: Record<
  GlobalKey,
  { label: string; hint: string; group: 'shell' | 'content' | 'sections' | 'advanced'; heroRelevant?: boolean }
> = {
  page_max: {
    label: 'Page container max width',
    hint: 'Outer shell — header, footer, and full-width sections align to this.',
    group: 'shell',
    heroRelevant: true,
  },
  padding_x: {
    label: 'Side padding',
    hint: 'Horizontal inset on small screens and inside the page shell.',
    group: 'shell',
  },
  content_max: {
    label: 'Main content column',
    hint: 'Articles, long copy, and primary content blocks.',
    group: 'content',
    heroRelevant: true,
  },
  inner_max: {
    label: 'Narrow prose / forms',
    hint: 'Subcopy, legal text, compact forms.',
    group: 'content',
  },
  section_standard: {
    label: 'Default section inner width',
    hint: 'Most marketing sections use this unless a section override applies.',
    group: 'sections',
    heroRelevant: true,
  },
  section_wide: {
    label: 'Wide sections',
    hint: 'Compare tables, feature grids, full-bleed inner content.',
    group: 'sections',
  },
  section_narrow: {
    label: 'Narrow sections',
    hint: 'FAQ lists, testimonials, tight columns.',
    group: 'sections',
  },
  section_compact: {
    label: 'Compact sections',
    hint: 'Search bars, subtitles, utility rows.',
    group: 'sections',
  },
  full_bleed: {
    label: 'Full-bleed width',
    hint: 'Percentage of viewport for edge-to-edge bands (advanced).',
    group: 'advanced',
  },
  min_width: {
    label: 'Minimum layout width',
    hint: 'Prevents layout from shrinking below this (rare).',
    group: 'advanced',
  },
  max_width_cap: {
    label: 'Hard max cap',
    hint: 'Absolute ceiling on any resolved width token.',
    group: 'advanced',
  },
}

const GROUP_LABELS: Record<(typeof FIELD_META)[GlobalKey]['group'], string> = {
  shell: 'Page shell',
  content: 'Content columns',
  sections: 'Section presets',
  advanced: 'Advanced',
}

type WidthTaskId =
  | 'site_defaults'
  | 'by_page_type'
  | 'single_page'
  | 'service_hero'
  | 'service_block'
  | 'marketing_section'

const TASKS: {
  id: WidthTaskId
  title: string
  description: string
  scope: WidthLayoutFocus['scope']
  defaultSection?: string
}[] = [
  {
    id: 'site_defaults',
    title: 'Whole site defaults',
    description: 'Start here — every page inherits these unless you override a specific page or section.',
    scope: 'global',
  },
  {
    id: 'service_hero',
    title: 'Service page hero width',
    description: 'Controls hero content width on all /service/… pages. For one service only, use Service Page Builder → Hero Settings.',
    scope: 'service_section',
    defaultSection: 'hero',
  },
  {
    id: 'by_page_type',
    title: 'By page type',
    description: 'Home, blog, city pages, etc. — override defaults for that template only.',
    scope: 'page_type',
  },
  {
    id: 'single_page',
    title: 'One specific page',
    description: 'Slug-based override (about, contact, or any marketing URL path).',
    scope: 'page',
  },
  {
    id: 'service_block',
    title: 'Other service page sections',
    description: 'FAQ, wizard, pricing blocks on service detail pages — not the global homepage.',
    scope: 'service_section',
  },
  {
    id: 'marketing_section',
    title: 'Homepage & global sections',
    description: 'Hero, cities, newsletter on the home page and shared section keys site-wide.',
    scope: 'section',
  },
]

function taskFromFocus(focus: WidthLayoutFocus): WidthTaskId {
  if (focus.scope === 'global') return 'site_defaults'
  if (focus.scope === 'page_type') return 'by_page_type'
  if (focus.scope === 'page') return 'single_page'
  if (focus.scope === 'service_section') {
    return focus.selectedSection === 'hero' ? 'service_hero' : 'service_block'
  }
  return 'marketing_section'
}

function focusFromTask(
  taskId: WidthTaskId,
  selectedType: string,
  selectedPage: string,
  selectedSection: string,
): WidthLayoutFocus {
  const task = TASKS.find((t) => t.id === taskId)!
  const section =
    task.defaultSection ??
    (task.scope === 'service_section' || task.scope === 'section' ? selectedSection : undefined)
  return {
    scope: task.scope,
    selectedType: task.scope === 'page_type' ? selectedType : undefined,
    selectedPage: task.scope === 'page' ? selectedPage : undefined,
    selectedSection: section,
  }
}

function readDesktopPx(value: unknown): number | null {
  if (value == null || value === 'inherit') return null
  if (typeof value === 'object' && !Array.isArray(value)) {
    const raw = String((value as Record<string, string>).desktop ?? Object.values(value as Record<string, string>)[0] ?? '')
    const n = parseInt(stripPxForInput(raw), 10)
    return Number.isFinite(n) ? n : null
  }
  const n = parseInt(stripPxForInput(String(value)), 10)
  return Number.isFinite(n) ? n : null
}

function effectiveDesktop(layer: Record<string, unknown>, global: Record<string, unknown>, key: GlobalKey): number | null {
  return readDesktopPx(layer[key]) ?? readDesktopPx(global[key])
}

function WidthPreview({
  global,
  layer,
  title,
}: {
  global: Record<string, unknown>
  layer: Record<string, unknown>
  title: string
}) {
  const page = effectiveDesktop(layer, global, 'page_max') ?? 1200
  const content = effectiveDesktop(layer, global, 'content_max') ?? 960
  const section = effectiveDesktop(layer, global, 'section_standard') ?? 1100
  const max = Math.max(page, content, section, 400)
  const bar = (w: number, color: string, label: string) => (
    <div key={label} style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12 }}>
      <span style={{ width: 120, color: '#64748B' }}>{label}</span>
      <div style={{ flex: 1, height: 10, background: '#E2E8F0', borderRadius: 999, overflow: 'hidden' }}>
        <div style={{ width: `${Math.min(100, (w / max) * 100)}%`, height: '100%', background: color, borderRadius: 999 }} />
      </div>
      <span style={{ width: 48, textAlign: 'right', fontWeight: 600 }}>{w}px</span>
    </div>
  )
  return (
    <div
      style={{
        border: '1px solid #E2E8F0',
        borderRadius: 12,
        padding: 16,
        background: '#F8FAFC',
        display: 'grid',
        gap: 10,
      }}
    >
      <div style={{ fontWeight: 700, fontSize: 14 }}>{title}</div>
      <p style={{ margin: 0, fontSize: 12, color: '#64748B' }}>Desktop preview — inherited values count toward the bars.</p>
      {bar(page, '#2563EB', 'Page shell')}
      {bar(section, '#7C3AED', 'Section default')}
      {bar(content, '#059669', 'Content column')}
    </div>
  )
}

function ResponsiveField({
  fieldKey,
  meta,
  value,
  onChange,
  onReset,
  inherited,
  simple,
}: {
  fieldKey: GlobalKey
  meta: (typeof FIELD_META)[GlobalKey]
  value: unknown
  onChange: (v: Record<string, string> | string) => void
  onReset?: () => void
  inherited?: boolean
  simple: boolean
}) {
  const [expanded, setExpanded] = useState(false)
  const isPercent = fieldKey === 'full_bleed'
  const isResponsive = typeof value === 'object' && value !== null && !Array.isArray(value)
  const formatOut = (raw: string) => (isPercent ? (raw.includes('%') ? raw : `${raw}%`) : parsePx(raw))

  const desktopOnly = simple && !expanded && isResponsive

  return (
    <div
      style={{
        border: '1px solid #E2E8F0',
        borderRadius: 10,
        padding: 12,
        background: inherited ? '#fff' : '#F0FDF4',
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'flex-start', marginBottom: 8 }}>
        <div>
          <div style={{ fontWeight: 700, fontSize: 13 }}>{meta.label}</div>
          <div style={{ fontSize: 11, color: '#64748B', marginTop: 2 }}>{meta.hint}</div>
        </div>
        <span
          style={{
            fontWeight: 600,
            fontSize: 11,
            color: inherited ? '#64748B' : '#059669',
            whiteSpace: 'nowrap',
          }}
        >
          {inherited ? 'Using inherited' : 'Custom here'}
        </span>
      </div>

      {desktopOnly ? (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'flex-end' }}>
          {isPercent ? (
            <PercentField
              label="Desktop"
              value={String((value as Record<string, string>).desktop ?? '')}
              onChange={(v) => onChange({ ...(value as Record<string, string>), desktop: v })}
            />
          ) : (
            <PxTokenField
              label="Desktop"
              value={String((value as Record<string, string>).desktop ?? '')}
              onChange={(v) => onChange({ ...(value as Record<string, string>), desktop: formatOut(v) })}
            />
          )}
          <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={() => setExpanded(true)}>
            All breakpoints…
          </button>
        </div>
      ) : isResponsive ? (
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

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 8 }}>
        {simple && expanded && (
          <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={() => setExpanded(false)}>
            Simple view
          </button>
        )}
        {onReset && (
          <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={onReset}>
            Clear override (inherit)
          </button>
        )}
      </div>
    </div>
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

  const [taskId, setTaskId] = useState<WidthTaskId>('site_defaults')
  const [selectedType, setSelectedType] = useState<string>('home')
  const [selectedPage, setSelectedPage] = useState<string>('about')
  const [selectedSection, setSelectedSection] = useState<string>('hero')
  const [simpleMode, setSimpleMode] = useState(true)
  const [showAdvancedFields, setShowAdvancedFields] = useState(false)

  const task = TASKS.find((t) => t.id === taskId)!
  const scope = task.scope

  React.useEffect(() => {
    if (!focus) return
    setTaskId(taskFromFocus(focus))
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

  const visibleKeys = useMemo(() => {
    if (taskId === 'service_hero') {
      return GLOBAL_KEYS.filter((k) => FIELD_META[k].heroRelevant)
    }
    if (!showAdvancedFields) {
      return GLOBAL_KEYS.filter((k) => FIELD_META[k].group !== 'advanced')
    }
    return [...GLOBAL_KEYS]
  }, [taskId, showAdvancedFields])

  const keysByGroup = useMemo(() => {
    const groups: Record<string, GlobalKey[]> = { shell: [], content: [], sections: [], advanced: [] }
    for (const key of visibleKeys) {
      groups[FIELD_META[key].group].push(key)
    }
    return groups
  }, [visibleKeys])

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

  const breadcrumb = useMemo(() => {
    const parts = ['Global defaults']
    if (scope === 'page_type') parts.push(`Page type: ${selectedType}`)
    if (scope === 'page') parts.push(`Page: /${selectedPage}`)
    if (scope === 'service_section') parts.push(`Service page → ${selectedSection.replace(/_/g, ' ')}`)
    if (scope === 'section') parts.push(`Section: ${selectedSection.replace(/_/g, ' ')}`)
    return parts.join(' → ')
  }, [scope, selectedType, selectedPage, selectedSection])

  return (
    <div style={{ display: 'grid', gap: 20, maxWidth: 960 }}>
      <div style={{ display: 'grid', gap: 8 }}>
        <h3 className="s2-t-h3" style={{ margin: 0 }}>
          Width &amp; layout
        </h3>
        <p className="s2-t-body" style={{ margin: 0, color: '#475569' }}>
          Pick <strong>what you want to change</strong>, adjust a few widths, then publish. You do not need every token — most sites only touch{' '}
          <strong>site defaults</strong> or <strong>service hero width</strong>.
        </p>
      </div>

      <div style={{ display: 'grid', gap: 10 }}>
        <span style={{ fontSize: 13, fontWeight: 700 }}>What are you changing?</span>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 10 }}>
          {TASKS.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => {
                setTaskId(t.id)
                if (t.defaultSection) setSelectedSection(t.defaultSection)
              }}
              style={{
                textAlign: 'left',
                padding: 14,
                borderRadius: 12,
                border: taskId === t.id ? '2px solid #2563EB' : '1px solid #E2E8F0',
                background: taskId === t.id ? '#EFF6FF' : '#fff',
                cursor: 'pointer',
              }}
            >
              <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 4 }}>{t.title}</div>
              <div style={{ fontSize: 12, color: '#64748B', lineHeight: 1.4 }}>{t.description}</div>
            </button>
          ))}
        </div>
      </div>

      <div
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          gap: 12,
          alignItems: 'center',
          padding: '10px 14px',
          background: '#F1F5F9',
          borderRadius: 10,
          fontSize: 12,
        }}
      >
        <span>
          <strong>Inheritance:</strong> {breadcrumb}
        </span>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, marginLeft: 'auto', cursor: 'pointer' }}>
          <input type="checkbox" checked={simpleMode} onChange={(e) => setSimpleMode(e.target.checked)} />
          Simple mode (desktop only)
        </label>
      </div>

      {taskId === 'site_defaults' && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
          <span style={{ fontSize: 13, fontWeight: 600 }}>Quick site presets (desktop):</span>
          {WIDTH_DESKTOP_PRESETS.map((p) => (
            <button key={p.label} type="button" className="s2-btn s2-btn--sm s2-btn--outline" onClick={() => applyDesktopPreset(p)}>
              {p.label.split('(')[0].trim()}
            </button>
          ))}
        </div>
      )}

      {scope === 'page_type' && (
        <label style={{ fontSize: 13, maxWidth: 320 }}>
          Page type
          <select
            value={selectedType}
            onChange={(e) => setSelectedType(e.target.value)}
            style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, width: '100%' }}
          >
            {WIDTH_PAGE_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </label>
      )}

      {scope === 'page' && (
        <label style={{ fontSize: 13, maxWidth: 360 }}>
          Page slug (URL path without leading slash)
          <input
            type="text"
            value={selectedPage}
            onChange={(e) => setSelectedPage(e.target.value.replace(/\s+/g, '-').toLowerCase())}
            placeholder="about, contact, faq…"
            style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, width: '100%' }}
          />
        </label>
      )}

      {(scope === 'section' || scope === 'service_section') && taskId !== 'service_hero' && (
        <label style={{ fontSize: 13, maxWidth: 360 }}>
          Section block
          <select
            value={selectedSection}
            onChange={(e) => setSelectedSection(e.target.value)}
            style={{ display: 'block', marginTop: 6, padding: 8, borderRadius: 8, width: '100%' }}
          >
            {WIDTH_SECTIONS.map((s) => (
              <option key={s} value={s}>
                {s.replace(/_/g, ' ')}
              </option>
            ))}
          </select>
        </label>
      )}

      <WidthPreview global={global} layer={activeLayer} title={`Preview — ${task.title}`} />

      {taskId === 'service_hero' && (
        <p style={{ margin: 0, fontSize: 13, padding: 12, background: '#FFFBEB', borderRadius: 10, border: '1px solid #FDE68A' }}>
          These values apply to <strong>every</strong> service page hero. To change one service, open{' '}
          <strong>Service Page Builder → Hero Settings → Hero content max width</strong> instead.
        </p>
      )}

      {(['shell', 'content', 'sections', 'advanced'] as const).map((group) => {
        const keys = keysByGroup[group]
        if (!keys.length) return null
        return (
          <section key={group} style={{ display: 'grid', gap: 12 }}>
            <h4 style={{ margin: 0, fontSize: 15 }}>{GROUP_LABELS[group]}</h4>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 }}>
              {keys.map((key) => (
                <ResponsiveField
                  key={key}
                  fieldKey={key}
                  meta={FIELD_META[key]}
                  value={activeLayer[key]}
                  inherited={scope !== 'global' && activeLayer[key] == null}
                  simple={simpleMode}
                  onChange={(v) => patch([...basePath, key], v)}
                  onReset={scope !== 'global' ? () => patch([...basePath, key], undefined) : undefined}
                />
              ))}
            </div>
          </section>
        )
      })}

      {taskId !== 'service_hero' && (
        <button
          type="button"
          className="s2-btn s2-btn--ghost s2-btn--sm"
          onClick={() => setShowAdvancedFields((v) => !v)}
          style={{ justifySelf: 'start' }}
        >
          {showAdvancedFields ? 'Hide advanced tokens' : 'Show advanced tokens (full bleed, min/max caps)'}
        </button>
      )}

      {scope === 'global' && (
        <p style={{ fontSize: 12, color: '#64748B', margin: 0 }}>
          After publish, the Spacing tab keeps legacy <code>container_max</code> in sync with <strong>page container max width (desktop)</strong>.
        </p>
      )}
    </div>
  )
}
