import { useEffect } from 'react';
import { useAuth, getDeviceId } from '../context/AuthContext';
import { registerDeviceToken } from '../api/notifications';

async function registerSyntheticToken(platform: 'web' | 'ios' | 'android'): Promise<void> {
  const token = platform === 'web' ? `web_${getDeviceId()}` : `${platform}_${getDeviceId()}`;
  await registerDeviceToken(platform, token);
}

/** Registers fleet owner device for push notifications (driver events, reassignments). */
export function usePushRegistration() {
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    if (!isAuthenticated) return;
    const platform: 'web' | 'ios' | 'android' = 'web';
    void registerSyntheticToken(platform).catch(() => undefined);
  }, [isAuthenticated]);
}
