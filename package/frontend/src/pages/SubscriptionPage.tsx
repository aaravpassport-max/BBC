import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import {
  getMySubscription,
  purchaseSubscription,
  cancelSubscription,
  reactivateSubscription,
  getErrorMessage,
  type Subscription,
} from '../api';
import { BRAND } from '../constants/brand';

const PLAN = {
  id: 'platform_plus',
  name: BRAND.plus,
  price: 99,
  benefits: ['Zero platform fee on every trip', 'Priority support', 'Exclusive offers'],
};

export function SubscriptionPage() {
  const navigate = useNavigate();
  const [sub, setSub] = useState<Subscription | null | undefined>(undefined);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function refresh() {
    try {
      const s = await getMySubscription();
      setSub(s);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load membership.'));
      setSub(null);
    }
  }

  useEffect(() => {
    void refresh();
  }, []);

  async function handlePurchase() {
    setLoading(true);
    setError('');
    try {
      await purchaseSubscription(PLAN.id);
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
      <div
        style={{
          border: '1px solid var(--border)',
          borderRadius: 16,
          background: 'var(--surface)',
          padding: 20,
        }}
      >
        <div style={{ fontSize: 20, fontWeight: 700 }}>{PLAN.name}</div>
        <div style={{ fontSize: 28, fontWeight: 700, color: 'var(--accent-strong)', margin: '8px 0' }}>
          ₹{PLAN.price}
          <span style={{ fontSize: 14, fontWeight: 500, color: 'var(--text-muted)' }}>/month</span>
        </div>
        <ul style={{ margin: 0, paddingLeft: 18, color: 'var(--text-muted)', fontSize: 14, lineHeight: 1.6 }}>
          {PLAN.benefits.map((b) => (
            <li key={b}>{b}</li>
          ))}
        </ul>
      </div>

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
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Your membership is cancelled.</p>
      )}

      {!sub && sub !== undefined && (
        <Button onClick={() => void handlePurchase()} loading={loading}>
          Join {BRAND.plus}
        </Button>
      )}
      {sub && isActive && (
        <Button variant="ghost" onClick={() => void handleCancel()} loading={loading}>
          Cancel membership
        </Button>
      )}
      {sub && sub.status === 'cancelled' && (
        <Button onClick={() => void handleReactivate()} loading={loading}>
          Reactivate membership
        </Button>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
    </Screen>
  );
}
