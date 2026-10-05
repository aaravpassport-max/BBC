import { test, expect } from '@playwright/test';

const ADMIN_USER = {
  id: 2,
  email: 'admin@e2e.test',
  display_name: 'E2E Admin',
  s2nri_role: 'super_admin',
};

test.describe.configure({ mode: 'serial' });

test.describe('Design System admin save → public site', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.request.post('/mock-api/e2e/reset-platform-settings');
    await page.addInitScript((user) => {
      window.__S2NRI_E2E_USER__ = user;
    }, ADMIN_USER);
  });

  test('hero heading saved in Design System appears on homepage', async ({ page }) => {
    await page.goto('/admin/design');
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 30_000 });

    await page.locator('.s2-design-builder-nav__page-btn:not(.s2-design-builder-nav__page-btn--sub)').filter({ hasText: 'Homepage' }).click();
    await page.locator('[data-section-id="hero"] .s2-band-card__main').click();
    await expect(page.getByTestId('section-editor-hero')).toBeVisible({ timeout: 15_000 });

    const heroLine1 = page.locator('#ds-field-hero_heading_1');
    await expect(heroLine1).toBeVisible();
    await heroLine1.fill('Admin CMS Hero Line One');

    await page.getByRole('region', { name: 'Save section' }).getByRole('button', { name: 'Save section content' }).click();
    await expect(page.getByText('Content saved')).toBeVisible({ timeout: 15_000 });

    await page.goto('/');
    await expect(
      page.locator('[data-s2-page="home"][data-s2-section="hero"][data-s2-element="heading"]'),
    ).toContainText('Admin CMS Hero Line One', { timeout: 20_000 });
  });

  test('newsletter title saved in Design System appears on homepage', async ({ page }) => {
    await page.goto('/admin/design');
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 30_000 });

    await page.locator('.s2-design-builder-nav__page-btn:not(.s2-design-builder-nav__page-btn--sub)').filter({ hasText: 'Homepage' }).click();
    await page.locator('[data-section-id="newsletter"] .s2-band-card__main').click();
    await expect(page.getByTestId('section-editor-newsletter')).toBeVisible({ timeout: 15_000 });

    await page.locator('#ds-field-newsletter_title').fill('Admin Saved Newsletter');
    await page.getByRole('region', { name: 'Save section' }).getByRole('button', { name: 'Save section content' }).click();
    await expect(page.getByText('Content saved')).toBeVisible({ timeout: 15_000 });

    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Admin Saved Newsletter' })).toBeVisible({
      timeout: 20_000,
    });
  });
});
