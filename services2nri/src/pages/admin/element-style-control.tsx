import React from 'react'
import { ELEMENT_STYLE_FIELDS } from '@/lib/element-style-fields'
import { HexColorField, PxTokenField, parsePx } from './design-admin-fields'
import { hasOverrideAtPath, OverrideFieldShell, readPathLeaf } from './design-inherit-ui'

type DesignConfig = Record<string, unknown>
type PatchFn = (path: string[], value: unknown) => void

export function ElementStyleControlEditor({
  styleKey,
  path,
  config,
  patch,
}: {
  styleKey: string
  path: string[]
  config: DesignConfig
  patch: PatchFn
}) {
  const def = ELEMENT_STYLE_FIELDS.find((f) => f.key === styleKey)
  if (!def || path.length === 0) return null

  const raw = readPathLeaf(config, path)
  const val = raw === undefined || raw === null ? '' : String(raw)
  const inherited = !hasOverrideAtPath(config, path)

  const onClear = inherited ? undefined : () => patch(path, null)

  if (def.type === 'color') {
    return (
      <OverrideFieldShell label={def.label} inherited={inherited} onClear={onClear}>
        <HexColorField label="" value={val} onChange={(v) => patch(path, v || null)} />
      </OverrideFieldShell>
    )
  }

  if (def.type === 'px') {
    return (
      <OverrideFieldShell label={def.label} inherited={inherited} onClear={onClear}>
        <PxTokenField label="" value={val} onChange={(v) => patch(path, v ? parsePx(v) : null)} />
      </OverrideFieldShell>
    )
  }

  if (def.type === 'select' && def.options) {
    return (
      <OverrideFieldShell label={def.label} inherited={inherited} onClear={onClear}>
        <select
          value={val}
          onChange={(e) => patch(path, e.target.value || null)}
          style={{ width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid #E2E8F0' }}
        >
          <option value="">Inherit</option>
          {def.options.map((opt) => (
            <option key={opt} value={opt}>
              {opt}
            </option>
          ))}
        </select>
      </OverrideFieldShell>
    )
  }

  return (
    <OverrideFieldShell label={def.label} inherited={inherited} onClear={onClear}>
      <input
        type="text"
        value={val}
        onChange={(e) => patch(path, e.target.value || null)}
        placeholder="Inherit from theme"
        style={{ width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid #E2E8F0' }}
      />
    </OverrideFieldShell>
  )
}
