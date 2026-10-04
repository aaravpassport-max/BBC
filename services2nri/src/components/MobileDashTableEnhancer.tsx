import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

/** Adds data-label to dashboard table cells for mobile card layout (CSS in platform-mobile-app.css). */
export function MobileDashTableEnhancer() {
  const { pathname } = useLocation()

  useEffect(() => {
    function enhance() {
      if (window.innerWidth > 768) return
      document.querySelectorAll('.s2-mobile-app-surface table').forEach((table) => {
        table.classList.add('s2-dash-table')
        const headers = Array.from(table.querySelectorAll('thead th')).map(
          (th) => th.textContent?.trim() || '',
        )
        if (!headers.length) return
        table.querySelectorAll('tbody tr').forEach((tr) => {
          Array.from(tr.children).forEach((cell, i) => {
            if (cell instanceof HTMLElement && !cell.dataset.label) {
              cell.dataset.label = headers[i] || `Column ${i + 1}`
            }
          })
        })
      })
    }

    enhance()
    const root = document.querySelector('.s2-mobile-app-surface')
    const observer = root
      ? new MutationObserver(() => enhance())
      : null
    if (root && observer) {
      observer.observe(root, { childList: true, subtree: true })
    }
    window.addEventListener('resize', enhance)
    const t = window.setTimeout(enhance, 400)

    return () => {
      observer?.disconnect()
      window.removeEventListener('resize', enhance)
      window.clearTimeout(t)
    }
  }, [pathname])

  return null
}
