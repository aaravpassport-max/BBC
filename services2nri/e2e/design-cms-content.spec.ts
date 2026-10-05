import { test, expect } from '@playwright/test';

test.describe('Design System CMS content on public pages', () => {
  test('blog hero reflects custom eyebrow from settings', async ({ page }) => {
    await page.addInitScript(() => {
      (window as unknown as { __E2E_SETTINGS_PATCH__: Record<string, string> }).__E2E_SETTINGS_PATCH__ = {
        blog_hero_eyebrow: 'CMS Eyebrow E2E',
        blog_hero_title: 'CMS Title E2E',
      };
    });
    await page.goto('/blog');
    await expect(page.getByText('CMS Eyebrow E2E')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('heading', { name: 'CMS Title E2E' })).toBeVisible();
  });

  test('home hero primary CTA hidden via hide_el setting', async ({ page }) => {
    await page.addInitScript(() => {
      (window as unknown as { __E2E_SETTINGS_PATCH__: Record<string, string> }).__E2E_SETTINGS_PATCH__ = {
        hero_cta_text: 'Browse CMS CTA',
        hide_el_home_hero_primary_button: '1',
      };
    });
    await page.goto('/');
    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('link', { name: 'Browse CMS CTA' })).toHaveCount(0);
    await expect(page.locator('[data-s2-element="secondary_button"]')).toBeVisible();
  });

  test('service detail exposes CMS element markers', async ({ page }) => {
    await page.goto('/service/complete-property-management');
    await expect(page.locator('[data-s2-page="service"][data-s2-section="hero"][data-s2-element="heading"]')).toBeVisible({
      timeout: 25_000,
    });
    await expect(page.locator('[data-s2-section="wizard"][data-s2-element="step_heading"]')).toBeVisible();
  });

  test('contact form submit label from settings', async ({ page }) => {
    await page.addInitScript(() => {
      (window as unknown as { __E2E_SETTINGS_PATCH__: Record<string, string> }).__E2E_SETTINGS_PATCH__ = {
        contact_form_submit_text: 'Submit CMS Test',
      };
    });
    await page.goto('/contact');
    await expect(page.getByRole('button', { name: 'Submit CMS Test' })).toBeVisible({ timeout: 20_000 });
  });
});
