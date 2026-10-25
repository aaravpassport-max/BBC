import { useEffect, useState } from 'react';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { SkeletonRowList } from '../components/Skeleton';
import { listInvoices, downloadInvoicePdf, getErrorMessage, type TripInvoice } from '../api';

function money(n: string | number): string {
  return `₹${parseFloat(String(n)).toFixed(2)}`;
}

export function InvoicesPage() {
  const [invoices, setInvoices] = useState<TripInvoice[] | null>(null);
  const [error, setError] = useState('');
  const [downloading, setDownloading] = useState<string | null>(null);

  useEffect(() => {
    listInvoices()
      .then((r) => setInvoices(r.items))
      .catch((err) => setError(getErrorMessage(err, 'Could not load invoices.')));
  }, []);

  async function handleDownload(bookingId: string) {
    setDownloading(bookingId);
    try {
      const blob = await downloadInvoicePdf(bookingId);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `invoice-${bookingId.slice(0, 8)}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not download invoice.'));
    } finally {
      setDownloading(null);
    }
  }

  return (
    <Screen eyebrow="Account" title="Trip invoices" withNav>
      {error && <p style={{ color: 'var(--danger)', fontSize: 14 }}>{error}</p>}

      {invoices === null && !error && <SkeletonRowList count={4} />}

      {invoices && invoices.length === 0 && (
        <p style={{ color: 'var(--text-muted)' }}>No invoices yet. Invoices appear after completed trips.</p>
      )}

      {invoices && invoices.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {invoices.map((inv) => (
            <div
              key={inv.id}
              style={{
                border: '1px solid var(--border)',
                borderRadius: 12,
                padding: '14px 16px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                gap: 12,
              }}
            >
              <div>
                <div style={{ fontWeight: 600 }}>{inv.invoice_number}</div>
                <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>
                  Trip #{inv.booking_id.slice(0, 8).toUpperCase()} · {money(inv.amount)}
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                  {new Date(inv.generated_at).toLocaleDateString('en-IN')}
                </div>
              </div>
              <Button
                variant="ghost"
                style={{ width: 'auto', padding: '6px 14px' }}
                loading={downloading === inv.booking_id}
                onClick={() => void handleDownload(inv.booking_id)}
              >
                PDF
              </Button>
            </div>
          ))}
        </div>
      )}
    </Screen>
  );
}
