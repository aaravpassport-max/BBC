import { useState, type FormEvent } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { authApi } from '@/api/auth';
import type { ApiError } from '@/types';

const EXPERIENCE_LEVELS = [
  { value: 'fresher', label: 'Fresher (0 years)' },
  { value: '0-2', label: '0–2 years' },
  { value: '2-5', label: '2–5 years' },
  { value: '5-10', label: '5–10 years' },
  { value: '10+', label: '10+ years' },
];

export default function Register() {
  const nav = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [experience, setExperience] = useState('fresher');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  // Client-side mirror of the backend's real validation rules (class-api-auth.php do_register) —
  // catches obvious mistakes before a round trip, but the server is still the source of truth.
  function validate(): boolean {
    const fe: Record<string, string> = {};
    if (name.trim().length < 2) fe.name = 'Name must be at least 2 characters.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fe.email = 'Enter a valid email address.';
    if (password.length < 8) fe.password = 'Password must be at least 8 characters.';
    else if (!/[A-Z]/.test(password) || !/[0-9]/.test(password)) fe.password = 'Password needs one uppercase letter and one number.';
    setFieldErrors(fe);
    return Object.keys(fe).length === 0;
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (!validate()) return;
    setSubmitting(true);
    try {
      const r = await authApi.register({ name, email, password, experience_level: experience });
      nav('/verify-otp', { state: { unverifiedToken: r.unverified_token, email: r.email } });
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="ia-page">
      <h1>Create your account</h1>
      <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-6)' }}>
        Start practicing interviews with AI feedback.
      </p>
      <form onSubmit={onSubmit} noValidate>
        <div className="ia-field">
          <label htmlFor="name">Full name</label>
          <input id="name" className="ia-input" required value={name} onChange={(e) => setName(e.target.value)} />
          {fieldErrors.name && <span className="ia-field-error">{fieldErrors.name}</span>}
        </div>
        <div className="ia-field">
          <label htmlFor="email">Email</label>
          <input id="email" className="ia-input" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
          {fieldErrors.email && <span className="ia-field-error">{fieldErrors.email}</span>}
        </div>
        <div className="ia-field">
          <label htmlFor="password">Password</label>
          <input id="password" className="ia-input" type="password" autoComplete="new-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
          {fieldErrors.password ? (
            <span className="ia-field-error">{fieldErrors.password}</span>
          ) : (
            <span className="ia-state-hint">At least 8 characters, one uppercase letter, one number.</span>
          )}
        </div>
        <div className="ia-field">
          <label htmlFor="experience">Experience level</label>
          <select id="experience" className="ia-input" value={experience} onChange={(e) => setExperience(e.target.value)}>
            {EXPERIENCE_LEVELS.map((l) => (
              <option key={l.value} value={l.value}>{l.label}</option>
            ))}
          </select>
        </div>
        {error && <p className="ia-field-error" role="alert" style={{ marginBottom: 'var(--ia-space-4)' }}>{error.message}</p>}
        <button className="ia-btn ia-btn-primary ia-btn-block" type="submit" disabled={submitting}>
          {submitting ? 'Creating account…' : 'Create account'}
        </button>
      </form>
      <p style={{ marginTop: 'var(--ia-space-5)', fontSize: 'var(--ia-text-sm)', textAlign: 'center' }}>
        Already have an account? <Link to="/login">Sign in</Link>
      </p>
    </div>
  );
}
