/**
 * Lightweight scroll reveal for [data-s2-reveal] sections (public marketing surfaces).
 */
import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

let activeRevealCleanup: (() => void) | null = null

function bindReveal(root: ParentNode = document) {
  const nodes = root.querySelectorAll<HTMLElement>('[data-s2-reveal]:not(.s2-revealed)')
  if (!nodes.length) return () => {}

  if (prefersReducedMotion()) {
    nodes.forEach((el) => el.classList.add('s2-revealed'))
    return () => {}
  }

  const io = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return
        entry.target.classList.add('s2-revealed')
        io.unobserve(entry.target)
      })
    },
    { rootMargin: '0px 0px -6% 0px', threshold: 0.06 },
  )

  nodes.forEach((el) => io.observe(el))
  return () => io.disconnect()
}

/** Re-scan DOM after async content (e.g. service CMS sections) mounts. */
export function refreshExperienceReveal(): void {
  activeRevealCleanup?.()
  activeRevealCleanup = bindReveal()
}

export function ExperienceReveal() {
  const location = useLocation()

  useEffect(() => {
    refreshExperienceReveal()
    const t = window.setTimeout(() => refreshExperienceReveal(), 80)
    const t2 = window.setTimeout(() => refreshExperienceReveal(), 400)
    return () => {
      activeRevealCleanup?.()
      activeRevealCleanup = null
      window.clearTimeout(t)
      window.clearTimeout(t2)
    }
  }, [location.pathname])

  return null
}
