/**
 * Simplified, page-type-specific layout & width controls (premium presentation).
 */
import React, { useMemo, useState } from 'react'
import type { PageCatalogDef } from '@/lib/design-system-catalog'
import {
  widthLayerBasePath,
  widthProfileForPageType,
  type SimpleWidthControlDef,
} from '@/lib/page-width-profiles'
import { hasOverrideAtPath, OverrideFieldShell, readPathLeaf } from './design-inherit-ui'
import { BP, BP_META, type BreakpointId } from './width-layout-shared'
import { parsePx, stripPxForInput } from './design-admin-fields'

type DesignConfig = Record<string, unknown>
type PatchFn = (path: string[], value: unknown) => void

const WIDTH_PRESETS = [
  { label: 'Full width', value: '100%' },
  { label: 'Wide 1320', value: '1320' },
  { label: 'Standard 1200', value: '1200' },
  { label: 'Comfort 1080', value: '1080' },
  { label: 'Compact 960', value: '960' },
  { label: 'Narrow 720', value: '720' },
]

const GUTTER_PRESETS = [
  { label: 'Tight 12px', value: '12' },
  { label: 'Balanced 20px', value: '20' },
  { label: 'Roomy 28px', value: '28' },
  { label: 'Spacious 40px', value: '40' },
]

function ensureResponsive(value: unknown): Record<BreakpointId, string> {
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    return { desktop: '', laptop: '', tablet: '', mobile: '', ...(value as Record<string, string>) }
  }
  if (value == null || value === '') {
    return { desktop: '', laptop: '', tablet: '', mobile: '' }
  }
  const s = String(value)
  return { desktop: s, laptop: s, tablet: s, mobile: s }
}

function readAtBp(value: unknown, bp: BreakpointId): string {
  const obj = ensureResponsive(value)
  const order: BreakpointId[] =
    bp === 'mobile'
      ? ['mobile', 'tablet', 'laptop', 'desktop']
      : bp === 'tablet'
        ? ['tablet', 'laptop', 'desktop']
        : bp === 'laptop'
          ? ['laptop', 'desktop']
          : ['desktop']
  for (const key of order) {
    const v = String(obj[key] ?? '').trim()
    if (v) return stripPxForInput(v)
  }
  return ''
}

function WidthMiniPreview({ pagePx, contentPx, gutterPx }: { pagePx: number; contentPx: number; gutterPx: number }) {
  const ref = 1200
  return (
    <div className="s2-ds-width-preview" aria-hidden>
      <div className="s2-ds-width-preview__frame">
        <div className="s2-ds-width-preview__page" style={{ width: `${Math.min(100, (pagePx / ref) * 100)}%` }} />
        <div className="s2-ds-width-preview__content" style={{ width: `${Math.min(92, (contentPx / ref) * 100)}%` }} />
        <div className="s2-ds-width-preview__gutter" style={{ paddingInline: `${Math.min(24, gutterPx / 2)}px` }} />
      </div>
      <p className="s2-ds-width-preview__caption">Live layout preview (approximate)</p>
    </div>
  )
}

function SimpleWidthControl({
  control,
  page,
  config,
  patch,
  bp,
}: {
  control: SimpleWidthControlDef
  page: PageCatalogDef
  config: DesignConfig
  patch: PatchFn
  bp: BreakpointId
}) {
  const base = widthLayerBasePath(page.widthPageType || 'page', page.widthPageSlug, control)
  const path = [...base, control.widthKey]
  const raw = readPathLeaf(config, path)
  const inherited = !hasOverrideAtPath(config, path)
  const display = readAtBp(raw, bp)
  const presets = control.widthKey === 'padding_x' ? GUTTER_PRESETS : WIDTH_PRESETS

  const setValue = (next: string) => {
    const current = ensureResponsive(raw)
    const val = next ? parsePx(next) : ''
    const updated = { ...current, [bp]: val }
    const allEmpty = BP.every((k) => !String(updated[k] ?? '').trim())
    patch(path, allEmpty ? null : updated)
  }

  return (
    <div className="s2-ds-premium-card s2-ds-width-control">
      <div className="s2-ds-premium-card__head">
        <div>
          <h4 className="s2-ds-premium-card__title">{control.label}</h4>
          <p className="s2-ds-premium-card__hint">{control.hint}</p>
        </div>
        <span className={`s2-ds-status-chip${inherited ? ' s2-ds-status-chip--inherit' : ' s2-ds-status-chip--custom'}`}>
          {inherited ? 'Inherited' : 'Custom'}
        </span>
      </div>
      <div className="s2-ds-width-presets">
        {presets.map((p) => (
          <button
            key={p.label}
            type="button"
            className={`s2-ds-width-preset${display === p.value ? ' is-active' : ''}`}
            onClick={() => setValue(p.value)}
          >
            {p.label}
          </button>
        ))}
      </div>
      <OverrideFieldShell
        label={`${BP_META[bp].label} value`}
        hint={BP_META[bp].hint}
        inherited={inherited}
        onClear={inherited ? undefined : () => patch(path, null)}
      >
        <input
          type="text"
          className="s2-ds-field-input"
          placeholder="e.g. 1200 or 100%"
          value={display}
          onChange={(e) => setValue(e.target.value)}
        />
      </OverrideFieldShell>
    </div>
  )
}

export function PageTypeWidthStudio({
  page,
  config,
  patch,
}: {
  page: PageCatalogDef
  config: DesignConfig
  patch: PatchFn
}) {
  const profile = useMemo(
    () => widthProfileForPageType(page.widthPageType, page.widthPageSlug),
    [page.widthPageSlug, page.widthPageType],
  )
  const [bp, setBp] = useState<BreakpointId>('desktop')

  const previewNums = useMemo(() => {
    const readNum = (control: SimpleWidthControlDef) => {
      const base = widthLayerBasePath(page.widthPageType || 'page', page.widthPageSlug, control)
      const raw = readPathLeaf(config, [...base, control.widthKey])
      const v = readAtBp(raw, bp)
      const n = parseFloat(v.replace(/[^0-9.]/g, ''))
      return Number.isFinite(n) ? n : 1200
    }
    const pageC = profile.controls.find((c) => c.widthKey === 'page_max') || profile.controls[0]
    const contentC = profile.controls.find((c) => c.widthKey === 'content_max') || profile.controls[0]
    const gutterC = profile.controls.find((c) => c.widthKey === 'padding_x') || profile.controls[0]
    return {
      pagePx: readNum(pageC),
      contentPx: readNum(contentC),
      gutterPx: readNum(gutterC),
    }
  }, [bp, config, page.widthPageSlug, page.widthPageType, profile.controls])

  return (
    <div className="s2-ds-width-studio">
      <div className="s2-ds-width-studio__hero">
        <div>
          <h3 className="s2-ds-width-studio__title">{profile.title}</h3>
          <p className="s2-ds-width-studio__lead">{profile.subtitle}</p>
          <p className="s2-ds-width-studio__context">
            Editing layout for <strong>{page.label}</strong>. Values inherit from Site Foundation until you customize them here.
          </p>
        </div>
        <WidthMiniPreview {...previewNums} />
      </div>

      <div className="s2-ds-bp-tabs" role="tablist" aria-label="Device width">
        {BP.map((id) => (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={bp === id}
            className={`s2-ds-bp-tab${bp === id ? ' is-active' : ''}`}
            onClick={() => setBp(id)}
          >
            <span className="s2-ds-bp-tab__label">{BP_META[id].label}</span>
            <span className="s2-ds-bp-tab__hint">{BP_META[id].hint}</span>
          </button>
        ))}
      </div>

      <div className="s2-ds-width-grid">
        {profile.controls.map((control) => (
          <SimpleWidthControl
            key={control.id}
            control={control}
            page={page}
            config={config}
            patch={patch}
            bp={control.showResponsive === false ? 'desktop' : bp}
          />
        ))}
      </div>
    </div>
  )
}
