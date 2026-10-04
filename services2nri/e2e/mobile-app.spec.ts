import { test, expect } from '@playwright/test'

test.describe('Mobile app shell', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.addInitScript(() => {
      document.cookie = 's2nri_cookie_consent=declined; path=/; max-age=86400'
    })
  })

  test('root has mobile app class and bottom nav on public routes', async ({ page }) => {
    await page.goto('/')
    await expect(page.locator('#s2nri-root.s2-mobile-app-root')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-bottom-nav')).toBeVisible()
    await expect(page.locator('.s2-page-wrap--mobile-shell')).toBeVisible()
  })

  test('primary buttons meet touch target height on mobile', async ({ page }) => {
    await page.goto('/contact')
    const btn = page.locator('.s2-btn--primary').first()
    await expect(btn).toBeVisible({ timeout: 25_000 })
    const box = await btn.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)
  })

  test('customer dashboard uses app surface and bottom nav', async ({ page }) => {
    await page.addInitScript(() => {
      window.__S2NRI_E2E_USER__ = {
        id: 1,
        email: 'customer@e2e.test',
        display_name: 'E2E Customer',
        s2nri_role: 'customer',
      }
    })
    await page.goto('/dashboard')
    await expect(page.locator('.s2-mobile-app-surface')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-bottom-nav--customer')).toBeVisible()
  })
})
