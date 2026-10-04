import React from 'react'
import { useLocation } from 'react-router-dom'
import { getRuntimeDesignConfig } from '@/lib/apply-design-config'
import { pageContextFromPath, widthScopeStyle } from '@/lib/width-layout'

/** Applies page-type width tokens to public main content (SPA route changes). */
export function PageWidthScope({ children }: { children: React.ReactNode }) {
  const { pathname } = useLocation()
  const ctx = pageContextFromPath(pathname)
  const design = getRuntimeDesignConfig()?.design as Record<string, unknown> | undefined

  return (
    <main
      className="s2-width-scope"
      style={{ flex: 1, ...widthScopeStyle(design, ctx) }}
      data-s2-page-type={ctx.page_type}
      data-s2-page-slug={ctx.page_slug}
    >
      {children}
    </main>
  )
}
