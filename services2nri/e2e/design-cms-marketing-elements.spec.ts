import { test, expect } from '@playwright/test';

const ADMIN_USER = {
  id: 2,
  email: 'admin@e2e.test',
  display_name: 'E2E Admin',
  s2nri_role: 'super_admin',
};

test.describe('Marketing pages — Elements tab coverage', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.request.post('/mock-api/e2e/reset-platform-settings');
    await page.addInitScript((user) => {
      window.__S2NRI_E2E_USER__ = user;
    }, ADMIN_USER);
  });

  test('About core values lists Value 1 card in Elements', async ({ page }) => {
    await page.goto('/admin/design');
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 30_000 });

    await page
      .locator('.s2-design-builder-nav__page-btn:not(.s2-design-builder-nav__page-btn--sub)')
      .filter({ hasText: 'About' })
      .click();
    await page.locator('[data-section-id="values"] .s2-band-card__main').click();
    const editor = page.getByTestId('section-editor-values');
    await expect(editor).toBeVisible({ timeout: 15_000 });
    await editor.getByRole('tab', { name: 'Elements' }).click();

    await editor.getByRole('option', { name: 'Value 1 title' }).click();
    await expect(editor.getByRole('heading', { name: 'Value 1 title' })).toBeVisible();
    await expect(
      editor.locator('.s2-ds-element-detail__group').filter({ hasText: 'Design' }).getByText('Text color'),
    ).toBeVisible();
  });

  test('How It Works page exposes per-step CMS markers', async ({ page }) => {
    await page.goto('/how-it-works');
    await expect(
      page.locator('[data-s2-page="how-it-works"][data-s2-section="process"][data-s2-element="page_step_1_title"]'),
    ).toBeVisible({ timeout: 20_000 });
    await expect(
      page.locator('[data-s2-page="how-it-works"][data-s2-element="page_step_2_icon"]'),
    ).toBeVisible();
  });

  test('About page exposes value card CMS markers', async ({ page }) => {
    await page.goto('/about');
    await expect(
      page.locator('[data-s2-page="about"][data-s2-section="values"][data-s2-element="value_1_title"]'),
    ).toBeVisible({ timeout: 20_000 });
  });
});
