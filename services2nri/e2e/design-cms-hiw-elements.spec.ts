import { test, expect } from '@playwright/test';

const ADMIN_USER = {
  id: 2,
  email: 'admin@e2e.test',
  display_name: 'E2E Admin',
  s2nri_role: 'super_admin',
};

test.describe('How It Works — Elements tab coverage', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.request.post('/mock-api/e2e/reset-platform-settings');
    await page.addInitScript((user) => {
      window.__S2NRI_E2E_USER__ = user;
    }, ADMIN_USER);
  });

  test('process section lists step cards and step icon styling in Elements', async ({ page }) => {
    await page.goto('/admin/design');
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 30_000 });

    await page.locator('.s2-design-builder-nav__page-btn:not(.s2-design-builder-nav__page-btn--sub)').filter({ hasText: 'Homepage' }).click();
    await page.locator('[data-section-id="process"] .s2-band-card__main').click();
    const editor = page.getByTestId('section-editor-process');
    await expect(editor).toBeVisible({ timeout: 15_000 });
    await editor.getByRole('tab', { name: 'Elements' }).click();

    await editor.getByRole('option', { name: 'Step 1 icon' }).click();
    const designGroup = editor.locator('.s2-ds-element-detail__group').filter({ hasText: 'Design' });
    await expect(designGroup.getByText('Background')).toBeVisible();
    await expect(designGroup.getByText('Text color')).toBeVisible();

    await editor.getByRole('option', { name: /Step cards \(shared look\)/i }).click();
    await expect(editor.getByText('Step card background')).toBeVisible();
    await expect(editor.getByText('Step icon circle background')).toBeVisible();
  });

  test('homepage exposes per-step CMS element markers', async ({ page }) => {
    await page.goto('/');
    await expect(
      page.locator('[data-s2-section="process"][data-s2-element="step_1_title"]'),
    ).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('[data-s2-section="process"][data-s2-element="step_3_icon"]')).toBeVisible();
  });
});
