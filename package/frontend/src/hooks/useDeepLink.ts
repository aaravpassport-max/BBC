import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { App } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import {
  consumePendingLocationLink,
  parseLocationUrl,
  parsedLinkToPoint,
  storePendingLocationLink,
} from '../lib/deepLink';
import { useAuth } from '../context/AuthContext';

function handleIncomingUrl(
  url: string,
  isAuthenticated: boolean,
  navigate: ReturnType<typeof useNavigate>
): void {
  const parsed = parseLocationUrl(url);
  if (!parsed) return;

  if (!isAuthenticated) {
    storePendingLocationLink(parsed);
    navigate('/login', { replace: true });
    return;
  }

  navigate('/book/from-link', {
    replace: true,
    state: { point: parsedLinkToPoint(parsed) },
  });
}

/** Resume a location link saved while the user was logged out. */
export function resumePendingLocationLink(navigate: ReturnType<typeof useNavigate>): boolean {
  const pending = consumePendingLocationLink();
  if (!pending) return false;
  navigate('/book/from-link', {
    replace: true,
    state: { point: parsedLinkToPoint(pending) },
  });
  return true;
}

export function useDeepLink(): void {
  const navigate = useNavigate();
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    if (!Capacitor.isNativePlatform()) return;

    let cancelled = false;

    void App.getLaunchUrl().then((result) => {
      if (cancelled || !result?.url) return;
      handleIncomingUrl(result.url, isAuthenticated, navigate);
    });

    const sub = App.addListener('appUrlOpen', (event) => {
      handleIncomingUrl(event.url, isAuthenticated, navigate);
    });

    return () => {
      cancelled = true;
      void sub.then((handle) => handle.remove());
    };
  }, [isAuthenticated, navigate]);
}
