import { test, expect } from '@playwright/test'

test.describe('S2NRI Builder mobile native shell', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
  })

  test('builder loads with bottom nav and sticky view site bar', async ({ page }) => {
    await page.goto('/s2nri-builder?page=status-manager')
    await expect(page.locator('#s2nri-builder-root.s2-mobile-app-root')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-builder-bottom-nav')).toBeVisible()
    await expect(page.locator('.s2-builder-sticky-action-bar')).toBeVisible()
    await expect(page.locator('.s2-builder-sticky-action-bar').getByRole('link', { name: 'View Site ↗' })).toBeVisible()
  })

  test('homepage builder shows save sticky on mobile', async ({ page }) => {
    await page.goto('/s2nri-builder?page=homepage-builder')
    await expect(page.locator('.s2builder-home-grid')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-builder-sticky-action-bar').getByRole('button', { name: /Save Homepage|Saved/ })).toBeVisible()
  })

  test('bottom nav switches to form builder', async ({ page }) => {
    await page.goto('/s2nri-builder')
    await expect(page.locator('.s2-builder-bottom-nav')).toBeVisible({ timeout: 25_000 })
    await page.locator('.s2-builder-bottom-nav').getByRole('button', { name: 'Forms' }).click()
    await expect(page.getByRole('heading', { name: 'Form Builder' })).toBeVisible()
  })
})
