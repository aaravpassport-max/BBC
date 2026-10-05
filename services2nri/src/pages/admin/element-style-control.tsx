import React, { useEffect, useMemo, useRef } from 'react'
import { ELEMENT_STYLE_FIELDS } from '@/lib/element-style-fields'
import { HexColorField, PxTokenField, parsePx } from './design-admin-fields'
import { hasOverrideAtPath, OverrideFieldShell, readPathLeaf } from './design-inherit-ui'

type DesignConfig = Record<string, unknown>
type PatchFn = (path: string[], value: unknown) => void

function useDebouncedPatch(patch: PatchFn, delayMs = 280) {
  const patchRef = useRef(patch)
  patchRef.current = patch
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current)
    },
    [],
  )

  return useMemo(
    () => (path: string[], value: unknown) => {
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => patchRef.current(path, value), delayMs)
    },
    [delayMs],
  )
}

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
  const debouncedPatch = useDebouncedPatch(patch)
  const def = ELEMENT_STYLE_FIELDS.find((f) => f.key === styleKey)
  if (!def || path.length === 0) return null

  const raw = readPathLeaf(config, path)
  const val = raw === undefined || raw === null ? '' : String(raw)
  const inherited = !hasOverrideAtPath(config, path)

  const apply = (value: unknown) => debouncedPatch(path, value)
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
        <PxTokenField label="" value={val} onChange={(v) => apply(v ? parsePx(v) : null)} />
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
        onChange={(e) => apply(e.target.value || null)}
        placeholder="Inherit from theme"
        style={{ width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid #E2E8F0' }}
      />
    </OverrideFieldShell>
  )
}
