import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SHOT_DIR = path.resolve(__dirname, 'e2e-screenshots');
fs.mkdirSync(SHOT_DIR, { recursive: true });

const API = process.env.E2E_API_URL || 'http://localhost:3000';
const APP_URL = process.env.E2E_APP_URL || 'http://localhost:5175';
const BACKEND_LOG_PATH = process.env.E2E_BACKEND_LOG || path.resolve(__dirname, '../backend/server.log');

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
  if (!otpRes.otp_id) {
    throw new Error(`apiLogin: OTP request failed for ${phone}: ${JSON.stringify(otpRes)}`);
  }

  const code = readLatestOtp(phone);

  const verifyRes = await fetch(`${API}/v1/auth/otp/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ otp_id: otpRes.otp_id, code, device_id: deviceId }),
  }).then((r) => r.json());
  if (!verifyRes.access_token) {
    throw new Error(`apiLogin: OTP verify failed for ${phone}: ${JSON.stringify(verifyRes)}`);
  }

  return { token: verifyRes.access_token, userId: verifyRes.user_id };
}

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 420, height: 860 } });
  page.on('pageerror', (err) => console.log('BROWSER PAGE ERROR:', err.message));
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log('BROWSER CONSOLE ERROR:', msg.text());
  });

  const driverPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();

  console.log('--- 1. Driver logs in via the real UI ---');
  await page.goto(`${APP_URL}/login`);
  await page.waitForSelector('input[type="tel"]');
  await page.screenshot({ path: `${SHOT_DIR}/01-login.png` });
  await page.fill('input[type="tel"]', driverPhone);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/verify');

  const code = readLatestOtp(driverPhone);
  for (let i = 0; i < 6; i++) {
    await page.locator('input[aria-label^="Digit"]').nth(i).fill(code[i]);
  }
  await page.waitForURL('**/kyc', { timeout: 8000 });

  console.log('--- 2. KYC screen (incomplete) ---');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/02-kyc-incomplete.png` });

  console.log('--- 3. Submit all 4 required documents via the real UI ---');
  const docInputs = await page.locator('input[placeholder="Paste document link"]');
  const count = await docInputs.count();
  for (let i = 0; i < count; i++) {
    await docInputs.nth(0).fill(`https://example.com/doc-${i}.jpg`);
    await page.locator('button:has-text("Submit")').nth(0).click();
    await page.waitForTimeout(600);
  }
  await page.screenshot({ path: `${SHOT_DIR}/03-kyc-pending-review.png` });

  console.log('--- 4. Approve the driver via the Admin API (simulating a reviewer) ---');
  const reviewerPhone = '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
  const reviewer = await apiLogin(reviewerPhone);

  const { Client } = await import('pg');
  const pgClient = new Client({ connectionString: 'postgres://app_user:dev_local_only@localhost:5432/logistics_superapp' });
  await pgClient.connect();
  const roleRow = await pgClient.query(`SELECT id FROM roles WHERE name = 'kyc_reviewer'`);
  await pgClient.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    reviewer.userId,
    roleRow.rows[0].id,
  ]);
  const docsResult = await pgClient.query(
    `SELECT kd.id FROM kyc_documents kd JOIN users u ON u.id = kd.subject_id WHERE u.phone = $1`,
    [driverPhone]
  );
  for (const doc of docsResult.rows) {
    await fetch(`${API}/v1/driver/kyc/documents/${doc.id}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${reviewer.token}` },
      body: JSON.stringify({ decision: 'approved' }),
    });
  }
  await pgClient.end();
  console.log(`Approved ${docsResult.rows.length} documents`);

  console.log('--- 5. Driver reloads and sees Approved, goes to Home (which redirects to Training first) ---');
  await page.reload();
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOT_DIR}/04-kyc-approved.png` });
  await page.click('text=Go to Home');
  await page.waitForURL('**/training', { timeout: 5000 });
  await page.screenshot({ path: `${SHOT_DIR}/04b-training-video.png` });

  console.log('--- 5a. Complete the training video through the real UI ---');
  const [videoProgressResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/training/platform_basics/progress')),
    page.click('text=Mark video as watched'),
  ]);
  console.log('Video progress response:', videoProgressResponse.status());
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/04c-training-quiz.png` });

  console.log('--- 5b. Answer the quiz correctly through the real UI and submit ---');
  // Matches training.service.ts's QUIZ correctIndex array exactly: [0,1,1,1,1].
  const correctAnswers = [0, 1, 1, 1, 1];
  for (let qi = 0; qi < correctAnswers.length; qi++) {
    await page.locator(`input[name="q${qi}"]`).nth(correctAnswers[qi]).check();
  }
  const [quizSubmitResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/quiz-submit')),
    page.click('text=Submit quiz'),
  ]);
  console.log('Quiz submit response:', quizSubmitResponse.status(), await quizSubmitResponse.json().catch(() => '<non-json>'));
  await page.waitForURL('**/home', { timeout: 6000 });
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/05-home-offline.png` });

  {
    const { Client } = await import('pg');
    const c = new Client({ connectionString: 'postgres://app_user:dev_local_only@localhost:5432/logistics_superapp' });
    await c.connect();
    // Isolation: take every OTHER driver offline first, so dispatch
    // deterministically assigns the job to OUR driver rather than some
    // leftover online driver from an earlier run of this same script.
    await c.query(
      `UPDATE driver_profiles SET online_status = false
       WHERE user_id != (SELECT id FROM users WHERE phone = $1)`,
      [driverPhone]
    );
    await c.end();
  }

  console.log('--- 5c. Verify server-side: training_status is genuinely passed, the actual dispatch-eligibility column ---');
  {
    const { Client } = await import('pg');
    const c = new Client({ connectionString: 'postgres://app_user:dev_local_only@localhost:5432/logistics_superapp' });
    await c.connect();
    const trainingCheck = await c.query(
      `SELECT training_status FROM driver_profiles WHERE user_id = (SELECT id FROM users WHERE phone = $1)`,
      [driverPhone]
    );
    console.log('training_status:', trainingCheck.rows[0].training_status);
    if (trainingCheck.rows[0].training_status !== 'passed') {
      throw new Error('training_status was not actually set to passed server-side after the real quiz flow.');
    }
    await c.end();
  }

  console.log('--- 5d. Register a vehicle via the real API (PRD 3.2 step 4 - a genuine gap found by an earlier run of this exact script, now fixed in the backend) ---');
  {
    // Reuses the browser's OWN already-authenticated session token (read
    // straight from localStorage, the same key context/AuthContext.tsx
    // writes to) rather than doing a second fresh OTP login for the same
    // phone — an earlier version of this script did the latter and hit the
    // 30s resend cooldown, since it ran only seconds after the browser's
    // own login, causing a silent auth failure this script's own error
    // handling didn't catch (fixed here, not just avoided).
    const driverToken = await page.evaluate(() => localStorage.getItem('access_token'));
    if (!driverToken) throw new Error('Expected the browser session to already have an access_token in localStorage.');

    const vehicleRes = await fetch(`${API}/v1/driver/vehicles`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${driverToken}` },
      body: JSON.stringify({ category: 'mini_truck', plate_number: `KA01E2E${Date.now() % 10000}` }),
    }).then((r) => r.json());
    console.log('Vehicle registered:', vehicleRes);
    if (vehicleRes.error) throw new Error(`Vehicle registration failed: ${JSON.stringify(vehicleRes.error)}`);
  }

  console.log('--- 6. Driver goes online ---');
  // Uses waitForLoadState + an explicit visibility wait + role-based
  // selector rather than a bare text= click immediately after navigation —
  // an earlier version of this script using a naive click right after
  // waitForURL intermittently clicked before the page had fully settled,
  // producing a silent no-op with no error and no network request. Kept
  // this more deliberate sequence since it's what actually made the click
  // reliable, not just what happened to work once.
  await page.waitForLoadState('networkidle');
  const goOnlineButton = page.getByRole('button', { name: 'Go online', exact: true });
  await goOnlineButton.waitFor({ state: 'visible' });
  await goOnlineButton.click();
  await page.waitForTimeout(1200);

  const errorParagraphs = await page.locator('p').allTextContents();
  const failedToGoOnline = errorParagraphs.some((t) => t.includes('Cannot go online') || t.includes('Could not update'));
  if (failedToGoOnline) {
    throw new Error(`Driver failed to go online. Page text: ${JSON.stringify(errorParagraphs)}`);
  }
  await page.screenshot({ path: `${SHOT_DIR}/06-home-online.png` });

  console.log('--- 7. Customer (via raw API) books a trip, dispatch offers it to our driver ---');
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
  const dispatch = await fetch(`${API}/v1/driver/dev/trigger-dispatch/${booking.id}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${customer.token}` },
  }).then((r) => r.json());
  console.log('Dispatch result:', dispatch);

  console.log('--- 8. Driver app polls and shows the job offer ---');
  await page.waitForURL('**/offer/**', { timeout: 6000 });
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${SHOT_DIR}/07-job-offer.png` });

  console.log('--- 9. Driver accepts ---');
  await page.click('text=Accept');
  await page.waitForURL('**/trip/**', { timeout: 6000 });
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/08-trip-awaiting-pickup.png` });

  console.log('--- 10. Get the real pickup OTP from the customer-facing booking detail, enter it as the driver ---');
  const bookingDetail = await fetch(`${API}/v1/bookings/${booking.id}`, {
    headers: { Authorization: `Bearer ${customer.token}` },
  }).then((r) => r.json());
  const pickupOtp: string = bookingDetail.pickup_otp;
  console.log('Real pickup OTP (read from the customer app, entered by the driver):', pickupOtp);

  await page.fill('input[placeholder="0000"]', pickupOtp);
  await page.click('text=Confirm pickup');
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${SHOT_DIR}/09-trip-in-progress.png` });

  console.log('--- 11. Complete the drop stop with its real OTP ---');
  const stopOtp: string = bookingDetail.stops[0].otp_code;
  console.log('Real drop OTP:', stopOtp);
  await page.fill('input[placeholder="0000"]', stopOtp);
  await page.click('text=Confirm drop');
  await page.waitForURL('**/home', { timeout: 6000 });
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOT_DIR}/10-trip-completed-back-home.png` });

  console.log('--- 12. Verify the booking is actually completed server-side ---');
  const finalBooking = await fetch(`${API}/v1/bookings/${booking.id}`, {
    headers: { Authorization: `Bearer ${customer.token}` },
  }).then((r) => r.json());
  console.log('Final booking status:', finalBooking.status);
  if (finalBooking.status !== 'completed') {
    throw new Error(`Expected booking to be completed, got: ${finalBooking.status}`);
  }

  await browser.close();
  console.log('--- ALL STEPS PASSED. Screenshots in', SHOT_DIR, '---');
}

main().catch((err) => {
  console.error('SCRIPT FAILED:', err);
  process.exit(1);
});
