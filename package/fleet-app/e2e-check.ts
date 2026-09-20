import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SHOT_DIR = path.resolve(__dirname, 'e2e-screenshots');
fs.mkdirSync(SHOT_DIR, { recursive: true });

const API = process.env.E2E_API_URL || 'http://localhost:3000';
const APP_URL = process.env.E2E_APP_URL || 'http://localhost:5183';
const BACKEND_LOG_PATH = process.env.E2E_BACKEND_LOG || path.resolve(__dirname, '../backend/server.log');

function readLatestOtp(phone: string): string {
  const log = fs.readFileSync(BACKEND_LOG_PATH, 'utf-8');
  const matches = [...log.matchAll(new RegExp(`OTP for \\+91${phone}: (\\d{6})`, 'g'))];
  if (matches.length === 0) throw new Error(`No OTP found in backend log for ${phone}`);
  return matches[matches.length - 1][1];
}

function randomPhone(): string {
  return '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
}

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1000, height: 900 } });
  page.on('pageerror', (err) => {
    console.error('SCRIPT FAILED (browser page error):', err.message);
    process.exitCode = 1;
  });

  const { Client } = await import('pg');
  const pg = new Client({ connectionString: 'postgres://app_user:dev_local_only@localhost:5432/logistics_superapp' });
  await pg.connect();

  const driverPhone = randomPhone();
  const driverId = crypto.randomUUID();
  await pg.query(`INSERT INTO users (id, phone, country_code, account_type) VALUES ($1,$2,'+91','driver')`, [
    driverId,
    driverPhone,
  ]);
  await pg.query(
    `INSERT INTO driver_profiles (user_id, kyc_status, training_status, online_status, current_lat, current_lng, last_ping_at)
     VALUES ($1,'approved','passed',true,12.95,77.6,now())`,
    [driverId]
  );

  console.log('--- 1. Login screen ---');
  await page.goto(`${APP_URL}/login`);
  await page.screenshot({ path: `${SHOT_DIR}/01-login.png` });
  const ownerPhone = randomPhone();
  await page.fill('input[type="tel"]', ownerPhone);
  await page.click('text=Send code');
  await page.waitForURL('**/verify');

  console.log('--- 2. OTP screen ---');
  await page.waitForTimeout(400);
  const code = readLatestOtp(ownerPhone);
  console.log('Using real OTP from backend log:', code);
  for (let i = 0; i < 6; i++) {
    await page.fill(`input[aria-label="Digit ${i + 1}"]`, code[i]);
  }
  await page.waitForURL('**/home', { timeout: 8000 });

  console.log('--- 3. Empty dashboard ---');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/03-empty-dashboard.png` });
  const emptyStateVisible = await page.locator('text=No drivers in your fleet yet').count();
  if (emptyStateVisible === 0) throw new Error('Expected the empty-fleet state on first login.');

  console.log('--- 4. Add a real driver by phone ---');
  await page.click('text=+ Add driver');
  await page.waitForURL('**/add-driver');
  await page.fill('input[type="tel"]', driverPhone);
  await page.click('text=Add to fleet');
  await page.waitForURL('**/home', { timeout: 8000 });
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${SHOT_DIR}/04-dashboard-with-driver.png` });

  const driverRowVisible = await page.locator(`text=+91 ${driverPhone}`).count();
  if (driverRowVisible === 0) throw new Error('Expected the newly-added driver to appear on the dashboard.');
  const onlineVisible = await page.locator('text=Online').count();
  if (onlineVisible === 0) throw new Error('Expected the driver to show real status "Online".');

  console.log('--- 5. Driver detail ---');
  await page.click(`text=+91 ${driverPhone}`);
  await page.waitForURL('**/driver/**');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/05-driver-detail.png` });
  const balanceVisible = await page.locator('text=₹0.00').count();
  if (balanceVisible === 0) throw new Error('Expected the driver detail to show a real ₹0.00 starting balance.');

  console.log('--- 6. Remove the driver, verify their own account is untouched ---');
  await page.click('text=Remove from fleet');
  await page.waitForURL('**/home', { timeout: 8000 });
  const linkCheck = await pg.query('SELECT fleet_owner_id, kyc_status FROM driver_profiles WHERE user_id = $1', [
    driverId,
  ]);
  if (linkCheck.rows[0].fleet_owner_id !== null) throw new Error('Expected fleet_owner_id to be cleared after removal.');
  if (linkCheck.rows[0].kyc_status !== 'approved') {
    throw new Error("Removing from a fleet must not touch the driver's own KYC status.");
  }

  await pg.end();
  await browser.close();
  console.log('--- ALL STEPS PASSED. Screenshots in', SHOT_DIR, '---');
}

main().catch((err) => {
  console.error('SCRIPT FAILED:', err);
  process.exit(1);
});
