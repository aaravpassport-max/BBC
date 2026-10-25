import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { authApi } from '@/api/auth';
import { getAccessToken } from '@/api/client';
import type { User } from '@/types';

interface AuthState {
  user: User | null;
  status: 'loading' | 'authenticated' | 'unauthenticated';
  refreshUser: () => Promise<void>;
  logout: () => Promise<void>;
  setUser: (u: User | null) => void;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<AuthState['status']>('loading');

  const refreshUser = useCallback(async () => {
    try {
      const u = await authApi.me();
      setUser(u);
      setStatus('authenticated');
    } catch {
      setUser(null);
      setStatus('unauthenticated');
    }
  }, []);

  useEffect(() => {
    // On a hard reload the in-memory access token is gone by design (see
    // api/client.ts) — apiFetch's built-in 401-then-refresh-then-retry
    // handles recovering a session from the HttpOnly refresh cookie
    // transparently the moment /auth/me is called, with no extra logic
    // needed here.
    void refreshUser();
  }, [refreshUser]);

  const logout = useCallback(async () => {
    await authApi.logout();
    setUser(null);
    setStatus('unauthenticated');
  }, []);

  return (
    <AuthContext.Provider value={{ user, status, refreshUser, logout, setUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}

/** True only once we've actually confirmed via /auth/me — never trust getAccessToken() alone for a route guard, a stale in-memory token can be present but rejected. */
export function hasSessionHint(): boolean {
  return Boolean(getAccessToken());
}
