import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SHOT_DIR = path.resolve(__dirname, 'e2e-screenshots');
fs.mkdirSync(SHOT_DIR, { recursive: true });

const APP_URL = process.env.E2E_APP_URL || 'http://localhost:5173';
const BACKEND_LOG_PATH = process.env.E2E_BACKEND_LOG || path.resolve(__dirname, '../backend/server.log');

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 420, height: 860 } });

  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log('BROWSER CONSOLE ERROR:', msg.text());
  });
  page.on('pageerror', (err) => console.log('BROWSER PAGE ERROR:', err.message));

  console.log('--- 1. Login screen ---');
  await page.goto(`${APP_URL}/login`);
  await page.waitForSelector('input[type="tel"]');
  await page.screenshot({ path: `${SHOT_DIR}/01-login.png` });

  const phone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  await page.fill('input[type="tel"]', phone);
  await page.click('button[type="submit"]');

  console.log('--- 2. OTP screen ---');
  await page.waitForURL('**/verify');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/02-otp-empty.png` });

  const otpLog = fs.readFileSync(BACKEND_LOG_PATH, 'utf-8');
  const matches = [...otpLog.matchAll(new RegExp(`OTP for \\+91${phone}: (\\d{6})`, 'g'))];
  const code = matches[matches.length - 1][1];
  console.log('Using real OTP from backend log:', code);

  for (let i = 0; i < 6; i++) {
    await page.locator('input[aria-label^="Digit"]').nth(i).fill(code[i]);
  }
  await page.waitForURL('**/home', { timeout: 8000 });

  console.log('--- 3. Home screen ---');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/03-home.png` });

  await page.click('text=See prices');
  await page.waitForSelector('text=Choose a vehicle', { timeout: 8000 });
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${SHOT_DIR}/03b-vehicle-selection.png` });

  // Real vehicle selection (P1 gap-analysis item) — pick any one of the
  // real, differently-priced options rather than assuming a single
  // hardcoded category still exists.
  await page.click('text=mini truck');
  await page.waitForURL('**/confirm', { timeout: 8000 });

  console.log('--- 4. Confirm screen (Waybill fare card) ---');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/04-confirm.png` });

  await page.click('text=/Confirm ·/');
  await page.waitForURL('**/track/**', { timeout: 8000 });

  console.log('--- 5. Track screen (searching) ---');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${SHOT_DIR}/05-track-searching.png` });

  await browser.close();
  console.log('--- Done. Screenshots in', SHOT_DIR, '---');
}

main().catch((err) => {
  console.error('SCRIPT FAILED:', err);
  process.exit(1);
});
