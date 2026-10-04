import React, { useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { applyDesignForPath, getRuntimeDesignConfig } from '@/lib/apply-design-config'
import { DESIGN_UPDATED_EVENT } from '@/lib/design-live-sync'
import { pageContextFromPath, widthScopeStyle } from '@/lib/width-layout'

/** Applies page-type design + width tokens on every public SPA route change. */
export function PageWidthScope({ children }: { children: React.ReactNode }) {
  const { pathname } = useLocation()
  const ctx = pageContextFromPath(pathname)
  const [designTick, setDesignTick] = useState(0)

  useEffect(() => {
    const bump = () => setDesignTick((n) => n + 1)
    window.addEventListener(DESIGN_UPDATED_EVENT, bump)
    return () => window.removeEventListener(DESIGN_UPDATED_EVENT, bump)
  }, [])

  const design = getRuntimeDesignConfig()?.design as Record<string, unknown> | undefined
  void designTick

  useEffect(() => {
    applyDesignForPath(pathname)
  }, [pathname])

  useEffect(() => {
    const onResize = () => applyDesignForPath(pathname)
    window.addEventListener('resize', onResize, { passive: true })
    return () => window.removeEventListener('resize', onResize)
  }, [pathname])

  useEffect(() => {
    const reapply = () => applyDesignForPath(pathname)
    window.addEventListener(DESIGN_UPDATED_EVENT, reapply)
    return () => window.removeEventListener(DESIGN_UPDATED_EVENT, reapply)
  }, [pathname])

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
