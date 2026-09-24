'use strict';
const path = require('path');
const { startStaticServer, sleep } = require('./lib/static-visual-server');

async function main() {
  let puppeteer;
  try {
    puppeteer = require('puppeteer');
  } catch {
    console.log('SKIP chart-production-module-browser.test.js (puppeteer not installed)');
    process.exit(0);
  }
  const ROOT = path.join(__dirname, '..');
  const srv = await startStaticServer(ROOT, { defaultPath: '/tests/chart-production-module-harness.html' });
  try {
    const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
    const page = await browser.newPage();
    page.on('pageerror', (err) => console.error('pageerror:', err.message));
    await page.goto(`http://127.0.0.1:${srv.port}/tests/chart-production-module-harness.html`, {
      waitUntil: 'networkidle0',
      timeout: 120000,
    });
    await page.waitForFunction(() => window.__prodModuleAudit && typeof window.__prodModuleAudit.ready === 'boolean', { timeout: 90000 });
    const audit = await page.evaluate(() => window.__prodModuleAudit);
    const status = await page.evaluate(() => document.getElementById('status').textContent);
    if (!audit.ready) {
      throw new Error(`Production module indicator E2E failed: ${status}\n${JSON.stringify(audit, null, 2)}`);
    }
    await browser.close();
    console.log('chart-production-module-browser: production ES module indicator flow verified');
    console.log('  steps:', audit.steps.join(' | '));
  } finally {
    await srv.close();
  }
}

main().catch((e) => {
  console.error(e.message || e);
  process.exit(1);
});
