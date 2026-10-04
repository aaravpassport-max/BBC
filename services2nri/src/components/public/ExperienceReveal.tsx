/**
 * Lightweight scroll reveal for [data-s2-reveal] sections (public marketing surfaces).
 */
import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

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

export function ExperienceReveal() {
  const location = useLocation()

  useEffect(() => {
    const cleanup = bindReveal()
    const t = window.setTimeout(() => bindReveal(), 80)
    return () => {
      cleanup()
      window.clearTimeout(t)
    }
  }, [location.pathname])

  return null
}
