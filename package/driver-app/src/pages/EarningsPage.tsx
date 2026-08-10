import { useState, useEffect, useCallback } from 'react';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getWithdrawableBalance, requestWithdrawal, getEarningsHistory, getErrorMessage, type EarningsTransaction } from '../api';

export function EarningsPage() {
  const [balance, setBalance] = useState<{ available: number; held: number } | null>(null);
  const [history, setHistory] = useState<EarningsTransaction[]>([]);
  const [amount, setAmount] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  const refresh = useCallback(async () => {
    try {
      const [b, h] = await Promise.all([getWithdrawableBalance(), getEarningsHistory()]);
      setBalance(b);
      setHistory(h);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your balance.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleWithdraw() {
    setError('');
    setSuccess(false);
    const value = parseFloat(amount);
    if (!value || value <= 0) {
      setError('Enter a valid amount.');
      return;
    }
    setSubmitting(true);
    try {
      await requestWithdrawal(value, 'standard');
      setSuccess(true);
      setAmount('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not process this withdrawal.'));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Screen eyebrow="Earnings" title="Your balance">
      <div
        style={{
          border: '1px solid var(--border)',
          borderRadius: 16,
          background: 'var(--surface)',
          padding: 24,
          textAlign: 'center',
        }}
      >
        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>Available to withdraw</div>
        <div style={{ fontFamily: 'var(--font-mono)', fontSize: 36, fontWeight: 700, color: 'var(--accent-strong)' }}>
          ₹{balance ? balance.available.toFixed(2) : '—'}
        </div>
        {balance && balance.held > 0 && (
          <div style={{ fontSize: 12, color: 'var(--danger)', marginTop: 8 }}>
            ₹{balance.held.toFixed(2)} held pending review
          </div>
        )}
      </div>

      <Input
        label="Withdraw amount"
        prefix="₹"
        inputMode="decimal"
        value={amount}
        onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))}
      />

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>Withdrawal requested — it's on its way.</p>}

      <Button onClick={handleWithdraw} loading={submitting}>
        Withdraw
      </Button>

      <h2 style={{ fontSize: 15, marginTop: 10 }}>Earnings history</h2>
      {history.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No transactions yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {history.map((t) => (
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
                {t.entry_type === 'credit' ? '+' : '−'}₹{parseFloat(t.amount).toFixed(2)}
              </div>
            </div>
          ))}
        </div>
      )}
    </Screen>
  );
}
