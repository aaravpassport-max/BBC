/**
 * Page → Section → Content | Design builder shell for the public site.
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import {
  DESIGN_PAGE_CATALOG,
  FOUNDATION_NAV,
  SECTION_DESIGN_COLOR_KEYS,
  SECTION_TYPOGRAPHY_ROLES,
  type FoundationPanelId,
  type PageCatalogDef,
  type SectionCatalogDef,
} from '@/lib/design-system-catalog'
import {
  PAGE_TEMPLATES,
  TEMPLATE_COLOR_KEYS,
  templateColorOverridePath,
  templateTypographyPath,
  type PageTemplateDef,
} from '@/lib/design-page-templates'
import {
  TypographyRolesEditor,
  TokenGroupEditor,
  ComponentsEditor,
  SiteChromePanel,
  FontAssignPanel,
  LiveSitePreviewFrame,
} from './design-system-panels'
import { HexColorField, HexAlphaColorField, PxTokenField, parsePx } from './design-admin-fields'
import { hasOverrideAtPath, OverrideFieldShell, readPathLeaf } from './design-inherit-ui'
import { WidthLayoutPanel, type WidthLayoutFocus } from './width-layout-panel'

type DesignConfig = Record<string, unknown>
type PatchFn = (path: string[], value: unknown) => void

const COLOR_KEYS_HEX = [
  'primary', 'primary_hover', 'primary_active', 'secondary', 'secondary_hover',
  'accent', 'accent_hover', 'background', 'surface', 'surface_alt', 'card',
  'border', 'divider', 'heading', 'body', 'muted', 'placeholder', 'link', 'link_hover',
  'success', 'warning', 'error', 'info', 'disabled',
] as const

const COLOR_KEYS_ALPHA = ['overlay', 'shadow'] as const

function sectionColorPath(sectionKey: string, colorKey: string): string[] {
  return ['overrides', 'sections', sectionKey, 'colors', colorKey]
}

function sectionTypographyPath(sectionKey: string, role: string, field: string): string[] {
  return ['overrides', 'sections', sectionKey, 'typography', role, field]
}

function SectionTypographyPanel({
  sectionKey,
  config,
  patch,
}: {
  sectionKey: string
  config: DesignConfig
  patch: PatchFn
}) {
  if (!sectionKey || sectionKey === '_page') return null
  return (
    <div>
      <h3 style={{ margin: '0 0 12px', fontSize: 15 }}>Section typography</h3>
      <p style={{ margin: '0 0 12px', fontSize: 13, color: '#64748B' }}>
        Overrides headings and body text inside this band only. Clear a field to inherit from Site Foundation.
      </p>
      <div style={{ display: 'grid', gap: 16 }}>
        {SECTION_TYPOGRAPHY_ROLES.map((role) => (
          <fieldset key={role} style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 14 }}>
            <legend style={{ fontWeight: 700, padding: '0 6px', fontSize: 13 }}>{role.replace(/_/g, ' ')}</legend>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 10 }}>
              {(['size_desktop', 'size_tablet', 'size_mobile'] as const).map((field) => {
                const path = sectionTypographyPath(sectionKey, role, field)
                const raw = readPathLeaf(config, path)
                const inherited = !hasOverrideAtPath(config, path)
                return (
                  <OverrideFieldShell
                    key={field}
                    label={field.replace(/_/g, ' ')}
                    inherited={inherited}
                    onClear={inherited ? undefined : () => patch(path, null)}
                  >
                    <PxTokenField
                      label=""
                      value={typeof raw === 'string' ? raw : ''}
                      onChange={(v) => patch(path, v ? parsePx(v) : null)}
                    />
                  </OverrideFieldShell>
                )
              })}
              {(['font_weight', 'line_height'] as const).map((field) => {
                const path = sectionTypographyPath(sectionKey, role, field)
                const raw = readPathLeaf(config, path)
                const inherited = !hasOverrideAtPath(config, path)
                return (
                  <OverrideFieldShell
                    key={field}
                    label={field.replace(/_/g, ' ')}
                    inherited={inherited}
                    onClear={inherited ? undefined : () => patch(path, null)}
                  >
                    <input
                      type="text"
                      value={typeof raw === 'string' ? raw : ''}
                      onChange={(e) => patch(path, e.target.value || null)}
                      style={{ width: '100%', padding: 6, borderRadius: 6, border: '1px solid #E2E8F0' }}
                    />
                  </OverrideFieldShell>
                )
              })}
              {(() => {
                const path = sectionTypographyPath(sectionKey, role, 'color')
                const raw = readPathLeaf(config, path)
                const inherited = !hasOverrideAtPath(config, path)
                return (
                  <OverrideFieldShell label="color" inherited={inherited} onClear={inherited ? undefined : () => patch(path, null)}>
                    <HexColorField
                      label=""
                      value={typeof raw === 'string' && raw.startsWith('#') ? raw : ''}
                      onChange={(v) => patch(path, v || null)}
                    />
                  </OverrideFieldShell>
                )
              })()}
            </div>
          </fieldset>
        ))}
      </div>
    </div>
  )
}

function SectionContentPanel({
  section,
  page,
  settings,
  onChange,
  onSave,
  saving,
}: {
  section: SectionCatalogDef
  page: PageCatalogDef
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
  onSave: () => void
  saving: boolean
}) {
  const fields = section.isPageScope
    ? page.pageContentFields || []
    : section.contentFields || []
  if (fields.length === 0) {
    return (
      <div className="s2-design-builder-panel">
        {section.adminLink && (
          <p style={{ margin: '0 0 12px' }}>
            <a href={section.adminLink.path} className="s2-btn s2-btn--outline s2-btn--sm">
              {section.adminLink.label} →
            </a>
          </p>
        )}
        <p style={{ color: '#64748B', fontSize: 14, lineHeight: 1.6, margin: 0 }}>
          {section.contentNote ||
            'No direct text fields for this block yet. Use linked admin screens (FAQs, Testimonials, Service Registry) or Homepage Builder for copy.'}
        </p>
      </div>
    )
  }
  return (
    <div className="s2-design-builder-panel">
      {section.adminLink && (
        <p style={{ margin: '0 0 12px' }}>
          <a href={section.adminLink.path} className="s2-btn s2-btn--outline s2-btn--sm">
            {section.adminLink.label} →
          </a>
        </p>
      )}
      {section.contentNote && (
        <p style={{ color: '#64748B', fontSize: 13, marginTop: 0 }}>{section.contentNote}</p>
      )}
      <div style={{ display: 'grid', gap: 14, maxWidth: 640 }}>
        {fields.map((f) => (
          <label key={f.key} style={{ fontSize: 13, fontWeight: 600, color: '#374151' }}>
            {f.label}
            {f.type === 'textarea' ? (
              <textarea
                rows={f.rows ?? 3}
                value={settings[f.key] || ''}
                onChange={(e) => onChange(f.key, e.target.value)}
                placeholder={f.placeholder}
                style={{ display: 'block', width: '100%', marginTop: 6, padding: 10, borderRadius: 8, border: '1px solid #E2E8F0', fontFamily: 'inherit' }}
              />
            ) : (
              <input
                type="text"
                value={settings[f.key] || ''}
                onChange={(e) => onChange(f.key, e.target.value)}
                placeholder={f.placeholder}
                style={{ display: 'block', width: '100%', marginTop: 6, padding: 10, borderRadius: 8, border: '1px solid #E2E8F0' }}
              />
            )}
            {f.hint && <span style={{ display: 'block', fontWeight: 400, fontSize: 12, color: '#94A3B8', marginTop: 4 }}>{f.hint}</span>}
          </label>
        ))}
      </div>
      <button type="button" className="s2-btn s2-btn--primary" style={{ marginTop: 20 }} onClick={onSave} disabled={saving}>
        {saving ? 'Saving content…' : 'Save section content'}
      </button>
    </div>
  )
}

function PageTemplateForPage(page: PageCatalogDef): PageTemplateDef | undefined {
  return PAGE_TEMPLATES.find((t) => t.id === page.id)
}

function SectionPlatformDesignFields({
  section,
  settings,
  onChange,
  onSave,
  saving,
}: {
  section: SectionCatalogDef
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
  onSave: () => void
  saving: boolean
}) {
  const fields = section.designSettingFields || []
  if (fields.length === 0) return null
  return (
    <div>
      <h3 style={{ margin: '0 0 12px', fontSize: 15 }}>Section styling (platform)</h3>
      <p style={{ margin: '0 0 12px', fontSize: 13, color: '#64748B' }}>
        These map to homepage CSS tokens. Clear a field to remove the override and fall back to global defaults.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 12 }}>
        {fields.map((f) => {
          const val = settings[f.key] || ''
          const inherited = val === ''
          return (
            <OverrideFieldShell
              key={f.key}
              label={f.label}
              hint={f.hint}
              inherited={inherited}
              onClear={inherited ? undefined : () => onChange(f.key, '')}
            >
              {f.type === 'color' ? (
                <HexColorField label="" value={val} onChange={(v) => onChange(f.key, v)} />
              ) : (
                <input
                  type="text"
                  value={val}
                  placeholder={f.placeholder}
                  onChange={(e) => onChange(f.key, e.target.value)}
                  style={{ width: '100%', padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
                />
              )}
            </OverrideFieldShell>
          )
        })}
      </div>
      <button type="button" className="s2-btn s2-btn--outline" style={{ marginTop: 16 }} onClick={onSave} disabled={saving}>
        {saving ? 'Saving styling…' : 'Save section styling'}
      </button>
    </div>
  )
}

function PageDefaultsDesignPanel({
  page,
  config,
  patch,
  onOpenLayout,
}: {
  page: PageCatalogDef
  config: DesignConfig
  patch: PatchFn
  onOpenLayout: (focus: WidthLayoutFocus) => void
}) {
  const template = PageTemplateForPage(page)
  if (!template) {
    return <p style={{ color: '#64748B' }}>No page template metadata for this route.</p>
  }
  const widthFocus: WidthLayoutFocus = page.widthPageSlug
    ? { scope: 'page', selectedPage: page.widthPageSlug }
    : page.widthPageType
      ? { scope: 'page_type', selectedType: page.widthPageType }
      : { scope: 'global' }

  return (
    <div className="s2-design-builder-panel" style={{ display: 'grid', gap: 20 }}>
      <p style={{ margin: 0, fontSize: 13, color: '#64748B', maxWidth: 720 }}>
        Page-level design sits between <strong>Site Foundation</strong> and individual sections. Clear any field to inherit from foundation.
      </p>
      <div>
        <h3 style={{ margin: '0 0 12px', fontSize: 15 }}>Page colors</h3>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 12 }}>
          {TEMPLATE_COLOR_KEYS.map((colorKey) => {
            const path = templateColorOverridePath(template, colorKey)
            const raw = readPathLeaf(config, path)
            const inherited = !hasOverrideAtPath(config, path)
            return (
              <OverrideFieldShell
                key={colorKey}
                label={colorKey.replace(/_/g, ' ')}
                inherited={inherited}
                onClear={inherited ? undefined : () => patch(path, null)}
              >
                <HexColorField
                  label=""
                  value={typeof raw === 'string' ? raw : ''}
                  onChange={(v) => patch(path, v || null)}
                />
              </OverrideFieldShell>
            )
          })}
        </div>
      </div>
      <div>
        <h3 style={{ margin: '0 0 12px', fontSize: 15 }}>Page typography samples</h3>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 }}>
          {(['page_title', 'body'] as const).map((role) => {
            const path = templateTypographyPath(template, role, 'size_desktop')
            const raw = readPathLeaf(config, path)
            const inherited = !hasOverrideAtPath(config, path)
            return (
              <OverrideFieldShell
                key={role}
                label={`${role.replace(/_/g, ' ')} size (desktop)`}
                inherited={inherited}
                onClear={inherited ? undefined : () => patch(path, null)}
              >
                <input
                  type="text"
                  value={typeof raw === 'string' ? raw : ''}
                  placeholder="e.g. 2.5rem"
                  onChange={(e) => patch(path, e.target.value || null)}
                  style={{ width: '100%', padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
                />
              </OverrideFieldShell>
            )
          })}
        </div>
      </div>
      <div style={{ borderTop: '1px solid #E2E8F0', paddingTop: 16 }}>
        <h3 style={{ margin: '0 0 8px', fontSize: 15 }}>Page layout & width</h3>
        <button type="button" className="s2-btn s2-btn--outline" onClick={() => onOpenLayout(widthFocus)}>
          Open page width defaults
        </button>
      </div>
    </div>
  )
}

function SectionDesignPanel({
  section,
  page,
  config,
  patch,
  settings,
  onSettingsChange,
  onSavePlatformDesign,
  platformDesignSaving,
  onOpenLayout,
}: {
  section: SectionCatalogDef
  page: PageCatalogDef
  config: DesignConfig
  patch: PatchFn
  settings: Record<string, string>
  onSettingsChange: (key: string, value: string) => void
  onSavePlatformDesign: () => void
  platformDesignSaving: boolean
  onOpenLayout: (focus: WidthLayoutFocus) => void
}) {
  if (section.isPageScope) {
    return (
      <PageDefaultsDesignPanel page={page} config={config} patch={patch} onOpenLayout={onOpenLayout} />
    )
  }
  const hideKey = section.hideSettingKey
  const [hideVal, setHideVal] = useState('0')
  useEffect(() => {
    if (!hideKey) return
    api.get<{ settings: Record<string, string> }>('admin/settings').then((d) => {
      setHideVal(String(d.settings?.[hideKey] ?? '0'))
    }).catch(() => {})
  }, [hideKey, section.id])

  const saveVisibility = async (hidden: boolean) => {
    if (!hideKey) return
    const v = hidden ? '1' : '0'
    setHideVal(v)
    await api.put('admin/settings', { [hideKey]: v })
  }

  const widthFocus: WidthLayoutFocus = page.widthPageType === 'home' && section.sectionKey === 'hero'
    ? { scope: 'page_type_section', selectedType: 'home', selectedSection: 'hero' }
    : page.widthPageSlug
      ? { scope: 'page', selectedPage: page.widthPageSlug, selectedSection: section.sectionKey }
      : page.widthPageType
        ? { scope: 'page_type', selectedType: page.widthPageType, selectedSection: section.sectionKey }
        : { scope: 'section', selectedSection: section.sectionKey }

  return (
    <div className="s2-design-builder-panel" style={{ display: 'grid', gap: 20 }}>
      <p style={{ margin: 0, fontSize: 13, color: '#64748B', maxWidth: 720 }}>
        Section styling overrides inherit from <strong>Site Foundation</strong> → page → this block.
        Clear any field to revert to the inherited default.
      </p>

      {hideKey && (
        <OverrideFieldShell
          label="Show on homepage"
          hint="When off, this block is hidden on the live homepage."
          inherited={hideVal === '0'}
          onClear={hideVal === '1' ? () => saveVisibility(false) : undefined}
        >
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 14 }}>
            <input
              type="checkbox"
              checked={hideVal !== '1'}
              onChange={(e) => saveVisibility(!e.target.checked)}
            />
            Visible on site
          </label>
        </OverrideFieldShell>
      )}

      <div>
        <h3 style={{ margin: '0 0 12px', fontSize: 15 }}>Section colors</h3>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 12 }}>
          {SECTION_DESIGN_COLOR_KEYS.map((colorKey) => {
            const path = sectionColorPath(section.sectionKey, colorKey)
            const raw = readPathLeaf(config, path)
            const inherited = !hasOverrideAtPath(config, path)
            return (
              <OverrideFieldShell
                key={colorKey}
                label={colorKey.replace(/_/g, ' ')}
                inherited={inherited}
                onClear={inherited ? undefined : () => patch(path, null)}
              >
                <HexColorField
                  label=""
                  value={typeof raw === 'string' ? raw : ''}
                  onChange={(v) => patch(path, v || null)}
                />
              </OverrideFieldShell>
            )
          })}
        </div>
      </div>

      <SectionTypographyPanel sectionKey={section.sectionKey} config={config} patch={patch} />

      <SectionPlatformDesignFields
        section={section}
        settings={settings}
        onChange={onSettingsChange}
        onSave={onSavePlatformDesign}
        saving={platformDesignSaving}
      />

      <div style={{ borderTop: '1px solid #E2E8F0', paddingTop: 16 }}>
        <h3 style={{ margin: '0 0 8px', fontSize: 15 }}>Layout & width</h3>
        <p style={{ margin: '0 0 12px', fontSize: 13, color: '#64748B' }}>
          Padding, max width, and gutters for this section band.
        </p>
        <button type="button" className="s2-btn s2-btn--outline" onClick={() => onOpenLayout(widthFocus)}>
          Open layout controls for this section
        </button>
      </div>
    </div>
  )
}

function SiteFoundationPanel({
  panel,
  config,
  patch,
  fonts,
  fontQuery,
  setFontQuery,
  filteredFonts,
  presets,
  applyPreset,
  saving,
  onLayoutGlobal,
}: {
  panel: FoundationPanelId
  config: DesignConfig
  patch: PatchFn
  fonts: Array<{ id: string; name: string; category: string; pairing: string }>
  fontQuery: string
  setFontQuery: (q: string) => void
  filteredFonts: typeof fonts
  presets: Record<string, { label: string; description: string }>
  applyPreset: (id: string) => void
  saving: boolean
  onLayoutGlobal: () => void
}) {
  const colors = (config.colors || {}) as Record<string, string>
  const spacing = (config.spacing || {}) as Record<string, string>
  const fontRoles = (config.fonts || {}) as Record<string, string>
  const typography = (config.typography || {}) as Record<string, Record<string, string>>
  const radius = (config.radius || {}) as Record<string, string>
  const shadow = (config.shadow || {}) as Record<string, string>
  const motion = (config.motion || {}) as Record<string, string | boolean>
  const breakpoints = (config.breakpoints || {}) as Record<string, number>
  const components = (config.components || {}) as Record<string, string>
  const chrome = (config.chrome || {}) as Record<string, unknown>

  switch (panel) {
    case 'colors':
      return (
        <div style={{ display: 'grid', gap: 20 }}>
          <p style={{ margin: 0, color: '#64748B', fontSize: 13 }}>
            Site Foundation palette — pages and sections inherit these until you set a section override.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 16 }}>
            {COLOR_KEYS_HEX.map((key) => (
              <HexColorField key={key} label={key.replace(/_/g, ' ')} value={colors[key] || ''} onChange={(v) => patch(['colors', key], v)} />
            ))}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 16 }}>
            {COLOR_KEYS_ALPHA.map((key) => (
              <HexAlphaColorField key={key} label={key.replace(/_/g, ' ')} value={colors[key] || ''} onChange={(v) => patch(['colors', key], v)} />
            ))}
          </div>
        </div>
      )
    case 'typography':
      return <TypographyRolesEditor typography={typography} patch={patch} />
    case 'fonts':
      return (
        <div style={{ display: 'grid', gap: 24 }}>
          <FontAssignPanel fonts={filteredFonts} fontRoles={fontRoles} onAssign={(role, fontId) => patch(['fonts', role], fontId)} />
          <input
            type="search"
            placeholder="Search fonts…"
            value={fontQuery}
            onChange={(e) => setFontQuery(e.target.value)}
            style={{ maxWidth: 400, padding: 10, borderRadius: 8, border: '1px solid #E2E8F0' }}
          />
        </div>
      )
    case 'components':
      return <ComponentsEditor components={components} patch={patch} />
    case 'spacing':
      return <TokenGroupEditor title="Spacing scale (px)" basePath={['spacing']} tokens={spacing} patch={patch} keys={Object.keys(spacing)} unit="px" />
    case 'layout':
      return (
        <div>
          <p style={{ color: '#64748B', fontSize: 13 }}>Global container widths and gutters — the default for all pages.</p>
          <button type="button" className="s2-btn s2-btn--outline" style={{ marginBottom: 16 }} onClick={onLayoutGlobal}>
            Edit site foundation widths
          </button>
          <WidthLayoutPanel config={config} patch={patch} focus={{ scope: 'global' }} />
        </div>
      )
    case 'borders':
      return <TokenGroupEditor title="Border radius (px)" basePath={['radius']} tokens={radius} patch={patch} keys={Object.keys(radius)} unit="px" />
    case 'shadows':
      return <TokenGroupEditor title="Elevation" basePath={['shadow']} tokens={shadow} patch={patch} keys={Object.keys(shadow)} />
    case 'motion':
      return (
        <div style={{ display: 'grid', gap: 16, maxWidth: 480 }}>
          <label style={{ fontSize: 13 }}>
            Transition duration
            <input type="text" value={String(motion.duration || '')} onChange={(e) => patch(['motion', 'duration'], e.target.value)} style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }} />
          </label>
          <label style={{ fontSize: 13 }}>
            Easing
            <input type="text" value={String(motion.ease || '')} onChange={(e) => patch(['motion', 'ease'], e.target.value)} style={{ display: 'block', width: '100%', marginTop: 6, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }} />
          </label>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
            <input type="checkbox" checked={Boolean(motion.reduce_motion)} onChange={(e) => patch(['motion', 'reduce_motion'], e.target.checked)} />
            Respect prefers-reduced-motion
          </label>
        </div>
      )
    case 'responsive':
      return (
        <TokenGroupEditor
          title="Breakpoints (px)"
          basePath={['breakpoints']}
          tokens={Object.fromEntries(Object.entries(breakpoints).map(([k, v]) => [k, String(v)]))}
          patch={(path, val) => patch(path, Number(val))}
          keys={Object.keys(breakpoints)}
        />
      )
    case 'chrome':
      return <SiteChromePanel chrome={chrome} patch={patch} />
    case 'presets':
      return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 16 }}>
          {Object.entries(presets).map(([id, p]) => (
            <div key={id} className="s2-card" style={{ border: '1px solid #E2E8F0', borderRadius: 16, padding: 20 }}>
              <h3 style={{ margin: '0 0 8px' }}>{p.label}</h3>
              <p style={{ fontSize: 13, color: '#64748B', minHeight: 48 }}>{p.description}</p>
              <button type="button" onClick={() => applyPreset(id)} className="s2-btn s2-btn--primary" disabled={saving}>
                Apply site-wide
              </button>
            </div>
          ))}
        </div>
      )
    default:
      return null
  }
}

export function DesignSystemBuilder({
  config,
  patch,
  presets,
  fonts,
  applyPreset,
  saving,
  previewPath,
  setPreviewPath,
  toolsSlot,
}: {
  config: DesignConfig
  patch: PatchFn
  presets: Record<string, { label: string; description: string }>
  fonts: Array<{ id: string; name: string; category: string; pairing: string }>
  applyPreset: (id: string) => void
  saving: boolean
  previewPath: string
  setPreviewPath: (p: string) => void
  toolsSlot?: React.ReactNode
}) {
  const [navMode, setNavMode] = useState<'foundation' | 'page'>('page')
  const [foundationPanel, setFoundationPanel] = useState<FoundationPanelId>('colors')
  const [pageId, setPageId] = useState('home')
  const [sectionId, setSectionId] = useState('hero')
  const [sectionTab, setSectionTab] = useState<'content' | 'design'>('content')
  const [expandedPages, setExpandedPages] = useState<Record<string, boolean>>({ home: true })
  const [layoutFocus, setLayoutFocus] = useState<WidthLayoutFocus | null>(null)
  const [settings, setSettings] = useState<Record<string, string>>({})
  const [contentSaving, setContentSaving] = useState(false)
  const [platformDesignSaving, setPlatformDesignSaving] = useState(false)
  const [fontQuery, setFontQuery] = useState('')

  const page = useMemo(() => DESIGN_PAGE_CATALOG.find((p) => p.id === pageId) || DESIGN_PAGE_CATALOG[0], [pageId])
  const section = useMemo(
    () => page.sections.find((s) => s.id === sectionId) || page.sections[0],
    [page, sectionId],
  )

  const loadSettings = useCallback(async () => {
    try {
      const d = await api.get<{ settings: Record<string, string> }>('admin/settings')
      setSettings(d.settings || {})
    } catch {
      setSettings({})
    }
  }, [])

  useEffect(() => {
    loadSettings()
  }, [loadSettings])

  useEffect(() => {
    setPreviewPath(page.previewPath)
  }, [page.previewPath, setPreviewPath])

  const filteredFonts = useMemo(() => {
    const q = fontQuery.toLowerCase().trim()
    if (!q) return fonts.slice(0, 40)
    return fonts.filter(
      (f) => f.name.toLowerCase().includes(q) || f.category.toLowerCase().includes(q),
    )
  }, [fonts, fontQuery])

  const selectPage = (id: string) => {
    setNavMode('page')
    setPageId(id)
    const p = DESIGN_PAGE_CATALOG.find((x) => x.id === id)
    const firstSec = p?.sections.find((s) => !s.isPageScope)?.id || p?.sections[0]?.id || 'hero'
    setSectionId(firstSec)
    setExpandedPages((prev) => ({ ...prev, [id]: true }))
  }

  const selectSection = (pid: string, sid: string) => {
    setNavMode('page')
    setPageId(pid)
    setSectionId(sid)
    setExpandedPages((prev) => ({ ...prev, [pid]: true }))
  }

  const saveSectionContent = async () => {
    const fields = section?.isPageScope ? page.pageContentFields : section?.contentFields
    if (!fields?.length) return
    setContentSaving(true)
    try {
      const payload: Record<string, string> = {}
      fields.forEach((f) => {
        payload[f.key] = settings[f.key] || ''
      })
      await api.put('admin/settings', payload)
      await loadSettings()
    } finally {
      setContentSaving(false)
    }
  }

  const saveSectionPlatformDesign = async () => {
    if (!section?.designSettingFields?.length) return
    setPlatformDesignSaving(true)
    try {
      const payload: Record<string, string> = {}
      section.designSettingFields.forEach((f) => {
        payload[f.key] = settings[f.key] || ''
      })
      await api.put('admin/settings', payload)
      await loadSettings()
    } finally {
      setPlatformDesignSaving(false)
    }
  }

  const breadcrumb =
    navMode === 'foundation'
      ? `Site Foundation → ${FOUNDATION_NAV.find((f) => f.id === foundationPanel)?.label || ''}`
      : `${page.label} → ${section?.label || ''}`

  return (
    <div className="s2-design-system-builder" style={{ display: 'grid', gridTemplateColumns: 'minmax(220px, 280px) 1fr', gap: 24, alignItems: 'start' }}>
      <nav className="s2-design-builder-nav" aria-label="Design pages" style={{ position: 'sticky', top: 12 }}>
        <button
          type="button"
          className={`s2-design-builder-nav__foundation${navMode === 'foundation' ? ' is-active' : ''}`}
          onClick={() => setNavMode('foundation')}
          style={{
            width: '100%',
            textAlign: 'left',
            padding: '12px 14px',
            marginBottom: 12,
            borderRadius: 12,
            border: navMode === 'foundation' ? '2px solid var(--s2-primary, #4A6FA5)' : '1px solid #E2E8F0',
            background: navMode === 'foundation' ? '#EBF0F8' : '#fff',
            fontWeight: 700,
            cursor: 'pointer',
          }}
        >
          Site Foundation
        </button>
        {navMode === 'foundation' && (
          <ul style={{ listStyle: 'none', margin: '0 0 16px', padding: 0, display: 'grid', gap: 4 }}>
            {FOUNDATION_NAV.map((f) => (
              <li key={f.id}>
                <button
                  type="button"
                  onClick={() => setFoundationPanel(f.id)}
                  style={{
                    width: '100%',
                    textAlign: 'left',
                    padding: '8px 12px',
                    borderRadius: 8,
                    border: 'none',
                    background: foundationPanel === f.id ? '#F1F5F9' : 'transparent',
                    fontWeight: foundationPanel === f.id ? 700 : 500,
                    fontSize: 13,
                    cursor: 'pointer',
                  }}
                >
                  {f.label}
                </button>
              </li>
            ))}
          </ul>
        )}

        <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 0.06, color: '#94A3B8', marginBottom: 8 }}>
          Public pages
        </div>
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 4 }}>
          {DESIGN_PAGE_CATALOG.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                onClick={() => {
                  if (expandedPages[p.id]) selectPage(p.id)
                  else setExpandedPages((prev) => ({ ...prev, [p.id]: true }))
                  selectPage(p.id)
                }}
                style={{
                  width: '100%',
                  textAlign: 'left',
                  padding: '8px 12px',
                  borderRadius: 8,
                  border: 'none',
                  background: navMode === 'page' && pageId === p.id ? '#EBF0F8' : 'transparent',
                  fontWeight: navMode === 'page' && pageId === p.id ? 700 : 600,
                  fontSize: 14,
                  cursor: 'pointer',
                }}
              >
                {p.label}
              </button>
              {(expandedPages[p.id] || (navMode === 'page' && pageId === p.id)) && (
                <ul style={{ listStyle: 'none', margin: '4px 0 8px', padding: '0 0 0 12px', borderLeft: '2px solid #E2E8F0' }}>
                  {p.sections.map((s) => (
                    <li key={s.id}>
                      <button
                        type="button"
                        onClick={() => selectSection(p.id, s.id)}
                        style={{
                          width: '100%',
                          textAlign: 'left',
                          padding: '6px 10px',
                          borderRadius: 6,
                          border: 'none',
                          background: navMode === 'page' && pageId === p.id && sectionId === s.id ? '#DBEAFE' : 'transparent',
                          fontSize: 13,
                          fontWeight: navMode === 'page' && pageId === p.id && sectionId === s.id ? 700 : 400,
                          cursor: 'pointer',
                          color: '#334155',
                        }}
                      >
                        {s.label}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
        {toolsSlot && <div style={{ marginTop: 20, borderTop: '1px solid #E2E8F0', paddingTop: 16 }}>{toolsSlot}</div>}
      </nav>

      <div className="s2-design-builder-main" style={{ minWidth: 0 }}>
        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 16 }}>
          <div>
            <div style={{ fontSize: 12, color: '#64748B', marginBottom: 4 }}>{breadcrumb}</div>
            <h2 style={{ margin: 0, fontSize: 20 }}>{navMode === 'foundation' ? 'Site Foundation' : section?.label}</h2>
          </div>
          <a href={previewPath} target="_blank" rel="noopener noreferrer" className="s2-btn s2-btn--outline s2-btn--sm">
            Preview page ↗
          </a>
        </div>

        {navMode === 'foundation' ? (
          <SiteFoundationPanel
            panel={foundationPanel}
            config={config}
            patch={patch}
            fonts={fonts}
            fontQuery={fontQuery}
            setFontQuery={setFontQuery}
            filteredFonts={filteredFonts}
            presets={presets}
            applyPreset={applyPreset}
            saving={saving}
            onLayoutGlobal={() => setLayoutFocus({ scope: 'global' })}
          />
        ) : (
          <>
            <div className="s2-design-system-tabs s2-design-builder-subtabs" style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
              {(['content', 'design'] as const).map((t) => (
                <button
                  key={t}
                  type="button"
                  onClick={() => setSectionTab(t)}
                  style={{
                    padding: '10px 20px',
                    borderRadius: 999,
                    border: sectionTab === t ? '2px solid var(--s2-primary, #4A6FA5)' : '1px solid #E2E8F0',
                    background: sectionTab === t ? '#EBF0F8' : '#fff',
                    fontWeight: sectionTab === t ? 700 : 500,
                    cursor: 'pointer',
                    textTransform: 'capitalize',
                  }}
                >
                  {t}
                </button>
              ))}
            </div>
            {sectionTab === 'content' ? (
              <SectionContentPanel
                section={section}
                page={page}
                settings={settings}
                onChange={(k, v) => setSettings((prev) => ({ ...prev, [k]: v }))}
                onSave={saveSectionContent}
                saving={contentSaving}
              />
            ) : (
              <SectionDesignPanel
                section={section}
                page={page}
                config={config}
                patch={patch}
                settings={settings}
                onSettingsChange={(k, v) => setSettings((prev) => ({ ...prev, [k]: v }))}
                onSavePlatformDesign={saveSectionPlatformDesign}
                platformDesignSaving={platformDesignSaving}
                onOpenLayout={(focus) => setLayoutFocus(focus)}
              />
            )}
          </>
        )}

        {layoutFocus && (
          <div style={{ marginTop: 32, borderTop: '1px solid #E2E8F0', paddingTop: 24 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <h3 style={{ margin: 0 }}>Layout & width</h3>
              <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={() => setLayoutFocus(null)}>
                Close
              </button>
            </div>
            <WidthLayoutPanel config={config} patch={patch} focus={layoutFocus} />
          </div>
        )}

        <div style={{ marginTop: 32 }}>
          <h3 style={{ fontSize: 15, marginBottom: 8 }}>Live preview</h3>
          <LiveSitePreviewFrame path={previewPath} />
        </div>
      </div>
    </div>
  )
}
