import request from 'supertest';
import { Express } from 'express';

/** Generates a random 10-digit Indian mobile number for test isolation —
 * using a random number per test run/case avoids colliding with the
 * per-number rate limits (5/hour) that are correctly enforced by
 * auth.service and would otherwise make tests flaky against each other. */
export function randomPhone(): string {
  return '9' + Math.floor(100000000 + Math.random() * 899999999).toString();
}

/**
 * Full OTP login flow against a running app instance, returning a ready-to-use
 * access token and the created user_id. Used at the top of nearly every
 * integration test since almost everything requires authentication.
 *
 * Captures the OTP code from auth.service's dev-only console.log (the
 * reference implementation logs instead of sending real SMS) — a deliberate
 * testability seam, not something a production build would rely on.
 */
export async function loginAsNewUser(
  app: Express,
  phone: string = randomPhone(),
  deviceId?: string
): Promise<{ accessToken: string; userId: string; phone: string; isNewUser: boolean }> {
  const effectiveDeviceId = deviceId || `test-device-${crypto.randomUUID()}`;
  const logSpy = jest.spyOn(console, 'log');

  const otpRes = await request(app)
    .post('/v1/auth/otp/request')
    .send({ phone, country_code: '+91', device_id: effectiveDeviceId, app_version: '1.0.0' });

  if (otpRes.status !== 202) {
    logSpy.mockRestore();
    throw new Error(`OTP request failed: ${JSON.stringify(otpRes.body)}`);
  }

  const call = logSpy.mock.calls.find((c) => typeof c[0] === 'string' && c[0].includes('[DEV ONLY] OTP for'));
  logSpy.mockRestore();
  if (!call) throw new Error('OTP code was not logged.');
  const match = (call[0] as string).match(/(\d{6})$/);
  if (!match) throw new Error(`Could not parse OTP from: ${call[0]}`);
  const code = match[1];

  const verifyRes = await request(app)
    .post('/v1/auth/otp/verify')
    .send({ otp_id: otpRes.body.otp_id, code, device_id: effectiveDeviceId });

  if (verifyRes.status !== 200) {
    throw new Error(`OTP verify failed: ${JSON.stringify(verifyRes.body)}`);
  }

  return {
    accessToken: verifyRes.body.access_token,
    userId: verifyRes.body.user_id,
    phone,
    isNewUser: verifyRes.body.is_new_user,
  };
}
