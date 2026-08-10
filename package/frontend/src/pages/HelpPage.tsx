import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { FAQ_ITEMS } from '../constants/brand';

const QUICK_LINKS = [
  { label: 'Safety', path: '/safety', icon: '🛡️' },
  { label: 'Support', path: '/support', icon: '💬' },
  { label: 'Wallet', path: '/wallet', icon: '💳' },
];

export function HelpPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');

  const query = search.trim().toLowerCase();
  const filtered = query
    ? FAQ_ITEMS.filter((item) => item.q.toLowerCase().includes(query) || item.a.toLowerCase().includes(query))
    : FAQ_ITEMS;

  return (
    <Screen eyebrow="Help" title="Help & support" onBack={() => navigate('/profile')}>
      <Input
        placeholder="Search FAQs…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {QUICK_LINKS.map((link) => (
          <button
            key={link.path}
            type="button"
            onClick={() => navigate(link.path)}
            style={{
              flex: '1 1 auto',
              minWidth: 90,
              border: '1px solid var(--border)',
              borderRadius: 12,
              background: 'var(--surface)',
              padding: '12px 14px',
              cursor: 'pointer',
              fontSize: 13,
              fontWeight: 600,
              color: 'var(--text)',
            }}
          >
            {link.icon} {link.label}
          </button>
        ))}
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {filtered.length === 0 && (
          <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No FAQs match your search.</p>
        )}
        {filtered.map((item) => (
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
