import { useState, useEffect, useCallback } from 'react';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import {
  getWithdrawableBalance,
  requestWithdrawal,
  getEarningsHistory,
  getEarningsSummary,
  getErrorMessage,
  type EarningsTransaction,
  type EarningsSummary,
} from '../api';

const TX_FILTERS = ['all', 'credit', 'debit'] as const;

export function EarningsPage() {
  const [balance, setBalance] = useState<{ available: number; held: number } | null>(null);
  const [summary, setSummary] = useState<EarningsSummary | null>(null);
  const [history, setHistory] = useState<EarningsTransaction[]>([]);
  const [amount, setAmount] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);
  const [withdrawMode, setWithdrawMode] = useState<'standard' | 'instant'>('standard');
  const [txFilter, setTxFilter] = useState<(typeof TX_FILTERS)[number]>('all');

  const refresh = useCallback(async () => {
    try {
      const [b, h, s] = await Promise.all([getWithdrawableBalance(), getEarningsHistory(), getEarningsSummary()]);
      setBalance(b);
      setHistory(h);
      setSummary(s);
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
      await requestWithdrawal(value, withdrawMode);
      setSuccess(true);
      setAmount('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not process this withdrawal.'));
    } finally {
      setSubmitting(false);
    }
  }

  const filtered = history.filter((t) => txFilter === 'all' || t.entry_type === txFilter);
  const instantFee = withdrawMode === 'instant' && amount ? (parseFloat(amount) * 0.02).toFixed(2) : null;

  return (
    <Screen eyebrow="Earnings" title="Your balance" withNav>
      {summary && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <StatCard label="This week" trips={summary.trips_week} earnings={summary.wallet_credits_week || summary.gross_earnings_week} />
          <StatCard label="This month" trips={summary.trips_month} earnings={summary.wallet_credits_month || summary.gross_earnings_month} />
        </div>
      )}

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
        {summary && summary.total_withdrawn > 0 && (
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8 }}>
            Lifetime withdrawn: ₹{summary.total_withdrawn.toFixed(0)}
          </div>
        )}
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
        <button
          type="button"
          onClick={() => setWithdrawMode('standard')}
          style={{
            flex: 1,
            padding: '8px 12px',
            borderRadius: 10,
            border: `1px solid ${withdrawMode === 'standard' ? 'var(--accent)' : 'var(--border)'}`,
            background: withdrawMode === 'standard' ? 'var(--accent-soft)' : 'var(--surface)',
            fontWeight: 600,
            cursor: 'pointer',
            fontSize: 13,
          }}
        >
          Standard (free · 1–2 days)
        </button>
        <button
          type="button"
          onClick={() => setWithdrawMode('instant')}
          style={{
            flex: 1,
            padding: '8px 12px',
            borderRadius: 10,
            border: `1px solid ${withdrawMode === 'instant' ? 'var(--accent)' : 'var(--border)'}`,
            background: withdrawMode === 'instant' ? 'var(--accent-soft)' : 'var(--surface)',
            fontWeight: 600,
            cursor: 'pointer',
            fontSize: 13,
          }}
        >
          Instant (2% fee)
        </button>
      </div>

      <Input label="Withdraw amount" prefix="₹" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} />
      {instantFee && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 8px' }}>Instant fee: ₹{instantFee}</p>}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>Withdrawal requested — it's on its way.</p>}

      <Button onClick={handleWithdraw} loading={submitting}>Withdraw</Button>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 10 }}>
        <h2 style={{ fontSize: 15, margin: 0 }}>Earnings history</h2>
        <div style={{ display: 'flex', gap: 6 }}>
          {TX_FILTERS.map((f) => (
            <button
              key={f}
              type="button"
              onClick={() => setTxFilter(f)}
              style={{
                border: `1px solid ${txFilter === f ? 'var(--accent)' : 'var(--border)'}`,
                background: txFilter === f ? 'var(--accent-soft)' : 'var(--surface)',
                borderRadius: 16,
                padding: '4px 10px',
                fontSize: 11,
                fontWeight: 600,
                cursor: 'pointer',
                textTransform: 'capitalize',
              }}
            >
              {f}
            </button>
          ))}
        </div>
      </div>

      {filtered.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No transactions in this category.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {filtered.map((t) => (
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
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Balance: ₹{parseFloat(t.balance_after).toFixed(2)}</div>
              </div>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 14, fontWeight: 600, color: t.entry_type === 'credit' ? 'var(--success)' : 'var(--danger)' }}>
                {t.entry_type === 'credit' ? '+' : '−'}₹{parseFloat(t.amount).toFixed(2)}
              </div>
            </div>
          ))}
        </div>
      )}
    </Screen>
  );
}

function StatCard({ label, trips, earnings }: { label: string; trips: number; earnings: number }) {
  return (
    <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)' }}>
      <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ fontSize: 18, fontWeight: 700, color: 'var(--accent-strong)' }}>₹{earnings.toFixed(0)}</div>
      <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{trips} trip{trips === 1 ? '' : 's'}</div>
    </div>
  );
}
