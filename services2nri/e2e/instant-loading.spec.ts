import { test, expect } from '@playwright/test'

test.describe('Instant shell loading', () => {
  test('service detail uses layout shell instead of spinner-only screen', async ({ page }) => {
    await page.goto('/service/complete-property-management')
    await expect(page.locator('.s2-svc-page-main, .s2-svc-layout, .s2-skeleton-region').first()).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-svc-spinner-wrap')).toHaveCount(0)
  })

  test('services directory keeps page chrome while data resolves', async ({ page }) => {
    await page.goto('/services')
    await expect(page.locator('.s2-public-page, .s2-svc-breadcrumb, h1').first()).toBeVisible({ timeout: 20_000 })
    await expect(page.getByText('Loading…', { exact: true })).toHaveCount(0)
  })
})
