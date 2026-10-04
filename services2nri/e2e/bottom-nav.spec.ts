import { test, expect } from '@playwright/test'

const PUBLIC_ROUTES = ['/', '/services', '/contact', '/pricing', '/faq', '/about', '/blog']

test.describe('Global bottom navigation (mobile)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
  })

  for (const path of PUBLIC_ROUTES) {
    test(`public bottom nav visible on ${path}`, async ({ page }) => {
      await page.goto(path)
      await expect(page.locator('.s2-bottom-nav')).toBeVisible({ timeout: 25_000 })
      await expect(page.locator('.s2-bottom-nav-item').first()).toBeVisible()
      await expect(page.locator('.s2-bottom-nav-icon svg').first()).toBeVisible()
    })
  }

  test('service detail shows Inquire tab', async ({ page }) => {
    await page.goto('/service/complete-property-management')
    await expect(page.getByRole('navigation', { name: /Primary mobile navigation/i })).toBeVisible({
      timeout: 25_000,
    })
    await expect(page.getByRole('button', { name: /Inquire/i })).toBeVisible()
  })

  test('active tab highlights Services on /services', async ({ page }) => {
    await page.goto('/services')
    const servicesTab = page.locator('.s2-bottom-nav-item.active').filter({ hasText: 'Services' })
    await expect(servicesTab).toBeVisible({ timeout: 25_000 })
  })

  test('customer portal uses customer bottom nav variant', async ({ page }) => {
    await page.addInitScript(() => {
      window.__S2NRI_E2E_USER__ = {
        id: 1,
        email: 'customer@e2e.test',
        display_name: 'E2E Customer',
        s2nri_role: 'customer',
      }
    })
    await page.goto('/dashboard')
    await expect(page.locator('.s2-bottom-nav--customer')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-bottom-nav--customer').getByRole('link', { name: 'Bookings' })).toBeVisible()
  })

  test('admin portal uses admin bottom nav with Menu tab', async ({ page }) => {
    await page.addInitScript(() => {
      window.__S2NRI_E2E_USER__ = {
        id: 2,
        email: 'admin@e2e.test',
        display_name: 'E2E Admin',
        s2nri_role: 'super_admin',
      }
    })
    await page.goto('/admin')
    await expect(page.locator('.s2-bottom-nav--admin')).toBeVisible({ timeout: 25_000 })
    await expect(page.getByRole('button', { name: 'Menu' })).toBeVisible()
  })
})
