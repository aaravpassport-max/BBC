import { useState, useRef, type KeyboardEvent, type ClipboardEvent } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Button } from '../components/Button';
import { verifyOtp, requestOtp, ApiError, getErrorMessage } from '../api';
import { useAuth, getDeviceId } from '../context/AuthContext';

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
      const res = await verifyOtp(otpId, code, getDeviceId());
      auth.login(res.access_token, res.user_id, res.refresh_token);
      navigate('/rate-cards');
    } catch (err) {
      setError(getErrorMessage(err, 'Something went wrong.'));
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
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ width: 360 }}>
        <div style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)', marginBottom: 8 }}>
          +91 {phone}
        </div>
        <h1 style={{ fontSize: 26, marginBottom: 8 }}>Enter the code</h1>
        <p style={{ color: 'var(--text-muted)', fontSize: 14, marginBottom: 20 }}>
          We sent a 6-digit code by SMS.
        </p>
        <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
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
                height: 56,
                textAlign: 'center',
                fontFamily: 'var(--font-mono)',
                fontSize: 24,
                fontWeight: 600,
                background: 'var(--surface)',
                border: `1px solid ${error ? 'var(--danger)' : 'var(--border)'}`,
                borderRadius: 10,
                color: 'var(--text)',
                outline: 'none',
              }}
              disabled={loading}
            />
          ))}
        </div>
        {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 12 }}>{error}</p>}
        <Button variant="ghost" onClick={resend} loading={resending} type="button">
          Resend code
        </Button>
      </div>
    </div>
  );
}
