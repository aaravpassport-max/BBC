import { test, expect } from '@playwright/test'

const WP_BASE = process.env.WP_E2E_BASE || 'http://127.0.0.1:8080'

test.describe('WordPress integration (birth-certificate)', () => {
  test.skip(!process.env.WP_E2E_BASE && !process.env.CI_WP, 'Set WP_E2E_BASE to run against live WordPress')

  test('birth-certificate shows CMS/default sections on the left', async ({ page }) => {
    await page.goto(`${WP_BASE}/service/birth-certificate`, { waitUntil: 'networkidle', timeout: 60_000 })
    await expect(page.locator('.s2-svc-hero__title')).toContainText('Birth Certificate', { timeout: 45_000 })
    await expect(page.locator('.s2-svc-trust-grid').first()).toBeVisible({ timeout: 20_000 })
    await page.locator('.s2-svc-process-journey').first().scrollIntoViewIfNeeded()
    await expect(page.locator('.s2-svc-process-journey').first()).toBeVisible()
    const opacity = await page.locator('.s2-svc-trust-grid').first().evaluate((el) => getComputedStyle(el).opacity)
    expect(Number(opacity)).toBeGreaterThan(0.9)
  })
})
