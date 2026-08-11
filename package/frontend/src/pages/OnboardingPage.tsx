import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PortMyStuffHeader } from '../components/PortMyStuffHeader';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { updateProfile } from '../api';
import { resumePendingLocationLink } from '../hooks/useDeepLink';
import { BRAND, STORAGE_KEYS } from '../constants/brand';
import styles from './LoginPage.module.css';

const APP_FEATURES = [
  'Book local deliveries in minutes with upfront pricing',
  'Track your driver live and share trip status with others',
  'Pay with wallet, card, or corporate billing',
];

export function OnboardingPage() {
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function handleContinue() {
    const trimmed = name.trim() || BRAND.defaultUserName;
    setLoading(true);
    setError('');
    try {
      const payload: { name: string; email?: string } = { name: trimmed };
      if (email.trim()) payload.email = email.trim();
      await updateProfile(payload);
      localStorage.setItem(STORAGE_KEYS.displayName, trimmed);
      localStorage.setItem(STORAGE_KEYS.onboarded, 'true');
      if (resumePendingLocationLink(navigate)) return;
      navigate('/home', { replace: true });
    } catch {
      localStorage.setItem(STORAGE_KEYS.displayName, trimmed);
      localStorage.setItem(STORAGE_KEYS.onboarded, 'true');
      if (resumePendingLocationLink(navigate)) return;
      navigate('/home', { replace: true });
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className={styles.page}>
      <PortMyStuffHeader variant="auth" />
      <div className={styles.sheet}>
        <h2 className={styles.title}>Welcome to {BRAND.name}</h2>
        <p className={styles.subtitle}>Tell us your name so we can personalise your experience.</p>

        <ul style={{ margin: '0 0 16px', paddingLeft: 18, fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.6 }}>
          {APP_FEATURES.map((feature) => (
            <li key={feature}>{feature}</li>
          ))}
        </ul>

        <Input placeholder="Your name" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
        <Input
          type="email"
          placeholder="Email (optional)"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        <Button loading={loading} onClick={() => void handleContinue()}>Get started</Button>
      </div>
    </div>
  );
}
