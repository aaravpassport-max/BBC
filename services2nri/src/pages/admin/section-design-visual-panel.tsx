/**
 * Reference-style Design tab — grouped controls, device breakpoints, visual hierarchy.
 */
import React, { useMemo, useState } from 'react'
import type { SectionCatalogDef, PageCatalogDef } from '@/lib/design-system-catalog'
import { SECTION_DESIGN_COLOR_KEYS, SECTION_TYPOGRAPHY_ROLES } from '@/lib/design-system-catalog'
import { HexColorField, parsePx } from './design-admin-fields'
import { hasOverrideAtPath, OverrideFieldShell, readPathLeaf } from './design-inherit-ui'
import {
  BandDesignGroup,
  BandDeviceTabs,
  BandFieldGrid,
  BandSegment,
  BandTextField,
  type DeviceId,
} from './design-visual-section-ui'
import { DesignVisibilityToggle } from './design-premium-design-panel'
import { readAdminSetting } from '@/lib/settings-admin'

type DesignConfig = Record<string, unknown>
type PatchFn = (path: string[], value: unknown) => void

function sectionColorPath(sectionKey: string, colorKey: string): string[] {
  return ['overrides', 'sections', sectionKey, 'colors', colorKey]
}

function sectionTypographyPath(sectionKey: string, role: string, field: string): string[] {
  return ['overrides', 'sections', sectionKey, 'typography', role, field]
}

function deviceTypographyField(device: DeviceId): 'size_desktop' | 'size_tablet' | 'size_mobile' {
  if (device === 'tablet') return 'size_tablet'
  if (device === 'mobile') return 'size_mobile'
  return 'size_desktop'
}

function inferBackgroundMode(raw: string): string {
  const v = raw.trim().toLowerCase()
  if (!v) return 'builtin'
  if (v === 'none' || v === 'transparent') return 'none'
  if (v.includes('gradient')) return 'gradient'
  if (v.includes('url(')) return 'image'
  return 'solid'
}

function backgroundValueForMode(mode: string, current: string): string {
  if (mode === 'builtin') return ''
  if (mode === 'none') return 'transparent'
  if (mode === 'gradient') {
    if (current.includes('gradient')) return current
    return 'linear-gradient(180deg, #0f234d 0%, #1e3a6e 100%)'
  }
  if (mode === 'image') {
    if (current.includes('url(')) return current
    return 'url(https://example.com/hero.jpg) center / cover no-repeat'
  }
  if (mode === 'solid') {
    if (current && !current.includes('gradient') && !current.includes('url(')) return current
    return '#0f234d'
  }
  return current
}

export function SectionDesignVisualPanel({
  section,
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
  const [device, setDevice] = useState<DeviceId>('desktop')

  const hideKey = section.hideSettingKey
  const visible = !hideKey || readAdminSetting(settings, hideKey) !== '1'

  const platformFields = section.designSettingFields ?? []
  const bgField = platformFields.find((f) => f.key.includes('_bg') && f.type !== 'color')
  const colorPlatform = platformFields.filter((f) => f.type === 'color')
  const spacingPlatform = platformFields.filter(
    (f) => f.type !== 'color' && f.key !== bgField?.key && !f.key.includes('opacity'),
  )
  const opacityField = platformFields.find((f) => f.key.includes('opacity'))

  const bgVal = bgField ? readAdminSetting(settings, bgField.key) : ''
  const bgMode = useMemo(() => inferBackgroundMode(bgVal), [bgVal])

  const typoRole = SECTION_TYPOGRAPHY_ROLES[0] || 'section_heading'
  const typoSizePath = sectionTypographyPath(section.sectionKey, typoRole, deviceTypographyField(device))
  const typoSizeRaw = readPathLeaf(config, typoSizePath)
  const typoSizeInherited = !hasOverrideAtPath(config, typoSizePath)
  const wPath = sectionTypographyPath(section.sectionKey, typoRole, 'font_weight')
  const wRaw = readPathLeaf(config, wPath)
  const wInherited = !hasOverrideAtPath(config, wPath)

  return (
    <div className="s2-band-design-studio">
      {hideKey && onVisibilityChange && (
        <BandDesignGroup title="Visibility">
          <DesignVisibilityToggle
            visible={visible}
            onChange={onVisibilityChange}
            label="Show this section on the public site"
          />
        </BandDesignGroup>
      )}

      {bgField && (
        <BandDesignGroup title="Background">
          <BandSegment
            label=""
            value={bgMode}
            onChange={(mode) => onSettingsChange(bgField.key, backgroundValueForMode(mode, bgVal))}
            options={[
              { id: 'builtin', label: 'Built-in' },
              { id: 'solid', label: 'Solid colour' },
              { id: 'gradient', label: 'Gradient' },
              { id: 'image', label: 'Image' },
              { id: 'none', label: 'None' },
            ]}
          />
          {bgMode !== 'builtin' && (
            <div style={{ marginTop: 12 }}>
              <BandTextField
                label={bgField.label}
                hint={bgField.hint}
                placeholder={bgField.placeholder || 'CSS background value'}
                value={bgVal}
                inherited={bgVal === ''}
                onChange={(v) => onSettingsChange(bgField.key, v)}
                onClear={bgVal !== '' ? () => onSettingsChange(bgField.key, '') : undefined}
              />
            </div>
          )}
        </BandDesignGroup>
      )}

      <BandDesignGroup title="Colours">
        <BandFieldGrid>
          {colorPlatform.map((f) => {
            const val = readAdminSetting(settings, f.key)
            return (
              <label key={f.key} className="s2-band-colour-chip">
                <span className="s2-band-field__label">{f.label}</span>
                <HexColorField label="" value={val} onChange={(v) => onSettingsChange(f.key, v)} />
              </label>
            )
          })}
          {SECTION_DESIGN_COLOR_KEYS.slice(0, 4).map((colorKey) => {
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
          {opacityField && (
            <BandTextField
              label={opacityField.label}
              placeholder={opacityField.placeholder}
              value={readAdminSetting(settings, opacityField.key)}
              inherited={readAdminSetting(settings, opacityField.key) === ''}
              onChange={(v) => onSettingsChange(opacityField.key, v)}
              onClear={
                readAdminSetting(settings, opacityField.key) !== ''
                  ? () => onSettingsChange(opacityField.key, '')
                  : undefined
              }
            />
          )}
        </BandFieldGrid>
      </BandDesignGroup>

      <BandDesignGroup title="Heading typography">
        <BandFieldGrid columns={2}>
          <label className="s2-band-field s2-band-field--select">
            <span className="s2-band-field__label">
              Font weight
              {wInherited && <span className="s2-band-field__inherit">Built-in</span>}
            </span>
            <select
              className="s2-band-select"
              value={typeof wRaw === 'string' ? wRaw : ''}
              onChange={(e) => patch(wPath, e.target.value || null)}
            >
              <option value="">Built-in</option>
              <option value="400">Regular (400)</option>
              <option value="500">Medium (500)</option>
              <option value="600">Semibold (600)</option>
              <option value="700">Bold (700)</option>
              <option value="800">Extra bold (800)</option>
            </select>
          </label>
        </BandFieldGrid>
        <BandDeviceTabs device={device} onDevice={setDevice} />
        <div className="s2-band-responsive-row">
          {(['desktop', 'tablet', 'mobile'] as const).map((d) => {
            const path = sectionTypographyPath(section.sectionKey, typoRole, deviceTypographyField(d))
            const raw = readPathLeaf(config, path)
            const inherited = !hasOverrideAtPath(config, path)
            const label = d === 'desktop' ? 'Desktop' : d === 'tablet' ? 'Tablet' : 'Mobile'
            return (
              <BandTextField
                key={d}
                label={`Font size — ${label}`}
                value={typeof raw === 'string' ? raw.replace(/px$/, '') : ''}
                placeholder="Built-in"
                inherited={inherited}
                onChange={(v) => patch(path, v ? parsePx(v) : null)}
                onClear={inherited ? undefined : () => patch(path, null)}
              />
            )
          })}
        </div>
      </BandDesignGroup>

      {spacingPlatform.length > 0 && (
        <BandDesignGroup title="Spacing & layout">
          <BandDeviceTabs device={device} onDevice={setDevice} />
          <BandFieldGrid>
            {spacingPlatform.map((f) => (
              <BandTextField
                key={f.key}
                label={f.label}
                hint={f.hint || (device !== 'desktop' ? `Applies at ${device} breakpoint when supported` : undefined)}
                placeholder={f.placeholder}
                value={readAdminSetting(settings, f.key)}
                inherited={readAdminSetting(settings, f.key) === ''}
                onChange={(v) => onSettingsChange(f.key, v)}
                onClear={
                  readAdminSetting(settings, f.key) !== '' ? () => onSettingsChange(f.key, '') : undefined
                }
              />
            ))}
          </BandFieldGrid>
        </BandDesignGroup>
      )}

      {platformFields.length > 0 && (
        <div className="s2-band-design-actions">
          <button type="button" className="s2-btn s2-btn--accent" onClick={onSavePlatformDesign} disabled={platformDesignSaving}>
            {platformDesignSaving ? 'Saving…' : 'Save section styling'}
          </button>
          {designSaveMessage && (
            <span className={`s2-design-builder-toast s2-design-builder-toast--${designSaveMessage.type}`} role="status">
              {designSaveMessage.text}
            </span>
          )}
        </div>
      )}
    </div>
  )
}
