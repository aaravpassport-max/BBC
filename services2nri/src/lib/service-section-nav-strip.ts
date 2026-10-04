/**
 * Removes legacy PHP-injected "On this page" section nav from service detail URLs.
 * Old plugin builds inject inline scripts on every full page load; this keeps the
 * layout clean until PHP is updated on the server.
 */
export function stripServiceSectionNav(): void {
  document.getElementById('s2nri-nav-btn')?.remove()
  document.getElementById('s2nri-nav-overlay')?.remove()
  document.getElementById('s2nri-nav')?.remove()
  document.getElementById('s2nri-nav-wrap')?.remove()

  const row = document.getElementById('s2nri-nav-row')
  const grid = document.querySelector<HTMLElement>('.svc-grid')
  if (row && grid && row.contains(grid)) {
    row.parentElement?.insertBefore(grid, row)
    row.remove()
    grid.style.margin = ''
    grid.style.paddingLeft = ''
    grid.style.paddingRight = ''
  }

  if (document.body.style.overflow === 'hidden') {
    document.body.style.overflow = ''
  }
}

export function installServiceSectionNavStrip(): () => void {
  stripServiceSectionNav()
  const ob = new MutationObserver(() => stripServiceSectionNav())
  ob.observe(document.documentElement, { childList: true, subtree: true })
  const t1 = window.setTimeout(stripServiceSectionNav, 400)
  const t2 = window.setTimeout(stripServiceSectionNav, 1500)
  const t3 = window.setTimeout(stripServiceSectionNav, 3500)
  return () => {
    ob.disconnect()
    window.clearTimeout(t1)
    window.clearTimeout(t2)
    window.clearTimeout(t3)
  }
}
