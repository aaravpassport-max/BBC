import { api } from '@/api/client';

export type OtpRequest = { otp_id: string; expires_in_seconds: number };
export type AuthResult = { access_token: string; refresh_token: string; user_id: string };

export function requestOtp(phone: string) {
  return api<OtpRequest>('/auth/otp/request', {
    method: 'POST',
    body: JSON.stringify({ phone, country_code: '+91', device_id: 'wp-web' }),
  });
}

export function verifyOtp(otpId: string, code: string) {
  return api<AuthResult>('/auth/otp/verify', {
    method: 'POST',
    body: JSON.stringify({ otp_id: otpId, code, device_id: 'wp-web' }),
  });
}
