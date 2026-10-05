import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from './Button';
import { localDemoAuth } from '../api/demoAuth';
import { useAuth } from '../context/AuthContext';
import { DEMO_OTP, DEMO_PHONE, SHOW_TEST_CREDENTIALS } from '../config/testCredentials';

export function DemoLoginPanel() {
  const navigate = useNavigate();
  const auth = useAuth();
  const [loading, setLoading] = useState(false);

  if (!SHOW_TEST_CREDENTIALS) return null;

  function enterDemo() {
    setLoading(true);
    const res = localDemoAuth();
    auth.login(res.access_token, res.user_id, res.refresh_token);
    setLoading(false);
    navigate('/home');
  }

  return (
    <div
      style={{
        marginTop: 16,
        padding: '14px 14px',
        borderRadius: 10,
        border: '1px dashed var(--border)',
        background: 'var(--surface)',
        fontSize: 13,
        lineHeight: 1.5,
        color: 'var(--text-muted)',
        display: 'flex',
        flexDirection: 'column',
        gap: 10,
      }}
    >
      <div>
        <strong style={{ color: 'var(--text)' }}>Demo account (never expires)</strong>
        <div style={{ marginTop: 6 }}>
          Phone <code style={{ fontFamily: 'var(--font-mono)' }}>{DEMO_PHONE}</code> · OTP{' '}
          <code style={{ fontFamily: 'var(--font-mono)' }}>{DEMO_OTP}</code>
        </div>
      </div>
      <Button type="button" onClick={() => void enterDemo()} loading={loading}>
        Enter demo app now
      </Button>
    </div>
  );
}
