import { useState, type FormEvent } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { authApi } from '@/api/auth';
import { useAuth } from '@/context/AuthContext';
import type { ApiError } from '@/types';

interface LocationState {
  unverifiedToken: string;
  email: string;
}

export default function VerifyOtp() {
  const location = useLocation();
  const nav = useNavigate();
  const { refreshUser } = useAuth();
  const state = location.state as LocationState | null;

  const [otp, setOtp] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [resending, setResending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [resendMessage, setResendMessage] = useState<string | null>(null);

  if (!state?.unverifiedToken) {
    // Directly-navigated with no signup/login-in-progress context — send them back rather than showing a broken form.
    return (
      <div className="ia-page">
        <div className="ia-state ia-state-empty">
          <h3>Session expired</h3>
          <p>Please sign in or register again to receive a new verification code.</p>
          <button className="ia-btn ia-btn-primary" onClick={() => nav('/login')}>Go to sign in</button>
        </div>
      </div>
    );
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await authApi.verifyOtp({ otp, unverified_token: state!.unverifiedToken });
      await refreshUser();
      nav('/dashboard');
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubmitting(false);
    }
  }

  async function onResend() {
    setResending(true);
    setResendMessage(null);
    setError(null);
    try {
      await authApi.resendOtp(state!.unverifiedToken);
      setResendMessage('A new code has been sent.');
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setResending(false);
    }
  }

  return (
    <div className="ia-page">
      <h1>Check your email</h1>
      <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-6)' }}>
        Enter the 6-digit code we sent to <strong>{state.email}</strong>.
      </p>
      <form onSubmit={onSubmit} noValidate>
        <div className="ia-field">
          <label htmlFor="otp">Verification code</label>
          <input
            id="otp"
            className="ia-input"
            inputMode="numeric"
            pattern="[0-9]{6}"
            maxLength={6}
            autoComplete="one-time-code"
            required
            value={otp}
            onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))}
          />
        </div>
        {error && (
          <p className="ia-field-error" role="alert" style={{ marginBottom: 'var(--ia-space-4)' }}>
            {error.code === 'ia_rate' ? error.message : error.message}
          </p>
        )}
        {resendMessage && <p style={{ color: 'var(--ia-success)', fontSize: 'var(--ia-text-sm)', marginBottom: 'var(--ia-space-4)' }}>{resendMessage}</p>}
        <button className="ia-btn ia-btn-primary ia-btn-block" type="submit" disabled={submitting || otp.length !== 6}>
          {submitting ? 'Verifying…' : 'Verify'}
        </button>
      </form>
      <button className="ia-btn ia-btn-ghost ia-btn-block" style={{ marginTop: 'var(--ia-space-3)' }} onClick={onResend} disabled={resending}>
        {resending ? 'Sending…' : "Didn't get a code? Resend"}
      </button>
    </div>
  );
}
