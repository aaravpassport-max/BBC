/** Fixed OTP map for local/demo testing on physical devices (no SMS or log tailing). */
const DEFAULT_TEST_PHONES: Record<string, string> = {
  '9000000001': '111111', // Customer app
  '9000000002': '222222', // Driver app (pre-onboarded in seed)
};

export function assertTestOtpConfigSafe(): void {
  if (process.env.NODE_ENV === 'production' && process.env.ALLOW_TEST_OTP === 'true') {
    console.error('FATAL: ALLOW_TEST_OTP must not be enabled when NODE_ENV=production.');
    process.exit(1);
  }
}

export function isTestOtpEnabled(): boolean {
  return process.env.ALLOW_TEST_OTP === 'true' && process.env.NODE_ENV !== 'production';
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
