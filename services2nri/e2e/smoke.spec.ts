import { test, expect } from '@playwright/test';

test.describe('Public marketing smoke', () => {
  test('home loads with platform name', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/Services2NRI/i).first()).toBeVisible({ timeout: 15_000 });
  });

  test('contact page shows form', async ({ page }) => {
    await page.goto('/contact');
    await expect(page.getByPlaceholder(/Full Name/i)).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('button', { name: /Send Message/i })).toBeVisible();
  });

  test('services directory loads', async ({ page }) => {
    await page.goto('/services');
    await expect(page.getByRole('heading', { name: /All NRI Services/i })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByPlaceholder(/Search services/i)).toBeVisible();
  });

  test('pricing page shows plan cards', async ({ page }) => {
    await page.goto('/pricing');
    await expect(page.getByText(/Starter|Basic|Advanced|Pro/i).first()).toBeVisible({ timeout: 20_000 });
  });

  test('FAQ page expands', async ({ page }) => {
    await page.goto('/faq');
    await expect(page.getByText(/Frequently Asked|Test\?/i).first()).toBeVisible({ timeout: 20_000 });
  });

  test('blog hub loads', async ({ page }) => {
    await page.goto('/blog');
    await expect(page.getByRole('heading', { name: /Expert Guides for NRIs/i })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByPlaceholder(/Search articles/i)).toBeVisible();
  });

  test('city landing loads', async ({ page }) => {
    await page.goto('/cities/property-management-in-pune');
    await expect(page.getByRole('heading', { name: /Property Management in Pune/i })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('link', { name: /Get Free Quote for Pune/i })).toBeVisible();
  });
});

test.describe('Service detail smoke', () => {
  test('service detail renders booking wizard', async ({ page }) => {
    await page.goto('/service/complete-property-management');
    await expect(page.getByText(/Property Management/i).first()).toBeVisible({ timeout: 25_000 });
    await expect(page.locator('#booking-form')).toBeVisible();
    await expect(page.getByRole('button', { name: /Next Step/i })).toBeVisible();
  });
});
