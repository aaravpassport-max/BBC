/** Fixed OTP map for local/demo testing on physical devices (no SMS or log tailing). */
const DEFAULT_TEST_PHONES: Record<string, string> = {
  '9000000001': '111111', // Customer app
  '9000000002': '222222', // Driver app (pre-onboarded in seed)
};

/** Matches backend/seed/002_test_demo_users.sql */
export const DEMO_USER_IDS: Record<string, string> = {
  '9000000001': 'e9c8b7a6-0001-4000-8000-000000000001',
  '9000000002': 'e9c8b7a6-0002-4000-8000-000000000002',
};

const DEMO_JWT_EXPIRY = '3650d';

export function assertTestOtpConfigSafe(): void {
  if (process.env.NODE_ENV === 'production' && isTestOtpEnabled()) {
    console.error('FATAL: Demo OTP logins must not be enabled when NODE_ENV=production.');
    process.exit(1);
  }
}

/** Enabled by default outside production unless explicitly disabled. */
export function isTestOtpEnabled(): boolean {
  if (process.env.NODE_ENV === 'production') {
    return process.env.ALLOW_TEST_OTP === 'true';
  }
  return process.env.ALLOW_TEST_OTP !== 'false';
}

export function getDemoJwtExpiry(): string {
  return DEMO_JWT_EXPIRY;
}

function parseTestPhoneMap(): Record<string, string> {
  if (process.env.OTP_TEST_PHONES) {
    return JSON.parse(process.env.OTP_TEST_PHONES) as Record<string, string>;
  }
  return DEFAULT_TEST_PHONES;
}

export function getTestOtpForPhone(phone: string): string | null {
  if (!isTestOtpEnabled()) return null;
  return parseTestPhoneMap()[phone] ?? null;
}

export function isTestPhone(phone: string): boolean {
  return getTestOtpForPhone(phone) !== null;
}
