import { test, expect } from '@playwright/test'

test.describe('Homepage marketplace flow', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/')
    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 25_000 })
  })

  test('hero shows clean slide without full-image overlay scrim', async ({ page }) => {
    const slideBg = page.locator('.s2-home-hero-slide-bg').first()
    await expect(slideBg).toBeVisible()
    const overlay = page.locator('.s2-home-hero-overlay')
    await expect(overlay).toHaveCount(0)
    await expect(page.locator('.s2-home-hero-copy__panel')).toBeVisible()
  })

  test('marquee and discovery sections render', async ({ page }) => {
    await expect(page.locator('.s2-home-marquee')).toBeVisible()
    await expect(page.locator('.s2-home-discovery')).toBeVisible()
  })

  test('service category pills swap grid without large layout jump', async ({ page }) => {
    const band = page.locator('#home-services-band')
    await band.scrollIntoViewIfNeeded()
    const shell = page.locator('.s2-home-svc-panel-shell')
    await expect(shell).toBeVisible()

    const tiles = page.locator('.s2-home-svc-cat-tile')
    const count = await tiles.count()
    expect(count).toBeGreaterThan(1)

    const heights: number[] = []
    for (let i = 0; i < Math.min(count, 4); i += 1) {
      const before = await shell.boundingBox()
      heights.push(before?.height ?? 0)
      await tiles.nth(i).click()
      await expect(tiles.nth(i)).toHaveClass(/is-active/)
      await page.waitForTimeout(120)
    }
    const max = Math.max(...heights)
    const min = Math.min(...heights.filter((h) => h > 0))
    expect(max - min).toBeLessThan(80)
  })

})

test.describe('Homepage marketplace flow — mobile', () => {
  test.use({ viewport: { width: 390, height: 844 } })

  test('discovery and category nav render', async ({ page }) => {
    await page.goto('/')
    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-home-discovery__search')).toBeVisible({ timeout: 25_000 })
    const band = page.locator('#home-services-band')
    await band.scrollIntoViewIfNeeded()
    await expect(page.locator('.s2-home-svc-categories__mobile-select select')).toBeVisible({ timeout: 25_000 })
  })
})
