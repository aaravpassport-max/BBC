import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from './Button';
import { demoLogin } from '../api';
import { useAuth, getDeviceId } from '../context/AuthContext';
import {
  DEMO_ACCESS_TOKEN,
  DEMO_OTP,
  DEMO_PHONE,
  DEMO_USER_ID,
  SHOW_TEST_CREDENTIALS,
} from '../config/testCredentials';

export function DemoLoginPanel() {
  const navigate = useNavigate();
  const auth = useAuth();
  const [loading, setLoading] = useState(false);

  if (!SHOW_TEST_CREDENTIALS) return null;

  async function enterDemo() {
    setLoading(true);
    try {
      const res = await demoLogin(DEMO_PHONE, getDeviceId());
      auth.login(res.access_token, res.user_id);
    } catch {
      auth.login(DEMO_ACCESS_TOKEN, DEMO_USER_ID);
    } finally {
      setLoading(false);
      navigate('/home');
    }
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
