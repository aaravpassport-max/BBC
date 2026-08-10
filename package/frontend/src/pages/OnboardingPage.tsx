import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PortMyStuffHeader } from '../components/PortMyStuffHeader';
import { BRAND, STORAGE_KEYS } from '../constants/brand';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import styles from './LoginPage.module.css';

export function OnboardingPage() {
  const navigate = useNavigate();
  const [name, setName] = useState('');

  function handleContinue() {
    const trimmed = name.trim() || BRAND.defaultUserName;
    localStorage.setItem(STORAGE_KEYS.displayName, trimmed);
    localStorage.setItem(STORAGE_KEYS.onboarded, 'true');
    navigate('/home', { replace: true });
  }

  return (
    <div className={styles.page}>
      <PortMyStuffHeader variant="auth" />
      <div className={styles.sheet}>
        <h2 className={styles.title}>Welcome to {BRAND.name}</h2>
        <p className={styles.subtitle}>Tell us your name so we can personalise your experience.</p>
        <Input placeholder="Your name" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
        <Button onClick={handleContinue}>Get started</Button>
      </div>
    </div>
  );
}
