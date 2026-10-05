import { test, expect } from '@playwright/test';

const ADMIN_USER = {
  id: 2,
  email: 'admin@e2e.test',
  display_name: 'E2E Admin',
  s2nri_role: 'super_admin',
};

/** Every homepage band: admin Content save → public `data-s2-element` text. */
const HOME_BAND_PROBES = [
  { sectionId: 'hero', settingKey: 'hero_heading_1', value: 'E2E Band Hero One', sectionKey: 'hero', elementId: 'heading' },
  { sectionId: 'notice', settingKey: 'home_notice_text', value: 'E2E Band Notice Message', sectionKey: 'notice', elementId: 'body' },
  { sectionId: 'search', settingKey: 'home_search_button', value: 'E2E Search Btn', sectionKey: 'search', elementId: 'primary_button' },
  { sectionId: 'features', settingKey: 'features_title', value: 'E2E Band Features Title', sectionKey: 'features', elementId: 'heading' },
  { sectionId: 'home_services', settingKey: 'services_title', value: 'E2E Band Services Title', sectionKey: 'home_services', elementId: 'heading' },
  { sectionId: 'cities', settingKey: 'cities_section_title', value: 'E2E Band Cities Title', sectionKey: 'cities', elementId: 'heading' },
  { sectionId: 'stats', settingKey: 'stat_1_label', value: 'E2E Stat Label', sectionKey: 'stats', elementId: 'stat_1_label', richStat: true },
  { sectionId: 'tagline', settingKey: 'home_tagline', value: 'E2E Band Tagline Quote', sectionKey: 'tagline', elementId: 'body' },
  { sectionId: 'testimonials', settingKey: 'testimonials_title', value: 'E2E Testimonials Title', sectionKey: 'testimonials', elementId: 'heading' },
  { sectionId: 'process', settingKey: 'hiw_title', value: 'E2E How It Works Title', sectionKey: 'process', elementId: 'heading' },
  { sectionId: 'press', settingKey: 'home_press_label', value: 'E2E Press Strip', sectionKey: 'press', elementId: 'eyebrow' },
  { sectionId: 'partners', settingKey: 'home_partners_label', value: 'E2E Partners Strip', sectionKey: 'partners', elementId: 'eyebrow' },
  { sectionId: 'about', settingKey: 'about_heading', value: 'E2E About Band Heading', sectionKey: 'about', elementId: 'heading' },
  { sectionId: 'awards', settingKey: 'home_awards_label', value: 'E2E Awards Strip', sectionKey: 'awards', elementId: 'eyebrow' },
  { sectionId: 'faq', settingKey: 'faq_section_title', value: 'E2E FAQ Band Title', sectionKey: 'faq', elementId: 'heading' },
  { sectionId: 'newsletter', settingKey: 'newsletter_title', value: 'E2E Newsletter Title', sectionKey: 'newsletter', elementId: 'heading' },
  { sectionId: 'app', settingKey: 'app_title', value: 'E2E App Band Title', sectionKey: 'app', elementId: 'heading' },
  { sectionId: 'locations', settingKey: 'home_locations_label', value: 'E2E Locations Label', sectionKey: 'locations', elementId: 'eyebrow' },
  { sectionId: 'header', settingKey: 'header_service_request_text', value: 'E2E Header Request', sectionKey: 'header', elementId: 'service_request_button', link: true, chromeRich: true },
  { sectionId: 'footer', settingKey: 'footer_col_quick_title', value: 'E2E Footer Quick Col', sectionKey: 'footer', elementId: 'heading', footerHeading: true },
] as const;

test.describe.configure({ mode: 'serial' });

test.describe('Homepage — every band admin save audit', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.request.post('/mock-api/e2e/reset-platform-settings');
    await page.addInitScript((user) => {
      window.__S2NRI_E2E_USER__ = user;
    }, ADMIN_USER);
    await page.goto('/admin/design');
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 30_000 });
    await page.locator('.s2-design-builder-nav__page-btn:not(.s2-design-builder-nav__page-btn--sub)').filter({ hasText: 'Homepage' }).click();
  });

  for (const probe of HOME_BAND_PROBES) {
    test(`band ${probe.sectionId} saves to public DOM`, async ({ page }) => {
      await page.locator(`[data-section-id="${probe.sectionId}"] .s2-band-card__main`).click();
      const editor = page.getByTestId(`section-editor-${probe.sectionId}`);
      await expect(editor).toBeVisible({ timeout: 15_000 });
      await editor.getByRole('tab', { name: 'Content' }).click();

      if ('richStat' in probe && probe.richStat) {
        await editor.locator('.s2-band-stats-grid__cell').first().locator('input').nth(1).fill(probe.value);
      } else if ('chromeRich' in probe && probe.chromeRich) {
        await editor.getByLabel('Service request label', { exact: false }).fill(probe.value);
      } else {
        const field = page.locator(`#ds-field-${probe.settingKey}`);
        await expect(field).toBeVisible({ timeout: 10_000 });
        await field.fill(probe.value);
      }

      await editor.getByRole('button', { name: 'Save section content' }).click();
      await expect(editor.getByText('Content saved')).toBeVisible({ timeout: 20_000 });

      await page.goto('/');
      const root = page.locator('#s2nri-root');
      await expect(root).toBeVisible({ timeout: 25_000 });

      if ('link' in probe && probe.link) {
        await expect(page.getByRole('link', { name: probe.value }).first()).toBeVisible({ timeout: 20_000 });
        return;
      }
      if ('footerHeading' in probe && probe.footerHeading) {
        await expect(page.getByRole('heading', { name: probe.value })).toBeVisible({ timeout: 20_000 });
        return;
      }

      const marker = page.locator(
        `[data-s2-page="home"][data-s2-section="${probe.sectionKey}"][data-s2-element="${probe.elementId}"]`,
      );
      if (probe.sectionId !== 'notice') {
        await marker.first().scrollIntoViewIfNeeded();
      }
      await expect(marker.first()).toContainText(probe.value, { timeout: 20_000 });
    });
  }
});
