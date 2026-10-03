import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { LoadingBlock } from './StateViews';

/** Routes reachable even with onboarding incomplete — never trap the user (settings so they can fix things, billing/logout implicitly via dashboard chrome). */
const ONBOARDING_EXEMPT = ['/onboarding', '/settings'];

export default function ProtectedRoute({ children }: { children: ReactNode }) {
  const { status, user } = useAuth();
  const location = useLocation();

  if (status === 'loading') return <div className="ia-page"><LoadingBlock label="Loading your session…" /></div>;
  if (status === 'unauthenticated') return <Navigate to="/login" replace />;

  if (user && !user.onboarding_complete && !ONBOARDING_EXEMPT.includes(location.pathname)) {
    return <Navigate to="/onboarding" replace />;
  }

  return <>{children}</>;
}
