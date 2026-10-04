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

  test('bookings list uses card table class and sticky action bar', async ({ page }) => {
    await page.goto('/admin/bookings')
    await expect(page.locator('.s2-mobile-app-surface')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
    await expect(page.locator('table.s2-dash-table')).toBeVisible()
  })

  test('design system studio has tab rail and publish sticky bar', async ({ page }) => {
    await page.goto('/admin/design')
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-design-system-tabs')).toBeVisible()
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: 'Publish design' })).toBeVisible()
  })

  test('settings form shows sticky save bar', async ({ page }) => {
    await page.goto('/admin/settings')
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: 'Save All Changes' })).toBeVisible()
  })
})
