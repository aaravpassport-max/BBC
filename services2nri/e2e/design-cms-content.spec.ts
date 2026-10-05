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
