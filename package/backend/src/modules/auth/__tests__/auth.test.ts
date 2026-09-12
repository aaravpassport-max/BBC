import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { randomPhone, loginAsNewUser } from '../../../test-utils/helpers';

const app = createApp();

/** A fresh device id per test run, never a fixed literal — otherwise
 * repeated `npx jest` invocations in the same day (routine during active
 * development) exhaust the legitimate 20-requests/device/day cap against
 * themselves, producing OTP_RATE_LIMITED failures that look like a broken
 * app but are actually the rate limiter working exactly as designed against
 * test-hygiene debt. Found the hard way after this file's literal
 * 'device-a'/'lockout-device'/etc. IDs got hammered across dozens of manual
 * suite runs in one dev session. */
function freshDeviceId(label: string): string {
  return `${label}-${crypto.randomUUID()}`;
}

afterAll(async () => {
  await pool.end();
});

describe('Auth: OTP request + verify (PRD 2.2.1-2.2.2)', () => {
  it('issues an OTP, verifies it, and creates a new user atomically', async () => {
    const phone = randomPhone();
    const { accessToken, userId } = await loginAsNewUser(app, phone);

    expect(accessToken).toBeTruthy();
    expect(userId).toBeTruthy();

    const userRow = await pool.query('SELECT phone, account_type FROM users WHERE id = $1', [userId]);
    expect(userRow.rows[0].phone).toBe(phone);
    expect(userRow.rows[0].account_type).toBe('customer');
  });

  it('logging in twice with the same phone returns the SAME user (is_new_user: false on the second)', async () => {
    const phone = randomPhone();
    const first = await loginAsNewUser(app, phone, freshDeviceId('device-a'));
    const second = await loginAsNewUser(app, phone, freshDeviceId('device-b'));

    expect(first.isNewUser).toBe(true);
    expect(second.isNewUser).toBe(false);
    expect(second.userId).toBe(first.userId);

    const userCount = await pool.query('SELECT count(*) FROM users WHERE phone = $1', [phone]);
    expect(parseInt(userCount.rows[0].count, 10)).toBe(1);
  });

  it('rejects an incorrect OTP code and decrements attempts_remaining', async () => {
    const phone = randomPhone();
    const deviceId = freshDeviceId('wrong-code-device');
    const otpRes = await request(app)
      .post('/v1/auth/otp/request')
      .send({ phone, country_code: '+91', device_id: deviceId, app_version: '1.0.0' });

    const verifyRes = await request(app)
      .post('/v1/auth/otp/verify')
      .send({ otp_id: otpRes.body.otp_id, code: '000000', device_id: deviceId });

    expect(verifyRes.status).toBe(401);
    expect(verifyRes.body.error.code).toBe('OTP_INCORRECT');
    expect(verifyRes.body.error.details.attempts_remaining).toBe(4);
  });

  it('locks out after max attempts (PRD 2.2.2 lockout rule)', async () => {
    const phone = randomPhone();
    const deviceId = freshDeviceId('lockout-device');
    const otpRes = await request(app)
      .post('/v1/auth/otp/request')
      .send({ phone, country_code: '+91', device_id: deviceId, app_version: '1.0.0' });

    let lastStatus = 0;
    for (let i = 0; i < 5; i++) {
      const res = await request(app)
        .post('/v1/auth/otp/verify')
        .send({ otp_id: otpRes.body.otp_id, code: '000000', device_id: deviceId });
      lastStatus = res.status;
    }

    expect(lastStatus).toBe(423);

    // Confirm the CORRECT code no longer works either, while locked (same
    // otp_id — lockout is per-OTP-request, not per-attempt-count-in-isolation).
    const anyCodeRes = await request(app)
      .post('/v1/auth/otp/verify')
      .send({ otp_id: otpRes.body.otp_id, code: '123456', device_id: deviceId });
    expect(anyCodeRes.status).toBe(423);
  });

  it('rejects OTP verification with a malformed 6-digit-code violation before ever touching the DB', async () => {
    const res = await request(app)
      .post('/v1/auth/otp/verify')
      .send({ otp_id: '11111111-1111-1111-1111-111111111111', code: 'abcdef', device_id: freshDeviceId('x') });
    expect(res.status).toBe(400);
    expect(res.body.error.code).toBe('VALIDATION_ERROR');
  });

  it('rejects a request with an invalid phone format', async () => {
    const res = await request(app)
      .post('/v1/auth/otp/request')
      .send({ phone: '12345', country_code: '+91', device_id: freshDeviceId('x'), app_version: '1.0.0' });
    expect(res.status).toBe(400);
  });
});
