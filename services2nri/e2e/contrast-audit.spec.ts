import { test, expect } from '@playwright/test'

/** Relative luminance of an sRGB color (0–1). */
function luminance(r: number, g: number, b: number): number {
  const lin = (c: number) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

function parseRgb(color: string): [number, number, number] | null {
  const m = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
  if (!m) return null
  return [Number(m[1]), Number(m[2]), Number(m[3])]
}

/** Text on dark bands should read as light (high luminance). */
async function expectLightText(page: import('@playwright/test').Page, selector: string) {
  const color = await page.locator(selector).first().evaluate((el) => getComputedStyle(el).color)
  const rgb = parseRgb(color)
  expect(rgb, `Could not parse color "${color}" for ${selector}`).toBeTruthy()
  if (rgb) {
    expect(luminance(rgb[0], rgb[1], rgb[2])).toBeGreaterThan(0.72)
  }
}

const DARK_HERO_PAGES: { path: string; selector: string; label: string }[] = [
  { path: '/', selector: '.s2-home-hero-overlay__title', label: 'home hero' },
  { path: '/services', selector: '.s2-dir-hero__title', label: 'services hero' },
  { path: '/about', selector: '.s2-page-hero__title', label: 'about hero' },
  { path: '/contact', selector: '.s2-page-hero__title', label: 'contact hero' },
  { path: '/how-it-works', selector: '.s2-page-hero__title', label: 'how-it-works hero' },
  { path: '/faq', selector: '.s2-page-hero__title', label: 'faq hero' },
  { path: '/pricing', selector: '.s2-page-hero__title', label: 'pricing hero' },
  { path: '/blog', selector: '.s2-blog-hero__title', label: 'blog hub hero' },
  { path: '/blog/oci-card-renewal-guide', selector: '.s2-blog-article-hero__title', label: 'blog article hero' },
  { path: '/privacy', selector: '.s2-privacy-hero__title', label: 'privacy hero' },
  { path: '/terms', selector: '.s2-page-hero__title', label: 'terms hero' },
  {
    path: '/cities/property-management-in-pune',
    selector: '.s2-city-hero__title',
    label: 'city landing hero',
  },
  { path: '/service/complete-property-management', selector: '.s2-svc-hero__title', label: 'service detail hero' },
]

test.describe('Platform contrast audit — dark band headings', () => {
  for (const { path, selector, label } of DARK_HERO_PAGES) {
    test(`${label} (${path}) uses light heading text`, async ({ page }) => {
      await page.goto(path)
      await expect(page.locator(selector).first()).toBeVisible({ timeout: 25_000 })
      await expectLightText(page, selector)
    })
  }

  test('home stats bar labels stay light on gradient band', async ({ page }) => {
    await page.goto('/')
    await expect(page.locator('.s2-stats-bar__label').first()).toBeVisible({ timeout: 25_000 })
    await expectLightText(page, '.s2-stats-bar__label')
  })

  test('home app download section title stays light', async ({ page }) => {
    await page.goto('/')
    const title = page.locator('.s2-home-app__title')
    await title.scrollIntoViewIfNeeded()
    await expect(title).toBeVisible({ timeout: 25_000 })
    await expectLightText(page, '.s2-home-app__title')
  })

  test('pricing plan head titles stay light on colored card heads', async ({ page }) => {
    await page.goto('/pricing')
    await expect(page.locator('.s2-public-pricing-head-title').first()).toBeVisible({ timeout: 25_000 })
    await expectLightText(page, '.s2-public-pricing-head-title')
  })
})
