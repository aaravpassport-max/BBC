import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PorterHeader } from '../components/PorterHeader';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import styles from './LoginPage.module.css';

export function OnboardingPage() {
  const navigate = useNavigate();
  const [name, setName] = useState('');

  function handleContinue() {
    const trimmed = name.trim() || 'Porter user';
    localStorage.setItem('porter_display_name', trimmed);
    localStorage.setItem('porter_onboarded', 'true');
    navigate('/home', { replace: true });
  }

  return (
    <div className={styles.page}>
      <PorterHeader variant="auth" />
      <div className={styles.sheet}>
        <h2 className={styles.title}>Welcome to Porter</h2>
        <p className={styles.subtitle}>Tell us your name so we can personalise your experience.</p>
        <Input placeholder="Your name" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
        <Button onClick={handleContinue}>Get started</Button>
      </div>
    </div>
  );
}
