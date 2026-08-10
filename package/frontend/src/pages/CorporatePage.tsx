import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { getMyCorporateAccounts, getErrorMessage, type CorporateAccount } from '../api';

export function CorporatePage() {
  const navigate = useNavigate();
  const [accounts, setAccounts] = useState<CorporateAccount[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    getMyCorporateAccounts()
      .then(setAccounts)
      .catch((err) => setError(getErrorMessage(err, 'Could not load corporate accounts.')));
  }, []);

  return (
    <Screen eyebrow="Business" title="Corporate billing" onBack={() => navigate('/profile')}>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {accounts.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          No corporate account linked. Ask your company admin to invite you.
        </p>
      )}
      {accounts.map((a) => (
        <div
          key={a.account_id}
          style={{
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: 16,
          }}
        >
          <div style={{ fontWeight: 700, fontSize: 16 }}>{a.name}</div>
          <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>
            Role: {a.role} · Status: {a.status}
          </div>
          <p style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 8 }}>
            Select &quot;Corporate billing&quot; at checkout to bill trips to this account.
          </p>
        </div>
      ))}
    </Screen>
  );
}
