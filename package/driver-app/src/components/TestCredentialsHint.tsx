import { DEMO_OTP, DEMO_PHONE, SHOW_TEST_CREDENTIALS } from '../config/testCredentials';

export function TestCredentialsHint() {
  if (!SHOW_TEST_CREDENTIALS) return null;

  return (
    <div
      style={{
        marginTop: 16,
        padding: '12px 14px',
        borderRadius: 10,
        border: '1px dashed var(--border)',
        background: 'var(--surface)',
        fontSize: 13,
        lineHeight: 1.5,
        color: 'var(--text-muted)',
      }}
    >
      <strong style={{ color: 'var(--text)' }}>Demo login</strong>
      <div style={{ marginTop: 6 }}>
        Phone: <code style={{ fontFamily: 'var(--font-mono)' }}>{DEMO_PHONE}</code>
      </div>
      <div>
        OTP: <code style={{ fontFamily: 'var(--font-mono)' }}>{DEMO_OTP}</code>
      </div>
    </div>
  );
}
