import { test, expect } from '@playwright/test'

/** E2E mock returns full section matrix for complete-property-management. */
const EXPECTED_SECTION_MARKERS = [
  { sel: '.s2-svc-trust-grid', label: 'trust badges' },
  { sel: '.s2-svc-block--charges, .s2-svc-pill-note', label: 'charges' },
  { sel: '.s2-svc-process-journey', label: 'process' },
  { sel: '.s2-svc-faq-inline', label: 'faq' },
  { sel: '.s2-svc-testimonial', label: 'testimonials' },
  { sel: '.s2-svc-cta-band', label: 'cta band' },
]

test.describe('Service page sections (mock API)', () => {
  test('renders builder section types from API', async ({ page }) => {
    await page.goto('/service/complete-property-management')
    await expect(page.locator('.s2-svc-hero__title')).toBeVisible({ timeout: 25_000 })

    for (const { sel, label } of EXPECTED_SECTION_MARKERS) {
      await expect(page.locator(sel).first(), `missing section: ${label}`).toBeVisible({ timeout: 10_000 })
    }

    await expect(page.locator('.s2-svc-why')).toHaveCount(0)
  })

  test('service CTA band text is light on dark band', async ({ page }) => {
    await page.goto('/service/complete-property-management')
    const title = page.locator('.s2-svc-cta-band .s2-t-h3, .s2-svc-cta-band h3').first()
    await title.scrollIntoViewIfNeeded()
    await expect(title).toBeVisible({ timeout: 25_000 })
    const color = await title.evaluate((el) => getComputedStyle(el).color)
    const m = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
    expect(m).toBeTruthy()
    if (m) {
      const lum = 0.2126 * Number(m[1]) + 0.7152 * Number(m[2]) + 0.0722 * Number(m[3])
      expect(lum).toBeGreaterThan(180)
    }
  })
})
