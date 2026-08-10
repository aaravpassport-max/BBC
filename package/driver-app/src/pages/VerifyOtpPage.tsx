import { useState, useRef, type KeyboardEvent, type ClipboardEvent } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { PorterHeader } from '../components/PorterHeader';
import { Button } from '../components/Button';
import { verifyOtp, requestOtp, ApiError, isDemoPhone } from '../api';
import { useAuth, getDeviceId } from '../context/AuthContext';
import { DEMO_OTP } from '../config/testCredentials';
import styles from './LoginPage.module.css';

interface LocationState {
  phone: string;
  otpId: string;
}

export function VerifyOtpPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const auth = useAuth();
  const state = location.state as LocationState | undefined;

  const [digits, setDigits] = useState<string[]>(['', '', '', '', '', '']);
  const [otpId, setOtpId] = useState(state?.otpId || '');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [resending, setResending] = useState(false);
  const inputRefs = useRef<(HTMLInputElement | null)[]>([]);

  if (!state?.phone) {
    navigate('/login');
    return null;
  }
  const phone = state.phone;

  function setDigit(index: number, value: string) {
    if (!/^[0-9]?$/.test(value)) return;
    const next = [...digits];
    next[index] = value;
    setDigits(next);
    if (value && index < 5) inputRefs.current[index + 1]?.focus();
    if (next.every((d) => d !== '')) void submit(next.join(''));
  }

  function handleKeyDown(index: number, e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && !digits[index] && index > 0) {
      inputRefs.current[index - 1]?.focus();
    }
  }

  function handlePaste(e: ClipboardEvent<HTMLInputElement>) {
    const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
    if (pasted.length === 6) {
      e.preventDefault();
      setDigits(pasted.split(''));
      void submit(pasted);
    }
  }

  async function submit(code: string) {
    setError('');
    setLoading(true);
    try {
      const res = await verifyOtp(otpId, code, getDeviceId(), phone);
      auth.login(res.access_token, res.user_id);
      navigate(isDemoPhone(phone) ? '/home' : '/kyc', { replace: true });
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
        if (err.code === 'OTP_LOCKED') {
          setDigits(['', '', '', '', '', '']);
        }
      } else {
        setError('Something went wrong.');
      }
      setDigits(['', '', '', '', '', '']);
      inputRefs.current[0]?.focus();
    } finally {
      setLoading(false);
    }
  }

  async function resend() {
    setResending(true);
    setError('');
    try {
      const res = await requestOtp(phone, getDeviceId());
      setOtpId(res.otp_id);
      setDigits(['', '', '', '', '', '']);
      inputRefs.current[0]?.focus();
    } catch (err) {
      if (err instanceof ApiError) setError(err.message);
    } finally {
      setResending(false);
    }
  }

  return (
    <div className={styles.page}>
      <PorterHeader variant="auth" />
      <div className={styles.sheet}>
        <h2 className={styles.title}>Enter OTP</h2>
        <p className={styles.subtitle}>
          Sent to +91 {phone}. Demo OTP: <strong>{DEMO_OTP}</strong>
        </p>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'space-between', marginBottom: 16 }}>
          {digits.map((d, i) => (
            <input
              key={i}
              ref={(el) => {
                inputRefs.current[i] = el;
              }}
              value={d}
              onChange={(e) => setDigit(i, e.target.value)}
              onKeyDown={(e) => handleKeyDown(i, e)}
              onPaste={handlePaste}
              inputMode="numeric"
              maxLength={1}
              aria-label={`Digit ${i + 1}`}
              style={{
                width: 46,
                height: 52,
                textAlign: 'center',
                fontSize: 22,
                fontWeight: 700,
                background: 'var(--surface-raised)',
                border: `2px solid ${error ? 'var(--danger)' : 'var(--border)'}`,
                borderRadius: 12,
                color: 'var(--text)',
                outline: 'none',
              }}
              disabled={loading}
            />
          ))}
        </div>
        {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        {loading && <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Verifying…</p>}
        <Button variant="ghost" onClick={resend} loading={resending} type="button">
          Resend OTP
        </Button>
      </div>
    </div>
  );
}
