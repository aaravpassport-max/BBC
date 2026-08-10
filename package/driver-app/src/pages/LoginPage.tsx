import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { PorterHeader } from '../components/PorterHeader';
import { Input } from '../components/Input';
import { Button } from '../components/Button';
import { requestOtp, ApiError } from '../api';
import { getDeviceId } from '../context/AuthContext';
import { DemoLoginPanel } from '../components/DemoLoginPanel';
import styles from './LoginPage.module.css';

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
    <div className={styles.page}>
      <PorterHeader variant="auth" />
      <div className={styles.sheet}>
        <h2 className={styles.title}>Partner login</h2>
        <p className={styles.subtitle}>Sign in to start earning with Porter Partner</p>
        <form onSubmit={handleSubmit} className={styles.form}>
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
            Continue
          </Button>
        </form>
        <DemoLoginPanel />
      </div>
    </div>
  );
}
