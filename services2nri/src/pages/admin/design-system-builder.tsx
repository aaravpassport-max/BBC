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
import { PageTypeWidthStudio } from './page-type-width-studio'
import { DesignSectionManager } from './design-section-manager'
import {
  DesignColorSwatchGrid,
  DesignPremiumGroup,
  DesignTypographyGrid,
  DesignVisibilityToggle,
} from './design-premium-design-panel'
import { DEFAULT_HOME_SECTION_ORDER, parseHomeSectionOrder } from '@/lib/home-section-order'
import {
  normalizeSettingsApiResponse,
  prepareSettingsPayload,
  readAdminSetting,
} from '@/lib/settings-admin'

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
    <DesignPremiumGroup
      title="Typography"
      description="Fine-tune headings and body text inside this band. Clear a field to inherit from Site Foundation."
      badge="Section override"
    >
      <DesignTypographyGrid>
        {SECTION_TYPOGRAPHY_ROLES.map((role) => (
          <div key={role} className="s2-ds-type-role">
            <div className="s2-ds-type-role__label">{role.replace(/_/g, ' ')}</div>
            <div className="s2-ds-type-role__fields">
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
          </div>
        ))}
      </DesignTypographyGrid>
    </DesignPremiumGroup>
  )
}

function SectionContentPanel({
  section,
  page,
  settings,
  onChange,
  onSave,
  saving,
  saveMessage,
}: {
  section: SectionCatalogDef
  page: PageCatalogDef
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
  onSave: () => void
  saving: boolean
  saveMessage: { type: 'success' | 'error'; text: string } | null
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
      <div className="s2-design-builder-panel__card">
        <div style={{ display: 'grid', gap: 4, maxWidth: 720 }}>
          {fields.map((f) => {
            const isJson = f.key.endsWith('_json')
            const val = readAdminSetting(settings, f.key)
            return (
              <div key={f.key} className="s2-design-builder-field">
                <label htmlFor={`ds-field-${f.key}`}>{f.label}</label>
                {f.type === 'textarea' ? (
                  <textarea
                    id={`ds-field-${f.key}`}
                    rows={f.rows ?? 3}
                    className={isJson ? 's2-design-builder-field__json' : undefined}
                    value={val}
                    onChange={(e) => onChange(f.key, e.target.value)}
                    placeholder={f.placeholder}
                  />
                ) : (
                  <input
                    id={`ds-field-${f.key}`}
                    type="text"
                    value={val}
                    onChange={(e) => onChange(f.key, e.target.value)}
                    placeholder={f.placeholder}
                  />
                )}
                {f.hint && <span className="s2-design-builder-field__hint">{f.hint}</span>}
              </div>
            )
          })}
        </div>
        <div className="s2-design-builder-actions">
          <button type="button" className="s2-btn s2-btn--primary" onClick={onSave} disabled={saving}>
            {saving ? 'Saving content…' : 'Save section content'}
          </button>
          {saveMessage && (
            <span className={`s2-design-builder-toast s2-design-builder-toast--${saveMessage.type}`} role="status">
              {saveMessage.text}
            </span>
          )}
        </div>
      </div>
    </div>
  )
}

function PageTemplateForPage(page: PageCatalogDef): PageTemplateDef | undefined {
  return PAGE_TEMPLATES.find((t) => t.id === page.id)
}

type SectionEditorTab = 'content' | 'design'
type PageWorkspaceTab = 'sections' | 'width'

function widthFocusForSection(page: PageCatalogDef, section: SectionCatalogDef): WidthLayoutFocus {
  if (section.isPageScope) {
    return page.widthPageSlug
      ? { scope: 'page', selectedPage: page.widthPageSlug }
      : page.widthPageType
        ? { scope: 'page_type', selectedType: page.widthPageType }
        : { scope: 'global' }
  }
  if (page.widthPageType === 'home' && section.sectionKey === 'hero') {
    return { scope: 'page_type_section', selectedType: 'home', selectedSection: 'hero' }
  }
  if (page.widthPageSlug) {
    return { scope: 'page', selectedPage: page.widthPageSlug, selectedSection: section.sectionKey }
  }
  if (page.widthPageType) {
    return { scope: 'page_type', selectedType: page.widthPageType, selectedSection: section.sectionKey }
  }
  return { scope: 'section', selectedSection: section.sectionKey }
}

function SectionPlatformDesignFields({
  section,
  settings,
  onChange,
  onSave,
  saving,
  saveMessage,
}: {
  section: SectionCatalogDef
  settings: Record<string, string>
  onChange: (key: string, value: string) => void
  onSave: () => void
  saving: boolean
  saveMessage: { type: 'success' | 'error'; text: string } | null
}) {
  const fields = section.designSettingFields || []
  if (fields.length === 0) return null
  return (
    <DesignPremiumGroup
      title="Band styling"
      description="Visual tokens for this section on the live site. Clear a field to inherit defaults."
      badge="Platform CSS"
    >
      <DesignColorSwatchGrid>
        {fields.map((f) => {
          const val = readAdminSetting(settings, f.key)
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
      </DesignColorSwatchGrid>
      <div className="s2-design-builder-actions">
        <button type="button" className="s2-btn s2-btn--primary" onClick={onSave} disabled={saving}>
          {saving ? 'Saving styling…' : 'Save band styling'}
        </button>
        {saveMessage && (
          <span className={`s2-design-builder-toast s2-design-builder-toast--${saveMessage.type}`} role="status">
            {saveMessage.text}
          </span>
        )}
      </div>
    </DesignPremiumGroup>
  )
}

function PageDefaultsDesignPanel({
  page,
  config,
  patch,
}: {
  page: PageCatalogDef
  config: DesignConfig
  patch: PatchFn
}) {
  const template = PageTemplateForPage(page)
  if (!template) {
    return <p style={{ color: '#64748B' }}>No page template metadata for this route.</p>
  }

  return (
    <div className="s2-ds-design-studio" style={{ display: 'grid', gap: 16 }}>
      <DesignPremiumGroup
        title="Page colors"
        description="Overrides for this route only. Individual sections can still override further."
        badge="Page defaults"
      >
        <DesignColorSwatchGrid>
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
        </DesignColorSwatchGrid>
      </DesignPremiumGroup>
      <DesignPremiumGroup title="Page typography" description="Title and body sizes for this page type.">
        <DesignTypographyGrid>
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
        </DesignTypographyGrid>
      </DesignPremiumGroup>
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
  designSaveMessage,
  onVisibilityChange,
}: {
  section: SectionCatalogDef
  page: PageCatalogDef
  config: DesignConfig
  patch: PatchFn
  settings: Record<string, string>
  onSettingsChange: (key: string, value: string) => void
  onSavePlatformDesign: () => void
  platformDesignSaving: boolean
  designSaveMessage: { type: 'success' | 'error'; text: string } | null
  onVisibilityChange?: (visible: boolean) => void
}) {
  if (section.isPageScope) {
    return <PageDefaultsDesignPanel page={page} config={config} patch={patch} />
  }
  const hideKey = section.hideSettingKey
  const visible = !hideKey || readAdminSetting(settings, hideKey) !== '1'

  return (
    <div className="s2-ds-design-studio" style={{ display: 'grid', gap: 16 }}>
      {hideKey && onVisibilityChange && (
        <DesignPremiumGroup title="Visibility" badge={visible ? 'Live' : 'Hidden'}>
          <DesignVisibilityToggle
            visible={visible}
            onChange={onVisibilityChange}
            label={`Show “${section.label}” on the public page`}
          />
        </DesignPremiumGroup>
      )}

      <DesignPremiumGroup
        title="Section colors"
        description="Design tokens for this band. Inherited from Site Foundation → page until you customize."
        badge="Section override"
      >
        <DesignColorSwatchGrid>
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
        </DesignColorSwatchGrid>
      </DesignPremiumGroup>

      <SectionTypographyPanel sectionKey={section.sectionKey} config={config} patch={patch} />

      <SectionPlatformDesignFields
        section={section}
        settings={settings}
        onChange={onSettingsChange}
        onSave={onSavePlatformDesign}
        saving={platformDesignSaving}
        saveMessage={designSaveMessage}
      />
    </div>
  )
}

const SECTION_TAB_LABELS: Record<SectionEditorTab, string> = {
  content: 'Content',
  design: 'Design',
}

function SectionEditorTabs({
  tab,
  onTab,
  compact,
}: {
  tab: SectionEditorTab
  onTab: (t: SectionEditorTab) => void
  compact?: boolean
}) {
  return (
    <div className={`s2-design-builder-subtabs s2-design-section-tabs${compact ? ' s2-design-section-tabs--compact' : ''}`}>
      {(['content', 'design'] as const).map((t) => (
        <button
          key={t}
          type="button"
          className={tab === t ? 'is-active' : ''}
          onClick={() => onTab(t)}
        >
          {SECTION_TAB_LABELS[t]}
        </button>
      ))}
    </div>
  )
}

function PageSectionAccordion({
  section,
  page,
  expanded,
  onToggle,
  tab,
  onTab,
  settings,
  settingsLoading,
  config,
  patch,
  onSettingsChange,
  onSaveContent,
  onSavePlatformDesign,
  contentSaving,
  platformDesignSaving,
  contentSaveMessage,
  designSaveMessage,
  onVisibilityChange,
}: {
  section: SectionCatalogDef
  page: PageCatalogDef
  expanded: boolean
  onToggle: () => void
  tab: SectionEditorTab
  onTab: (t: SectionEditorTab) => void
  settings: Record<string, string>
  settingsLoading: boolean
  config: DesignConfig
  patch: PatchFn
  onSettingsChange: (key: string, value: string) => void
  onSaveContent: () => void
  onSavePlatformDesign: () => void
  contentSaving: boolean
  platformDesignSaving: boolean
  contentSaveMessage: { type: 'success' | 'error'; text: string } | null
  designSaveMessage: { type: 'success' | 'error'; text: string } | null
  onVisibilityChange?: (visible: boolean) => void
}) {
  return (
    <section className={`s2-design-section-accordion${expanded ? ' is-expanded' : ''}`}>
      <button
        type="button"
        className="s2-design-section-accordion__head"
        aria-expanded={expanded}
        onClick={onToggle}
      >
        <span className="s2-design-section-accordion__title">{section.label}</span>
        <span className="s2-design-section-accordion__chevron" aria-hidden />
      </button>
      {expanded && (
        <div className="s2-design-section-accordion__body">
          <SectionEditorTabs tab={tab} onTab={onTab} compact />
          {tab === 'content' && settingsLoading ? (
            <div className="s2-design-builder-panel">
              <p className="s2-design-builder-workspace__desc">Loading saved content…</p>
            </div>
          ) : tab === 'content' ? (
            <SectionContentPanel
              section={section}
              page={page}
              settings={settings}
              onChange={onSettingsChange}
              onSave={onSaveContent}
              saving={contentSaving}
              saveMessage={contentSaveMessage}
            />
          ) : (
            <div className="s2-design-builder-panel s2-ds-design-studio">
              <SectionDesignPanel
                section={section}
                page={page}
                config={config}
                patch={patch}
                settings={settings}
                onSettingsChange={onSettingsChange}
                onSavePlatformDesign={onSavePlatformDesign}
                platformDesignSaving={platformDesignSaving}
                designSaveMessage={designSaveMessage}
                onVisibilityChange={
                  onVisibilityChange ||
                  (section.hideSettingKey
                    ? (vis) => onSettingsChange(section.hideSettingKey!, vis ? '0' : '1')
                    : undefined)
                }
              />
            </div>
          )}
        </div>
      )}
    </section>
  )
}

function FoundationAreaAccordion({
  item,
  expanded,
  onToggle,
  children,
}: {
  item: (typeof FOUNDATION_NAV)[number]
  expanded: boolean
  onToggle: () => void
  children: React.ReactNode
}) {
  return (
    <section className={`s2-design-section-accordion s2-design-section-accordion--foundation${expanded ? ' is-expanded' : ''}`}>
      <button type="button" className="s2-design-section-accordion__head" aria-expanded={expanded} onClick={onToggle}>
        <span className="s2-design-section-accordion__title">{item.label}</span>
        <span className="s2-design-section-accordion__chevron" aria-hidden />
      </button>
      {expanded && <div className="s2-design-section-accordion__body s2-design-builder-panel">{children}</div>}
    </section>
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
          <p style={{ color: '#64748B', fontSize: 13, marginTop: 0 }}>
            Global container widths and gutters — the default for all public pages until page or section overrides apply.
          </p>
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
  const [pageId, setPageId] = useState('home')
  const [pageWorkspaceTab, setPageWorkspaceTab] = useState<PageWorkspaceTab>('sections')
  const [activeSectionId, setActiveSectionId] = useState<string | null>(null)
  const [sectionOrder, setSectionOrder] = useState<string[]>([])
  const [structureBaseline, setStructureBaseline] = useState('')
  const [structureSaving, setStructureSaving] = useState(false)
  const [structureMessage, setStructureMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)
  const [expandedFoundation, setExpandedFoundation] = useState<Record<string, boolean>>({ colors: true })
  const [sectionTabById, setSectionTabById] = useState<Record<string, SectionEditorTab>>({})
  const [settings, setSettings] = useState<Record<string, string>>({})
  const [contentSavingId, setContentSavingId] = useState<string | null>(null)
  const [platformDesignSavingId, setPlatformDesignSavingId] = useState<string | null>(null)
  const [contentSaveMessageById, setContentSaveMessageById] = useState<
    Record<string, { type: 'success' | 'error'; text: string }>
  >({})
  const [designSaveMessageById, setDesignSaveMessageById] = useState<
    Record<string, { type: 'success' | 'error'; text: string }>
  >({})
  const [settingsLoading, setSettingsLoading] = useState(true)
  const [fontQuery, setFontQuery] = useState('')

  const page = useMemo(() => DESIGN_PAGE_CATALOG.find((p) => p.id === pageId) || DESIGN_PAGE_CATALOG[0], [pageId])

  const manageableSections = useMemo(
    () => page.sections.filter((s) => !s.isPageScope),
    [page.sections],
  )

  const structureSnapshot = useCallback(
    (flat: Record<string, string>, order: string[]) => {
      const vis: Record<string, string> = {}
      manageableSections.forEach((s) => {
        if (s.hideSettingKey) vis[s.hideSettingKey] = flat[s.hideSettingKey] ?? '0'
      })
      return JSON.stringify({ vis, order })
    },
    [manageableSections],
  )

  const structureDirty =
    structureBaseline !== structureSnapshot(settings, page.id === 'home' ? sectionOrder : [])

  const orderedSectionIds = useMemo(() => {
    if (page.id !== 'home') return manageableSections.map((s) => s.id)
    const known = new Set(manageableSections.map((s) => s.id))
    const ids = sectionOrder.filter((id) => known.has(id))
    manageableSections.forEach((s) => {
      if (!ids.includes(s.id)) ids.push(s.id)
    })
    return ids
  }, [manageableSections, page.id, sectionOrder])

  const activeSection = useMemo(
    () => page.sections.find((s) => s.id === activeSectionId) || null,
    [activeSectionId, page.sections],
  )

  const sectionTabFor = useCallback(
    (sectionKey: string): SectionEditorTab => sectionTabById[sectionKey] || 'content',
    [sectionTabById],
  )

  const setSectionTabFor = useCallback((sectionKey: string, tab: SectionEditorTab) => {
    setSectionTabById((prev) => ({ ...prev, [sectionKey]: tab }))
  }, [])

  const applyStructureBaseline = useCallback(
    (flat: Record<string, string>, order: string[], pid: string) => {
      setStructureBaseline(structureSnapshot(flat, pid === 'home' ? order : []))
    },
    [structureSnapshot],
  )

  const loadSettings = useCallback(async () => {
    setSettingsLoading(true)
    try {
      const d = await api.get<{ settings: Record<string, unknown>; settings_flat?: Record<string, string> }>('admin/settings')
      const flat = normalizeSettingsApiResponse(d)
      setSettings(flat)
      const order = parseHomeSectionOrder(flat.home_section_order_json)
      setSectionOrder(order)
      applyStructureBaseline(flat, order, pageId)
    } catch {
      setSettings({})
      setSectionOrder([...DEFAULT_HOME_SECTION_ORDER])
    } finally {
      setSettingsLoading(false)
    }
  }, [applyStructureBaseline, pageId])

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
    setPageWorkspaceTab('sections')
    setActiveSectionId(null)
  }

  useEffect(() => {
    applyStructureBaseline(settings, sectionOrder, pageId)
    // Re-anchor dirty tracking when switching pages only (not on every settings keystroke).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageId])

  const selectFoundation = () => {
    setNavMode('foundation')
  }

  const toggleFoundationExpanded = (panelId: FoundationPanelId) => {
    setExpandedFoundation((prev) => ({ ...prev, [panelId]: !prev[panelId] }))
  }

  const saveSectionContent = async (section: SectionCatalogDef) => {
    const fields = section.isPageScope ? page.pageContentFields : section.contentFields
    if (!fields?.length) return
    setContentSavingId(section.id)
    setContentSaveMessageById((prev) => {
      const next = { ...prev }
      delete next[section.id]
      return next
    })
    try {
      const payload = prepareSettingsPayload(settings, fields.map((f) => f.key))
      await api.put('admin/settings', payload)
      await loadSettings()
      setContentSaveMessageById((prev) => ({
        ...prev,
        [section.id]: { type: 'success', text: 'Content saved — values reloaded for editing.' },
      }))
    } catch {
      setContentSaveMessageById((prev) => ({
        ...prev,
        [section.id]: { type: 'error', text: 'Could not save content. Try again.' },
      }))
    } finally {
      setContentSavingId(null)
    }
  }

  const saveSectionPlatformDesign = async (section: SectionCatalogDef) => {
    if (!section.designSettingFields?.length) return
    setPlatformDesignSavingId(section.id)
    setDesignSaveMessageById((prev) => {
      const next = { ...prev }
      delete next[section.id]
      return next
    })
    try {
      const payload = prepareSettingsPayload(
        settings,
        section.designSettingFields.map((f) => f.key),
      )
      await api.put('admin/settings', payload)
      await loadSettings()
      setDesignSaveMessageById((prev) => ({
        ...prev,
        [section.id]: { type: 'success', text: 'Section styling saved.' },
      }))
    } catch {
      setDesignSaveMessageById((prev) => ({
        ...prev,
        [section.id]: { type: 'error', text: 'Could not save styling.' },
      }))
    } finally {
      setPlatformDesignSavingId(null)
    }
  }

  const savePageStructure = async () => {
    setStructureSaving(true)
    setStructureMessage(null)
    try {
      const payload: Record<string, string> = {}
      manageableSections.forEach((s) => {
        if (s.hideSettingKey) payload[s.hideSettingKey] = readAdminSetting(settings, s.hideSettingKey) || '0'
      })
      if (page.id === 'home') {
        payload.home_section_order_json = JSON.stringify(orderedSectionIds)
      }
      await api.put('admin/settings', payload)
      await loadSettings()
      setStructureMessage({ type: 'success', text: 'Page structure saved — order and visibility updated on the live site.' })
    } catch {
      setStructureMessage({ type: 'error', text: 'Could not save page structure.' })
    } finally {
      setStructureSaving(false)
    }
  }

  const setSectionVisibility = (section: SectionCatalogDef, visible: boolean) => {
    if (!section.hideSettingKey) return
    setSettings((prev) => ({ ...prev, [section.hideSettingKey!]: visible ? '0' : '1' }))
  }

  const openSectionEditor = (sectionId: string, tab: SectionEditorTab) => {
    setActiveSectionId(sectionId)
    setSectionTabFor(sectionId, tab)
    setPageWorkspaceTab('sections')
  }

  const workspaceDesc =
    navMode === 'foundation'
      ? 'Global tokens and chrome for the entire public site. Pages and sections inherit these until they define an override.'
      : pageWorkspaceTab === 'width'
        ? 'Simple layout controls for this page type — no container jargon.'
        : 'Drag sections, hide or show bands, then open Content or Design for focused editing.'

  return (
    <div className="s2-design-system-builder">
      <nav className="s2-design-builder-nav" aria-label="Public pages">
        <p className="s2-design-builder-nav__intro">Which public page are you managing?</p>
        <button
          type="button"
          className={`s2-design-builder-nav__foundation${navMode === 'foundation' ? ' is-active' : ''}`}
          onClick={selectFoundation}
        >
          Site Foundation
        </button>

        <div className="s2-design-builder-nav__group-label">Public site pages</div>
        <ul className="s2-design-builder-nav__list">
          {DESIGN_PAGE_CATALOG.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                className={`s2-design-builder-nav__page-btn${navMode === 'page' && pageId === p.id ? ' is-active' : ''}`}
                onClick={() => selectPage(p.id)}
              >
                {p.label}
              </button>
            </li>
          ))}
        </ul>
        {toolsSlot && <div className="s2-design-builder-tools">{toolsSlot}</div>}
      </nav>

      <div className="s2-design-builder-main">
        <div className="s2-design-builder-workspace">
          <div className="s2-design-builder-workspace__head">
            <div className="s2-design-builder-breadcrumb">
              {navMode === 'foundation' ? (
                <>
                  <span className="s2-design-builder-breadcrumb__chip s2-design-builder-breadcrumb__chip--foundation">Foundation</span>
                  <span>Site-wide defaults</span>
                </>
              ) : (
                <>
                  <span className="s2-design-builder-breadcrumb__chip">Page</span>
                  <span>{page.label}</span>
                  <span aria-hidden>→</span>
                  <span>Sections</span>
                </>
              )}
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', gap: 12, alignItems: 'flex-start' }}>
              <div>
                <h2 className="s2-design-builder-workspace__title">
                  {navMode === 'foundation' ? 'Site Foundation' : page.label}
                </h2>
                {workspaceDesc && <p className="s2-design-builder-workspace__desc">{workspaceDesc}</p>}
              </div>
              {navMode === 'page' && (
                <a href={previewPath} target="_blank" rel="noopener noreferrer" className="s2-btn s2-btn--outline s2-btn--sm">
                  Preview page ↗
                </a>
              )}
            </div>
            {navMode === 'page' && (
              <div className="s2-ds-page-tabs" role="tablist">
                <button
                  type="button"
                  role="tab"
                  className={`s2-ds-page-tab${pageWorkspaceTab === 'sections' ? ' is-active' : ''}`}
                  onClick={() => setPageWorkspaceTab('sections')}
                >
                  Sections
                </button>
                <button
                  type="button"
                  role="tab"
                  className={`s2-ds-page-tab${pageWorkspaceTab === 'width' ? ' is-active' : ''}`}
                  onClick={() => setPageWorkspaceTab('width')}
                >
                  Layout &amp; Width
                </button>
              </div>
            )}
          </div>

        {navMode === 'foundation' ? (
          <div className="s2-design-builder-section-stack">
            {FOUNDATION_NAV.map((f) => (
              <FoundationAreaAccordion
                key={f.id}
                item={f}
                expanded={Boolean(expandedFoundation[f.id])}
                onToggle={() => toggleFoundationExpanded(f.id)}
              >
                {f.hint && <p className="s2-design-builder-workspace__desc" style={{ marginTop: 0 }}>{f.hint}</p>}
                <SiteFoundationPanel
                  panel={f.id}
                  config={config}
                  patch={patch}
                  fonts={fonts}
                  fontQuery={fontQuery}
                  setFontQuery={setFontQuery}
                  filteredFonts={filteredFonts}
                  presets={presets}
                  applyPreset={applyPreset}
                  saving={saving}
                />
              </FoundationAreaAccordion>
            ))}
          </div>
        ) : pageWorkspaceTab === 'width' ? (
          <div className="s2-design-builder-panel">
            <PageTypeWidthStudio page={page} config={config} patch={patch} />
          </div>
        ) : (
          <>
            {page.sections.some((s) => s.isPageScope) && (
              <div className="s2-design-builder-panel" style={{ paddingBottom: 0 }}>
                <PageSectionAccordion
                  section={page.sections.find((s) => s.isPageScope)!}
                  page={page}
                  expanded={activeSectionId === '_page'}
                  onToggle={() => setActiveSectionId(activeSectionId === '_page' ? null : '_page')}
                  tab={sectionTabFor('_page')}
                  onTab={(t) => setSectionTabFor('_page', t)}
                  settings={settings}
                  settingsLoading={settingsLoading}
                  config={config}
                  patch={patch}
                  onSettingsChange={(k, v) => setSettings((prev) => ({ ...prev, [k]: v }))}
                  onSaveContent={() => saveSectionContent(page.sections.find((s) => s.isPageScope)!)}
                  onSavePlatformDesign={() => saveSectionPlatformDesign(page.sections.find((s) => s.isPageScope)!)}
                  contentSaving={contentSavingId === '_page'}
                  platformDesignSaving={platformDesignSavingId === '_page'}
                  contentSaveMessage={contentSaveMessageById._page ?? null}
                  designSaveMessage={designSaveMessageById._page ?? null}
                />
              </div>
            )}
            <DesignSectionManager
              page={page}
              sections={manageableSections}
              orderedIds={orderedSectionIds}
              onReorder={setSectionOrder}
              settings={settings}
              onVisibilityChange={setSectionVisibility}
              activeSectionId={activeSectionId}
              onSelectSection={(id) => setActiveSectionId((prev) => (prev === id ? null : id))}
              onOpenSectionTab={openSectionEditor}
              onSaveStructure={savePageStructure}
              structureSaving={structureSaving}
              structureDirty={structureDirty}
              structureMessage={structureMessage}
              reorderEnabled={page.id === 'home'}
            />
            {activeSection && !activeSection.isPageScope && (
              <div className="s2-ds-section-editor">
                <PageSectionAccordion
                  section={activeSection}
                  page={page}
                  expanded
                  onToggle={() => setActiveSectionId(null)}
                  tab={sectionTabFor(activeSection.id)}
                  onTab={(t) => setSectionTabFor(activeSection.id, t)}
                  settings={settings}
                  settingsLoading={settingsLoading}
                  config={config}
                  patch={patch}
                  onSettingsChange={(k, v) => setSettings((prev) => ({ ...prev, [k]: v }))}
                  onSaveContent={() => saveSectionContent(activeSection)}
                  onSavePlatformDesign={() => saveSectionPlatformDesign(activeSection)}
                  contentSaving={contentSavingId === activeSection.id}
                  platformDesignSaving={platformDesignSavingId === activeSection.id}
                  contentSaveMessage={contentSaveMessageById[activeSection.id] ?? null}
                  designSaveMessage={designSaveMessageById[activeSection.id] ?? null}
                  onVisibilityChange={(vis) => setSectionVisibility(activeSection, vis)}
                />
              </div>
            )}
          </>
        )}

        <div className="s2-design-builder-preview-block">
          <h3>Live preview</h3>
          <LiveSitePreviewFrame path={previewPath} />
        </div>
        </div>
      </div>
    </div>
  )
}
