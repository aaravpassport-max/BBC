import { test, expect } from '@playwright/test';

const ADMIN_USER = {
  id: 2,
  email: 'admin@e2e.test',
  display_name: 'E2E Admin',
  s2nri_role: 'super_admin',
};

/** Marketing templates: admin Content save → public `data-s2-element` text. */
type MarketingProbe = {
  pageLabel: string;
  previewPath: string;
  pageId: string;
  sectionId: string;
  settingKey: string;
  value: string;
  sectionKey: string;
  elementId: string;
  /** Rich panel `BandTextInput` label (no `#ds-field-*` id). */
  fillLabel?: string;
  /** Disambiguate when multiple fields share the same label. */
  fillPlaceholder?: string;
  /** First timeline step title field on How It Works. */
  hiwFirstStepTitle?: boolean;
};

const MARKETING_BAND_PROBES: MarketingProbe[] = [
  {
    pageLabel: 'About',
    previewPath: '/about',
    pageId: 'about',
    sectionId: 'about',
    settingKey: 'about_heading',
    value: 'E2E About Story Heading',
    sectionKey: 'about',
    elementId: 'heading',
    fillLabel: 'Heading',
  },
  {
    pageLabel: 'About',
    previewPath: '/about',
    pageId: 'about',
    sectionId: 'values',
    settingKey: 'about_values_title',
    value: 'E2E About Values Title',
    sectionKey: 'values',
    elementId: 'heading',
    fillLabel: 'Section heading',
  },
  {
    pageLabel: 'About',
    previewPath: '/about',
    pageId: 'about',
    sectionId: 'cta',
    settingKey: 'about_cta_title',
    value: 'E2E About CTA Title',
    sectionKey: 'cta',
    elementId: 'heading',
    fillLabel: 'Heading',
  },
  {
    pageLabel: 'Pricing',
    previewPath: '/pricing',
    pageId: 'pricing',
    sectionId: 'pricing',
    settingKey: 'pricing_grid_title',
    value: 'E2E Pricing Grid Title',
    sectionKey: 'pricing',
    elementId: 'heading',
    fillLabel: 'Heading',
  },
  {
    pageLabel: 'Pricing',
    previewPath: '/pricing',
    pageId: 'pricing',
    sectionId: 'compare',
    settingKey: 'pricing_compare_brand_title',
    value: 'E2E Brand Compare Title',
    sectionKey: 'compare',
    elementId: 'heading',
    fillPlaceholder: 'NRIWAY vs. Traditional Agents',
  },
  {
    pageLabel: 'Pricing',
    previewPath: '/pricing',
    pageId: 'pricing',
    sectionId: 'consultation',
    settingKey: 'pricing_consultation_title',
    value: 'E2E Consultation CTA Title',
    sectionKey: 'consultation',
    elementId: 'heading',
  },
  {
    pageLabel: 'Contact',
    previewPath: '/contact',
    pageId: 'contact',
    sectionId: 'contact',
    settingKey: 'contact_title',
    value: 'E2E Contact Column Title',
    sectionKey: 'contact',
    elementId: 'heading',
    fillLabel: 'Left column heading',
  },
  {
    pageLabel: 'Contact',
    previewPath: '/contact',
    pageId: 'contact',
    sectionId: 'contact',
    settingKey: 'contact_form_title',
    value: 'E2E Contact Form Title',
    sectionKey: 'contact',
    elementId: 'form_heading',
    fillLabel: 'Form title',
  },
  {
    pageLabel: 'FAQ',
    previewPath: '/faq',
    pageId: 'faq',
    sectionId: 'faq_cta',
    settingKey: 'faq_cta_title',
    value: 'E2E FAQ CTA Title',
    sectionKey: 'faq_cta',
    elementId: 'heading',
    fillLabel: 'Heading',
  },
  {
    pageLabel: 'How it works',
    previewPath: '/how-it-works',
    pageId: 'how-it-works',
    sectionId: 'process',
    settingKey: 'hiw_page_step1_title',
    value: 'E2E HIW Page Step One',
    sectionKey: 'process',
    elementId: 'page_step_1_title',
    hiwFirstStepTitle: true,
  },
  {
    pageLabel: 'How it works',
    previewPath: '/how-it-works',
    pageId: 'how-it-works',
    sectionId: 'hiw_cta',
    settingKey: 'hiw_page_cta_title',
    value: 'E2E HIW Callout Title',
    sectionKey: 'hiw_cta',
    elementId: 'heading',
    fillLabel: 'Heading',
  },
];

test.describe.configure({ mode: 'serial' });

test.describe('Marketing pages — section admin save audit', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.request.post('/mock-api/e2e/reset-platform-settings');
    await page.addInitScript((user) => {
      window.__S2NRI_E2E_USER__ = user;
    }, ADMIN_USER);
    await page.goto('/admin/design');
    await expect(page.locator('.s2-design-system-page')).toBeVisible({ timeout: 30_000 });
  });

  for (const probe of MARKETING_BAND_PROBES) {
    test(`${probe.pageLabel} / ${probe.sectionId} / ${probe.elementId} saves to public DOM`, async ({ page }) => {
      await page
        .locator('.s2-design-builder-nav__page-btn:not(.s2-design-builder-nav__page-btn--sub)')
        .filter({ hasText: probe.pageLabel })
        .click();

      await page.locator(`[data-section-id="${probe.sectionId}"] .s2-band-card__main`).click();
      const editor = page.getByTestId(`section-editor-${probe.sectionId}`);
      await expect(editor).toBeVisible({ timeout: 15_000 });
      await editor.getByRole('tab', { name: 'Content' }).click();

      if (probe.hiwFirstStepTitle) {
        await editor.getByLabel('Title').first().fill(probe.value);
      } else if (probe.fillPlaceholder) {
        await editor.getByPlaceholder(probe.fillPlaceholder).fill(probe.value);
      } else if (probe.fillLabel) {
        await editor.getByLabel(probe.fillLabel, { exact: true }).fill(probe.value);
      } else {
        const field = page.locator(`#ds-field-${probe.settingKey}`);
        await expect(field).toBeVisible({ timeout: 12_000 });
        await field.fill(probe.value);
      }

      await editor.getByRole('button', { name: 'Save section content' }).click();
      await expect(editor.getByText('Content saved')).toBeVisible({ timeout: 20_000 });

      await page.goto(probe.previewPath);
      await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 25_000 });

      const marker = page.locator(
        `[data-s2-page="${probe.pageId}"][data-s2-section="${probe.sectionKey}"][data-s2-element="${probe.elementId}"]`,
      );
      await marker.first().scrollIntoViewIfNeeded();
      await expect(marker.first()).toContainText(probe.value, { timeout: 20_000 });
    });
  }
});
