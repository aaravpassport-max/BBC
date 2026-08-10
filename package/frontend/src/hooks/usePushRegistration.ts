import { useEffect } from 'react';
import { Capacitor } from '@capacitor/core';
import { useAuth, getDeviceId } from '../context/AuthContext';
import { registerDeviceToken } from '../api/features';

/**
 * Registers a device token with the backend for notification routing.
 * Uses local notifications on-device (see lib/notify.ts) — native FCM push
 * is intentionally not wired here because it requires google-services.json
 * and crashes the Android app without it.
 */
export function usePushRegistration() {
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    if (!isAuthenticated) return;

    const platform = Capacitor.getPlatform() === 'ios' ? 'ios' : Capacitor.getPlatform() === 'android' ? 'android' : 'web';
    const token = platform === 'web' ? `web_${getDeviceId()}` : `${platform}_${getDeviceId()}`;
    void registerDeviceToken(platform, token).catch(() => undefined);
  }, [isAuthenticated]);
}
