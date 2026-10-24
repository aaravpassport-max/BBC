import { useEffect, useState } from 'react';
import { billingApi, type BillingStatus, type Invoice } from '@/api/billing';
import { LoadingBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError, PlanConfig } from '@/types';

declare global {
  interface Window {
    Razorpay?: new (opts: Record<string, unknown>) => { open: () => void };
  }
}

function loadRazorpayScript(): Promise<boolean> {
  if (window.Razorpay) return Promise.resolve(true);
  return new Promise((resolve) => {
    const script = document.createElement('script');
    script.src = 'https://checkout.razorpay.com/v1/checkout.js';
    script.onload = () => resolve(true);
    script.onerror = () => resolve(false);
    document.body.appendChild(script);
  });
}

export default function Billing() {
  const [plans, setPlans] = useState<Record<string, PlanConfig & { id: string }> | null>(null);
  const [status, setStatus] = useState<BillingStatus | null>(null);
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [subscribing, setSubscribing] = useState<string | null>(null);
  const [cancelling, setCancelling] = useState(false);

  function load() {
    setLoading(true);
    setError(null);
    Promise.all([billingApi.plans(), billingApi.status(), billingApi.invoices().catch(() => ({ invoices: [] }))])
      .then(([p, s, i]) => {
        setPlans(p.plans);
        setStatus(s);
        setInvoices(i.invoices);
      })
      .catch((err) => setError(err as ApiError))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  async function onSubscribe(planId: 'pro' | 'premium') {
    setSubscribing(planId);
    setError(null);
    try {
      const r = await billingApi.subscribe(planId);
      const ok = await loadRazorpayScript();
      if (!ok || !window.Razorpay) {
        setError({ code: 'ia_rzp_load', message: 'Could not load the payment provider. Please check your connection and try again.', status: 0, retryable: true });
        return;
      }
      const rzp = new window.Razorpay({
        key: r.razorpay_key_id,
        subscription_id: r.subscription_id,
        name: 'InterviewAce',
        description: `${plans?.[planId]?.display_name ?? planId} plan`,
        theme: { color: '#8B5CF6' },
        handler: () => {
          // Actual activation is confirmed server-side via the Razorpay
          // webhook (class-api-billing.php wh_charged), not this client
          // callback — re-fetching status here just reflects whatever the
          // webhook has recorded by the time the user lands back here.
          load();
        },
      });
      rzp.open();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubscribing(null);
    }
  }

  async function onCancel() {
    if (!window.confirm('Cancel your subscription? You’ll keep access until the end of your current billing period.')) return;
    setCancelling(true);
    try {
      await billingApi.cancel();
      load();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setCancelling(false);
    }
  }

  if (loading) return <div className="ia-page-wide"><LoadingBlock label="Loading billing info…" /></div>;
  if (error && !plans) return <div className="ia-page-wide"><ErrorBlock error={error} onRetry={load} /></div>;

  return (
    <div className="ia-page-wide">
      <h1>Billing</h1>

      {status && (
        <div className="ia-card" style={{ marginBottom: 'var(--ia-space-6)' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <span className="ia-badge ia-badge-info">{status.plan.toUpperCase()}</span>
              {status.cancel_at_period_end && <span className="ia-badge ia-badge-warning" style={{ marginLeft: 'var(--ia-space-2)' }}>Cancels at period end</span>}
            </div>
            {status.plan !== 'free' && status.status === 'active' && !status.cancel_at_period_end && (
              <button className="ia-btn ia-btn-ghost" onClick={() => void onCancel()} disabled={cancelling}>
                {cancelling ? 'Cancelling…' : 'Cancel plan'}
              </button>
            )}
          </div>
          <p style={{ color: 'var(--ia-text-secondary)', marginTop: 'var(--ia-space-3)' }}>
            {status.plan === 'free'
              ? `${status.week_interviews} of ${status.week_limit ?? '—'} free interviews used this week.`
              : `${status.minutes_remaining_this_month} minutes remaining this month.`}
          </p>
        </div>
      )}

      {error && <div style={{ marginBottom: 'var(--ia-space-4)' }}><ErrorBlock error={error} /></div>}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 'var(--ia-space-4)', marginBottom: 'var(--ia-space-8)' }}>
        {plans && Object.values(plans).map((p) => (
          <div key={p.id} className="ia-card" style={p.highlight ? { borderColor: 'var(--ia-accent)' } : undefined}>
            {p.ribbon_text && <span className="ia-badge ia-badge-success" style={{ marginBottom: 'var(--ia-space-2)' }}>{p.ribbon_text}</span>}
            <h2>{p.display_name}</h2>
            <p style={{ fontSize: 'var(--ia-text-2xl)', fontWeight: 800 }}>
              {p.price_display}<span style={{ fontSize: 'var(--ia-text-sm)', color: 'var(--ia-text-secondary)' }}>{p.price_period}</span>
            </p>
            <ul style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>
              {p.features.map((f, i) => <li key={i}>{f}</li>)}
            </ul>
            <button
              className="ia-btn ia-btn-primary ia-btn-block"
              onClick={() => void onSubscribe(p.id as 'pro' | 'premium')}
              disabled={subscribing === p.id || status?.plan === p.id}
            >
              {status?.plan === p.id ? 'Current plan' : subscribing === p.id ? 'Starting checkout…' : `Upgrade to ${p.display_name}`}
            </button>
          </div>
        ))}
      </div>

      <h2>Invoices</h2>
      {invoices.length === 0 ? (
        <p style={{ color: 'var(--ia-text-tertiary)' }}>No invoices yet.</p>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--ia-text-secondary)', fontSize: 'var(--ia-text-sm)' }}>
                <th style={{ padding: 'var(--ia-space-2)' }}>Date</th>
                <th style={{ padding: 'var(--ia-space-2)' }}>Plan</th>
                <th style={{ padding: 'var(--ia-space-2)' }}>Amount</th>
                <th style={{ padding: 'var(--ia-space-2)' }}>Status</th>
              </tr>
            </thead>
            <tbody>
              {invoices.map((inv) => (
                <tr key={inv.id} style={{ borderTop: '1px solid var(--ia-border)' }}>
                  <td style={{ padding: 'var(--ia-space-2)' }}>{new Date(inv.date).toLocaleDateString()}</td>
                  <td style={{ padding: 'var(--ia-space-2)' }}>{inv.plan ?? '—'}</td>
                  <td style={{ padding: 'var(--ia-space-2)' }}>{(inv.amount / 100).toLocaleString(undefined, { style: 'currency', currency: inv.currency })}</td>
                  <td style={{ padding: 'var(--ia-space-2)' }}><span className={`ia-badge ${inv.status === 'captured' ? 'ia-badge-success' : 'ia-badge-danger'}`}>{inv.status}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
