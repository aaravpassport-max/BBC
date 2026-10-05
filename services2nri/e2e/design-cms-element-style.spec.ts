import { test, expect } from '@playwright/test';

const ADMIN_USER = {
  id: 2,
  email: 'admin@e2e.test',
  display_name: 'E2E Admin',
  s2nri_role: 'super_admin',
};

test.describe('Design System element styling → public DOM', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.request.post('/mock-api/e2e/reset-platform-settings');
    await page.addInitScript((user) => {
      window.__S2NRI_E2E_USER__ = user;
    }, ADMIN_USER);
  });

  test('hero heading text color from Elements tab applies on homepage', async ({ page }) => {
    await page.goto('/admin/design');
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 30_000 });

    await page.locator('.s2-design-builder-nav__page-btn:not(.s2-design-builder-nav__page-btn--sub)').filter({ hasText: 'Homepage' }).click();
    await page.locator('[data-section-id="hero"] .s2-band-card__main').click();
    await expect(page.getByTestId('section-editor-hero')).toBeVisible({ timeout: 15_000 });

    const editor = page.getByTestId('section-editor-hero');
    await editor.getByRole('tab', { name: 'Elements' }).click();
    await editor.getByRole('option', { name: /Main heading/i }).click();

    const designGroup = editor.locator('.s2-ds-element-detail__group').filter({ hasText: 'Design' });
    const colorPicker = designGroup.locator('input[type="color"]').first();
    await colorPicker.evaluate((el) => {
      const input = el as HTMLInputElement;
      input.value = '#E11D48';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    const publishPromise = page.waitForResponse(
      (r) => r.url().includes('/mock-api/admin/design') && r.request().method() === 'PUT',
      { timeout: 25_000 },
    );
    await editor.getByRole('button', { name: 'Save elements' }).click();
    await publishPromise;
    await expect(page.getByText('Elements saved')).toBeVisible({ timeout: 15_000 });

    await page.goto('/');
    const heroHeading = page.locator('[data-s2-page="home"][data-s2-section="hero"][data-s2-element="heading"]');
    await expect(heroHeading).toBeVisible({ timeout: 20_000 });

    const color = await heroHeading.evaluate((el) => window.getComputedStyle(el).color);
    expect(color).toMatch(/225,\s*29,\s*72|rgb\(225, 29, 72\)/);
  });
});
