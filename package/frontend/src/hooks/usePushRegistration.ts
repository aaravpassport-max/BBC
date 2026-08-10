import { useEffect } from 'react';
import { Capacitor } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import { useAuth, getDeviceId } from '../context/AuthContext';
import { registerDeviceToken } from '../api/features';

/** Registers this device for push notifications when the user is signed in. */
export function usePushRegistration() {
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    if (!isAuthenticated) return;

    let cancelled = false;

    async function register() {
      if (Capacitor.isNativePlatform()) {
        const perm = await PushNotifications.requestPermissions();
        if (perm.receive !== 'granted') return;

        await PushNotifications.register();
        PushNotifications.addListener('registration', (token) => {
          if (cancelled) return;
          const platform = Capacitor.getPlatform() === 'ios' ? 'ios' : 'android';
          void registerDeviceToken(platform, token.value).catch(() => undefined);
        });
        return;
      }

      const webToken = `web_${getDeviceId()}`;
      await registerDeviceToken('web', webToken);
    }

    void register().catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [isAuthenticated]);
}
