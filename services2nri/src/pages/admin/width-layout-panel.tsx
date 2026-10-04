/**
 * Layout Studio — enterprise width & layout control (Design System tab).
 */
import React, { useMemo, useState } from 'react'
import {
  mergeWidthContext,
  resolveSectionLayer,
  type PageWidthContext,
} from '@/lib/width-layout'
import { PercentField, parsePx, stripPxForInput, WIDTH_DESKTOP_PRESETS } from './design-admin-fields'
import {
  BP,
  BP_META,
  countLayerOverrides,
  effectiveBp,
  FIELD_META,
  GLOBAL_KEYS,
  GROUP_LABELS,
  pageTypeOptions,
  PREVIEW_SCENES,
  sectionOptions,
  TASKS,
  taskFromFocus,
  homeHeroLayer,
  type BreakpointId,
  type GlobalKey,
  type WidthConfig,
  type WidthLayoutFocus,
  type WidthTaskId,
  PAGE_TYPE_LABELS,
} from './width-layout-shared'
type PatchFn = (path: string[], value: unknown) => void

export type { WidthLayoutFocus }

const NAV_ICONS: Record<WidthTaskId, string> = {
  site_defaults: '◆',
  home_hero: '⌂',
  service_hero: '▣',
  marketing_section: '☰',
  service_block: '☰',
  by_page_type: '▤',
  single_page: '⌁',
}

const HOME_HERO_WIDTH_PRESETS = [
  { label: 'Full width', content: '100%', wide: '100%' },
  { label: 'Wide 1320px', content: '1320', wide: '1320' },
  { label: 'Standard 1200px', content: '1200', wide: '1200' },
  { label: 'Contained 960px', content: '960', wide: '960' },
] as const

const PRESET_CARDS = [
  { preset: WIDTH_DESKTOP_PRESETS[0], title: 'Marketplace', sub: '1200px shell · balanced catalog' },
  { preset: WIDTH_DESKTOP_PRESETS[1], title: 'Compact', sub: '1080px shell · dense UI' },
  { preset: WIDTH_DESKTOP_PRESETS[2], title: 'Wide marketing', sub: '1320px shell · hero-forward' },
]

function ensureResponsive(value: unknown): Record<string, string> {
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    return { desktop: '', laptop: '', tablet: '', mobile: '', ...(value as Record<string, string>) }
  }
  if (value == null || value === '') {
    return { desktop: '', laptop: '', tablet: '', mobile: '' }
  }
  const s = String(value)
  return { desktop: s, laptop: s, tablet: s, mobile: s }
}

function readRawAtBp(value: unknown, bp: BreakpointId): string {
  if (value == null) return ''
  if (typeof value === 'object' && !Array.isArray(value)) {
    const obj = value as Record<string, string>
    return String(obj[bp] ?? obj.desktop ?? '')
  }
  return String(value)
}

function LayoutWireframe({
  pagePx,
  sectionPx,
  contentPx,
  paddingPx,
  url,
}: {
  pagePx: number
  sectionPx: number
  contentPx: number
  paddingPx: number
  url: string
}) {
  const ref = 1440
  const pct = (px: number) => `${Math.min(100, (px / ref) * 100)}%`
  const padPct = `${Math.min(12, (paddingPx / ref) * 100)}%`

  return (
    <div className="s2-wls__browser">
      <div className="s2-wls__browser-chrome">
        <span className="s2-wls__dot" />
        <span className="s2-wls__dot" />
        <span className="s2-wls__dot" />
        <div className="s2-wls__browser-url">{url}</div>
      </div>
      <div className="s2-wls__viewport">
        <div className="s2-wls__wire-page">
          <div className="s2-wls__wire-bar s2-wls__wire-bar--page" style={{ width: pct(pagePx) }}>
            PAGE {pagePx}px
          </div>
          <div className="s2-wls__wire-bar s2-wls__wire-bar--section" style={{ width: pct(sectionPx) }}>
            SECTION {sectionPx}px
          </div>
          <div className="s2-wls__wire-bar s2-wls__wire-bar--content" style={{ width: pct(contentPx) }}>
            CONTENT {contentPx}px
          </div>
          <div className="s2-wls__wire-bar s2-wls__wire-bar--pad" style={{ width: `calc(${pct(pagePx)} - ${padPct} * 2)` }}>
            Gutter {paddingPx}px
          </div>
        </div>
      </div>
    </div>
  )
}

function WidthControl({
  fieldKey,
  bp,
  value,
  inherited,
  global,
  onChange,
  onReset,
  onSyncBreakpoints,
}: {
  fieldKey: GlobalKey
  bp: BreakpointId
  value: unknown
  inherited: boolean
  global: Record<string, unknown>
  onChange: (v: Record<string, string> | string) => void
  onReset?: () => void
  onSyncBreakpoints: () => void
}) {
  const meta = FIELD_META[fieldKey]
  const isPercent = fieldKey === 'full_bleed'

  function readBpPxLocal(v: unknown, breakpoint: BreakpointId): number | null {
    const raw = readRawAtBp(v, breakpoint)
    if (!raw) return null
    const n = parseInt(stripPxForInput(raw), 10)
    return Number.isFinite(n) ? n : null
  }

  const effective = inherited
    ? effectiveBp({}, global, fieldKey, bp)
    : readBpPxLocal(value, bp) ?? effectiveBp({}, global, fieldKey, bp)
  const displayNum = effective ?? (meta.slider?.min ?? 960)

  function setAtBp(next: string) {
    const responsive = ensureResponsive(value)
    responsive[bp] = isPercent ? next : parsePx(next)
    onChange(responsive)
  }

  const rawCurrent = readRawAtBp(value, bp)

  if (isPercent) {
    return (
      <div className={`s2-wls__control ${!inherited ? 'is-custom' : ''}`}>
        <div className="s2-wls__control-top">
          <div>
            <div className="s2-wls__control-label">{meta.label}</div>
            <div className="s2-wls__control-hint">{meta.hint}</div>
          </div>
          <span className={`s2-wls__status ${inherited ? 's2-wls__status--inherit' : 's2-wls__status--custom'}`}>
            {inherited ? 'Inherited' : 'Custom'}
          </span>
        </div>
        <PercentField label={BP_META[bp].label} value={rawCurrent || String(effective ?? '')} onChange={(v) => setAtBp(v)} />
        <div className="s2-wls__control-actions">
          {onReset && (
            <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={onReset}>
              Clear override
            </button>
          )}
        </div>
      </div>
    )
  }

  const slider = meta.slider
  const numVal = readBpPxLocal(value, bp) ?? displayNum

  return (
    <div className={`s2-wls__control ${!inherited ? 'is-custom' : ''}`}>
      <div className="s2-wls__control-top">
        <div>
          <div className="s2-wls__control-label">{meta.label}</div>
          <div className="s2-wls__control-hint">{meta.hint}</div>
        </div>
        <span className={`s2-wls__status ${inherited ? 's2-wls__status--inherit' : 's2-wls__status--custom'}`}>
          {inherited ? 'Inherited' : 'Custom'}
        </span>
      </div>
      {slider ? (
        <div className="s2-wls__slider-row">
          <input
            type="range"
            className="s2-wls__slider"
            min={slider.min}
            max={slider.max}
            step={slider.step ?? 1}
            value={numVal}
            onChange={(e) => setAtBp(`${e.target.value}px`)}
          />
          <div className="s2-wls__value-box">
            <input
              type="number"
              min={slider.min}
              max={slider.max}
              step={slider.step ?? 1}
              value={numVal}
              onChange={(e) => setAtBp(`${e.target.value}px`)}
            />
            <span style={{ fontSize: 12, fontWeight: 700, color: '#64748B' }}>px</span>
          </div>
        </div>
      ) : (
        <input
          type="text"
          value={rawCurrent}
          onChange={(e) => setAtBp(parsePx(e.target.value))}
          style={{ width: '100%', padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
        />
      )}
      <div className="s2-wls__control-actions">
        <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={onSyncBreakpoints}>
          Apply {BP_META[bp].label} to all breakpoints
        </button>
        {onReset && (
          <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={onReset}>
            Clear override
          </button>
        )}
      </div>
    </div>
  )
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
  const [selectedType, setSelectedType] = useState('home')
  const [selectedPage, setSelectedPage] = useState('about')
  const [selectedSection, setSelectedSection] = useState('hero')
  const [activeBp, setActiveBp] = useState<BreakpointId>('desktop')
  const [showAdvanced, setShowAdvanced] = useState(false)
  const [previewSceneId, setPreviewSceneId] = useState<(typeof PREVIEW_SCENES)[number]['id']>('home')

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
    if (scope === 'page_type_section') return homeHeroLayer(pageTypes)
    if (scope === 'page_type') return pageTypes[selectedType] || {}
    if (scope === 'page') return pages[selectedPage] || {}
    if (scope === 'service_section') return serviceSections[selectedSection] || {}
    return sections[selectedSection] || {}
  }, [scope, global, pageTypes, pages, sections, serviceSections, selectedType, selectedPage, selectedSection])

  const basePath = useMemo(() => {
    if (scope === 'global') return ['widths', 'global']
    if (scope === 'page_type_section') return ['widths', 'page_types', 'home', 'sections', 'hero']
    if (scope === 'page_type') return ['widths', 'page_types', selectedType]
    if (scope === 'page') return ['widths', 'pages', selectedPage]
    if (scope === 'service_section') return ['widths', 'service_page', 'sections', selectedSection]
    return ['widths', 'sections', selectedSection]
  }, [scope, selectedType, selectedPage, selectedSection])

  const overrideCount = countLayerOverrides(activeLayer)

  const visibleKeys = useMemo(() => {
    if (taskId === 'home_hero') {
      return ['content_max', 'section_wide', 'full_bleed'] as GlobalKey[]
    }
    if (taskId === 'service_hero') {
      return GLOBAL_KEYS.filter((k) => FIELD_META[k].heroRelevant)
    }
    if (!showAdvanced) {
      return GLOBAL_KEYS.filter((k) => FIELD_META[k].group !== 'advanced')
    }
    return [...GLOBAL_KEYS]
  }, [taskId, showAdvanced])

  const keysByGroup = useMemo(() => {
    const groups: Record<string, GlobalKey[]> = { shell: [], content: [], sections: [], advanced: [] }
    for (const key of visibleKeys) {
      groups[FIELD_META[key].group].push(key)
    }
    return groups
  }, [visibleKeys])

  function applyHomeHeroPreset(preset: (typeof HOME_HERO_WIDTH_PRESETS)[number]) {
    const isFull = preset.content === '100%'
    const responsive = (px: string) => {
      const out = ensureResponsive(undefined)
      for (const bp of BP) {
        out[bp] = px
      }
      return out
    }
    const layer: Record<string, unknown> = {
      ...(typeof activeLayer === 'object' && activeLayer !== null ? activeLayer : {}),
      content_max: responsive(isFull ? '100%' : `${preset.content}px`),
      section_wide: responsive(isFull ? '100%' : `${preset.wide}px`),
    }
    if (isFull) {
      layer.full_bleed = responsive('100%')
    }
    patch(basePath, layer)
  }

  function applyDesktopPreset(preset: (typeof WIDTH_DESKTOP_PRESETS)[0]) {
    const layer: Record<string, unknown> = {
      ...(typeof activeLayer === 'object' && activeLayer !== null ? activeLayer : {}),
    }
    for (const [key, num] of Object.entries(preset.values)) {
      const cur = activeLayer[key]
      const responsive = ensureResponsive(cur)
      for (const bp of BP) {
        responsive[bp] = `${num}px`
      }
      layer[key] = responsive
    }
    patch(basePath, layer)
  }

  function homeHeroPresetMatches(preset: (typeof HOME_HERO_WIDTH_PRESETS)[number]): boolean {
    const content = readRawAtBp(activeLayer.content_max, activeBp).replace(/\s/g, '')
    const wide = readRawAtBp(activeLayer.section_wide, activeBp).replace(/\s/g, '')
    if (preset.content === '100%') {
      return content === '100%' && wide === '100%'
    }
    const want = `${preset.content}px`
    return content === want && wide === want
  }

  function syncAllBreakpoints(key: GlobalKey) {
    const raw = readRawAtBp(activeLayer[key], activeBp)
    if (!raw) return
    const responsive = ensureResponsive(activeLayer[key])
    for (const bp of BP) {
      responsive[bp] = raw
    }
    patch([...basePath, key], responsive)
  }

  const steps = useMemo(() => {
    const list = ['Global foundation']
    if (scope === 'page_type') list.push(`Template · ${PAGE_TYPE_LABELS[selectedType] || selectedType}`)
    if (scope === 'page_type_section') list.push('Homepage · hero carousel only')
    if (scope === 'page') list.push(`Page · /${selectedPage}`)
    if (scope === 'service_section') list.push(`Service · ${selectedSection.replace(/_/g, ' ')}`)
    if (scope === 'section') list.push(`Section · ${selectedSection.replace(/_/g, ' ')}`)
    return list
  }, [scope, selectedType, selectedPage, selectedSection])

  const previewScene = PREVIEW_SCENES.find((s) => s.id === previewSceneId) ?? PREVIEW_SCENES[0]

  const previewMetrics = useMemo(() => {
    const ctx = previewScene.ctx as PageWidthContext
    let merged = mergeWidthContext(config, ctx)
    const sec = previewScene.section
    if (sec) {
      const secLayer = resolveSectionLayer(config, ctx.page_type, sec)
      if (secLayer) {
        merged = { ...merged, ...secLayer }
      }
    }
    const g = global
    const pagePx = effectiveBp(merged, g, 'page_max', activeBp) ?? 1200
    const sectionPx = effectiveBp(merged, g, 'section_standard', activeBp) ?? pagePx
    const contentPx = effectiveBp(merged, g, 'content_max', activeBp) ?? 960
    const paddingPx = effectiveBp(merged, g, 'padding_x', activeBp) ?? 20
    return { pagePx, sectionPx, contentPx, paddingPx }
  }, [config, previewScene, global, activeBp])

  const pageSlugSuggestions = useMemo(() => Object.keys(pages).slice(0, 8), [pages])

  const navGroups = [
    { id: 'foundation', label: 'Foundation' },
    { id: 'experiences', label: 'Experiences' },
    { id: 'targets', label: 'Targets' },
  ] as const

  return (
    <div className="s2-wls">
      <header className="s2-wls__hero">
        <div>
          <p className="s2-wls__hero-kicker">Design System</p>
          <h2 className="s2-wls__hero-title">Layout Studio</h2>
          <p className="s2-wls__hero-sub">
            Set how wide your site feels — site shell, reading columns, and section bands — with a clear inheritance chain.
            Publish when finished; the live site updates automatically.
          </p>
        </div>
        <div className="s2-wls__hero-meta">
          <span className="s2-wls__pill">{overrideCount ? `${overrideCount} custom token${overrideCount === 1 ? '' : 's'}` : 'All inherited'}</span>
          <span className="s2-wls__pill">{BP_META[activeBp].label} editing</span>
        </div>
      </header>

      <div className="s2-wls__shell">
        <nav className="s2-wls__rail" aria-label="Layout targets">
          {navGroups.map((group) => (
            <div key={group.id} className="s2-wls__rail-group">
              <div className="s2-wls__rail-label">{group.label}</div>
              {TASKS.filter((t) => t.navGroup === group.id).map((t) => {
                const count =
                  t.id === taskId
                    ? overrideCount
                    : t.scope === 'global'
                      ? countLayerOverrides(global)
                      : t.scope === 'page_type_section'
                        ? countLayerOverrides(homeHeroLayer(pageTypes))
                        : t.scope === 'page_type'
                          ? countLayerOverrides(pageTypes[selectedType] || {})
                          : t.scope === 'page'
                            ? countLayerOverrides(pages[selectedPage] || {})
                            : t.scope === 'service_section'
                              ? countLayerOverrides(serviceSections[selectedSection] || {})
                              : countLayerOverrides(sections[selectedSection] || {})
                return (
                  <button
                    key={t.id}
                    type="button"
                    className={`s2-wls__nav-item ${taskId === t.id ? 'is-active' : ''}`}
                    onClick={() => {
                      setTaskId(t.id)
                      if (t.defaultSection) setSelectedSection(t.defaultSection)
                    }}
                  >
                    <span className="s2-wls__nav-icon" aria-hidden>
                      {NAV_ICONS[t.id]}
                    </span>
                    <span>
                      <div className="s2-wls__nav-title">{t.title}</div>
                      <div className="s2-wls__nav-desc">{t.description}</div>
                    </span>
                    {count > 0 && <span className="s2-wls__badge">{count}</span>}
                  </button>
                )
              })}
            </div>
          ))}
        </nav>

        <main className="s2-wls__workspace">
          <div className="s2-wls__toolbar">
            <div className="s2-wls__stepper" aria-label="Inheritance">
              {steps.map((step, i) => (
                <React.Fragment key={step}>
                  {i > 0 && <span aria-hidden>›</span>}
                  <span className={`s2-wls__step ${i === steps.length - 1 ? 'is-current' : ''}`}>{step}</span>
                </React.Fragment>
              ))}
            </div>
            <div className="s2-wls__bp-bar" role="tablist" aria-label="Breakpoint">
              {BP.map((bp) => (
                <button
                  key={bp}
                  type="button"
                  role="tab"
                  aria-selected={activeBp === bp}
                  className={`s2-wls__bp-btn ${activeBp === bp ? 'is-active' : ''}`}
                  title={BP_META[bp].hint}
                  onClick={() => setActiveBp(bp)}
                >
                  {BP_META[bp].label}
                </button>
              ))}
            </div>
          </div>

          {(scope === 'page_type' || scope === 'page' || scope === 'section' || scope === 'service_section') && (
            <div className="s2-wls__select-row">
              {scope === 'page_type' && (
                <div className="s2-wls__field">
                  <label htmlFor="wls-page-type">Template</label>
                  <select id="wls-page-type" value={selectedType} onChange={(e) => setSelectedType(e.target.value)}>
                    {pageTypeOptions().map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                </div>
              )}
              {scope === 'page' && (
                <div className="s2-wls__field">
                  <label htmlFor="wls-page-slug">Page slug</label>
                  <input
                    id="wls-page-slug"
                    list="wls-page-slugs"
                    value={selectedPage}
                    onChange={(e) => setSelectedPage(e.target.value.replace(/\s+/g, '-').toLowerCase())}
                    placeholder="about, contact, pricing…"
                  />
                  <datalist id="wls-page-slugs">
                    {pageSlugSuggestions.map((s) => (
                      <option key={s} value={s} />
                    ))}
                  </datalist>
                </div>
              )}
              {(scope === 'section' || scope === 'service_section') && taskId !== 'service_hero' && (
                <div className="s2-wls__field">
                  <label htmlFor="wls-section">Block</label>
                  <select id="wls-section" value={selectedSection} onChange={(e) => setSelectedSection(e.target.value)}>
                    {sectionOptions()
                      .filter((o) => taskId !== 'marketing_section' || o.value !== 'hero')
                      .map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.group} — {o.label}
                      </option>
                    ))}
                  </select>
                </div>
              )}
            </div>
          )}

          {taskId === 'home_hero' && (
            <>
              <div className="s2-wls__callout" style={{ borderColor: '#BFDBFE', background: '#EFF6FF' }}>
                Controls the <strong>homepage banner carousel only</strong> (route <code>/</code>). Service page heroes are
                under <strong>Service hero</strong> in the left rail.
              </div>
              <div className="s2-wls__presets">
                {HOME_HERO_WIDTH_PRESETS.map((p) => (
                  <button
                    key={p.label}
                    type="button"
                    className={`s2-wls__preset${homeHeroPresetMatches(p) ? ' is-active' : ''}`}
                    onClick={() => applyHomeHeroPreset(p)}
                  >
                    <div className="s2-wls__preset-title">{p.label}</div>
                    <div className="s2-wls__preset-sub">Hero carousel max width</div>
                  </button>
                ))}
              </div>
            </>
          )}

          {taskId === 'service_hero' && (
            <div className="s2-wls__callout">
              Applies to <strong>every service page hero</strong>. For a single service, use{' '}
              <strong>Service Page Builder → Hero Settings → Hero content max width</strong>.
            </div>
          )}

          {taskId === 'site_defaults' && (
            <div className="s2-wls__presets">
              {PRESET_CARDS.map(({ preset, title, sub }) => (
                <button key={title} type="button" className="s2-wls__preset" onClick={() => applyDesktopPreset(preset)}>
                  <div className="s2-wls__preset-title">{title}</div>
                  <div className="s2-wls__preset-sub">{sub}</div>
                  <div className="s2-wls__preset-bars" aria-hidden>
                    <div className="s2-wls__preset-bar" style={{ width: `${(Number(preset.values.page_max) / 1400) * 100}%` }} />
                    <div className="s2-wls__preset-bar" style={{ width: `${(Number(preset.values.content_max) / 1400) * 100}%`, opacity: 0.7 }} />
                    <div className="s2-wls__preset-bar" style={{ width: `${(Number(preset.values.section_standard) / 1400) * 100}%`, opacity: 0.5 }} />
                  </div>
                </button>
              ))}
            </div>
          )}

          {(['shell', 'content', 'sections', 'advanced'] as const).map((group) => {
            const keys = keysByGroup[group]
            if (!keys.length) return null
            const meta = GROUP_LABELS[group]
            return (
              <section key={group} className="s2-wls__group">
                <div className="s2-wls__group-head">
                  <h3 className="s2-wls__group-title">{meta.title}</h3>
                  <p className="s2-wls__group-blurb">{meta.blurb}</p>
                </div>
                {keys.map((key) => (
                  <WidthControl
                    key={key}
                    fieldKey={key}
                    bp={activeBp}
                    value={activeLayer[key]}
                    inherited={scope !== 'global' && activeLayer[key] == null}
                    global={global}
                    onChange={(v) => patch([...basePath, key], v)}
                    onReset={
                      scope !== 'global'
                        ? () => patch([...basePath, key], undefined)
                        : undefined
                    }
                    onSyncBreakpoints={() => syncAllBreakpoints(key)}
                  />
                ))}
              </section>
            )
          })}

          {taskId !== 'service_hero' && (
            <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={() => setShowAdvanced((v) => !v)}>
              {showAdvanced ? 'Hide advanced tokens' : 'Show advanced tokens'}
            </button>
          )}
        </main>

        <aside className="s2-wls__preview">
          <p className="s2-wls__preview-title">Live structure preview</p>
          <div className="s2-wls__scene-tabs">
            {PREVIEW_SCENES.map((scene) => (
              <button
                key={scene.id}
                type="button"
                className={`s2-wls__scene-tab ${previewSceneId === scene.id ? 'is-active' : ''}`}
                onClick={() => setPreviewSceneId(scene.id)}
              >
                {scene.label}
              </button>
            ))}
          </div>
          <LayoutWireframe
            pagePx={previewMetrics.pagePx}
            sectionPx={previewMetrics.sectionPx}
            contentPx={previewMetrics.contentPx}
            paddingPx={previewMetrics.paddingPx}
            url={
              previewScene.ctx.page_type === 'home'
                ? '/'
                : previewScene.ctx.page_type === 'service'
                  ? '/service/passport'
                  : `/${previewScene.ctx.page_slug}`
            }
          />
          <div className="s2-wls__legend">
            <div>Preview uses published config + your unsaved edits in this panel.</div>
            <div>
              {BP_META[activeBp].label}: page {previewMetrics.pagePx}px · section {previewMetrics.sectionPx}px · content{' '}
              {previewMetrics.contentPx}px
            </div>
          </div>
          <p className="s2-wls__footnote">
            Width tokens map to CSS variables on the public site (e.g. <code>--s2-width-page-max</code>,{' '}
            <code>--s2-width-sec-hero-max</code>).
          </p>
        </aside>
      </div>
    </div>
  )
}
