import { test, expect } from '@playwright/test'

test.describe('Admin mobile native shell', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.addInitScript(() => {
      document.cookie = 's2nri_cookie_consent=declined; path=/; max-age=86400'
      window.__S2NRI_E2E_USER__ = {
        id: 2,
        email: 'admin@e2e.test',
        display_name: 'E2E Admin',
        s2nri_role: 'super_admin',
      }
    })
  })

  test('admin dashboard has sticky view requests bar', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.locator('.s2-mobile-app-surface')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: 'View All Requests' })).toBeVisible()
  })

  test('bookings list uses card table class and sticky action bar', async ({ page }) => {
    await page.goto('/admin/bookings')
    await expect(page.locator('.s2-mobile-app-surface')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
    await expect(page.locator('table.s2-dash-table')).toBeVisible()
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: '⬇ Export CSV' })).toBeVisible()
  })

  test('mobile sidebar overlays content without horizontal shift', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.locator('.s2-mobile-app-surface')).toBeVisible({ timeout: 25_000 })
    const content = page.locator('.s2-dash-main')
    const boxBefore = await content.boundingBox()
    await page.locator('.s2-dash-mobile-header__menu-btn').click()
    await expect(page.locator('.s2-dash-overlay')).toBeVisible()
    const boxAfter = await content.boundingBox()
    expect(boxBefore).not.toBeNull()
    expect(boxAfter).not.toBeNull()
    if (boxBefore && boxAfter) {
      expect(Math.abs(boxAfter.x - boxBefore.x)).toBeLessThan(2)
      expect(Math.abs(boxAfter.width - boxBefore.width)).toBeLessThan(2)
    }
    const overflow = await page.evaluate(() => {
      const el = document.documentElement
      return el.scrollWidth - el.clientWidth
    })
    expect(overflow).toBeLessThanOrEqual(1)
    await page.locator('.s2-dash-overlay').click({ position: { x: 320, y: 200 } })
    await expect(page.locator('.s2-dash-overlay')).toHaveCount(0)
  })

  test('admin bottom nav requests tab links to /admin/requests', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.locator('.s2-bottom-nav--admin')).toBeVisible({ timeout: 25_000 })
    await page.locator('.s2-bottom-nav--admin').getByRole('link', { name: 'Requests' }).click()
    await expect(page).toHaveURL(/\/admin\/requests/)
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
  })

  test('design system studio has section accordions and publish sticky bar', async ({ page }) => {
    await page.goto('/admin/design')
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-design-section-accordion').first()).toBeVisible()
    const heroHead = page.getByRole('button', { name: 'Hero Section' })
    await heroHead.click()
    await expect(page.locator('.s2-design-section-tabs').getByRole('button', { name: 'Content' })).toBeVisible()
    await expect(page.locator('.s2-design-section-tabs').getByRole('button', { name: 'Width Control' })).toBeVisible()
    const heroLine1 = page.locator('#ds-field-hero_heading_1')
    await expect(heroLine1).toBeVisible()
    await expect(heroLine1).toHaveValue('Stay Connected to')
    await expect(heroLine1).not.toHaveValue('[object Object]')
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: 'Publish design' })).toBeVisible()
  })

  test('s2nri-admin basename has bottom nav and overlay sidebar', async ({ page }) => {
    await page.goto('/s2nri-admin/admin')
    await expect(page.locator('.s2-mobile-app-surface')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-bottom-nav--admin')).toBeVisible()
    await page.locator('.s2-bottom-nav--admin').getByRole('button', { name: 'Menu' }).click()
    await expect(page.locator('.s2-dash-overlay')).toBeVisible()
  })

  test('settings form shows sticky save bar', async ({ page }) => {
    await page.goto('/admin/settings')
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: 'Save All Changes' })).toBeVisible()
  })
})
