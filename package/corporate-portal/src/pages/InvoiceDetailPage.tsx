import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { getInvoiceDetail, markInvoicePaid, emailCorporateInvoice, downloadCorporateInvoicePdf, getMyAccounts, getErrorMessage, type InvoiceDetail } from '../api';
import { Skeleton } from '../components/Skeleton';

function money(n: number | string): string {
  return `₹${parseFloat(String(n)).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function InvoiceDetailPage() {
  const { accountId, invoiceId } = useParams<{ accountId: string; invoiceId: string }>();
  const navigate = useNavigate();
  const [invoice, setInvoice] = useState<InvoiceDetail | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const [markingPaid, setMarkingPaid] = useState(false);
  const [emailing, setEmailing] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    if (!accountId || !invoiceId) return;
    Promise.all([getInvoiceDetail(accountId, invoiceId), getMyAccounts()])
      .then(([inv, accounts]) => {
        setInvoice(inv);
        setIsAdmin(accounts.some((a) => a.account_id === accountId && a.role === 'account_admin'));
      })
      .catch((err) => setError(getErrorMessage(err, 'Could not load this invoice.')));
  }, [accountId, invoiceId]);

  async function handleDownloadPdf() {
    if (!accountId || !invoiceId || !invoice) return;
    setError('');
    try {
      const blob = await downloadCorporateInvoicePdf(accountId, invoiceId);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${invoice.invoice_number}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not download PDF.'));
    }
  }

  async function handleEmailInvoice() {
    if (!accountId || !invoiceId) return;
    setEmailing(true);
    setError('');
    setSuccess('');
    try {
      const result = await emailCorporateInvoice(accountId, invoiceId);
      setSuccess(`Invoice emailed to ${result.recipients.join(', ')}.`);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not email this invoice.'));
    } finally {
      setEmailing(false);
    }
  }

  async function handleMarkPaid() {
    if (!accountId || !invoiceId) return;
    setMarkingPaid(true);
    try {
      await markInvoicePaid(accountId, invoiceId);
      setInvoice((prev) => (prev ? { ...prev, status: 'paid' } : prev));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not mark invoice as paid.'));
    } finally {
      setMarkingPaid(false);
    }
  }

  if (error) {
    return (
      <Screen eyebrow="Invoice" title="Could not load invoice">
        <p style={{ color: 'var(--danger)', fontSize: 14 }}>{error}</p>
        <Button variant="ghost" onClick={() => navigate(-1)}>
          ← Back
        </Button>
      </Screen>
    );
  }

  if (!invoice) {
    return (
      <Screen eyebrow="Invoice" title="Invoice">
        <div style={{ border: '1px solid var(--border)', borderRadius: 16, background: 'var(--surface)', padding: 28 }}>
          <div style={{ textAlign: 'center', marginBottom: 20 }}>
            <Skeleton width={160} height={22} style={{ margin: '0 auto 8px' }} />
            <Skeleton width={100} height={12} style={{ margin: '0 auto' }} />
          </div>
          <Skeleton width="100%" height={14} style={{ marginBottom: 10 }} />
          <Skeleton width="100%" height={14} style={{ marginBottom: 10 }} />
          <Skeleton width="100%" height={14} style={{ marginBottom: 20 }} />
          <Skeleton width={120} height={20} />
        </div>
      </Screen>
    );
  }

  return (
    <>
      <style>{`
        @media print {
          .no-print { display: none !important; }
          .invoice-print-area {
            background: white !important;
            color: black !important;
            box-shadow: none !important;
            border: 1px solid #ccc !important;
          }
          .invoice-print-area * { color: black !important; }
          body { background: white !important; }
        }
      `}</style>
      <Screen eyebrow="Invoice" title={invoice.invoice_number}>
        <div className="no-print" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', justifyContent: 'space-between' }}>
          <Button variant="ghost" style={{ width: 'auto', padding: '4px 0' }} onClick={() => navigate(-1)}>
            ← Back
          </Button>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Button variant="ghost" style={{ width: 'auto', padding: '8px 14px' }} onClick={() => void handleDownloadPdf()}>
              Download PDF
            </Button>
            {isAdmin && (
              <Button loading={emailing} variant="ghost" style={{ width: 'auto', padding: '8px 14px' }} onClick={() => void handleEmailInvoice()}>
                Email to billing
              </Button>
            )}
            <Button style={{ width: 'auto', padding: '8px 18px' }} onClick={() => window.print()}>
              Print
            </Button>
            {isAdmin && invoice.status === 'issued' && (
              <Button loading={markingPaid} style={{ width: 'auto', padding: '8px 18px' }} onClick={() => void handleMarkPaid()}>
                Mark as paid
              </Button>
            )}
          </div>
        </div>

        {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}

        <div
          className="invoice-print-area"
          style={{
            border: '1px solid var(--border)',
            borderRadius: 16,
            background: 'var(--surface)',
            padding: 28,
          }}
        >
          <div style={{ textAlign: 'center', marginBottom: 20 }}>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 22 }}>PORTMYSTUFF Business</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Tax invoice · {invoice.status}</div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 16 }}>
            <div>
              <div style={{ color: 'var(--text-muted)' }}>Invoice #</div>
              <div style={{ fontFamily: 'var(--font-mono)' }}>{invoice.invoice_number}</div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ color: 'var(--text-muted)' }}>Billing period</div>
              <div>
                {new Date(invoice.period_start).toLocaleDateString()} – {new Date(invoice.period_end).toLocaleDateString()}
              </div>
            </div>
          </div>

          <div style={{ borderTop: '1px dashed var(--border)', paddingTop: 12 }}>
            <div style={{ display: 'flex', fontSize: 11, color: 'var(--text-muted)', marginBottom: 8, fontWeight: 700 }}>
              <div style={{ flex: 2 }}>TRIP</div>
              <div style={{ flex: 1.5 }}>EMPLOYEE</div>
              <div style={{ flex: 1 }}>DATE</div>
              <div style={{ flex: 1, textAlign: 'right' }}>AMOUNT</div>
            </div>
            {invoice.lineItems.length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No trips in this billing period.</p>
            ) : (
              invoice.lineItems.map((item) => (
                <div key={item.id} style={{ display: 'flex', fontSize: 13, padding: '6px 0', borderBottom: '1px solid var(--border)' }}>
                  <div style={{ flex: 2, fontFamily: 'var(--font-mono)' }}>#{item.id.slice(0, 8).toUpperCase()}</div>
                  <div style={{ flex: 1.5 }}>+91 {item.employee_phone}</div>
                  <div style={{ flex: 1, color: 'var(--text-muted)' }}>{new Date(item.created_at).toLocaleDateString()}</div>
                  <div style={{ flex: 1, textAlign: 'right', fontFamily: 'var(--font-mono)' }}>
                    {money(item.fare_breakdown.final_fare)}
                  </div>
                </div>
              ))
            )}
          </div>

          <div
            style={{
              borderTop: '1px solid var(--border)',
              marginTop: 14,
              paddingTop: 14,
              display: 'flex',
              justifyContent: 'space-between',
              fontSize: 17,
              fontWeight: 700,
            }}
          >
            <span>Total due</span>
            <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--accent-strong)' }}>{money(invoice.total_amount)}</span>
          </div>

          <div style={{ marginTop: 20, fontSize: 11, color: 'var(--text-muted)', textAlign: 'center' }}>
            {invoice.booking_count} trip{invoice.booking_count === 1 ? '' : 's'} · Generated{' '}
            {new Date(invoice.generated_at).toLocaleDateString()} · This is a computer-generated invoice.
          </div>
        </div>
      </Screen>
    </>
  );
}
