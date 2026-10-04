import { test, expect } from '@playwright/test'

test.describe('Services directory visuals', () => {
  test('shows a grid of service cards with edge-aligned images', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/services')
    await expect(page.getByRole('heading', { name: /All NRI Services/i })).toBeVisible({ timeout: 20_000 })

    const cards = page.locator('.s2-dir-card')
    await expect(cards.first()).toBeVisible({ timeout: 15_000 })
    await expect(cards).toHaveCount(8)

    const imgWrap = cards.first().locator('.s2-dir-card__img-wrap')
    const cardBox = await cards.first().boundingBox()
    const imgBox = await imgWrap.boundingBox()
    expect(cardBox && imgBox).toBeTruthy()
    if (cardBox && imgBox) {
      expect(Math.abs(imgBox.x - cardBox.x)).toBeLessThan(2)
      expect(Math.abs(imgBox.width - cardBox.width)).toBeLessThan(2)
      expect(imgBox.y - cardBox.y).toBeLessThan(2)
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
