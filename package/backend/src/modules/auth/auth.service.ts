import bcrypt from 'bcrypt';
import jwt from 'jsonwebtoken';
import { randomUUID as uuidv4 } from 'crypto';
import { PoolClient } from 'pg';
import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';
import * as smsProvider from './sms.provider';
import { getTestOtpForPhone, getDemoJwtExpiry, isTestOtpEnabled } from './otp-test';

const OTP_EXPIRY_SECONDS = parseInt(process.env.OTP_EXPIRY_SECONDS || '300', 10);
const OTP_MAX_ATTEMPTS = parseInt(process.env.OTP_MAX_ATTEMPTS || '5', 10);
const OTP_RESEND_COOLDOWN_SECONDS = parseInt(process.env.OTP_RESEND_COOLDOWN_SECONDS || '30', 10);
const OTP_LOCKOUT_MINUTES = 15;

// Rate limits per PRD 2.2.1: max 5 requests/number/hour, max 20/device/day.
const MAX_REQUESTS_PER_NUMBER_PER_HOUR = 5;
const MAX_REQUESTS_PER_DEVICE_PER_DAY = 20;

function generateOtpCode(): string {
  return Math.floor(100000 + Math.random() * 900000).toString();
}

export async function requestOtp(params: {
  phone: string;
  countryCode: string;
  deviceId: string;
}): Promise<{ otpId: string; expiresInSeconds: number; resendAfterSeconds: number }> {
  const { phone, countryCode, deviceId } = params;
  const testOtp = getTestOtpForPhone(phone);

  // --- Rate limiting (PRD 2.2.1 edge case) ---
  if (!testOtp) {
  const perNumberCount = await pool.query(
    `SELECT count(*) FROM otp_requests
     WHERE phone = $1 AND country_code = $2 AND created_at > now() - interval '1 hour'`,
    [phone, countryCode]
  );
  if (parseInt(perNumberCount.rows[0].count, 10) >= MAX_REQUESTS_PER_NUMBER_PER_HOUR) {
    throw Errors.otpRateLimited(3600);
  }

  const perDeviceCount = await pool.query(
    `SELECT count(*) FROM otp_requests
     WHERE device_id = $1 AND created_at > now() - interval '1 day'`,
    [deviceId]
  );
  if (parseInt(perDeviceCount.rows[0].count, 10) >= MAX_REQUESTS_PER_DEVICE_PER_DAY) {
    throw Errors.otpRateLimited(86400);
  }

  // Resend cooldown: if the most recent request for this number was too recent, block.
  const lastRequest = await pool.query(
    `SELECT created_at FROM otp_requests
     WHERE phone = $1 AND country_code = $2
     ORDER BY created_at DESC LIMIT 1`,
    [phone, countryCode]
  );
  if (lastRequest.rowCount && lastRequest.rowCount > 0) {
    const secondsSince = (Date.now() - new Date(lastRequest.rows[0].created_at).getTime()) / 1000;
    if (secondsSince < OTP_RESEND_COOLDOWN_SECONDS) {
      throw Errors.otpRateLimited(Math.ceil(OTP_RESEND_COOLDOWN_SECONDS - secondsSince));
    }
  }
  }

  const code = testOtp ?? generateOtpCode();
  const codeHash = await bcrypt.hash(code, 10);
  const otpId = uuidv4();
  const expiresAt = testOtp
    ? new Date('2099-12-31T00:00:00Z')
    : new Date(Date.now() + OTP_EXPIRY_SECONDS * 1000);

  await pool.query(
    `INSERT INTO otp_requests (id, phone, country_code, device_id, code_hash, max_attempts, expires_at)
     VALUES ($1, $2, $3, $4, $5, $6, $7)`,
    [otpId, phone, countryCode, deviceId, codeHash, OTP_MAX_ATTEMPTS, expiresAt]
  );

  // In production this hands off to the Notification Service (PRD Section 16) —
  // OTP always goes via SMS regardless of any user notification preference.
  // Real delivery via MSG91 (smsProvider.isConfigured()) the moment real
  // credentials are set — see sms.provider.ts for what that requires.
  // Falls back to logging here (not sending) when unconfigured, exactly
  // like this reference environment, which has neither a real MSG91
  // account nor network access to reach it.
  if (testOtp) {
    // Fixed OTP for demo phones — no SMS, no console log (documented in app UI instead).
  } else if (smsProvider.isConfigured()) {
    try {
      await smsProvider.sendOtpSms({ countryCode, phone, code });
    } catch (err) {
      // A failed SMS delivery must not silently strand the user with an
      // OTP request that "succeeded" but was never actually deliverable —
      // surface it as a real error rather than pretending the code was
      // sent when it wasn't.
      console.error('Failed to send OTP SMS:', err);
      throw Errors.internal();
    }
  } else {
    console.log(`[DEV ONLY] OTP for ${countryCode}${phone}: ${code}`);
  }

  return {
    otpId,
    expiresInSeconds: OTP_EXPIRY_SECONDS,
    resendAfterSeconds: OTP_RESEND_COOLDOWN_SECONDS,
  };
}

export async function verifyOtp(params: {
  otpId: string;
  code: string;
  deviceId: string;
}): Promise<{
  accessToken: string;
  refreshToken: string;
  isNewUser: boolean;
  userId: string;
}> {
  const { otpId, code, deviceId } = params;

  // Discriminated result type so the transaction below can signal an expected
  // business outcome (wrong code, locked, expired) WITHOUT throwing inside the
  // transaction callback — throwing there would roll back the very
  // attempts_used/locked_until UPDATE we're trying to persist (this was a real
  // bug: the lockout counter was silently never committing because the
  // increment and the "reject this attempt" error were in the same rolled-
  // back transaction). Every branch below still runs inside ONE atomic
  // transaction; only the decision of what to throw is deferred until after
  // it has successfully committed.
  type VerifyOutcome =
    | { kind: 'success'; accessToken: string; refreshToken: string; isNewUser: boolean; userId: string }
    | { kind: 'not_found' }
    | { kind: 'locked'; lockedUntil: string }
    | { kind: 'incorrect'; attemptsRemaining: number }
    | { kind: 'expired' };

  const outcome = await withTransaction<VerifyOutcome>(async (client: PoolClient) => {
    const otpResult = await client.query(`SELECT * FROM otp_requests WHERE id = $1 FOR UPDATE`, [otpId]);
    if (otpResult.rowCount === 0) {
      return { kind: 'not_found' };
    }
    const otpRow = otpResult.rows[0];
    const demoOtp = getTestOtpForPhone(otpRow.phone);
    const isDemoOtp = demoOtp !== null && code === demoOtp;

    if (!isDemoOtp) {
    if (otpRow.consumed_at) {
      return { kind: 'expired' }; // single-use — PRD 2.2.2
    }
    if (otpRow.locked_until && new Date(otpRow.locked_until) > new Date()) {
      return { kind: 'locked', lockedUntil: otpRow.locked_until };
    }
    if (new Date(otpRow.expires_at) < new Date()) {
      return { kind: 'expired' };
    }

    const codeMatches = await bcrypt.compare(code, otpRow.code_hash);
    if (!codeMatches) {
      const attemptsUsed = otpRow.attempts_used + 1;
      const attemptsRemaining = otpRow.max_attempts - attemptsUsed;

      if (attemptsRemaining <= 0) {
        const lockedUntil = new Date(Date.now() + OTP_LOCKOUT_MINUTES * 60 * 1000);
        await client.query(`UPDATE otp_requests SET attempts_used = $1, locked_until = $2 WHERE id = $3`, [
          attemptsUsed,
          lockedUntil,
          otpId,
        ]);
        return { kind: 'locked', lockedUntil: lockedUntil.toISOString() };
      }

      await client.query(`UPDATE otp_requests SET attempts_used = $1 WHERE id = $2`, [attemptsUsed, otpId]);
      return { kind: 'incorrect', attemptsRemaining };
    }
    }

    // Demo OTPs are reusable and never consumed; real OTPs are single-use (PRD 2.2.2).
    if (!isDemoOtp) {
    await client.query(`UPDATE otp_requests SET consumed_at = now() WHERE id = $1`, [otpId]);
    }

    const existingUser = await client.query(
      `SELECT id FROM users WHERE phone = $1 AND country_code = $2 AND deleted_at IS NULL`,
      [otpRow.phone, otpRow.country_code]
    );

    let userId: string;
    let isNewUser: boolean;

    if (existingUser.rowCount && existingUser.rowCount > 0) {
      userId = existingUser.rows[0].id;
      isNewUser = false;
    } else {
      const newUser = await client.query(
        `INSERT INTO users (phone, country_code, account_type)
         VALUES ($1, $2, 'customer') RETURNING id`,
        [otpRow.phone, otpRow.country_code]
      );
      userId = newUser.rows[0].id;
      isNewUser = true;

      // Token issuance is atomic with user creation within this same transaction —
      // PRD 2.2.2 acceptance criteria: never a state where OTP is verified but no
      // user record exists.
    }

    const accessToken = jwt.sign(
      { sub: userId, account_type: 'customer' },
      process.env.JWT_ACCESS_SECRET as string,
      {
        expiresIn: (isDemoOtp ? getDemoJwtExpiry() : process.env.JWT_ACCESS_EXPIRY || '15m') as jwt.SignOptions['expiresIn'],
      }
    );
    const refreshTokenRaw = uuidv4() + uuidv4();
    const refreshTokenHash = await bcrypt.hash(refreshTokenRaw, 10);

    await client.query(
      `INSERT INTO refresh_tokens (user_id, device_id, token_hash, expires_at)
       VALUES ($1, $2, $3, now() + interval '30 days')`,
      [userId, deviceId, refreshTokenHash]
    );

    return { kind: 'success', accessToken, refreshToken: refreshTokenRaw, isNewUser, userId };
  });

  switch (outcome.kind) {
    case 'success':
      return {
        accessToken: outcome.accessToken,
        refreshToken: outcome.refreshToken,
        isNewUser: outcome.isNewUser,
        userId: outcome.userId,
      };
    case 'not_found':
    case 'expired':
      throw Errors.otpExpiredOrInvalid();
    case 'locked':
      throw Errors.otpLocked(outcome.lockedUntil);
    case 'incorrect':
      throw Errors.otpIncorrect(outcome.attemptsRemaining);
  }
}

/** One-tap demo login for physical-device testing — no OTP request step required. */
export async function demoLogin(params: {
  phone: string;
  countryCode: string;
  deviceId: string;
}): Promise<{
  accessToken: string;
  refreshToken: string;
  isNewUser: boolean;
  userId: string;
}> {
  if (!isTestOtpEnabled()) {
    throw Errors.forbidden('Demo login is disabled');
  }
  const testOtp = getTestOtpForPhone(params.phone);
  if (!testOtp) {
    throw Errors.forbidden('Not a demo phone number');
  }

  return withTransaction(async (client: PoolClient) => {
    const existingUser = await client.query(
      `SELECT id FROM users WHERE phone = $1 AND country_code = $2 AND deleted_at IS NULL`,
      [params.phone, params.countryCode]
    );

    let userId: string;
    let isNewUser: boolean;

    if (existingUser.rowCount && existingUser.rowCount > 0) {
      userId = existingUser.rows[0].id;
      isNewUser = false;
    } else {
      const newUser = await client.query(
        `INSERT INTO users (phone, country_code, account_type)
         VALUES ($1, $2, 'customer') RETURNING id`,
        [params.phone, params.countryCode]
      );
      userId = newUser.rows[0].id;
      isNewUser = true;
    }

    const accessToken = jwt.sign(
      { sub: userId, account_type: 'customer' },
      process.env.JWT_ACCESS_SECRET as string,
      { expiresIn: getDemoJwtExpiry() as jwt.SignOptions['expiresIn'] }
    );
    const refreshTokenRaw = uuidv4() + uuidv4();
    const refreshTokenHash = await bcrypt.hash(refreshTokenRaw, 10);

    await client.query(
      `INSERT INTO refresh_tokens (user_id, device_id, token_hash, expires_at)
       VALUES ($1, $2, $3, now() + interval '30 days')`,
      [userId, params.deviceId, refreshTokenHash]
    );

    return { accessToken, refreshToken: refreshTokenRaw, isNewUser, userId };
  });
}

/** Exchanges a valid refresh token for a new access token (and rotated refresh token). */
export async function refreshAccessToken(params: {
  refreshToken: string;
  deviceId: string;
}): Promise<{ accessToken: string; refreshToken: string }> {
  const { refreshToken, deviceId } = params;

  const candidates = await pool.query(
    `SELECT id, user_id, token_hash, device_id, expires_at
     FROM refresh_tokens
     WHERE revoked_at IS NULL AND expires_at > now()
     ORDER BY created_at DESC
     LIMIT 200`
  );

  let matched: { id: string; user_id: string } | null = null;
  for (const row of candidates.rows) {
    if (row.device_id !== deviceId) continue;
    const ok = await bcrypt.compare(refreshToken, row.token_hash);
    if (ok) {
      matched = { id: row.id, user_id: row.user_id };
      break;
    }
  }

  if (!matched) {
    throw Errors.forbidden('Invalid or expired refresh token.');
  }

  return withTransaction(async (client: PoolClient) => {
    await client.query(`UPDATE refresh_tokens SET revoked_at = now() WHERE id = $1`, [matched!.id]);

    const user = await client.query(`SELECT account_type FROM users WHERE id = $1`, [matched!.user_id]);
    const accountType = user.rows[0]?.account_type || 'customer';

    const accessToken = jwt.sign(
      { sub: matched!.user_id, account_type: accountType },
      process.env.JWT_ACCESS_SECRET as string,
      { expiresIn: (process.env.JWT_ACCESS_EXPIRY || '15m') as jwt.SignOptions['expiresIn'] }
    );

    const refreshTokenRaw = uuidv4() + uuidv4();
    const refreshTokenHash = await bcrypt.hash(refreshTokenRaw, 10);
    await client.query(
      `INSERT INTO refresh_tokens (user_id, device_id, token_hash, expires_at)
       VALUES ($1, $2, $3, now() + interval '30 days')`,
      [matched!.user_id, deviceId, refreshTokenHash]
    );

    return { accessToken, refreshToken: refreshTokenRaw };
  });
}
