import { test, expect } from '@playwright/test'

test.describe('Homepage section reorder', () => {
  test('saved catalog order moves services band before features on public page', async ({ page }) => {
    await page.request.post('/mock-api/e2e/reset-platform-settings')
    await page.request.put('/mock-api/admin/settings', {
      data: { hide_section_services: '0', hide_section_features: '0' },
    })
    const order = [
      'hero',
      'notice',
      'search',
      'home_services',
      'features',
      'cities',
      'stats',
      'tagline',
      'testimonials',
      'process',
      'press',
      'partners',
      'about',
      'awards',
      'faq',
      'newsletter',
      'app',
      'locations',
    ]
    await page.request.put('/mock-api/admin/settings', {
      data: {
        home_section_order_json: JSON.stringify(order),
        public_page_section_orders_json: JSON.stringify({ home: order }),
      },
    })

    await page.goto('/')
    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 25_000 })

    const bands = page.locator('.s2-home-page [data-home-section-id]')
    await expect(bands.first()).toHaveAttribute('data-home-section-id', 'hero')

    const ids = await bands.evaluateAll((els) =>
      els.map((el) => el.getAttribute('data-home-section-id')).filter(Boolean),
    )
    const svcIdx = ids.indexOf('home_services')
    const featIdx = ids.indexOf('features')
    expect(svcIdx).toBeGreaterThan(-1)
    expect(featIdx).toBeGreaterThan(-1)
    expect(svcIdx).toBeLessThan(featIdx)
  })

  test('legacy home order keys (services, how) still apply', async ({ page }) => {
    await page.request.post('/mock-api/e2e/reset-platform-settings')
    const legacy = [
      'hero',
      'notice',
      'search',
      'services',
      'features',
      'process',
      'faq',
    ]
    await page.request.put('/mock-api/admin/settings', {
      data: { home_section_order_json: JSON.stringify(legacy) },
    })

    await page.goto('/')
    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 25_000 })

    const ids = await page.locator('.s2-home-page [data-home-section-id]').evaluateAll((els) =>
      els.map((el) => el.getAttribute('data-home-section-id')).filter(Boolean),
    )
    expect(ids.indexOf('home_services')).toBeLessThan(ids.indexOf('features'))
    expect(ids.indexOf('process')).toBeLessThan(ids.indexOf('faq'))
  })
})
