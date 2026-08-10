import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getMyCorporateAccounts, acceptCorporateInvite, getErrorMessage, type CorporateAccount } from '../api';

export function CorporatePage() {
  const navigate = useNavigate();
  const [accounts, setAccounts] = useState<CorporateAccount[]>([]);
  const [inviteEmail, setInviteEmail] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  async function refresh() {
    try {
      setAccounts(await getMyCorporateAccounts());
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load corporate accounts.'));
    }
  }

  useEffect(() => {
    void refresh();
  }, []);

  async function handleAcceptInvite() {
    if (!inviteEmail.trim()) return;
    setLoading(true);
    setError('');
    setSuccess('');
    try {
      await acceptCorporateInvite(inviteEmail.trim());
      setSuccess('Corporate invite accepted. You can now bill trips to your company.');
      setInviteEmail('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not accept invite. Check the email your admin used.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Business" title="Corporate billing" onBack={() => navigate('/profile')}>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}

      {accounts.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          No corporate account linked yet. Enter the work email your company admin invited.
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

      <div style={{ borderTop: '1px solid var(--border)', paddingTop: 16 }}>
        <div style={{ fontWeight: 600, marginBottom: 8 }}>Join with invite email</div>
        <Input
          type="email"
          placeholder="work@company.com"
          value={inviteEmail}
          onChange={(e) => setInviteEmail(e.target.value)}
        />
        <Button loading={loading} onClick={() => void handleAcceptInvite()}>Accept invite</Button>
      </div>
    </Screen>
  );
}
