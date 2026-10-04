/**
 * Client-side navigation helpers — keep internal moves inside React Router.
 */

import type { NavigateFunction } from 'react-router-dom'

const BUILDER_PREFIX = '/s2nri-builder'

let registeredNavigate: NavigateFunction | null = null

export function registerSpaNavigate(navigate: NavigateFunction): () => void {
  registeredNavigate = navigate
  return () => {
    if (registeredNavigate === navigate) registeredNavigate = null
  }
}

export function spaNavigate(to: string, options?: { replace?: boolean }): void {
  const dest = resolveInternalDestination(to)
  if (dest && registeredNavigate) {
    registeredNavigate(dest, { replace: options?.replace })
    return
  }
  window.location.assign(to)
}

/** Shell prefix for portal vs public vs admin standalone entry. */
export function shellBaseForPath(pathname: string): string {
  if (pathname === '/portal' || pathname.startsWith('/portal/')) return '/portal'
  if (pathname === '/s2nri-admin' || pathname.startsWith('/s2nri-admin/')) return '/s2nri-admin'
  return ''
}

export function currentShellBase(): string {
  const cfg = (window.S2NRI_CONFIG?.basePath || '').replace(/\/$/, '')
  return cfg || ''
}

function stripShellPrefix(pathname: string): string {
  for (const base of ['/s2nri-admin', '/portal']) {
    if (pathname === base) return '/'
    if (pathname.startsWith(base + '/')) return pathname.slice(base.length) || '/'
  }
  return pathname
}

export function isHardNavigationPath(pathname: string): boolean {
  return pathname === BUILDER_PREFIX || pathname.startsWith(BUILDER_PREFIX + '/')
}

/** True when this in-app URL can be handled by the current React Router instance. */
export function canClientNavigateTo(pathname: string, search = '', hash = ''): boolean {
  if (isHardNavigationPath(pathname)) return false
  const targetShell = shellBaseForPath(pathname)
  if (targetShell !== currentShellBase()) return false
  const routerPath = stripShellPrefix(pathname)
  if (!routerPath.startsWith('/')) return false
  void search
  void hash
  return true
}

/** Router-relative path (respects Portal basename stripping). */
export function resolveInternalDestination(href: string): string | null {
  if (!href || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) {
    return null
  }
  if (href.startsWith('#')) return null

  let url: URL
  try {
    url = new URL(href, window.location.origin)
  } catch {
    return null
  }

  if (url.origin !== window.location.origin) return null
  if (!canClientNavigateTo(url.pathname, url.search, url.hash)) return null

  const path = stripShellPrefix(url.pathname)
  return `${path}${url.search}${url.hash}`
}

export function scrollToHash(hash: string, behavior: ScrollBehavior = 'smooth'): void {
  if (!hash || hash === '#') return
  const el = document.querySelector(hash)
  if (el) el.scrollIntoView({ behavior, block: 'start' })
}
