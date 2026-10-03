import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { BRAND } from '../constants/brand';
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

  async function copyCode() {
    if (!summary) return;
    await navigator.clipboard.writeText(summary.referral_code);
    setSuccess('Code copied to clipboard!');
  }

  async function shareInvite() {
    if (!summary) return;
    const text = `Use my ${BRAND.name} referral code ${summary.referral_code} on your first trip and we both earn rewards!`;
    if (navigator.share) {
      try {
        await navigator.share({ title: `${BRAND.name} referral`, text });
        return;
      } catch {
        // user cancelled
      }
    }
    await navigator.clipboard.writeText(text);
    setSuccess('Invite message copied — share it with friends!');
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
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', flexWrap: 'wrap' }}>
            <Button variant="ghost" onClick={() => void copyCode()} style={{ width: 'auto' }}>Copy code</Button>
            <Button variant="ghost" onClick={() => void shareInvite()} style={{ width: 'auto' }}>Share invite</Button>
          </div>
        </div>
      )}

      {summary && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10 }}>
          <Stat label="Referrals" value={String(summary.successful_referrals)} />
          <Stat label="Earned" value={`₹${summary.earned_confirmed}`} />
          <Stat label="Pending review" value={`₹${summary.earned_pending_review}`} />
        </div>
      )}

      {summary && summary.earned_pending_review > 0 && (
        <p style={{ fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.5 }}>
          ₹{summary.earned_pending_review} is pending review — rewards are credited after your friend&apos;s first trip is verified and completes without disputes.
        </p>
      )}

      <div
        style={{
          border: '1px solid var(--border)',
          borderRadius: 12,
          background: 'var(--surface)',
          padding: 16,
        }}
      >
        <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 12 }}>How it works</div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <Step n={1} title="Share your code" body="Send your referral code or invite link to friends who haven't used PORTMYSTUFF yet." />
          <Step n={2} title="Friend books a trip" body="They sign up, apply your code, and complete their first delivery." />
          <Step n={3} title="Both earn rewards" body="You receive wallet credit once their trip is verified. They get a welcome bonus too." />
        </div>
      </div>

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
      <div style={{ fontSize: 18, fontWeight: 700, marginTop: 4 }}>{value}</div>
    </div>
  );
}

function Step({ n, title, body }: { n: number; title: string; body: string }) {
  return (
    <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
      <div
        style={{
          width: 28,
          height: 28,
          borderRadius: '50%',
          background: 'var(--accent-soft)',
          color: 'var(--accent-strong)',
          fontWeight: 700,
          fontSize: 14,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          flexShrink: 0,
        }}
      >
        {n}
      </div>
      <div>
        <div style={{ fontWeight: 600, fontSize: 14 }}>{title}</div>
        <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 2, lineHeight: 1.5 }}>{body}</div>
      </div>
    </div>
  );
}
