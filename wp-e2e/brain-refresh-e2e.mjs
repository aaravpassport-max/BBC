import { chromium } from 'playwright';

const BASE = process.env.FNO_WP_URL || 'http://127.0.0.1:8080';
const USER = process.env.FNO_WP_USER || 'admin';
const PASS = process.env.FNO_WP_PASS || 'AdminPass123!';

const errors = [];
const consoleMsgs = [];

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (msg) => {
    const t = msg.type();
    const text = msg.text();
    if (t === 'error' || /toFixed|Brain refresh failed/i.test(text)) {
      consoleMsgs.push(`[${t}] ${text}`);
    }
  });

  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.includes('wp-login.php'), { timeout: 30000 });
  await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });

  await page.waitForSelector('#refresh', { timeout: 15000 });
  const ver = await page.locator('meta[name="fno-plugin-version"]').getAttribute('content');
  console.log('Plugin version:', ver);

  await page.click('#refresh');
  await page.waitForTimeout(25000);

  const decision = await page.locator('#brainDecision').innerText();
  const brainLog = await page.locator('#brainLog').innerText();
  const totalScore = await page.locator('#totalScore').innerText();

  console.log('--- brainDecision ---');
  console.log(decision.slice(0, 500));
  console.log('--- totalScore ---', totalScore);
  console.log('--- brainLog (tail) ---');
  console.log(brainLog.slice(-400));

  const failed =
    /Brain refresh failed/i.test(decision) ||
    /Cannot read properties of undefined \(reading 'toFixed'\)/.test(decision + brainLog) ||
    /Loading brain/i.test(decision);

  if (consoleMsgs.length) {
    console.log('--- console ---');
    consoleMsgs.forEach((m) => console.log(m));
  }
  if (errors.length) {
    console.log('--- page errors ---');
    errors.forEach((m) => console.log(m));
  }

  await browser.close();
  if (failed) {
    console.error('\nE2E FAIL: brain refresh did not complete successfully');
    process.exit(1);
  }
  if (totalScore === '-' || totalScore === '—') {
    console.error('\nE2E FAIL: totalScore still placeholder');
    process.exit(1);
  }
  console.log('\nE2E PASS: brain refresh completed');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
