import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getMyAccounts, acceptInvite, getErrorMessage, type MyAccount } from '../api';
import { SkeletonRowList } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';

export function MyAccountsPage() {
  const navigate = useNavigate();
  const auth = useAuth();
  const [accounts, setAccounts] = useState<MyAccount[] | null>(null);
  const [error, setError] = useState('');
  const [email, setEmail] = useState('');
  const [accepting, setAccepting] = useState(false);

  const refresh = useCallback(async () => {
    try {
      setAccounts(await getMyAccounts());
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your accounts.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleAcceptInvite() {
    if (!email.trim()) return;
    setError('');
    setAccepting(true);
    try {
      const result = await acceptInvite(email.trim());
      navigate(`/accounts/${result.accountId}`);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not accept this invite.'));
    } finally {
      setAccepting(false);
    }
  }

  if (accounts === null) {
    return (
      <Screen eyebrow="Corporate Portal" title="Your company accounts">
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
        <SkeletonRowList count={2} />
      </Screen>
    );
  }

  if (accounts.length === 0) {
    return (
      <Screen eyebrow="Corporate Portal" title="Accept an invite">
        <p style={{ color: 'var(--text-muted)', fontSize: 15 }}>
          You're not yet part of a company account. Enter the email address your invite was sent to.
        </p>
        <Input label="Invite email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        <Button onClick={handleAcceptInvite} loading={accepting}>
          Accept invite
        </Button>
        <Button variant="ghost" onClick={auth.logout}>
          Sign out
        </Button>
      </Screen>
    );
  }

  return (
    <Screen eyebrow="Corporate Portal" title="Your company accounts">
      {accounts.map((a) => (
        <button
          key={a.account_id}
          onClick={() => navigate(`/accounts/${a.account_id}`)}
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            width: '100%',
            textAlign: 'left',
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: 16,
            cursor: 'pointer',
            color: 'var(--text)',
          }}
        >
          <div>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 15 }}>{a.name}</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{a.role.replace(/_/g, ' ')}</div>
          </div>
          <span style={{ color: 'var(--text-muted)' }}>→</span>
        </button>
      ))}
      <Button variant="ghost" onClick={auth.logout}>
        Sign out
      </Button>
    </Screen>
  );
}
