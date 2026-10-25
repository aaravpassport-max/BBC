import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Input } from '../components/Input';
import { Button } from '../components/Button';
import { requestOtp, ApiError } from '../api';
import { getDeviceId } from '../context/AuthContext';

export function LoginPage() {
  const navigate = useNavigate();
  const [phone, setPhone] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError('');

    if (!/^[0-9]{10}$/.test(phone)) {
      setError('Enter a valid 10-digit mobile number');
      return;
    }

    setLoading(true);
    try {
      const res = await requestOtp(phone, getDeviceId());
      navigate('/verify', { state: { phone, otpId: res.otp_id } });
    } catch (err) {
      if (err instanceof ApiError) setError(err.message);
      else setError('Something went wrong. Please try again.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Corporate Portal" title="Manage your team's deliveries">
      <p style={{ color: 'var(--text-muted)', fontSize: 15, lineHeight: 1.6, marginBottom: 8 }}>
        Enter your mobile number. Sign in to manage your company account.
      </p>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <Input
          label="Mobile number"
          type="tel"
          inputMode="numeric"
          prefix="+91"
          placeholder="98765 43210"
          value={phone}
          maxLength={10}
          onChange={(e) => setPhone(e.target.value.replace(/\D/g, ''))}
          error={error}
          autoFocus
        />
        <Button type="submit" loading={loading}>
          Send code
        </Button>
      </form>
    </Screen>
  );
}
