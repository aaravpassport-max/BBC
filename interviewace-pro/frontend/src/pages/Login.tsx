import { useState, type FormEvent } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { authApi } from '@/api/auth';
import { useAuth } from '@/context/AuthContext';
import type { ApiError } from '@/types';

export default function Login() {
  const nav = useNavigate();
  const { refreshUser } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await authApi.login({ email, password });
      await refreshUser();
      nav('/dashboard');
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr.code === 'ia_unverified') {
        const unverifiedToken = (apiErr.extra?.unverified_token as string) ?? '';
        nav('/verify-otp', { state: { unverifiedToken, email: apiErr.extra?.email ?? email } });
        return;
      }
      setError(apiErr);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="ia-page">
      <h1>Welcome back</h1>
      <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-6)' }}>
        Sign in to continue practicing.
      </p>
      <form onSubmit={onSubmit} noValidate>
        <div className="ia-field">
          <label htmlFor="email">Email</label>
          <input
            id="email"
            className="ia-input"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </div>
        <div className="ia-field">
          <label htmlFor="password">Password</label>
          <input
            id="password"
            className="ia-input"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </div>
        {error && (
          <p className="ia-field-error" role="alert" style={{ marginBottom: 'var(--ia-space-4)' }}>
            {error.message}
          </p>
        )}
        <button className="ia-btn ia-btn-primary ia-btn-block" type="submit" disabled={submitting}>
          {submitting ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
      <p style={{ marginTop: 'var(--ia-space-5)', fontSize: 'var(--ia-text-sm)', textAlign: 'center' }}>
        <Link to="/forgot-password">Forgot password?</Link>
      </p>
      <p style={{ marginTop: 'var(--ia-space-3)', fontSize: 'var(--ia-text-sm)', textAlign: 'center' }}>
        New here? <Link to="/register">Create an account</Link>
      </p>
    </div>
  );
}
