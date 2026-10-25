import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getMyAccounts, listInvoices, generateInvoice, getErrorMessage, type Invoice } from '../api';
import { SkeletonRowList } from '../components/Skeleton';

function money(v: string | number): string {
  return `₹${parseFloat(String(v)).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function firstOfMonth(monthsAgo: number): string {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() - monthsAgo);
  d.setHours(0, 0, 0, 0);
  return d.toISOString().slice(0, 10);
}

export function InvoicesPage() {
  const { accountId } = useParams<{ accountId: string }>();
  const navigate = useNavigate();
  const [invoices, setInvoices] = useState<Invoice[] | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [generating, setGenerating] = useState(false);
  const [periodStart, setPeriodStart] = useState(firstOfMonth(1));
  const [periodEnd, setPeriodEnd] = useState(firstOfMonth(0));

  const refresh = useCallback(async () => {
    if (!accountId) return;
    try {
      const [list, myAccounts] = await Promise.all([listInvoices(accountId), getMyAccounts()]);
      setInvoices(list);
      setIsAdmin(myAccounts.some((a) => a.account_id === accountId && a.role === 'account_admin'));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load invoices.'));
    }
  }, [accountId]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleGenerate() {
    if (!accountId) return;
    setError('');
    setSuccess('');
    setGenerating(true);
    try {
      const result = await generateInvoice(
        accountId,
        new Date(periodStart).toISOString(),
        new Date(periodEnd).toISOString()
      );
      setSuccess(
        `Generated ${result.invoiceNumber} — ${money(result.totalAmount)} across ${result.bookingCount} trip${result.bookingCount === 1 ? '' : 's'}.`
      );
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not generate this invoice.'));
    } finally {
      setGenerating(false);
    }
  }

  return (
    <Screen eyebrow="Billing" title="Invoices">
      <Button variant="ghost" style={{ width: 'auto', padding: '4px 0' }} onClick={() => navigate(`/accounts/${accountId}`)}>
        ← Back to account
      </Button>

      {isAdmin && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 18 }}>
          <h2 style={{ fontSize: 15, marginBottom: 12 }}>Generate an invoice</h2>
          <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
            <Input label="Period start" type="date" value={periodStart} onChange={(e) => setPeriodStart(e.target.value)} />
            <Input label="Period end" type="date" value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} />
            <Button loading={generating} style={{ width: 'auto', padding: '10px 20px' }} onClick={handleGenerate}>
              Generate
            </Button>
          </div>
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}

      {invoices === null && !error && <SkeletonRowList count={2} />}
      {invoices && invoices.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No invoices generated yet.</p>
      )}

      {invoices && invoices.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {invoices.map((inv) => (
            <button
              key={inv.id}
              onClick={() => navigate(`/accounts/${accountId}/invoices/${inv.id}`)}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                textAlign: 'left',
                border: '1px solid var(--border)',
                borderRadius: 12,
                background: 'var(--surface)',
                padding: '14px 16px',
                cursor: 'pointer',
                color: 'var(--text)',
              }}
            >
              <div>
                <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, fontSize: 14 }}>{inv.invoice_number}</div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
                  {new Date(inv.period_start).toLocaleDateString()} – {new Date(inv.period_end).toLocaleDateString()} ·{' '}
                  {inv.booking_count} trip{inv.booking_count === 1 ? '' : 's'} · {inv.status}
                </div>
              </div>
              <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, fontSize: 16, color: 'var(--accent-strong)' }}>
                {money(inv.total_amount)}
              </div>
            </button>
          ))}
        </div>
      )}
    </Screen>
  );
}
