import {
  DEMO_ACCESS_TOKEN,
  DEMO_OTP,
  DEMO_OTP_ID,
  DEMO_PHONE,
  DEMO_USER_ID,
  SHOW_TEST_CREDENTIALS,
} from '../config/testCredentials';

const DEMO_SESSION_KEY = 'porter_driver_demo_session';

export function enableDemoSession() {
  localStorage.setItem(DEMO_SESSION_KEY, 'true');
}

export function clearDemoSession() {
  localStorage.removeItem(DEMO_SESSION_KEY);
}

export function isDemoSession(): boolean {
  return localStorage.getItem(DEMO_SESSION_KEY) === 'true' || localStorage.getItem('user_id') === DEMO_USER_ID;
}

export interface DemoAuthResult {
  access_token: string;
  refresh_token: string;
  is_new_user: boolean;
  user_id: string;
}

export function isDemoPhone(phone: string): boolean {
  return SHOW_TEST_CREDENTIALS && phone === DEMO_PHONE;
}

export function isDemoOtp(code: string): boolean {
  return code === DEMO_OTP;
}

export function isDemoOtpRequest(otpId: string): boolean {
  return otpId === DEMO_OTP_ID;
}

export function localDemoAuth(): DemoAuthResult {
  enableDemoSession();
  return {
    access_token: DEMO_ACCESS_TOKEN,
    refresh_token: 'demo-refresh-token',
    is_new_user: false,
    user_id: DEMO_USER_ID,
  };
}

export function localRequestOtp(phone: string) {
  if (!isDemoPhone(phone)) {
    throw new Error('Not a demo phone number');
  }
  return {
    otp_id: DEMO_OTP_ID,
    expires_in_seconds: 999999,
    resend_after_seconds: 0,
  };
}

export function localVerifyOtp(phone: string, code: string): DemoAuthResult {
  if (!isDemoPhone(phone) || !isDemoOtp(code)) {
    throw new Error('Invalid demo credentials');
  }
  return localDemoAuth();
}
