import { test, expect } from '@playwright/test'

/**
 * Regression: seeded CMS sections must render even when Design System template
 * hide flags are set, and pages with zero DB rows must still show default content.
 */
test.describe('Service section lifecycle', () => {
  test('CMS sections stay visible when hide_tmpl_service_* flags are set', async ({ page }) => {
    await page.addInitScript(() => {
      const orig = window.fetch.bind(window)
      window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
        if (url.includes('/settings/public') || url.includes('settings/public')) {
          return new Response(
            JSON.stringify({
              settings: {
                platform_name: 'Services2NRI',
                primary_color: '#4A6FA5',
                hide_tmpl_service_trust_badges: '1',
                hide_tmpl_service_description: '1',
                hide_tmpl_service_process: '1',
                hide_tmpl_service_faq: '1',
                hide_tmpl_service_pricing: '1',
              },
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
          )
        }
        return orig(input, init)
      }
    })

    await page.goto('/service/complete-property-management')
    await expect(page.locator('.s2-svc-hero__title')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-svc-trust-grid').first()).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('.s2-svc-process-journey').first()).toBeVisible()
    await expect(page.locator('.s2-svc-block--charges, .s2-svc-pill-note').first()).toBeVisible()
  })

  test('empty sections API still renders default left-column content', async ({ page }) => {
    await page.addInitScript(() => {
      const orig = window.fetch.bind(window)
      window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
        if (url.includes('/sections')) {
          return new Response(JSON.stringify({ sections: [] }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          })
        }
        return orig(input, init)
      }
    })

    await page.goto('/service/complete-property-management')
    await expect(page.locator('.s2-svc-hero__title')).toBeVisible({ timeout: 25_000 })
    await expect(page.locator('.s2-svc-trust-grid').first()).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('.s2-svc-process-journey').first()).toBeVisible()
    await expect(page.locator('.s2-svc-block--charges, .s2-svc-pill-note').first()).toBeVisible()
  })

  test('async-loaded sections become visible (scroll reveal rebind)', async ({ page }) => {
    await page.addInitScript(() => {
      const orig = window.fetch.bind(window)
      window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
        if (url.includes('/sections')) {
          await new Promise((r) => setTimeout(r, 350))
          return orig(input, init)
        }
        return orig(input, init)
      }
    })

    await page.goto('/service/complete-property-management')
    await expect(page.locator('.s2-svc-trust-tile__value').first()).toBeVisible({ timeout: 25_000 })
    const opacity = await page.locator('.s2-svc-trust-grid').first().evaluate((el) => getComputedStyle(el).opacity)
    expect(Number(opacity)).toBeGreaterThan(0.9)
  })
})
