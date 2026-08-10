import { useEffect } from 'react';
import { Capacitor } from '@capacitor/core';
import { useAuth, getDeviceId } from '../context/AuthContext';
import { registerDeviceToken } from '../api/features';

const NATIVE_PUSH_ENABLED = import.meta.env.VITE_ENABLE_NATIVE_PUSH === 'true';

async function registerSyntheticToken(platform: 'web' | 'ios' | 'android'): Promise<void> {
  const token = platform === 'web' ? `web_${getDeviceId()}` : `${platform}_${getDeviceId()}`;
  await registerDeviceToken(platform, token);
}

/**
 * Registers a device token with the backend for notification routing.
 * Uses synthetic tokens by default so the app never crashes without
 * google-services.json. Set VITE_ENABLE_NATIVE_PUSH=true and add
 * google-services.json to android/app/ for real FCM tokens.
 */
export function usePushRegistration() {
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    if (!isAuthenticated) return;

    const platform: 'web' | 'ios' | 'android' =
      Capacitor.getPlatform() === 'ios' ? 'ios' : Capacitor.getPlatform() === 'android' ? 'android' : 'web';

    let cancelled = false;

    async function register() {
      if (NATIVE_PUSH_ENABLED && Capacitor.isNativePlatform()) {
        try {
          const { PushNotifications } = await import('@capacitor/push-notifications');
          const perm = await PushNotifications.requestPermissions();
          if (perm.receive !== 'granted') {
            if (!cancelled) await registerSyntheticToken(platform);
            return;
          }

          const registration = await new Promise<string>((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('FCM registration timeout')), 15000);
            void PushNotifications.addListener('registration', ({ value }) => {
              clearTimeout(timeout);
              resolve(value);
            });
            void PushNotifications.addListener('registrationError', (err) => {
              clearTimeout(timeout);
              reject(err);
            });
            void PushNotifications.register();
          });

          if (!cancelled) await registerDeviceToken(platform, registration);
          return;
        } catch {
          // Fall back to synthetic token when Firebase is not configured.
        }
      }

      if (!cancelled) await registerSyntheticToken(platform);
    }

    void register().catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [isAuthenticated]);
}
