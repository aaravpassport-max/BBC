/**
 * Section → Element hierarchical CMS inspector (content / design / layout / visibility).
 */
import React, { useMemo, useState } from 'react'
import type { PageCatalogDef, SectionCatalogDef } from '@/lib/design-system-catalog'
import {
  buildSectionElementTree,
  type CmsElementControl,
  type CmsElementNode,
  type ElementControlGroup,
} from '@/lib/design-element-tree'
import { readAdminSetting } from '@/lib/settings-admin'
import { OverrideFieldShell, hasOverrideAtPath } from './design-inherit-ui'

const GROUP_LABELS: Record<ElementControlGroup, string> = {
  content: 'Content',
  design: 'Design',
  layout: 'Layout',
  visibility: 'Visibility',
  responsive: 'Responsive',
}

type DesignConfig = Record<string, unknown>

function ControlEditor({
  control,
  settings,
  config,
  onSettingsChange,
}: {
  control: CmsElementControl
  settings: Record<string, string>
  config: DesignConfig
  onSettingsChange: (key: string, value: string) => void
}) {
  if (control.group === 'visibility' && control.settingKey) {
    const hidden = readAdminSetting(settings, control.settingKey) === '1'
    return (
      <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
        <input
          type="checkbox"
          checked={!hidden}
          onChange={(e) => onSettingsChange(control.settingKey!, e.target.checked ? '0' : '1')}
        />
        Visible on public site
      </label>
    )
  }

  if (control.group === 'layout' || control.group === 'responsive') {
    const path = control.designPath || []
    const hasPath = path.length > 0
    const inherited = !hasPath || !hasOverrideAtPath(config, path)
    return (
      <p className="s2-ds-premium-card__hint" style={{ margin: 0 }}>
        {control.label} — use the <strong>Design</strong> tab (Width &amp; Layout studio) or section typography
        overrides. JSON path: <code>{path.join(' › ')}</code>
        {hasPath && (
          <span style={{ display: 'block', marginTop: 6 }}>
            Status: {inherited ? 'Inherited from Site Foundation' : 'Custom override active'}
          </span>
        )}
      </p>
    )
  }

  if (!control.settingKey || !control.field) {
    return (
      <p className="s2-ds-premium-card__hint" style={{ margin: 0 }}>
        {control.label === 'Content source'
          ? 'This element is managed in the Service Registry (per-service page builder) or linked admin tool — not platform-wide settings.'
          : 'No editable control mapped.'}
      </p>
    )
  }

  const field = control.field
  const val = readAdminSetting(settings, control.settingKey)
  const isJson = control.settingKey.endsWith('_json')

  const elementHideKey = control.hideSettingKey?.startsWith('hide_el_') ? control.hideSettingKey : undefined

  return (
    <>
      <OverrideFieldShell
        label={field.label}
        hint={'hint' in field ? field.hint : undefined}
        inherited={val === '' || val === undefined}
        onClear={
          val
            ? () => onSettingsChange(control.settingKey!, '')
            : undefined
        }
      >
        {field.type === 'textarea' ? (
          <textarea
            rows={'rows' in field ? field.rows ?? 3 : 3}
            className={isJson ? 's2-design-builder-field__json' : undefined}
            value={val}
            onChange={(e) => onSettingsChange(control.settingKey!, e.target.value)}
            placeholder={'placeholder' in field ? field.placeholder : undefined}
            style={{ width: '100%' }}
          />
        ) : (
          <input
            type="text"
            value={val}
            onChange={(e) => onSettingsChange(control.settingKey!, e.target.value)}
            placeholder={'placeholder' in field ? field.placeholder : undefined}
            style={{ width: '100%' }}
          />
        )}
      </OverrideFieldShell>
      {elementHideKey && (
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, marginTop: 6 }}>
          <input
            type="checkbox"
            checked={readAdminSetting(settings, elementHideKey) !== '1'}
            onChange={(e) => onSettingsChange(elementHideKey, e.target.checked ? '0' : '1')}
          />
          Show this element on the public site
        </label>
      )}
    </>
  )
}

export function SectionElementInspector({
  page,
  section,
  settings,
  config,
  onSettingsChange,
  onSave,
  saving,
}: {
  page: PageCatalogDef
  section: SectionCatalogDef
  settings: Record<string, string>
  config: DesignConfig
  onSettingsChange: (key: string, value: string) => void
  onSave: () => void
  saving: boolean
}) {
  const tree = useMemo(() => buildSectionElementTree(page.id, section), [page.id, section])
  const [activeElementId, setActiveElementId] = useState<string>(tree[0]?.id || '_section')

  const activeElement: CmsElementNode | undefined = tree.find((n) => n.id === activeElementId) || tree[0]

  const grouped = useMemo(() => {
    const map = new Map<ElementControlGroup, CmsElementControl[]>()
    for (const c of activeElement?.controls || []) {
      const list = map.get(c.group) || []
      list.push(c)
      map.set(c.group, list)
    }
    return map
  }, [activeElement])

  return (
    <div className="s2-ds-element-inspector">
      <div className="s2-ds-element-inspector__intro">
        <h4 className="s2-ds-element-inspector__title">Elements in this section</h4>
        <p className="s2-ds-premium-card__hint">
          Select an element to edit its content, styling, layout, and visibility. Inherited values show a grey badge;
          use <strong>Clear override</strong> to restore Site Foundation defaults.
        </p>
      </div>
      <div className="s2-ds-element-inspector__split">
        <ul className="s2-ds-element-tree" role="listbox" aria-label="Section elements">
          {tree.map((node) => (
            <li key={node.id}>
              <button
                type="button"
                role="option"
                aria-selected={activeElement?.id === node.id}
                className={`s2-ds-element-tree__item${activeElement?.id === node.id ? ' is-active' : ''}`}
                onClick={() => setActiveElementId(node.id)}
              >
                <span className="s2-ds-element-tree__label">{node.label}</span>
                <span className="s2-ds-element-tree__count">{node.controls.length}</span>
              </button>
            </li>
          ))}
        </ul>
        <div className="s2-ds-element-detail">
          {activeElement && (
            <>
              <h5 className="s2-ds-element-detail__heading">{activeElement.label}</h5>
              {activeElement.description && (
                <p className="s2-ds-premium-card__hint" style={{ marginTop: 0 }}>{activeElement.description}</p>
              )}
              {(['content', 'design', 'layout', 'visibility', 'responsive'] as const).map((group) => {
                const controls = grouped.get(group)
                if (!controls?.length) return null
                return (
                  <div key={group} className="s2-ds-element-detail__group">
                    <div className="s2-ds-element-detail__group-label">{GROUP_LABELS[group]}</div>
                    <div className="s2-ds-element-detail__controls">
                      {controls.map((control) => (
                        <ControlEditor
                          key={control.id}
                          control={control}
                          settings={settings}
                          config={config}
                          onSettingsChange={onSettingsChange}
                        />
                      ))}
                    </div>
                  </div>
                )
              })}
            </>
          )}
        </div>
      </div>
      <div className="s2-design-builder-actions s2-band-design-actions">
        <button type="button" className="s2-btn s2-btn--accent" onClick={onSave} disabled={saving}>
          {saving ? 'Saving…' : 'Save element changes'}
        </button>
      </div>
    </div>
  )
}
