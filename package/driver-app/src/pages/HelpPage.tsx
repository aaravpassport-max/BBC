import { useState, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { FAQ_ITEMS } from '../constants/brand';

export function HelpPage() {
  const navigate = useNavigate();
  const [query, setQuery] = useState('');

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return FAQ_ITEMS;
    return FAQ_ITEMS.filter(
      (item) => item.q.toLowerCase().includes(q) || item.a.toLowerCase().includes(q)
    );
  }, [query]);

  return (
    <Screen eyebrow="Help" title="Help & support" onBack={() => navigate('/profile')}>
      <Input
        placeholder="Search FAQs…"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Search FAQs"
      />

      {filtered.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No FAQs match your search.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
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
      )}

      <Button onClick={() => navigate('/support')}>Contact support</Button>
      <Button variant="ghost" onClick={() => navigate('/support/new')}>
        Raise a new ticket
      </Button>
    </Screen>
  );
}
