import { test, expect } from '@playwright/test';

test.describe('Production boot shell', () => {
  test('app boots with import map + BUILD_STAMP (no export errors, splash clears)', async ({
    page,
  }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    await page.goto('/?shell=production');

    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('.s2nri-splash')).toHaveCount(0, { timeout: 20_000 });
    await expect(page.getByText(/Services2NRI/i).first()).toBeVisible({ timeout: 20_000 });

    const stylesheets = await page.locator('link[rel="stylesheet"]').count();
    expect(stylesheets).toBeGreaterThanOrEqual(8);

    const brokenImages = await page.evaluate(() => {
      const imgs = Array.from(document.querySelectorAll('img'));
      return imgs.filter((img) => !img.complete || img.naturalWidth === 0).length;
    });
    expect(brokenImages, `expected loaded images, ${brokenImages} broken`).toBe(0);

    await expect(page.locator('.s2-tabs, .s2-svc-grid').first()).toBeVisible({ timeout: 15_000 });

    const pwned = await page.evaluate(() => (window as unknown as { __S2NRI_PWNED?: boolean }).__S2NRI_PWNED);
    expect(pwned).toBeUndefined();

    const exportErrors = errors.filter(
      (e) =>
        e.includes('does not provide an export named') ||
        e.includes('Invalid or unexpected token') ||
        e.includes('SyntaxError'),
    );
    expect(exportErrors, exportErrors.join('\n')).toEqual([]);
  });

  test('chunk URLs share BUILD_STAMP query param', async ({ page }) => {
    await page.goto('/?shell=production');
    const html = await page.content();
    const stampMatch = html.match(/app\.js\?v=([a-f0-9]{12})/);
    expect(stampMatch).toBeTruthy();
    const stamp = stampMatch![1];
    expect(html).toContain(`booking.js?v=${stamp}`);
    expect(html).toContain(`"./chunks/booking.js": "/assets/chunks/booking.js?v=${stamp}"`);
  });
});
