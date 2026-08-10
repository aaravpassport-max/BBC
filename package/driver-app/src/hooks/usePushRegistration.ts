import { useEffect } from 'react';
import { Capacitor } from '@capacitor/core';
import { useAuth, getDeviceId } from '../context/AuthContext';
import { registerDeviceToken } from '../api/notifications';

/** Device token registration — local notifications only; no FCM native push. */
export function usePushRegistration() {
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    if (!isAuthenticated) return;

    const platform = Capacitor.getPlatform() === 'ios' ? 'ios' : Capacitor.getPlatform() === 'android' ? 'android' : 'web';
    const token = platform === 'web' ? `web_${getDeviceId()}` : `${platform}_${getDeviceId()}`;
    void registerDeviceToken(platform, token).catch(() => undefined);
  }, [isAuthenticated]);
}
