import { isTestOtpEnabled } from '../modules/auth/otp-test';

const INSECURE_JWT_MARKERS = ['change_this', 'change_this_too', 'dev_local_only', 'example', 'placeholder'];

function looksInsecureSecret(value: string | undefined): boolean {
  if (!value || value.length < 32) return true;
  const lower = value.toLowerCase();
  return INSECURE_JWT_MARKERS.some((marker) => lower.includes(marker));
}

/**
 * Fails fast on boot when production is misconfigured — prevents shipping with
 * demo OTP, open CORS, or placeholder JWT secrets.
 */
export function assertProductionConfig(): void {
  if (process.env.NODE_ENV !== 'production') return;

  const fatal: string[] = [];

  if (isTestOtpEnabled()) {
    fatal.push('ALLOW_TEST_OTP must be false (or unset) in production.');
  }
  if (looksInsecureSecret(process.env.JWT_ACCESS_SECRET)) {
    fatal.push('JWT_ACCESS_SECRET must be a strong secret (32+ chars, not a placeholder).');
  }
  if (looksInsecureSecret(process.env.JWT_REFRESH_SECRET)) {
    fatal.push('JWT_REFRESH_SECRET must be a strong secret (32+ chars, not a placeholder).');
  }
  if (!process.env.CORS_ORIGIN?.trim()) {
    fatal.push('CORS_ORIGIN must list your production frontend origins (comma-separated).');
  }
  if (!process.env.DATABASE_URL?.trim()) {
    fatal.push('DATABASE_URL is required.');
  }

  const warnings: string[] = [];
  if (!process.env.MSG91_AUTH_KEY) {
    warnings.push('MSG91_AUTH_KEY unset — OTP SMS will not be delivered.');
  }
  if (!process.env.RAZORPAY_KEY_ID) {
    warnings.push('RAZORPAY_KEY_ID unset — payments run in simulated mode.');
  }
  if (!process.env.FCM_PROJECT_ID) {
    warnings.push('FCM_PROJECT_ID unset — push notifications log to console only.');
  }

  if (fatal.length > 0) {
    console.error('FATAL: Production configuration errors:\n' + fatal.map((m) => `  - ${m}`).join('\n'));
    process.exit(1);
  }

  if (warnings.length > 0) {
    console.warn('Production configuration warnings:\n' + warnings.map((m) => `  - ${m}`).join('\n'));
  }
}
