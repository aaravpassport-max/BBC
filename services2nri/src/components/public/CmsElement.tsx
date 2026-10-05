/**
 * Marks a configurable frontend element for CMS audit + optional per-element hide + design tokens.
 */
import React, { useEffect, useMemo, useState } from 'react'
import { useStore } from '@/lib/store'
import { sectionHidden } from '@/lib/section-visibility'
import { DESIGN_UPDATED_EVENT } from '@/lib/design-live-sync'
import { getRuntimeDesignConfig } from '@/lib/apply-design-config'
import { elementStyleToReactStyle, readElementStyleTokens } from '@/lib/element-style-runtime'
import type { DesignPayload } from '@/lib/design-resolve'

export function CmsElement({
  pageId,
  sectionKey,
  elementId,
  hideSettingKey,
  as: Tag = 'div',
  className,
  style,
  children,
}: {
  pageId: string
  sectionKey: string
  elementId: string
  hideSettingKey?: string
  as?: keyof JSX.IntrinsicElements
  className?: string
  style?: React.CSSProperties
  children: React.ReactNode
}) {
  const settings = useStore((s) => s.settings)
  const [designTick, setDesignTick] = useState(0)

  useEffect(() => {
    const onDesign = () => setDesignTick((n) => n + 1)
    window.addEventListener(DESIGN_UPDATED_EVENT, onDesign)
    return () => window.removeEventListener(DESIGN_UPDATED_EVENT, onDesign)
  }, [])

  const hideKey =
    hideSettingKey || `hide_el_${pageId.replace(/-/g, '_')}_${sectionKey}_${elementId}`
  if (sectionHidden(settings, hideKey)) return null

  const cmsStyle = useMemo(() => {
    void designTick
    const design = getRuntimeDesignConfig()?.design as DesignPayload | undefined
    const tokens = readElementStyleTokens(design, sectionKey, elementId)
    return elementStyleToReactStyle(tokens, design)
  }, [designTick, sectionKey, elementId])

  return (
    <Tag
      className={className}
      style={{ ...cmsStyle, ...style }}
      data-s2-element={elementId}
      data-s2-section={sectionKey}
      data-s2-page={pageId}
    >
      {children}
    </Tag>
  )
}
