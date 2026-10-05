import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  registerSpaNavigate,
  resolveInternalDestination,
  scrollToHash,
} from '@/lib/spa-navigation'
import { prefetchForRoute } from '@/lib/prefetch'

/**
 * Registers global navigate() for store/logout and intercepts same-origin
 * anchor clicks that would otherwise trigger a full document load.
 */
export function SpaNavigationBridge() {
  const navigate = useNavigate()

  useEffect(() => registerSpaNavigate(navigate), [navigate])

  useEffect(() => {
    function onDocumentClick(event: MouseEvent) {
      if (event.defaultPrevented) return
      if (event.button !== 0) return
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

      const anchor = (event.target as Element | null)?.closest('a[href]') as HTMLAnchorElement | null
      if (!anchor) return
      if (anchor.target && anchor.target !== '_self') return
      if (anchor.hasAttribute('download')) return

      const href = anchor.getAttribute('href')
      if (!href) return

      if (href.startsWith('#')) {
        event.preventDefault()
        scrollToHash(href)
        if (history.pushState) {
          history.pushState(null, '', `${window.location.pathname}${window.location.search}${href}`)
        }
        return
      }

      const internal = resolveInternalDestination(href)
      if (!internal) return

      event.preventDefault()
      prefetchForRoute(internal)
      navigate(internal)
    }

    function onLinkHover(event: Event) {
      const anchor = (event.target as Element | null)?.closest('a[href]') as HTMLAnchorElement | null
      if (!anchor) return
      const href = anchor.getAttribute('href')
      if (!href || href.startsWith('#')) return
      const internal = resolveInternalDestination(href)
      if (internal) prefetchForRoute(internal)
    }

    document.addEventListener('click', onDocumentClick, true)
    document.addEventListener('mouseover', onLinkHover, true)
    document.addEventListener('focusin', onLinkHover, true)
    return () => {
      document.removeEventListener('click', onDocumentClick, true)
      document.removeEventListener('mouseover', onLinkHover, true)
      document.removeEventListener('focusin', onLinkHover, true)
    }
  }, [navigate])

  return null
}
