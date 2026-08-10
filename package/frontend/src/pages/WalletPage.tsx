import { useState, useEffect, useCallback } from 'react';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import {
  getWallet,
  getWalletTransactions,
  addMoney,
  verifyPayment,
  devSimulateWebhook,
  getErrorMessage,
  type WalletBalance,
  type WalletTransaction,
} from '../api';
import { Skeleton, SkeletonRowList } from '../components/Skeleton';

// Razorpay's Checkout widget is loaded from their own CDN, on demand, only
// when a real (non-simulated) payment is actually being made — no point
// loading a third-party script for users who never touch this flow, and
// this reference environment's own network restrictions mean it can only
// ever be exercised with real credentials on a real deployment anyway.
const RAZORPAY_SCRIPT_URL = 'https://checkout.razorpay.com/v1/checkout.js';

declare global {
  interface Window {
    Razorpay: new (options: Record<string, unknown>) => { open: () => void };
  }
}

function loadRazorpayScript(): Promise<boolean> {
  return new Promise((resolve) => {
    if (window.Razorpay) {
      resolve(true);
      return;
    }
    const script = document.createElement('script');
    script.src = RAZORPAY_SCRIPT_URL;
    script.onload = () => resolve(true);
    script.onerror = () => resolve(false);
    document.body.appendChild(script);
  });
}

function money(n: number | string): string {
  return `₹${parseFloat(String(n)).toFixed(2)}`;
}

const QUICK_AMOUNTS = [200, 500, 1000, 2000];

export function WalletPage() {
  const [balance, setBalance] = useState<WalletBalance | null>(null);
  const [transactions, setTransactions] = useState<WalletTransaction[] | null>(null);
  const [amount, setAmount] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const refresh = useCallback(async () => {
    try {
      const [b, t] = await Promise.all([getWallet(), getWalletTransactions()]);
      setBalance(b);
      setTransactions(t);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your wallet.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleAddMoney(chosenAmount: number) {
    setError('');
    setSuccess('');
    if (chosenAmount <= 0) {
      setError('Enter an amount greater than zero.');
      return;
    }
    setProcessing(true);
    try {
      const { gateway_session } = await addMoney(chosenAmount);

      if (gateway_session.simulated) {
        // No real Razorpay account configured on the backend — this
        // reference environment's own dev-only stand-in for a real
        // gateway webhook, confirming the SAME top-up server-side rather
        // than skipping confirmation entirely.
        await devSimulateWebhook(gateway_session.gateway_ref!);
        setSuccess(`Added ${money(chosenAmount)} to your wallet.`);
        setAmount('');
        await refresh();
        return;
      }

      // Real flow: load Razorpay's actual Checkout widget and let the user
      // pay with a real card/UPI/etc.
      const loaded = await loadRazorpayScript();
      if (!loaded) {
        setError('Could not load the payment provider. Check your connection and try again.');
        return;
      }
      const razorpay = new window.Razorpay({
        key: gateway_session.key_id,
        amount: gateway_session.amount,
        currency: gateway_session.currency,
        order_id: gateway_session.order_id,
        name: 'Waybill',
        description: 'Wallet top-up',
        handler: async (response: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => {
          try {
            await verifyPayment(response);
            setSuccess(`Added ${money(chosenAmount)} to your wallet.`);
            setAmount('');
            await refresh();
          } catch (err) {
            setError(getErrorMessage(err, 'Payment could not be verified.'));
          }
        },
        modal: {
          ondismiss: () => setProcessing(false),
        },
      });
      razorpay.open();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not start the top-up.'));
    } finally {
      setProcessing(false);
    }
  }

  return (
    <Screen eyebrow="Wallet" title="Porter Wallet" withNav>
      {balance ? (
        <div
          style={{
            border: 'none',
            borderRadius: 16,
            background: 'linear-gradient(135deg, #2b6ce6 0%, #1d5fd4 100%)',
            padding: 24,
            textAlign: 'center',
            color: 'white',
            boxShadow: 'var(--shadow-md)',
          }}
        >
          <div style={{ fontSize: 12, opacity: 0.9, marginBottom: 6 }}>Available balance</div>
          <div style={{ fontSize: 36, fontWeight: 700 }}>
            {money(balance.real_money_balance)}
          </div>
          {balance.promotional_credit_balance > 0 && (
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 6 }}>
              + {money(balance.promotional_credit_balance)} promo credit
            </div>
          )}
        </div>
      ) : (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 16,
            background: 'var(--surface)',
            padding: 24,
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            gap: 10,
          }}
        >
          <Skeleton width={110} height={12} />
          <Skeleton width={160} height={36} />
        </div>
      )}

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {QUICK_AMOUNTS.map((a) => (
          <Button
            key={a}
            variant="ghost"
            style={{ width: 'auto', padding: '8px 16px' }}
            disabled={processing}
            onClick={() => handleAddMoney(a)}
          >
            + ₹{a}
          </Button>
        ))}
      </div>

      <div style={{ display: 'flex', gap: 8 }}>
        <div style={{ flex: 1 }}>
          <Input
            placeholder="Custom amount"
            value={amount}
            onChange={(e) => setAmount(e.target.value.replace(/[^0-9]/g, ''))}
          />
        </div>
        <Button loading={processing} style={{ width: 'auto', padding: '0 20px' }} onClick={() => handleAddMoney(Number(amount))}>
          Add money
        </Button>
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}

      <h2 style={{ fontSize: 15, marginTop: 10 }}>Recent transactions</h2>
      {transactions === null && !error ? (
        <SkeletonRowList count={3} />
      ) : transactions && transactions.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No transactions yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {transactions?.map((t) => (
            <div
              key={t.id}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                border: '1px solid var(--border)',
                borderRadius: 10,
                padding: '10px 14px',
              }}
            >
              <div>
                <div style={{ fontSize: 14, textTransform: 'capitalize' }}>{t.reason.replace(/_/g, ' ')}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{new Date(t.created_at).toLocaleString()}</div>
              </div>
              <div
                style={{
                  fontFamily: 'var(--font-mono)',
                  fontSize: 14,
                  fontWeight: 600,
                  color: t.entry_type === 'credit' ? 'var(--success)' : 'var(--danger)',
                }}
              >
                {t.entry_type === 'credit' ? '+' : '−'}
                {money(t.amount)}
              </div>
            </div>
          ))}
        </div>
      )}
    </Screen>
  );
}
