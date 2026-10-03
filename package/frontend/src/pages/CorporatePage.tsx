import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getMyCorporateAccounts, acceptCorporateInvite, getErrorMessage, type CorporateAccount } from '../api';

function money(n: number): string {
  return `₹${n.toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;
}

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
      <p style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 0 }}>
        Bill deliveries to your company account with centralized invoicing and spend controls.
      </p>

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
            borderRadius: 16,
            background: 'var(--surface)',
            padding: 18,
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <div style={{ fontWeight: 700, fontSize: 17 }}>{a.name}</div>
              <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>
                Role: <strong>{a.role}</strong> · {a.status}
              </div>
            </div>
            <span
              style={{
                fontSize: 11,
                fontWeight: 700,
                padding: '4px 10px',
                borderRadius: 20,
                background: a.status === 'active' ? 'var(--success-soft, #f0fff4)' : 'var(--border)',
                color: a.status === 'active' ? 'var(--success)' : 'var(--text-muted)',
              }}
            >
              {a.status}
            </span>
          </div>

          {a.available_credit != null && (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 14 }}>
              <div style={{ border: '1px solid var(--border)', borderRadius: 10, padding: 12 }}>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Available credit</div>
                <div style={{ fontSize: 18, fontWeight: 700, color: 'var(--accent-strong)' }}>{money(a.available_credit)}</div>
              </div>
              <div style={{ border: '1px solid var(--border)', borderRadius: 10, padding: 12 }}>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Credit limit</div>
                <div style={{ fontSize: 18, fontWeight: 700 }}>{money(a.credit_limit ?? 0)}</div>
              </div>
            </div>
          )}

          {a.per_user_monthly_cap != null && a.per_user_monthly_cap > 0 && (
            <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 10, marginBottom: 0 }}>
              Your monthly spend cap: {money(a.per_user_monthly_cap)}
            </p>
          )}

          <p style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 12, marginBottom: 0 }}>
            Select &quot;Corporate billing&quot; at checkout and choose this account if you have multiple.
          </p>
        </div>
      ))}

      <div style={{ borderTop: '1px solid var(--border)', paddingTop: 16, marginTop: 8 }}>
        <div style={{ fontWeight: 600, marginBottom: 8 }}>Join with invite email</div>
        <Input type="email" placeholder="work@company.com" value={inviteEmail} onChange={(e) => setInviteEmail(e.target.value)} />
        <Button loading={loading} onClick={() => void handleAcceptInvite()}>Accept invite</Button>
      </div>
    </Screen>
  );
}
