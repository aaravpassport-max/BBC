import { test, expect } from '@playwright/test'

test.describe('SPA client navigation', () => {
  test('internal header link does not full-reload the document', async ({ page }) => {
    let loadEvents = 0
    page.on('load', () => { loadEvents += 1 })

    await page.goto('/')
    const loadsAfterBoot = loadEvents

    await page.locator('a[href="/about"]').first().click()
    await expect(page).toHaveURL(/\/about/)

    expect(loadEvents).toBe(loadsAfterBoot)
    await expect(page.locator('#s2nri-root')).toBeVisible()
  })

  test('blog route hydrates in React (no PHP blog shell)', async ({ page }) => {
    await page.goto('/blog')
    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-blog-hero')).toBeVisible({ timeout: 25_000 })
  })

  test('customer dashboard tabs navigate without document reload', async ({ page }) => {
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

    let loadEvents = 0
    page.on('load', () => { loadEvents += 1 })

    await page.goto('/dashboard')
    const loadsAfterBoot = loadEvents

    await page.locator('.s2-bottom-nav--customer').getByRole('link', { name: 'Bookings' }).click()
    await expect(page).toHaveURL(/\/dashboard\/bookings/)
    expect(loadEvents).toBe(loadsAfterBoot)
  })
})
