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

  test('boots under strict CSP (no inline scripts)', async ({ browser }) => {
    const context = await browser.newContext({
      extraHTTPHeaders: {
        'Content-Security-Policy':
          "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'",
      },
    });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/?shell=production');
    await expect(page.locator('.s2nri-splash')).toHaveCount(0, { timeout: 20_000 });
    const exportErrors = errors.filter((e) => e.includes('does not provide an export named'));
    expect(exportErrors).toEqual([]);
    await context.close();
  });

  test('chunk URLs share BUILD_STAMP query param', async ({ page, request }) => {
    await page.goto('/?shell=production');
    const html = await page.content();
    const stampMatch = html.match(/app\.js\?v=([a-f0-9]{12})/);
    expect(stampMatch).toBeTruthy();
    const stamp = stampMatch![1];
    expect(html).toMatch(new RegExp(`s2nri_import_map=1(&amp;|&)v=${stamp}`));
    const mapSrcRaw = html.match(/importmap" src="([^"]+)"/)?.[1];
    expect(mapSrcRaw).toBeTruthy();
    const mapSrc = mapSrcRaw!.replace(/&amp;/g, '&');
    const mapRes = await request.get(mapSrc);
    expect(mapRes.ok()).toBeTruthy();
    const mapBody = (await mapRes.json()) as { imports: Record<string, string> };
    const bookingUrl = mapBody.imports['./chunks/booking.js'] || mapBody.imports['chunks/booking.js'];
    expect(bookingUrl).toContain(`booking.js?v=${stamp}`);
  });
});
