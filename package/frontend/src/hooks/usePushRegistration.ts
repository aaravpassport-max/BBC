import { useEffect } from 'react';
import { Capacitor } from '@capacitor/core';
import { useAuth, getDeviceId } from '../context/AuthContext';
import { registerDeviceToken } from '../api/features';

/**
 * Registers a device token with the backend for notification routing.
 * Uses synthetic tokens by default so the app never crashes without
 * google-services.json. For real FCM: add google-services.json to
 * android/app/, install @capacitor/push-notifications, set
 * VITE_ENABLE_NATIVE_PUSH=true, and configure FCM_* on the backend.
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
