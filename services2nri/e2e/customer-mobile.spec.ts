import { test, expect } from '@playwright/test'

test.describe('Customer portal mobile native shell', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.addInitScript(() => {
      document.cookie = 's2nri_cookie_consent=declined; path=/; max-age=86400'
      window.__S2NRI_E2E_USER__ = {
        id: 1,
        email: 'customer@e2e.test',
        display_name: 'E2E Customer',
        s2nri_role: 'customer',
      }
    })
  })

  test('dashboard has sticky new request bar', async ({ page }) => {
    await page.goto('/dashboard')
    await expect(page.locator('.s2-mobile-app-surface')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
    await expect(
      page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: '+ New Service Request' }),
    ).toBeVisible()
  })

  test('bookings list has filter toolbar and sticky new request', async ({ page }) => {
    await page.goto('/dashboard/bookings')
    await expect(page.locator('.s2-admin-toolbar')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible()
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: '+ New Request' })).toBeVisible()
  })

  test('profile shows sticky save bar', async ({ page }) => {
    await page.goto('/dashboard/profile')
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: 'Save Profile' })).toBeVisible()
  })

  test('tickets list shows sticky new ticket bar', async ({ page }) => {
    await page.goto('/dashboard/tickets')
    await expect(page.locator('.s2-admin-sticky-action-bar')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-admin-sticky-action-bar').getByRole('button', { name: '+ New Ticket' })).toBeVisible()
  })
})
