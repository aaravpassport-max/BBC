import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SHOT_DIR = path.resolve(__dirname, 'e2e-screenshots');
fs.mkdirSync(SHOT_DIR, { recursive: true });

const API = process.env.E2E_API_URL || 'http://localhost:3000';
const APP_URL = process.env.E2E_APP_URL || 'http://localhost:5179';
const BACKEND_LOG_PATH = process.env.E2E_BACKEND_LOG || path.resolve(__dirname, '../backend/server.log');
const DB_URL = process.env.E2E_DATABASE_URL || 'postgres://app_user:dev_local_only@localhost:5432/logistics_superapp';

function readLatestOtp(phone: string): string {
  const log = fs.readFileSync(BACKEND_LOG_PATH, 'utf-8');
  const matches = [...log.matchAll(new RegExp(`OTP for \\+91${phone}: (\\d{6})`, 'g'))];
  if (matches.length === 0) throw new Error(`No OTP found in backend log for ${phone}`);
  return matches[matches.length - 1][1];
}

async function apiLogin(phone: string): Promise<{ token: string; userId: string }> {
  const deviceId = crypto.randomUUID();
  const otpRes = await fetch(`${API}/v1/auth/otp/request`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone, country_code: '+91', device_id: deviceId, app_version: '1.0.0' }),
  }).then((r) => r.json());
  if (!otpRes.otp_id) throw new Error(`apiLogin: OTP request failed for ${phone}: ${JSON.stringify(otpRes)}`);
  const code = readLatestOtp(phone);
  const verifyRes = await fetch(`${API}/v1/auth/otp/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ otp_id: otpRes.otp_id, code, device_id: deviceId }),
  }).then((r) => r.json());
  if (!verifyRes.access_token) throw new Error(`apiLogin: OTP verify failed for ${phone}: ${JSON.stringify(verifyRes)}`);
  return { token: verifyRes.access_token, userId: verifyRes.user_id };
}

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 860 } });
  page.on('pageerror', (err) => console.log('BROWSER PAGE ERROR:', err.message));
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log('BROWSER CONSOLE ERROR:', msg.text());
  });

  const { Client } = await import('pg');
  const pgClient = new Client({ connectionString: DB_URL });
  await pgClient.connect();

  // Idempotent reruns: a prior failed run of this exact script can leave an
  // unresolved SOS event behind, which would make the queue show more than
  // one alert and break this script's simple (deliberately not per-card
  // scoped) button selectors. Cleaned here rather than fought with fragile
  // DOM-scoping logic — a fresh run should always start from a clean queue.
  await pgClient.query(`DELETE FROM sos_events WHERE status != 'resolved'`);

  console.log('--- 1. Set up a real booking via the API, and trigger a real SOS from the customer ---');
  await pgClient.query(`UPDATE driver_profiles SET online_status = false`);
  const customerPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  const customer = await apiLogin(customerPhone);
  const quote = await fetch(`${API}/v1/pricing/quote`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${customer.token}` },
    body: JSON.stringify({
      pickup: { lat: 12.952, lng: 77.602 },
      drops: [{ lat: 12.97, lng: 77.62 }],
      vehicle_category: 'mini_truck',
    }),
  }).then((r) => r.json());
  const booking = await fetch(`${API}/v1/bookings`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${customer.token}`,
      'Idempotency-Key': crypto.randomUUID(),
    },
    body: JSON.stringify({ quote_id: quote.quotes[0].quote_id, payment_method: 'wallet' }),
  }).then((r) => r.json());
  console.log('Created booking:', booking.id);

  const sosEvent = await fetch(`${API}/ops/v1/sos/trigger`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${customer.token}` },
    body: JSON.stringify({ booking_id: booking.id, lat: 12.951, lng: 77.601 }),
  }).then((r) => r.json());
  console.log('SOS triggered:', sosEvent.id);

  console.log('--- 2. Control Room operator logs in via the real UI ---');
  const operatorPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  await page.goto(`${APP_URL}/login`);
  await page.waitForSelector('input[type="tel"]');
  await page.fill('input[type="tel"]', operatorPhone);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/verify');
  const code = readLatestOtp(operatorPhone);
  for (let i = 0; i < 6; i++) {
    await page.locator('input[aria-label^="Digit"]').nth(i).fill(code[i]);
  }
  await page.waitForURL('**/sos', { timeout: 8000 });

  console.log('--- 3. Before being granted the role: Access Pending ---');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/01-access-pending.png` });
  const accessPendingVisible = await page.locator('text=Access pending').count();
  if (accessPendingVisible === 0) throw new Error('Expected "Access pending" before any role is granted.');

  console.log('--- 4. Grant control_room_operator directly in the DB (mirrors real bootstrap) ---');
  const operatorUserRow = await pgClient.query(`SELECT id FROM users WHERE phone = $1`, [operatorPhone]);
  const operatorUserId = operatorUserRow.rows[0].id;
  const roleRow = await pgClient.query(`SELECT id FROM roles WHERE name = 'control_room_operator'`);
  await pgClient.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    operatorUserId,
    roleRow.rows[0].id,
  ]);

  console.log('--- 5. Reload: the real SOS alert appears in the queue ---');
  await page.reload();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/02-sos-queue.png` });

  const alertVisible = await page.locator(`text=${booking.id.slice(0, 8).toUpperCase()}`).count();
  if (alertVisible === 0) throw new Error('Expected the real SOS alert to appear in the queue.');

  console.log('--- 6. Acknowledge it through the real UI ---');
  await page.waitForLoadState('networkidle');
  const ackButton = page.getByRole('button', { name: 'Acknowledge', exact: true });
  await ackButton.waitFor({ state: 'visible' });
  const [ackResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/acknowledge') && r.request().method() === 'POST'),
    ackButton.click(),
  ]);
  console.log('Acknowledge response:', ackResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/03-sos-acknowledged.png` });

  console.log('--- 7. Resolve it through the real UI ---');
  await page.waitForLoadState('networkidle');
  const resolveButton = page.getByRole('button', { name: 'Resolve', exact: true });
  await resolveButton.waitFor({ state: 'visible' });
  await resolveButton.click();
  await page.waitForTimeout(200);
  await page.fill('input[placeholder="Resolution note (required, min 20 characters)"]', 'Confirmed with rider by phone — false alarm.');
  const submitResolveButton = page.getByRole('button', { name: 'Resolve', exact: true });
  await submitResolveButton.waitFor({ state: 'visible' });
  const [resolveResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/resolve') && r.request().method() === 'POST'),
    submitResolveButton.click(),
  ]);
  console.log('Resolve response:', resolveResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/04-sos-resolved.png` });

  console.log('--- 8. Verify server-side: the SOS event is genuinely resolved ---');
  const sosCheck = await pgClient.query(`SELECT status, resolution_note FROM sos_events WHERE id = $1`, [sosEvent.id]);
  console.log('DB state:', sosCheck.rows[0]);
  if (sosCheck.rows[0].status !== 'resolved') throw new Error('SOS event was not actually resolved server-side.');

  console.log('--- 8a. A second, fresh SOS event: verify escalation permissions and the escalate action through the real UI ---');
  const secondSosEvent = await fetch(`${API}/ops/v1/sos/trigger`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${customer.token}` },
    body: JSON.stringify({ booking_id: booking.id, lat: 12.951, lng: 77.601 }),
  }).then((r) => r.json());
  console.log('Second SOS triggered:', secondSosEvent.id);

  await page.reload();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(500);
  const escalateButtonVisible = await page.locator('text=Escalate to Safety Team Lead').count();
  if (escalateButtonVisible === 0) throw new Error('Expected the Escalate button on the new, unescalated alert.');

  console.log('--- 8b. SECURITY: our on-duty operator (sos_respond only, NOT sos_escalate) is rejected when attempting to escalate ---');
  const operatorToken = await page.evaluate(() => localStorage.getItem('access_token'));
  const rejectedEscalate = await fetch(`${API}/ops/v1/sos/${secondSosEvent.id}/escalate`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${operatorToken}` },
  });
  if (rejectedEscalate.status !== 403) {
    throw new Error(`Expected a plain operator's escalate attempt to be rejected with 403, got ${rejectedEscalate.status}`);
  }
  console.log('Confirmed: a plain on-duty operator cannot escalate — correctly rejected with', rejectedEscalate.status);

  console.log('--- 8c. Grant this same account safety_team_lead too, then escalate through the real UI ---');
  const safetyLeadRoleRow = await pgClient.query(`SELECT id FROM roles WHERE name = 'safety_team_lead'`);
  await pgClient.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    operatorUserId,
    safetyLeadRoleRow.rows[0].id,
  ]);
  await page.reload();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);
  const [escalateResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/escalate') && r.request().method() === 'POST'),
    page.click('text=Escalate to Safety Team Lead'),
  ]);
  console.log('Escalate response:', escalateResponse.status());
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/04b-sos-escalated.png` });

  const escalatedLabelVisible = await page.locator('text=Escalated to safety team lead').count();
  if (escalatedLabelVisible === 0) throw new Error('Expected the escalated indicator to appear on the alert after escalating.');

  console.log('--- 8d. Verify server-side: manually escalated, NOT auto-escalated ---');
  const escalateCheck = await pgClient.query(
    `SELECT escalated_by, escalated_at, auto_escalated FROM sos_events WHERE id = $1`,
    [secondSosEvent.id]
  );
  if (!escalateCheck.rows[0].escalated_at || !escalateCheck.rows[0].escalated_by) {
    throw new Error('SOS event was not actually escalated server-side.');
  }
  if (escalateCheck.rows[0].auto_escalated) {
    throw new Error('Expected this to be a MANUAL escalation (auto_escalated=false), but it was flagged as automatic.');
  }
  console.log('Confirmed: manually escalated by', escalateCheck.rows[0].escalated_by);

  console.log('--- 8e. A THIRD SOS event, aged past the 30s threshold, is picked up by the real auto-escalation sweep job ---');
  const thirdSosEvent = await fetch(`${API}/ops/v1/sos/trigger`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${customer.token}` },
    body: JSON.stringify({ booking_id: booking.id, lat: 12.951, lng: 77.601 }),
  }).then((r) => r.json());
  await pgClient.query(`UPDATE sos_events SET created_at = now() - interval '31 seconds' WHERE id = $1`, [
    thirdSosEvent.id,
  ]);
  // Wait for the real running backend's own scheduled sweep (every 3s per
  // jobs/scheduler.ts) to pick it up — not calling the sweep function
  // directly, since the whole point of this check is confirming the
  // ACTUAL server process's background job does this on its own.
  await new Promise((resolve) => setTimeout(resolve, 4000));
  const autoEscalateCheck = await pgClient.query(
    `SELECT auto_escalated, escalated_by FROM sos_events WHERE id = $1`,
    [thirdSosEvent.id]
  );
  if (!autoEscalateCheck.rows[0].auto_escalated) {
    throw new Error('Expected the real backend\'s scheduled sweep job to have auto-escalated this aged alert within 4s.');
  }
  if (autoEscalateCheck.rows[0].escalated_by !== null) {
    throw new Error('Expected auto-escalation to have escalated_by = NULL, distinguishing it from a manual escalation.');
  }
  console.log('Confirmed: the real backend\'s own scheduled job auto-escalated the aged alert with no manual action.');

  console.log('--- 9. Dispatch Monitor: look up the same booking, force-assign an eligible driver ---');
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
  const driverId: string = driverVerify.user_id;
  await pgClient.query(
    `INSERT INTO driver_profiles (user_id, kyc_status, training_status) VALUES ($1, 'approved', 'passed')
     ON CONFLICT (user_id) DO UPDATE SET kyc_status = 'approved', training_status = 'passed'`,
    [driverId]
  );
  await fetch(`${API}/v1/driver/vehicles`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${driverVerify.access_token}` },
    body: JSON.stringify({ category: 'mini_truck', plate_number: `KA01OPS${Date.now() % 10000}` }),
  });

  await page.click('text=Dispatch Monitor');
  await page.waitForURL('**/dispatch');
  await page.waitForLoadState('networkidle');
  await page.fill('input[placeholder="Booking ID"]', booking.id);
  const lookupButton = page.getByRole('button', { name: 'Look up', exact: true });
  await lookupButton.waitFor({ state: 'visible' });
  const [lookupResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/dispatch-log')),
    lookupButton.click(),
  ]);
  console.log('Dispatch log lookup response:', lookupResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/05-dispatch-log.png` });

  await page.fill('input[placeholder="Driver ID"]', driverId);
  const forceAssignButton = page.getByRole('button', { name: 'Force-assign', exact: true });
  await forceAssignButton.waitFor({ state: 'visible' });
  const [forceAssignResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/force-assign') && r.request().method() === 'POST'),
    forceAssignButton.click(),
  ]);
  console.log('Force-assign response:', forceAssignResponse.status());
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/06-force-assigned.png` });

  console.log('--- 10. Verify server-side: the booking is genuinely assigned to this exact driver ---');
  const bookingCheck = await pgClient.query(`SELECT driver_id, status FROM bookings WHERE id = $1`, [booking.id]);
  console.log('DB state:', bookingCheck.rows[0]);
  if (bookingCheck.rows[0].driver_id !== driverId) throw new Error('Booking was not actually force-assigned to the expected driver.');
  if (bookingCheck.rows[0].status !== 'driver_assigned') throw new Error('Booking status did not transition correctly.');

  await pgClient.end();
  await browser.close();
  console.log('--- ALL STEPS PASSED. Screenshots in', SHOT_DIR, '---');
}

main().catch((err) => {
  console.error('SCRIPT FAILED:', err);
  process.exit(1);
});
