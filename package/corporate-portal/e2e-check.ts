import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SHOT_DIR = path.resolve(__dirname, 'e2e-screenshots');
fs.mkdirSync(SHOT_DIR, { recursive: true });

const APP_URL = process.env.E2E_APP_URL || 'http://localhost:5181';
const BACKEND_LOG_PATH = process.env.E2E_BACKEND_LOG || path.resolve(__dirname, '../backend/server.log');
const DB_URL = process.env.E2E_DATABASE_URL || 'postgres://app_user:dev_local_only@localhost:5432/logistics_superapp';

function readLatestOtp(phone: string): string {
  const log = fs.readFileSync(BACKEND_LOG_PATH, 'utf-8');
  const matches = [...log.matchAll(new RegExp(`OTP for \\+91${phone}: (\\d{6})`, 'g'))];
  if (matches.length === 0) throw new Error(`No OTP found in backend log for ${phone}`);
  return matches[matches.length - 1][1];
}

async function loginViaUi(page: import('playwright').Page, phone: string) {
  await page.goto(`${APP_URL}/login`);
  await page.waitForSelector('input[type="tel"]');
  await page.fill('input[type="tel"]', phone);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/verify');
  const code = readLatestOtp(phone);
  for (let i = 0; i < 6; i++) {
    await page.locator('input[aria-label^="Digit"]').nth(i).fill(code[i]);
  }
  await page.waitForURL('**/accounts', { timeout: 8000 });
}

async function main() {
  const browser = await chromium.launch();
  const adminPage = await browser.newPage({ viewport: { width: 420, height: 900 } });
  adminPage.on('pageerror', (err) => console.log('ADMIN PAGE ERROR:', err.message));
  adminPage.on('console', (msg) => {
    if (msg.type() === 'error') console.log('ADMIN CONSOLE ERROR:', msg.text());
  });

  const { Client } = await import('pg');
  const pgClient = new Client({ connectionString: DB_URL });
  await pgClient.connect();

  console.log('--- 1. A company account exists, with a pending first-admin invite (how sales/ops onboards a new company - not self-service) ---');
  const accountId = (
    await pgClient.query(
      `INSERT INTO corporate_accounts (name, credit_limit, committed_spend, reserved_spend, status)
       VALUES ($1, 5000, 0, 0, 'active') RETURNING id`,
      [`E2E Freight Co ${Date.now()}`]
    )
  ).rows[0].id;
  const adminInviteEmail = `admin-${Date.now()}@e2efreight.test`;
  await pgClient.query(
    `INSERT INTO corporate_employees (corporate_account_id, email, role, status) VALUES ($1, $2, 'account_admin', 'invited')`,
    [accountId, adminInviteEmail]
  );
  console.log('Account created:', accountId, 'with pending admin invite:', adminInviteEmail);

  console.log('--- 2. The invited admin logs in for the first time via the real UI ---');
  const adminPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  await loginViaUi(adminPage, adminPhone);
  await adminPage.waitForTimeout(400);
  await adminPage.screenshot({ path: `${SHOT_DIR}/01-accept-invite-screen.png` });

  const acceptScreenVisible = await adminPage.locator('text=Accept an invite').count();
  if (acceptScreenVisible === 0) throw new Error('Expected the Accept-Invite screen for a user with zero corporate memberships.');

  console.log('--- 3. Accepts the invite through the real UI ---');
  await adminPage.fill('input[type="email"]', adminInviteEmail);
  const [acceptResponse] = await Promise.all([
    adminPage.waitForResponse((r) => r.url().includes('/invites/accept')),
    adminPage.click('text=Accept invite'),
  ]);
  console.log('Accept invite response:', acceptResponse.status());
  await adminPage.waitForURL(`**/accounts/${accountId}`, { timeout: 6000 });
  await adminPage.waitForTimeout(400);
  await adminPage.screenshot({ path: `${SHOT_DIR}/02-admin-dashboard.png` });

  const creditVisible = await adminPage.locator('text=Available credit').count();
  if (creditVisible === 0) throw new Error('Expected the credit summary Waybill on the account dashboard.');

  console.log('--- 4. Verify server-side: the admin is genuinely linked and active ---');
  const adminLinkCheck = await pgClient.query(
    `SELECT user_id, status FROM corporate_employees WHERE email = $1`,
    [adminInviteEmail]
  );
  if (adminLinkCheck.rows[0].status !== 'active' || !adminLinkCheck.rows[0].user_id) {
    throw new Error('Admin invite was not actually linked and activated server-side.');
  }

  console.log('--- 4a. The admin books a real corporate-billed trip via the API, and it appears on their own dashboard ---');
  const API = process.env.E2E_API_URL || 'http://localhost:3000';
  const adminToken = await adminPage.evaluate(() => localStorage.getItem('access_token'));
  const quote = await fetch(`${API}/v1/pricing/quote`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${adminToken}` },
    body: JSON.stringify({
      pickup: { lat: 12.952, lng: 77.602 },
      drops: [{ lat: 12.97, lng: 77.62 }],
      vehicle_category: 'mini_truck',
    }),
  }).then((r) => r.json());
  const corpBooking = await fetch(`${API}/v1/bookings`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${adminToken}`,
      'Idempotency-Key': crypto.randomUUID(),
    },
    body: JSON.stringify({ quote_id: quote.quotes[0].quote_id, payment_method: 'corporate_bill' }),
  }).then((r) => r.json());
  console.log('Corporate-billed booking created:', corpBooking.id, corpBooking.status);

  await adminPage.reload();
  await adminPage.waitForLoadState('networkidle');
  await adminPage.waitForTimeout(500);
  await adminPage.screenshot({ path: `${SHOT_DIR}/02b-dashboard-with-booking.png` });
  const bookingRowVisible = await adminPage.locator('text=Recent bookings').count();
  if (bookingRowVisible === 0) throw new Error('Expected the "Recent bookings" section on the dashboard.');
  const noBookingsTextVisible = await adminPage.locator('text=No bookings billed to this account yet.').count();
  if (noBookingsTextVisible > 0) throw new Error('Expected the real corporate-billed booking to appear, not the empty state.');

  console.log('--- 5. The admin invites a real teammate through the real UI ---');
  const teammateInviteEmail = `teammate-${Date.now()}@e2efreight.test`;
  await adminPage.click('text=+ Invite');
  await adminPage.waitForTimeout(200);
  await adminPage.fill('input[type="email"]', teammateInviteEmail);
  const [inviteResponse] = await Promise.all([
    adminPage.waitForResponse((r) => r.url().includes(`/corporate/${accountId}/employees`) && r.request().method() === 'POST'),
    adminPage.click('text=Send invite'),
  ]);
  console.log('Invite teammate response:', inviteResponse.status());
  await adminPage.waitForTimeout(400);
  await adminPage.screenshot({ path: `${SHOT_DIR}/03-teammate-invited.png` });

  const teammateRowVisible = await adminPage.locator(`text=${teammateInviteEmail}`).count();
  if (teammateRowVisible === 0) throw new Error('Expected the newly-invited teammate to appear in the roster.');

  console.log('--- 6. The teammate logs in on a separate session and accepts their own invite ---');
  const teammatePage = await browser.newPage({ viewport: { width: 420, height: 900 } });
  const teammatePhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  await loginViaUi(teammatePage, teammatePhone);
  await teammatePage.fill('input[type="email"]', teammateInviteEmail);
  await Promise.all([
    teammatePage.waitForResponse((r) => r.url().includes('/invites/accept')),
    teammatePage.click('text=Accept invite'),
  ]);
  await teammatePage.waitForURL(`**/accounts/${accountId}`, { timeout: 6000 });
  await teammatePage.waitForTimeout(400);
  await teammatePage.screenshot({ path: `${SHOT_DIR}/04-teammate-dashboard.png` });

  console.log('--- 7. SECURITY: the non-admin teammate does NOT see the Invite button (their own UI correctly hides admin-only actions) ---');
  const teammateSeesInvite = await teammatePage.locator('text=+ Invite').count();
  if (teammateSeesInvite > 0) throw new Error('Non-admin teammate should not see the Invite button.');

  console.log('--- 8. SECURITY: the teammate cannot invite via a raw API call either — the backend enforces it independently of the UI ---');
  const teammateToken = await teammatePage.evaluate(() => localStorage.getItem('access_token'));
  const bypassAttempt = await fetch(`${API}/v1/corporate/${accountId}/employees`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${teammateToken}` },
    body: JSON.stringify({ email: 'sneaky@e2efreight.test', role: 'employee' }),
  });
  if (bypassAttempt.status !== 403) {
    throw new Error(`Expected the backend to independently reject a non-admin invite attempt, got ${bypassAttempt.status}`);
  }
  console.log('Confirmed: backend rejects it with', bypassAttempt.status, 'even bypassing the UI entirely.');

  console.log('--- 8a. The admin edits the teammate\'s per-user monthly cap through the real UI ---');
  await adminPage.reload();
  await adminPage.waitForLoadState('networkidle');
  await adminPage.waitForTimeout(400);
  const teammateEditCapButton = adminPage.locator(`[data-employee-email="${teammateInviteEmail}"] button:has-text("Edit cap")`);
  await teammateEditCapButton.click();
  await adminPage.waitForTimeout(200);
  const capInputField = adminPage.locator(`[data-employee-email="${teammateInviteEmail}"] input`);
  await capInputField.fill('750');
  const [capSaveResponse] = await Promise.all([
    adminPage.waitForResponse((r) => r.url().includes('/cap') && r.request().method() === 'PATCH'),
    adminPage.locator(`[data-employee-email="${teammateInviteEmail}"] button:has-text("Save")`).click(),
  ]);
  console.log('Save cap response:', capSaveResponse.status());
  await adminPage.waitForTimeout(400);
  await adminPage.screenshot({ path: `${SHOT_DIR}/03b-cap-edited.png` });

  const capDisplayVisible = await adminPage.locator('text=cap ₹750/mo').count();
  if (capDisplayVisible === 0) throw new Error('Expected the updated cap to display on the roster.');

  console.log('--- 8b. Verify server-side: the cap is genuinely persisted ---');
  const capCheckRow = await pgClient.query('SELECT id, per_user_monthly_cap FROM corporate_employees WHERE email = $1', [
    teammateInviteEmail,
  ]);
  if (parseFloat(capCheckRow.rows[0].per_user_monthly_cap) !== 750) {
    throw new Error(`Expected cap of 750 server-side, got ${capCheckRow.rows[0].per_user_monthly_cap}`);
  }
  console.log('Confirmed: cap genuinely persisted server-side.');

  console.log('--- 8c. SECURITY: an over-limit cap is rejected even via a raw API call ---');
  const overCapAttempt = await fetch(`${API}/v1/corporate/${accountId}/employees/${capCheckRow.rows[0].id}/cap`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${adminToken}` },
    body: JSON.stringify({ per_user_monthly_cap: 999999 }),
  });
  if (overCapAttempt.status !== 400) {
    throw new Error(`Expected an over-limit cap to be rejected, got ${overCapAttempt.status}`);
  }
  console.log('Confirmed: over-limit cap correctly rejected with', overCapAttempt.status);

  console.log('--- 9. The admin removes the teammate (specifically, not themselves) through the real UI ---');
  await adminPage.reload();
  await adminPage.waitForTimeout(400);
  const teammateRow = adminPage.locator(`[data-employee-email="${teammateInviteEmail}"]`);
  const [removeResponse] = await Promise.all([
    adminPage.waitForResponse((r) => r.url().includes(`/employees/`) && r.request().method() === 'DELETE'),
    teammateRow.getByText('Remove', { exact: true }).click(),
  ]);
  console.log('Remove response:', removeResponse.status(), await removeResponse.json().catch(() => '<non-json>'));
  await adminPage.waitForTimeout(400);
  await adminPage.screenshot({ path: `${SHOT_DIR}/05-teammate-removed.png` });

  console.log('--- 10. Verify server-side: the teammate is genuinely removed, and can no longer view the account ---');
  const removedCheck = await pgClient.query(`SELECT status FROM corporate_employees WHERE email = $1`, [teammateInviteEmail]);
  if (removedCheck.rows[0].status !== 'removed') throw new Error('Teammate was not actually removed server-side.');

  const postRemovalAccess = await fetch(`${API}/v1/corporate/${accountId}`, {
    headers: { Authorization: `Bearer ${teammateToken}` },
  });
  if (postRemovalAccess.status !== 403) {
    throw new Error(`Expected a removed employee to lose access, got ${postRemovalAccess.status}`);
  }
  console.log('Confirmed: removed employee genuinely loses access, not just hidden in the UI.');

  await pgClient.end();
  await browser.close();
  console.log('--- ALL STEPS PASSED. Screenshots in', SHOT_DIR, '---');
}

main().catch((err) => {
  console.error('SCRIPT FAILED:', err);
  process.exit(1);
});
