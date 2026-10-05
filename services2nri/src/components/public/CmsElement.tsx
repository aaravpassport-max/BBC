/**
 * Marks a configurable frontend element for CMS audit + optional per-element hide.
 */
import React from 'react'
import { useStore } from '@/lib/store'
import { sectionHidden } from '@/lib/section-visibility'

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
  const hideKey =
    hideSettingKey || `hide_el_${pageId.replace(/-/g, '_')}_${sectionKey}_${elementId}`
  if (sectionHidden(settings, hideKey)) return null

  return (
    <Tag
      className={className}
      style={style}
      data-s2-element={elementId}
      data-s2-section={sectionKey}
      data-s2-page={pageId}
    >
      {children}
    </Tag>
  )
}
