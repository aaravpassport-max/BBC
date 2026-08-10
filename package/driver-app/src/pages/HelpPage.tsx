import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { FAQ_ITEMS } from '../constants/brand';

export function HelpPage() {
  const navigate = useNavigate();

  return (
    <Screen eyebrow="Help" title="Help & support" onBack={() => navigate('/profile')}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {FAQ_ITEMS.map((item) => (
          <div
            key={item.q}
            style={{
              border: '1px solid var(--border)',
              borderRadius: 12,
              background: 'var(--surface)',
              padding: '14px 16px',
            }}
          >
            <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 6 }}>{item.q}</div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.5 }}>{item.a}</div>
          </div>
        ))}
      </div>

      <Button onClick={() => navigate('/support')}>Contact support</Button>
      <Button variant="ghost" onClick={() => navigate('/support/new')}>
        Raise a new ticket
      </Button>
    </Screen>
  );
}
