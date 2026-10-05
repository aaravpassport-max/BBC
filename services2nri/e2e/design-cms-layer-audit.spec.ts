import { test, expect } from '@playwright/test';
import audit from './cms-layer-audit.generated.json';

test.describe.configure({ mode: 'serial' });

test.describe('CMS layer audit — API public settings (Layer 2)', () => {
  test.beforeEach(async ({ page }) => {
    await page.request.post('/mock-api/e2e/reset-platform-settings');
  });

  for (const probe of audit.saveProbes) {
    test(`PUT admin/settings → settings/public includes ${probe.key}`, async ({ request }) => {
      const value = probe.value;
      const put = await request.put('/mock-api/admin/settings', { data: { [probe.key]: value } });
      expect(put.ok()).toBeTruthy();
      const pub = await request.get('/mock-api/settings/public');
      expect(pub.ok()).toBeTruthy();
      const body = (await pub.json()) as { settings: Record<string, string> };
      expect(body.settings[probe.key]).toBe(value);
    });
  }
});

test.describe('CMS layer audit — canonical DOM markers (Layer 3)', () => {
  test('every canonical element id is present in the DOM', async ({ page }) => {
    test.setTimeout(120_000);
    const byRoute = new Map<string, typeof audit.markers>();
    for (const m of audit.markers) {
      const list = byRoute.get(m.route) ?? [];
      list.push(m);
      byRoute.set(m.route, list);
    }

    for (const [route, markers] of byRoute) {
      await page.goto(route);
      await expect(page.locator('#s2nri-root')).toBeVisible({ timeout: 25_000 });
      for (const m of markers) {
        const loc = page.locator(
          `[data-s2-page="${m.page}"][data-s2-section="${m.section}"][data-s2-element="${m.element}"]`,
        );
        await expect(loc.first()).toBeAttached({ timeout: 15_000 });
      }
    }
  });
});

