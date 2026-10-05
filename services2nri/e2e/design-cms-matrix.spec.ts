import { test, expect } from '@playwright/test';

type HomeBandCase = {
  title: string;
  patch: Record<string, string>;
  section: string;
  element: string;
  text: string;
};

const HOME_BAND_MATRIX: HomeBandCase[] = [
  {
    title: 'about heading',
    patch: { about_heading: 'Matrix About Heading' },
    section: 'about',
    element: 'heading',
    text: 'Matrix About Heading',
  },
  {
    title: 'app download heading',
    patch: { app_title: 'Matrix App Download' },
    section: 'app',
    element: 'heading',
    text: 'Matrix App Download',
  },
  {
    title: 'locations label',
    patch: { home_locations_label: 'Matrix Location Label' },
    section: 'locations',
    element: 'eyebrow',
    text: 'Matrix Location Label',
  },
  {
    title: 'tagline quote',
    patch: { home_tagline: 'Matrix client tagline' },
    section: 'tagline',
    element: 'body',
    text: 'Matrix client tagline',
  },
  {
    title: 'press strip label',
    patch: { home_press_label: 'Matrix Press Label' },
    section: 'press',
    element: 'eyebrow',
    text: 'Matrix Press Label',
  },
  {
    title: 'partners strip label',
    patch: { home_partners_label: 'Matrix Partners Label' },
    section: 'partners',
    element: 'eyebrow',
    text: 'Matrix Partners Label',
  },
  {
    title: 'awards strip label',
    patch: { home_awards_label: 'Matrix Awards Label' },
    section: 'awards',
    element: 'eyebrow',
    text: 'Matrix Awards Label',
  },
  {
    title: 'services grid heading',
    patch: { services_title: 'Matrix Services Grid' },
    section: 'home_services',
    element: 'heading',
    text: 'Matrix Services Grid',
  },
  {
    title: 'cities grid heading',
    patch: { cities_section_title: 'Matrix Cities Grid' },
    section: 'cities',
    element: 'heading',
    text: 'Matrix Cities Grid',
  },
];

function patchSettings(patch: Record<string, string>) {
  return `
    window.__E2E_SETTINGS_PATCH__ = ${JSON.stringify(patch)};
  `;
}

test.describe('Design System CMS matrix — home bands', () => {
  for (const row of HOME_BAND_MATRIX) {
    test(row.title, async ({ page }) => {
      await page.addInitScript({ content: patchSettings(row.patch) });
      await page.goto('/');
      const locator = page.locator(
        `[data-s2-page="home"][data-s2-section="${row.section}"][data-s2-element="${row.element}"]`,
      );
      await locator.scrollIntoViewIfNeeded();
      await expect(locator).toContainText(row.text, { timeout: 20_000 });
    });
  }

  test('hide_el removes newsletter heading from DOM', async ({ page }) => {
    await page.addInitScript({
      content: patchSettings({
        newsletter_title: 'Hidden Newsletter Title',
        hide_el_home_newsletter_heading: '1',
      }),
    });
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Hidden Newsletter Title' })).toHaveCount(0);
  });
});

test.describe('Design System CMS matrix — marketing pages', () => {
  test('pricing page title from settings', async ({ page }) => {
    await page.addInitScript({
      content: patchSettings({ pricing_page_title: 'Matrix Pricing Hero' }),
    });
    await page.goto('/pricing');
    await expect(page.getByRole('heading', { name: 'Matrix Pricing Hero' })).toBeVisible({ timeout: 20_000 });
  });

  test('FAQ page title from settings', async ({ page }) => {
    await page.addInitScript({
      content: patchSettings({ faq_page_title: 'Matrix FAQ Hero' }),
    });
    await page.goto('/faq');
    await expect(page.getByRole('heading', { name: 'Matrix FAQ Hero' })).toBeVisible({ timeout: 20_000 });
  });

  test('services directory title from settings', async ({ page }) => {
    await page.addInitScript({
      content: patchSettings({ services_page_title: 'Matrix Services Directory' }),
    });
    await page.goto('/services');
    await expect(page.getByRole('heading', { name: 'Matrix Services Directory' })).toBeVisible({ timeout: 20_000 });
  });
});
