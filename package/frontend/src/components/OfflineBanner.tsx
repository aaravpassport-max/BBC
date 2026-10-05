import { useState, useEffect } from 'react';
import styles from './OfflineBanner.module.css';

/**
 * A real, live connectivity indicator (offline/poor-connectivity
 * hardening) — uses the browser's actual `online`/`offline` events, which
 * fire the moment the OS reports a real network state change, not a
 * polling guess. Shown globally so any screen the user happens to be on
 * gets the same immediate, honest signal, rather than discovering they're
 * offline only after tapping something and watching it fail.
 *
 * Deliberately conservative: `navigator.onLine` can be true even when the
 * device has a connection but the API server specifically is unreachable
 * (a captive portal, a firewalled network) — this banner covers the
 * device-level case; the NetworkError thrown by the API client (see
 * client.ts) still covers the "device is online but the server isn't
 * reachable" case on a per-request basis.
 */
export function OfflineBanner() {
  const [isOnline, setIsOnline] = useState(navigator.onLine);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  if (isOnline) return null;

  return (
    <div className={styles.banner} role="status">
      No internet connection — some actions may not work until you're back online.
    </div>
  );
}
