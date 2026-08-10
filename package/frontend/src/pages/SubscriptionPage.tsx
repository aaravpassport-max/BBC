import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { BRAND } from '../constants/brand';
import {
  getMySubscription,
  getSubscriptionPlans,
  purchaseSubscription,
  cancelSubscription,
  reactivateSubscription,
  getErrorMessage,
  type Subscription,
  type SubscriptionPlan,
} from '../api';

export function SubscriptionPage() {
  const navigate = useNavigate();
  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [selectedPlan, setSelectedPlan] = useState<string | null>(null);
  const [sub, setSub] = useState<Subscription | null | undefined>(undefined);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function refresh() {
    try {
      const [s, p] = await Promise.all([getMySubscription(), getSubscriptionPlans()]);
      setSub(s);
      setPlans(p);
      if (!selectedPlan && p.length > 0) setSelectedPlan(p[0].id);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load membership.'));
      setSub(null);
    }
  }

  useEffect(() => {
    void refresh();
  }, []);

  const plan = plans.find((p) => p.id === selectedPlan) ?? plans[0];

  async function handlePurchase() {
    if (!plan) return;
    setLoading(true);
    setError('');
    try {
      await purchaseSubscription(plan.id);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not start membership.'));
    } finally {
      setLoading(false);
    }
  }

  async function handleCancel() {
    if (!sub) return;
    setLoading(true);
    setError('');
    try {
      await cancelSubscription(sub.id);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not cancel membership.'));
    } finally {
      setLoading(false);
    }
  }

  async function handleReactivate() {
    if (!sub) return;
    setLoading(true);
    setError('');
    try {
      await reactivateSubscription(sub.id);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not reactivate membership.'));
    } finally {
      setLoading(false);
    }
  }

  const isActive = sub?.status === 'active' || sub?.status === 'grace_period';

  return (
    <Screen eyebrow="Membership" title={BRAND.plus} onBack={() => navigate('/profile')}>
      {plans.length > 1 && (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {plans.map((p) => (
            <button
              key={p.id}
              type="button"
              onClick={() => setSelectedPlan(p.id)}
              style={{
                border: `1px solid ${selectedPlan === p.id ? 'var(--accent)' : 'var(--border)'}`,
                background: selectedPlan === p.id ? 'var(--accent-soft)' : 'var(--surface)',
                borderRadius: 20,
                padding: '6px 14px',
                fontSize: 13,
                fontWeight: 600,
                cursor: 'pointer',
              }}
            >
              {p.name}
            </button>
          ))}
        </div>
      )}

      {plan && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 16, background: 'var(--surface)', padding: 20 }}>
          <div style={{ fontSize: 20, fontWeight: 700 }}>{plan.name}</div>
          <div style={{ fontSize: 28, fontWeight: 700, color: 'var(--accent-strong)', margin: '8px 0' }}>
            ₹{plan.monthly_fee}
            <span style={{ fontSize: 14, fontWeight: 500, color: 'var(--text-muted)' }}>/month</span>
          </div>
          <ul style={{ margin: 0, paddingLeft: 18, color: 'var(--text-muted)', fontSize: 14, lineHeight: 1.6 }}>
            {plan.benefits.map((b) => (
              <li key={b}>{b}</li>
            ))}
          </ul>
        </div>
      )}

      {sub === undefined && <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>Loading…</p>}

      {sub && isActive && (
        <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>
          Active until {new Date(sub.current_period_end).toLocaleDateString()}
          {sub.status === 'grace_period' && sub.grace_period_ends_at && (
            <> · Grace period ends {new Date(sub.grace_period_ends_at).toLocaleDateString()}</>
          )}
        </div>
      )}

      {sub && sub.status === 'cancelled' && (
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>
          Your membership is cancelled. Benefits ended at the close of your last paid period.
        </p>
      )}

      {sub && sub.status === 'lapsed' && (
        <div style={{ fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.5 }}>
          <p>Your membership lapsed after renewal failed. Reactivate within 14 days to restore continuity — your original billing period is preserved.</p>
          <p>Reactivation charges your wallet for one month (₹{plan?.monthly_fee ?? '—'}).</p>
        </div>
      )}

      {!sub && sub !== undefined && plan && (
        <Button onClick={() => void handlePurchase()} loading={loading}>
          Join {plan.name}
        </Button>
      )}
      {sub && isActive && (
        <Button variant="ghost" onClick={() => void handleCancel()} loading={loading}>
          Cancel membership
        </Button>
      )}
      {sub && sub.status === 'lapsed' && (
        <Button onClick={() => void handleReactivate()} loading={loading}>
          Reactivate membership
        </Button>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
    </Screen>
  );
}
