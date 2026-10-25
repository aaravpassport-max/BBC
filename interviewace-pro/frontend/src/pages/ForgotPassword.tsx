import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { authApi } from '@/api/auth';

export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await authApi.forgotPassword(email);
    } finally {
      // Deliberately shown regardless of outcome — matches the backend's
      // account-enumeration-safe response (do_forgot always returns success).
      setSubmitting(false);
      setDone(true);
    }
  }

  if (done) {
    return (
      <div className="ia-page">
        <div className="ia-state ia-state-empty">
          <h3>Check your email</h3>
          <p>If an account exists for {email}, a reset link has been sent.</p>
          <Link to="/login" className="ia-btn ia-btn-secondary">Back to sign in</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="ia-page">
      <h1>Reset your password</h1>
      <form onSubmit={onSubmit} noValidate>
        <div className="ia-field">
          <label htmlFor="email">Email</label>
          <input id="email" className="ia-input" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <button className="ia-btn ia-btn-primary ia-btn-block" type="submit" disabled={submitting}>
          {submitting ? 'Sending…' : 'Send reset link'}
        </button>
      </form>
    </div>
  );
}
