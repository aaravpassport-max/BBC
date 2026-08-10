import { createContext, useContext, useState, useCallback, type ReactNode } from 'react';
import { setToken } from '../api/client';
import { clearDemoSession } from '../api/demoAuth';

interface AuthState {
  isAuthenticated: boolean;
  userId: string | null;
  login: (token: string, userId: string) => void;
  logout: () => void;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [userId, setUserId] = useState<string | null>(() => localStorage.getItem('user_id'));

  const login = useCallback((token: string, uid: string) => {
    setToken(token);
    localStorage.setItem('user_id', uid);
    setUserId(uid);
  }, []);

  const logout = useCallback(() => {
    setToken(null);
    localStorage.removeItem('user_id');
    clearDemoSession();
    setUserId(null);
  }, []);

  return (
    <AuthContext.Provider value={{ isAuthenticated: !!userId, userId, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}

/** A stable per-browser device identifier, generated once and persisted —
 * the backend's rate limiting and refresh-token binding key off this. */
export function getDeviceId(): string {
  let id = localStorage.getItem('device_id');
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem('device_id', id);
  }
  return id;
}
