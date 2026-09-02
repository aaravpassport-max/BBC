import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SHOT_DIR = path.resolve(__dirname, 'e2e-screenshots');
fs.mkdirSync(SHOT_DIR, { recursive: true });

const API = process.env.E2E_API_URL || 'http://localhost:3000';
const APP_URL = process.env.E2E_APP_URL || 'http://localhost:5177';
const BACKEND_LOG_PATH = process.env.E2E_BACKEND_LOG || path.resolve(__dirname, '../backend/server.log');
const DB_URL = process.env.E2E_DATABASE_URL || 'postgres://app_user:dev_local_only@localhost:5432/logistics_superapp';

function readLatestOtp(phone: string): string {
  const log = fs.readFileSync(BACKEND_LOG_PATH, 'utf-8');
  const matches = [...log.matchAll(new RegExp(`OTP for \\+91${phone}: (\\d{6})`, 'g'))];
  if (matches.length === 0) throw new Error(`No OTP found in backend log for ${phone}`);
  return matches[matches.length - 1][1];
}

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 860 } });
  page.on('pageerror', (err) => console.log('BROWSER PAGE ERROR:', err.message));
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log('BROWSER CONSOLE ERROR:', msg.text());
  });

  const adminPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();

  console.log('--- 1. Admin logs in via the real UI ---');
  await page.goto(`${APP_URL}/login`);
  await page.waitForSelector('input[type="tel"]');
  await page.screenshot({ path: `${SHOT_DIR}/01-login.png` });
  await page.fill('input[type="tel"]', adminPhone);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/verify');

  const code = readLatestOtp(adminPhone);
  for (let i = 0; i < 6; i++) {
    await page.locator('input[aria-label^="Digit"]').nth(i).fill(code[i]);
  }
  await page.waitForURL('**/rate-cards', { timeout: 8000 });

  console.log('--- 2. Before being granted any role: Access Pending ---');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/02-access-pending.png` });
  const accessPendingVisible = await page.locator('text=Access pending').count();
  if (accessPendingVisible === 0) {
    throw new Error('Expected "Access pending" to be shown before any role is granted.');
  }

  console.log('--- 3. Grant ops_admin via direct DB (mirrors how a real first admin is bootstrapped) ---');
  const { Client } = await import('pg');
  const pgClient = new Client({ connectionString: DB_URL });
  await pgClient.connect();
  const userRow = await pgClient.query(`SELECT id FROM users WHERE phone = $1`, [adminPhone]);
  const adminUserId = userRow.rows[0].id;
  const roleRow = await pgClient.query(`SELECT id FROM roles WHERE name = 'ops_admin'`);
  await pgClient.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    adminUserId,
    roleRow.rows[0].id,
  ]);

  console.log('--- 4. Reload: Rate Cards page now loads for real ---');
  await page.reload();
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/03-rate-cards-empty-or-listed.png` });

  console.log('--- 5. Create a new rate card via the real UI ---');
  await page.click('text=New rate card');
  await page.waitForTimeout(300);
  await page.getByLabel('Base fare (₹)').fill('65');
  await page.getByLabel('Per-km rate (₹)').fill('13');
  await page.getByLabel('Minimum fare (₹)').fill('85');
  await page.getByLabel('Platform fee (₹)').fill('12');
  await page.waitForLoadState('networkidle');
  const [createResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/pricing/rate-cards') && r.request().method() === 'POST'),
    page.click('text=Save as draft'),
  ]);
  console.log('Create response:', createResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/04-rate-card-created.png` });

  const draftRowVisible = await page.locator('text=draft').count();
  if (draftRowVisible === 0) throw new Error('Expected a draft rate card to appear in the table after creation.');

  console.log('--- 6. Publish it via the real UI ---');
  await page.waitForLoadState('networkidle');
  const publishButton = page.getByRole('button', { name: 'Publish', exact: true });
  await publishButton.waitFor({ state: 'visible' });
  const [publishResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/publish') && r.request().method() === 'POST'),
    publishButton.click(),
  ]);
  console.log('Publish response:', publishResponse.status(), await publishResponse.json().catch(() => '<non-json>'));
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/05-rate-card-published.png` });
  const publishedVisible = await page.locator('text=published').count();
  if (publishedVisible === 0) throw new Error('Expected the rate card to show status=published after publishing.');

  console.log('--- 7. Verify server-side: the card is genuinely published in the database ---');
  const dbCheck = await pgClient.query(
    `SELECT status, version FROM rate_cards WHERE base_fare = 65 AND per_km_rate = 13 ORDER BY created_at DESC LIMIT 1`
  );
  console.log('DB state:', dbCheck.rows[0]);
  if (dbCheck.rows[0].status !== 'published') throw new Error('Rate card was not actually published server-side.');

  console.log('--- 8. Create a driver via raw API, then suspend them through the real Drivers UI ---');
  const driverPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  const driverOtpReq = await fetch(`${API}/v1/auth/otp/request`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone: driverPhone, country_code: '+91', device_id: crypto.randomUUID(), app_version: '1.0.0' }),
  }).then((r) => r.json());
  const driverCode = readLatestOtp(driverPhone);
  const driverVerify = await fetch(`${API}/v1/auth/otp/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ otp_id: driverOtpReq.otp_id, code: driverCode, device_id: crypto.randomUUID() }),
  }).then((r) => r.json());
  // The Drivers admin list JOINs through driver_profiles, which only gets
  // created on KYC registration — a plain OTP login alone (account_type
  // defaults to 'customer') is not enough to appear there. Found by this
  // exact script failing with an empty search result on the first attempt.
  await fetch(`${API}/v1/driver/kyc/register`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${driverVerify.access_token}` },
  });

  await page.click('text=Drivers');
  await page.waitForURL('**/drivers');
  await page.waitForLoadState('networkidle');
  await page.fill('input[placeholder="Search by phone"]', driverPhone);
  const [searchResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/admin/v1/drivers?search=')),
    page.click('text=Search'),
  ]);
  console.log('Search response:', searchResponse.status());
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/06-driver-found.png` });

  const driverRowVisible = await page.locator(`text=${driverPhone}`).count();
  if (driverRowVisible === 0) throw new Error('Expected the newly-created driver to appear in the search results.');

  await page.click('text=Suspend');
  await page.waitForTimeout(300);
  await page.fill('input[placeholder="Note (required for OTHER)"]', 'E2E test suspension.');
  const [suspendResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/suspend') && r.request().method() === 'POST'),
    page.click('text=Confirm suspend'),
  ]);
  console.log('Suspend response:', suspendResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/07-driver-suspended.png` });

  const suspendedVisible = await page.locator('text=/Suspended/').count();
  if (suspendedVisible === 0) throw new Error('Expected the driver to show as Suspended after suspending.');

  console.log('--- 9. Verify server-side: the driver is genuinely suspended ---');
  const suspendCheck = await pgClient.query(
    `SELECT suspended_at, suspension_reason FROM driver_profiles dp JOIN users u ON u.id = dp.user_id WHERE u.phone = $1`,
    [driverPhone]
  );
  console.log('DB state:', suspendCheck.rows[0]);
  if (!suspendCheck.rows[0].suspended_at) throw new Error('Driver was not actually suspended server-side.');

  console.log('--- 10. Create a fraud flag via raw SQL, resolve it through the real Fraud Queue UI ---');
  await pgClient.query(
    `INSERT INTO fraud_flags (subject_type, subject_id, signal_types, evidence, severity)
     VALUES ('driver', $1, ARRAY['e2e_test_signal'], '{"note":"e2e"}', 'high')`,
    [adminUserId]
  );
  await page.click('text=Fraud Queue');
  await page.waitForURL('**/fraud-queue');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/08-fraud-queue.png` });

  const flagVisible = await page.locator('text=e2e_test_signal').count();
  if (flagVisible === 0) throw new Error('Expected the newly-created fraud flag to appear in the queue.');

  await page.click('text=Review');
  await page.waitForTimeout(200);
  await page.fill('input[placeholder="Resolution note (required)"]', 'Reviewed in e2e test — clearing.');
  const [clearResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/resolve') && r.request().method() === 'POST'),
    page.click('text=Clear'),
  ]);
  console.log('Clear response:', clearResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/09-fraud-flag-cleared.png` });

  const clearCheck = await pgClient.query(`SELECT status FROM fraud_flags WHERE subject_id = $1 ORDER BY created_at DESC LIMIT 1`, [
    adminUserId,
  ]);
  console.log('Fraud flag final status:', clearCheck.rows[0].status);
  if (clearCheck.rows[0].status !== 'cleared') throw new Error('Fraud flag was not actually cleared server-side.');

  console.log('--- 11. Support: create a ticket via raw API, resolve it through the real Support UI ---');
  const ticketCustomerPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  const ticketOtpReq = await fetch(`${API}/v1/auth/otp/request`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone: ticketCustomerPhone, country_code: '+91', device_id: crypto.randomUUID(), app_version: '1.0.0' }),
  }).then((r) => r.json());
  const ticketCode = readLatestOtp(ticketCustomerPhone);
  const ticketCustomer = await fetch(`${API}/v1/auth/otp/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ otp_id: ticketOtpReq.otp_id, code: ticketCode, device_id: crypto.randomUUID() }),
  }).then((r) => r.json());
  const ticket = await fetch(`${API}/v1/support/tickets`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${ticketCustomer.access_token}`,
      'Idempotency-Key': crypto.randomUUID(),
    },
    body: JSON.stringify({ category: 'payment', description: 'My wallet top-up never showed up, please help me.' }),
  }).then((r) => r.json());
  console.log('Created ticket:', ticket.id);

  await page.click('text=Support');
  await page.waitForURL('**/support');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/10-support-queue.png` });

  const ticketRowVisible = await page.locator('text=payment').count();
  if (ticketRowVisible === 0) throw new Error('Expected the newly-created ticket to appear in the queue.');

  await page.click('text=payment');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/11-support-ticket-detail.png` });

  const originalMessageVisible = await page.locator('text=My wallet top-up never showed up').count();
  if (originalMessageVisible === 0) throw new Error("Expected the customer's original message to appear in the thread.");

  await page.fill('input[placeholder="Reply to customer"]', 'We refunded the missing top-up to your wallet.');
  const [replyResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/messages') && r.request().method() === 'POST'),
    page.click('text=Send'),
  ]);
  console.log('Reply response:', replyResponse.status());
  await page.waitForTimeout(400);

  await page.fill('input[placeholder="Resolution note (required)"]', 'Refunded manually, confirmed with customer.');
  const [closeResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/close') && r.request().method() === 'POST'),
    page.click('text=Close ticket'),
  ]);
  console.log('Close response:', closeResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/12-support-ticket-closed.png` });

  console.log('--- 12. Verify server-side: the ticket is genuinely closed with the resolution note ---');
  const ticketCheck = await pgClient.query(`SELECT status, resolution_note FROM support_tickets WHERE id = $1`, [ticket.id]);
  console.log('DB state:', ticketCheck.rows[0]);
  if (ticketCheck.rows[0].status !== 'closed') throw new Error('Ticket was not actually closed server-side.');
  if (!ticketCheck.rows[0].resolution_note.includes('Refunded manually')) {
    throw new Error('Resolution note was not actually persisted server-side.');
  }

  console.log('--- 13. Analytics: the dashboard loads and reflects real numbers ---');
  await page.click('text=Analytics');
  await page.waitForURL('**/analytics');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/13-analytics.png` });

  const revenueCardVisible = await page.locator('text=Gross revenue').count();
  const funnelVisible = await page.locator('text=Booking funnel').count();
  const cancellationsVisible = await page.locator('text=/Cancellations ·/').count();
  const utilizationVisible = await page.locator('text=Driver utilization').count();
  if (revenueCardVisible === 0 || funnelVisible === 0 || cancellationsVisible === 0 || utilizationVisible === 0) {
    throw new Error('Expected the Analytics page to render revenue, funnel, cancellations, and driver utilization.');
  }
  console.log('Analytics page rendered all four sections: revenue, funnel, cancellations, driver utilization.');

  console.log('--- 14. RBAC: create a role, edit its permissions, assign it to a user, all through the real UI ---');
  await page.click('text=Roles & Access');
  await page.waitForURL('**/rbac');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/14-rbac-roles.png` });

  const newRoleName = `e2e_test_role_${Date.now()}`;
  await page.click('text=New role');
  await page.waitForTimeout(200);
  await page.fill('input[placeholder="Role name (e.g. finance_reviewer)"]', newRoleName);
  const [createRoleResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/rbac/roles') && r.request().method() === 'POST'),
    page.click('text=Create'),
  ]);
  console.log('Create role response:', createRoleResponse.status());
  await page.waitForTimeout(500);

  const roleButtonVisible = await page.locator(`text=${newRoleName}`).count();
  if (roleButtonVisible === 0) throw new Error('Expected the newly-created role to appear in the roles list.');

  await page.click(`text=${newRoleName}`);
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/15-rbac-role-permissions.png` });

  // Check exactly one permission checkbox (analytics.view, if present) and save.
  const analyticsCheckbox = page.locator('label:has-text("analytics.view") input[type="checkbox"]');
  if ((await analyticsCheckbox.count()) > 0) {
    await analyticsCheckbox.check();
    const [savePermsResponse] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/permissions') && r.request().method() === 'PUT'),
      page.click('text=Save permissions'),
    ]);
    console.log('Save permissions response:', savePermsResponse.status());
  }

  console.log('--- 15. RBAC: assign the new role to the driver we created earlier, verify server-side ---');
  await page.fill('input[placeholder="Search by phone"]', driverPhone);
  const [findResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/rbac/users/lookup')),
    page.click('text=Find'),
  ]);
  console.log('Find user response:', findResponse.status());
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/16-rbac-user-found.png` });

  await page.selectOption('select', { label: newRoleName });
  const [assignResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/user-roles') && r.request().method() === 'POST'),
    page.click('text=Grant'),
  ]);
  console.log('Assign role response:', assignResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/17-rbac-role-assigned.png` });

  const roleChipVisible = await page.locator(`text=${newRoleName}`).count();
  if (roleChipVisible < 2) throw new Error('Expected the assigned role to appear as a chip on the found user.');

  const driverUserRow = await pgClient.query(
    `SELECT ur.role_id FROM user_roles ur JOIN users u ON u.id = ur.user_id
     JOIN roles r ON r.id = ur.role_id WHERE u.phone = $1 AND r.name = $2`,
    [driverPhone, newRoleName]
  );
  if (driverUserRow.rowCount === 0) throw new Error('Role assignment was not actually persisted server-side.');
  console.log('Confirmed server-side: role genuinely assigned.');

  console.log('--- 16. Marketing: create and publish a banner through the real UI ---');
  await page.click('text=Marketing');
  await page.waitForURL('**/marketing');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/18-marketing-banners.png` });

  await page.click('text=New banner');
  await page.waitForTimeout(200);
  await page.getByLabel('Headline (max 60 chars)').fill('E2E Test Promo');
  await page.getByLabel('Image URL').fill('https://example.com/banner.jpg');
  await page.getByLabel('CTA deep link').fill('wallet');
  const [createBannerResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/cms/banners') && r.request().method() === 'POST'),
    page.click('text=Save as draft'),
  ]);
  console.log('Create banner response:', createBannerResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/19-banner-created.png` });

  const bannerRowVisible = await page.locator('text=E2E Test Promo').count();
  if (bannerRowVisible === 0) throw new Error('Expected the newly-created banner to appear in the table.');

  const publishBannerButton = page.getByRole('button', { name: 'Publish', exact: true });
  await publishBannerButton.waitFor({ state: 'visible' });
  const [publishBannerResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/cms/banners') && r.url().includes('/publish')),
    publishBannerButton.click(),
  ]);
  console.log('Publish banner response:', publishBannerResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/20-banner-published.png` });

  console.log('--- 17. Verify server-side: the banner is genuinely live/scheduled, not still draft ---');
  const bannerCheck = await pgClient.query(`SELECT status FROM banners WHERE headline = 'E2E Test Promo'`);
  console.log('DB state:', bannerCheck.rows[0]);
  if (bannerCheck.rows[0].status === 'draft') throw new Error('Banner was not actually published server-side.');

  await pgClient.end();
  await browser.close();
  console.log('--- ALL STEPS PASSED. Screenshots in', SHOT_DIR, '---');
}

main().catch((err) => {
  console.error('SCRIPT FAILED:', err);
  process.exit(1);
});
