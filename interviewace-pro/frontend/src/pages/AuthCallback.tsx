import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { authApi } from '@/api/auth';
import { useAuth } from '@/context/AuthContext';
import { LoadingBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

/**
 * Lands here after Google OAuth (interviewace.php redirects to
 * /app/auth/callback?code=... — a short-lived one-time code, NOT the JWT
 * itself; see class-api-auth.php do_google_callback). Exchanges the code
 * for a real access token via POST so the token never touches the URL,
 * browser history, or a Referer header.
 */
export default function AuthCallback() {
  const [params] = useSearchParams();
  const nav = useNavigate();
  const { refreshUser } = useAuth();
  const [error, setError] = useState<ApiError | null>(null);
  const code = params.get('code');

  useEffect(() => {
    if (!code) {
      setError({ code: 'ia_no_code', message: 'Missing sign-in code.', status: 400, retryable: false });
      return;
    }
    authApi
      .exchangeCode(code)
      .then(() => refreshUser())
      .then(() => nav('/dashboard', { replace: true }))
      .catch((err) => setError(err as ApiError));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code]);

  if (error) {
    return (
      <div className="ia-page">
        <ErrorBlock error={error} onRetry={() => nav('/login')} fallbackMessage="Sign-in failed." />
      </div>
    );
  }
  return (
    <div className="ia-page">
      <LoadingBlock label="Signing you in…" />
    </div>
  );
}
