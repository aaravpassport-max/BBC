import { useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { refreshSession } from '../api/client';

const REFRESH_INTERVAL_MS = 10 * 60 * 1000; // 10 min — JWT default is 15m

/** Proactively refreshes the session before the access token expires. */
export function useSessionKeepAlive() {
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    if (!isAuthenticated) return;

    void refreshSession().catch(() => undefined);
    const id = setInterval(() => {
      void refreshSession().catch(() => undefined);
    }, REFRESH_INTERVAL_MS);

    return () => clearInterval(id);
  }, [isAuthenticated]);
}
