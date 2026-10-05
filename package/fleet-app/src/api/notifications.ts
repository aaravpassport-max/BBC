import { api } from './client';

export function registerDeviceToken(platform: string, token: string) {
  return api.post<{ registered: boolean }>('/v1/notifications/device-tokens', { platform, token });
}
