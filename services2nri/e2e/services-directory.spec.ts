import { test, expect } from '@playwright/test'

test.describe('Services directory visuals', () => {
  test('shows a grid of service cards with edge-aligned images', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/services')
    await expect(page.getByRole('heading', { name: /All NRI Services/i })).toBeVisible({ timeout: 20_000 })

    const heroTitle = page.locator('.s2-dir-hero__title')
    const titleColor = await heroTitle.evaluate((el) => getComputedStyle(el).color)
    expect(titleColor).toMatch(/rgb\(255,\s*255,\s*255\)|rgba\(255,\s*255,\s*255/)

    const cards = page.locator('.s2-dir-card')
    await expect(cards.first()).toBeVisible({ timeout: 15_000 })
    await expect(cards).toHaveCount(8)

    const imgWrap = cards.first().locator('.s2-dir-card__img-wrap')
    const cardBox = await cards.first().boundingBox()
    const imgBox = await imgWrap.boundingBox()
    expect(cardBox && imgBox).toBeTruthy()
    if (cardBox && imgBox) {
      expect(Math.abs(imgBox.x - cardBox.x)).toBeLessThan(3)
      expect(Math.abs(imgBox.width - cardBox.width)).toBeLessThan(4)
      expect(imgBox.y - cardBox.y).toBeLessThan(3)
    }

    const img = cards.first().locator('img')
    await expect(img).toBeVisible()
    const natural = await img.evaluate((el: HTMLImageElement) => ({
      w: el.naturalWidth,
      h: el.naturalHeight,
    }))
    expect(natural.w).toBeGreaterThan(0)
    expect(natural.h).toBeGreaterThan(0)
  })

  test('hero and card grid have intentional vertical gap', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/services')
    await expect(page.locator('.s2-dir-card').first()).toBeVisible({ timeout: 20_000 })

    const gap = await page.evaluate(() => {
      const hero = document.querySelector('.s2-services-hero')
      const body = document.querySelector('.s2-services-body')
      const card = document.querySelector('.s2-dir-card')
      if (!hero || !body || !card) return 0
      const heroBottom = hero.getBoundingClientRect().bottom
      const cardTop = card.getBoundingClientRect().top
      return cardTop - heroBottom
    })
    expect(gap).toBeGreaterThan(24)
  })

  test('desktop layout uses multi-column grid', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/services')
    const cards = page.locator('.s2-dir-card')
    await expect(cards.first()).toBeVisible({ timeout: 20_000 })

    const xs = await cards.evaluateAll((els) =>
      els.map((el) => Math.round(el.getBoundingClientRect().x)),
    )
    expect(new Set(xs).size).toBeGreaterThanOrEqual(2)
  })
})
