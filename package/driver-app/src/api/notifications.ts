import { api } from './client';
import { isDemoSession } from './demoAuth';
import { localRegisterDeviceToken } from './demoDriver';

export function registerDeviceToken(platform: 'android' | 'ios' | 'web', token: string) {
  if (isDemoSession()) return localRegisterDeviceToken();
  return api.post<{ registered: boolean }>('/v1/notifications/device-tokens', { platform, token });
}
