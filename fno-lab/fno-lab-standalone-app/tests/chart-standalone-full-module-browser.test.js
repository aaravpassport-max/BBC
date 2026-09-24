'use strict';
const { execSync } = require('child_process');
const path = require('path');
execSync('node build-standalone-module-bundle.js', { cwd: __dirname, stdio: 'inherit' });
const { startStaticServer, sleep } = require('./lib/static-visual-server');

async function main() {
  let puppeteer;
  try { puppeteer = require('puppeteer'); } catch {
    console.log('SKIP chart-standalone-full-module-browser.test.js');
    process.exit(0);
  }
  const ROOT = path.join(__dirname, '..');
  const srv = await startStaticServer(ROOT, { defaultPath: '/tests/chart-standalone-full-module-harness.html' });
  try {
    const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'] });
    const page = await browser.newPage();
    page.on('pageerror', (e) => console.error('pageerror:', e.message));
    await page.goto(`http://127.0.0.1:${srv.port}/tests/chart-standalone-full-module-harness.html`, { waitUntil: 'networkidle0', timeout: 180000 });
    await page.waitForFunction(() => {
      const t = document.getElementById('status')?.textContent || '';
      return window.__fullModuleAudit?.ready === true || /^FAIL/.test(t);
    }, { timeout: 120000 });
    const audit = await page.evaluate(() => window.__fullModuleAudit);
    if (!audit.ready) throw new Error(JSON.stringify(audit, null, 2));
    await browser.close();
    console.log('chart-standalone-full-module-browser: PASS (same JS bundle as standalone-app.php module block)');
    console.log(' ', audit.steps.join(' | '));
  } finally {
    await srv.close();
  }
}

main().catch((e) => { console.error(e.message || e); process.exit(1); });
