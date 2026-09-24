'use strict';
/**
 * Real browser click audit for chart toolbar (Puppeteer drives the same
 * harness a human would open). Uses OS-assigned port to avoid EADDRINUSE.
 */
const path = require('path');
const { startStaticServer, sleep } = require('./lib/static-visual-server');

const ROOT = path.join(__dirname, '..');

function waitForAudit(page, timeoutMs) {
  return page.waitForFunction(
    () => {
      const r = window.__chartClickAuditResult;
      if (r && typeof r.total === 'number') return r;
      const t = document.getElementById('auditSummary')?.textContent || '';
      const m = t.match(/Audit:\s*(\d+)\/(\d+)\s*passed/);
      if (m) return { pass: Number(m[1]), total: Number(m[2]), ok: m[1] === m[2] };
      if (/Boot failed/.test(t)) return { bootFailed: t };
      return null;
    },
    { timeout: timeoutMs }
  );
}

async function main() {
  let puppeteer;
  try {
    puppeteer = require('puppeteer');
  } catch {
    console.log('SKIP chart-interactive-browser.test.js (puppeteer not installed)');
    process.exit(0);
  }

  const srv = await startStaticServer(ROOT, { defaultPath: '/tests/chart-interactive-harness.html' });
  try {
    const browser = await puppeteer.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const page = await browser.newPage();
    page.on('pageerror', (err) => {
      console.error('pageerror:', err.message);
    });
    await page.setViewport({ width: 1280, height: 900 });
    await page.goto(`http://127.0.0.1:${srv.port}/tests/chart-interactive-harness.html`, {
      waitUntil: 'networkidle0',
      timeout: 120000,
    });

    const resultHandle = await waitForAudit(page, 120000);
    const result = await resultHandle.jsonValue();
    if (result.bootFailed) throw new Error(result.bootFailed);
    if (!result.ok) {
      const lines = await page.$$eval('#auditList li', (els) => els.map((e) => e.textContent));
      const fails = lines.filter((l) => l.startsWith('FAIL'));
      throw new Error(`Chart click audit ${result.pass}/${result.total}: ${fails.join(' | ') || 'unknown failure'}`);
    }

    const consoleErrors = await page.evaluate(() => window.__chartAuditConsoleErrors || []);
    if (consoleErrors.length) {
      throw new Error(`Console errors during audit: ${consoleErrors.slice(0, 3).join(' | ')}`);
    }

    await page.click('#runAuditBtn');
    await sleep(800);
    const result2Handle = await waitForAudit(page, 120000);
    const result2 = await result2Handle.jsonValue();
    if (!result2.ok) throw new Error(`Re-run audit failed ${result2.pass}/${result2.total}`);

    await browser.close();
    console.log(`chart-interactive-browser: ${result.total} chart control checks passed (auto + manual re-run)`);
  } finally {
    await srv.close();
  }
}

main().catch((e) => {
  console.error(e.message || e);
  process.exit(1);
});
