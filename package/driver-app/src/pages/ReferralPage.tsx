import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getReferralSummary, redeemReferralCode, getErrorMessage, type ReferralSummary } from '../api';

export function ReferralPage() {
  const navigate = useNavigate();
  const [summary, setSummary] = useState<ReferralSummary | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    getReferralSummary()
      .then(setSummary)
      .catch((err) => setError(getErrorMessage(err, 'Could not load referral info.')));
  }, []);

  async function handleRedeem() {
    if (!code.trim()) return;
    setLoading(true);
    setError('');
    setSuccess('');
    try {
      await redeemReferralCode(code.trim());
      setSuccess('Referral code applied! Complete your first trip to unlock rewards.');
      setCode('');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not apply code.'));
    } finally {
      setLoading(false);
    }
  }

  function copyCode() {
    if (!summary) return;
    void navigator.clipboard.writeText(summary.referral_code);
    setSuccess('Code copied to clipboard!');
  }

  return (
    <Screen eyebrow="Rewards" title="Refer & earn" onBack={() => navigate('/profile')}>
      {summary && (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 16,
            background: 'linear-gradient(135deg, #e8f0fe, #fff)',
            padding: 20,
            textAlign: 'center',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Your referral code</div>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 28, fontWeight: 700, letterSpacing: '0.1em', margin: '8px 0' }}>
            {summary.referral_code}
          </div>
          <Button variant="ghost" onClick={copyCode} style={{ width: 'auto', margin: '0 auto' }}>
            Copy code
          </Button>
        </div>
      )}

      {summary && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <Stat label="Successful referrals" value={String(summary.successful_referrals)} />
          <Stat label="Earned" value={`₹${summary.earned_confirmed}`} />
        </div>
      )}

      <div>
        <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 8 }}>Have a friend&apos;s code?</div>
        <div style={{ display: 'flex', gap: 8 }}>
          <div style={{ flex: 1 }}>
            <Input placeholder="Enter code" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} />
          </div>
          <Button loading={loading} style={{ width: 'auto', padding: '0 16px' }} onClick={() => void handleRedeem()}>
            Apply
          </Button>
        </div>
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}
    </Screen>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 14, textAlign: 'center' }}>
      <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 700, marginTop: 4 }}>{value}</div>
    </div>
  );
}
