import { test, expect } from '@playwright/test'

const ADMIN_USER = {
  id: 2,
  email: 'admin@e2e.test',
  display_name: 'E2E Admin',
  s2nri_role: 'super_admin',
}

test.describe('Admin booking — delivery & fulfillment', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript((user) => {
      window.__S2NRI_E2E_USER__ = user
    }, ADMIN_USER)
    await page.goto('/admin/bookings/1')
    await expect(page.getByText('Delivery & fulfillment')).toBeVisible({ timeout: 30_000 })
  })

  test('sets working-day delivery and assigns vendor', async ({ page }) => {
    await page.getByPlaceholder('e.g. 30').fill('14')
    await page.getByRole('button', { name: 'Save delivery timeline' }).click()
    await expect(page.getByText('Delivery timeline updated.')).toBeVisible({ timeout: 15_000 })

    await page.getByTestId('admin-vendor-select').selectOption('1')
    await page.getByRole('button', { name: 'Assign vendor' }).click()
    await expect(page.getByText('Vendor assignment saved.')).toBeVisible({ timeout: 15_000 })
    await expect(page.locator('.s2-dash-card strong').filter({ hasText: 'BlueDart Partner' })).toBeVisible({ timeout: 15_000 })
  })
})
